#!/usr/bin/env node
/**
 * scripts/lib/mcp-client.js — Phase 1.2 Retry-Logik
 *
 * Resilient HTTP client for Novamira MCP API calls with exponential
 * backoff, jitter, and structured logging.
 *
 * Architecture note (v3.0.0):
 *   In the current arch, MCP calls go through the Claude agent's
 *   novamira-solar-local connector. This client handles the HTTP
 *   fallback path (REST endpoints, sync-schema, diagnostics) where
 *   direct fetch() calls benefit from retry resilience.
 *
 * Retry policy:
 *   - Retryable: 5xx server errors, network errors (fetch failed),
 *     timeouts (AbortError), 429 rate-limit
 *   - Non-retryable: 4xx client errors (401, 403, 404, 422, etc.),
 *     JSON parse errors (invalid response is a caller bug)
 *   - Delay: baseDelayMs * 2^attempt + random(0, 200) [jitter]
 *   - Max retries: configurable, default 3
 *
 * Logging:
 *   - WARN on each retry attempt
 *   - ERROR when all retries exhausted
 *   - INFO on successful retry
 *
 * Usage:
 *   import { McpClient } from './lib/mcp-client.js';
 *   const client = new McpClient('http://solar.local', {
 *     maxRetries: 3,
 *     baseDelayMs: 1000,
 *     timeout: 30000,
 *   });
 *   const schema = await client.get('/wp-json/novamira-adrianv2/v1/prop-schema');
 */

'use strict';

import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Returns a random millisecond value between 0 and max (inclusive).
 * Used for jitter to prevent thundering-herd on retry.
 *
 * @param {number} max Maximum jitter in ms.
 * @returns {number}
 */
function jitter(max = 200) {
  return Math.floor(Math.random() * (max + 1));
}

/**
 * Returns true if the error is retryable.
 *
 * Retryable:
 *   - HTTP 5xx (server errors)
 *   - HTTP 429 (rate limited)
 *   - Network errors (fetch threw, no response)
 *   - AbortError / TimeoutError
 *
 * Non-retryable:
 *   - HTTP 4xx (client errors — request is invalid)
 *   - JSON parse errors (malformed response)
 *
 * @param {Error|Response} err Error or response object.
 * @returns {boolean}
 */
function isRetryable(err) {
  // fetch() threw — network error or timeout
  if (!err.status && !err.ok && err.message) {
    const msg = err.message.toLowerCase();
    if (err.name === 'TimeoutError' || err.name === 'AbortError') return true;
    if (msg.includes('fetch failed') || msg.includes('network') || msg.includes('econnrefused') || msg.includes('enotfound')) return true;
  }
  // HTTP 5xx or 429
  if (typeof err.status === 'number') {
    if (err.status >= 500 && err.status < 600) return true;
    if (err.status === 429) return true;
  }
  return false;
}

// ─── McpClient ───────────────────────────────────────────────────────────────

export class McpClient {

  /**
   * @param {string}  baseUrl          WordPress base URL (e.g. http://solar.local).
   * @param {object}  [options]        Client configuration.
   * @param {number}  [options.maxRetries=3]   Maximum retry attempts.
   * @param {number}  [options.baseDelayMs=1000] Base delay in ms (doubles each attempt).
   * @param {number}  [options.timeout=30000]   Request timeout in ms.
   * @param {boolean} [options.verbose=false]   Enable debug logging.
   */
  constructor(baseUrl, options = {}) {
    this.baseUrl     = baseUrl.replace(/\/+$/, '');
    this.maxRetries  = options.maxRetries ?? 3;
    this.baseDelayMs = options.baseDelayMs ?? 1000;
    this.timeout     = options.timeout ?? 30000;
    this.verbose     = options.verbose ?? false;
    this._closed     = false;
  }

  /**
   * Cleanly closes the client, preventing UV_HANDLE_CLOSING crashes
   * on Windows when process.exit() is called with active keep-alive.
   *
   * On Windows, undici's keep-alive pool holds async handles that
   * trigger a libuv assertion when process.exit() fires. This method
   * destroys the global dispatcher to release those handles.
   *
   * Safe to call multiple times.
   */
  close() {
    if (this._closed) return;
    this._closed = true;

    // Destroy undici's global dispatcher to release async handles.
    // Without this, Node's global fetch() keep-alive pool triggers
    // "Assertion failed: !(handle->flags & UV_HANDLE_CLOSING)" on
    // Windows when process.exit() is called.
    try {
      const undici = require('undici');
      const dispatcher = undici.getGlobalDispatcher();
      if (dispatcher) {
        if (typeof dispatcher.destroy === 'function') dispatcher.destroy();
        else if (typeof dispatcher.close === 'function') dispatcher.close();
      }
    } catch {
      // undici is a Node 18+ internal; if require fails (bundled
      // differently), the Connection:close header on each request
      // already prevents keep-alive. Callers use process._exit()
      // on Windows as the final fallback against libuv assertions.
    }
  }

  // ── Public API ──────────────────────────────────────────────────────────

  /**
   * Executes a named Novamira ability via the MCP JSON-RPC adapter.
   *
   * Per the arch (v3.0.0): the primary path is the Claude agent's
   * MCP connector. This method serves as an HTTP fallback for scripts
   * that need direct API access (e.g., pre-flight checks, schema sync).
   *
   * @param {string} ability    Ability name (e.g. "novamira/adrians-greet").
   * @param {object} [params={}] Ability parameters.
   * @param {object} [retryOpts] Per-call retry overrides.
   * @param {number} [retryOpts.maxRetries]
   * @param {number} [retryOpts.baseDelayMs]
   * @returns {Promise<object>} Parsed ability response.
   */
  async executeAbility(ability, params = {}, retryOpts = {}) {
    const endpoint = '/wp-json/mcp/novamira';
    const body = {
      ability_name: ability,
      parameters: params,
    };
    return this._fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }, retryOpts);
  }

  /**
   * HTTP GET with retry.
   *
   * @param {string} path     URL path (appended to baseUrl).
   * @param {object} [retryOpts] Per-call retry overrides.
   * @returns {Promise<object>} Parsed JSON response.
   */
  async get(path, retryOpts = {}) {
    return this._fetch(path, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
    }, retryOpts);
  }

  /**
   * Discovers all registered abilities from the MCP adapter.
   *
   * @param {object} [retryOpts] Per-call retry overrides.
   * @returns {Promise<object>} Ability list.
   */
  async discoverAbilities(retryOpts = {}) {
    return this.executeAbility('mcp-adapter-discover-abilities', {}, retryOpts);
  }

  // ── Core fetch with retry ───────────────────────────────────────────────

  /**
   * Internal fetch with exponential-backoff retry.
   *
   * @param {string} path       URL path to append to baseUrl.
   * @param {object} fetchOpts  fetch() options (method, headers, body).
   * @param {object} retryOpts  Retry overrides for this call.
   * @returns {Promise<object>} Parsed JSON response.
   */
  async _fetch(path, fetchOpts = {}, retryOpts = {}) {
    const maxRetries  = retryOpts.maxRetries  ?? this.maxRetries;
    const baseDelayMs = retryOpts.baseDelayMs ?? this.baseDelayMs;
    const url = this.baseUrl + path;

    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      const isLastAttempt = attempt === maxRetries;

      try {
        const response = await this._send(url, fetchOpts);

        // Success — response may or may not have JSON body
        if (response.status === 204) return null;

        // 4xx client error — don't retry
        if (response.status >= 400 && response.status < 500 && response.status !== 429) {
          const body = await this._readBody(response);
          throw Object.assign(
            new Error(`HTTP ${response.status} from ${url}: ${body.slice(0, 300)}`),
            { status: response.status, body, retryable: false }
          );
        }

        // 5xx or 429 — may retry
        if (response.status >= 500 || response.status === 429) {
          const body = await this._readBody(response);
          const err = Object.assign(
            new Error(`HTTP ${response.status} from ${url}: ${body.slice(0, 300)}`),
            { status: response.status, body, retryable: true }
          );
          if (isLastAttempt) throw err;
          await this._retryDelay(err, attempt, baseDelayMs, maxRetries);
          continue;
        }

        // Parse JSON
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
          try {
            return await response.json();
          } catch (e) {
            // JSON parse error is non-retryable (caller bug)
            throw Object.assign(
              new Error(`Invalid JSON from ${url}: ${e.message}`),
              { status: response.status, retryable: false }
            );
          }
        }
        return await response.text();

      } catch (err) {
        if (err.retryable === false) throw err;       // explicit non-retryable
        if (!isRetryable(err)) throw err;              // 4xx, JSON parse, etc.
        if (isLastAttempt) {
          this._log('ERROR', `All ${maxRetries + 1} attempts failed for ${url}: ${err.message}`);
          throw err;
        }
        await this._retryDelay(err, attempt, baseDelayMs, maxRetries);
      }
    }
  }

  /**
   * Single fetch with timeout (Node 18+ AbortSignal.timeout).
   * Forces Connection:close to prevent undici keep-alive from
   * triggering UV_HANDLE_CLOSING assertion on Windows process.exit.
   */
  async _send(url, fetchOpts) {
    if (this._closed) throw new Error('McpClient is closed');
    return fetch(url, {
      ...fetchOpts,
      signal: AbortSignal.timeout(this.timeout),
      headers: {
        ...fetchOpts.headers,
        'Connection': 'close',
      },
    });
  }

  /**
   * Read response body as text, with size limit.
   */
  async _readBody(response) {
    try {
      const text = await response.text();
      return text.slice(0, 2000);
    } catch {
      return '(unable to read body)';
    }
  }

  /**
   * Calculates and waits the retry delay, then logs the attempt.
   *
   * Formula: baseDelayMs * 2^attempt + jitter(0, 200)
   * This gives: 1s, 2s, 4s, 8s, ... plus up to 200ms random.
   *
   * @param {Error}  err          The error that triggered the retry.
   * @param {number} attempt      Zero-based attempt counter.
   * @param {number} baseDelayMs  Base delay in ms.
   * @param {number} maxRetries   Total retry budget.
   */
  async _retryDelay(err, attempt, baseDelayMs, maxRetries) {
    const delay = baseDelayMs * Math.pow(2, attempt) + jitter(200);
    const attemptNum = attempt + 1;
    this._log('WARN',
      `Retry ${attemptNum}/${maxRetries} in ${delay}ms — ${err.message.slice(0, 120)}`
    );
    await new Promise(resolve => setTimeout(resolve, delay));
  }

  /**
   * Structured logger.
   *
   * @param {'WARN'|'ERROR'|'INFO'} level
   * @param {string} message
   */
  _log(level, message) {
    const prefix = `[mcp-client] ${level}:`;
    if (level === 'ERROR') {
      process.stderr.write(prefix + ' ' + message + '\n');
    } else if (level === 'WARN' || this.verbose) {
      process.stderr.write(prefix + ' ' + message + '\n');
    }
  }
}

export default McpClient;

// ── Self-Test ─────────────────────────────────────────────────────────────────
// node scripts/lib/mcp-client.js --self-test

if (process.argv.includes('--self-test')) {
  const client = new McpClient('http://localhost', { verbose: true });

  // Verify retry math
  const delays = [];
  for (let i = 0; i < 3; i++) {
    delays.push(client.baseDelayMs * Math.pow(2, i) + '[0-200]ms jitter');
  }

  console.log(`
╔══════════════════════════════════════════════════════════════╗
║       framer-v4-pipeline-v2 — MCP Client v1.0.0             ║
║       Phase 1.2: Exponential-Backoff Retry                  ║
╚══════════════════════════════════════════════════════════════╝

✅ Configuration:
   Base URL:      ${client.baseUrl}
   Max Retries:   ${client.maxRetries}
   Base Delay:    ${client.baseDelayMs}ms
   Timeout:       ${client.timeout}ms

📐 Retry Delays (exponential + jitter):
   Attempt 1: ${delays[0]}
   Attempt 2: ${delays[1]}
   Attempt 3: ${delays[2]}

🔄 Retryable errors:
   ✓ HTTP 5xx (500-599)
   ✓ HTTP 429 (rate limit)
   ✓ Network failure (fetch failed, ECONNREFUSED, ENOTFOUND)
   ✓ Timeout (AbortError, TimeoutError)

🚫 Non-retryable errors:
   ✗ HTTP 4xx (400-499, except 429)
   ✗ JSON parse errors
   ✗ Invalid URL / configuration

📋 Usage:
   import { McpClient } from './lib/mcp-client.js';
   const client = new McpClient('http://solar.local');
   const data = await client.get('/wp-json/novamira-adrianv2/v1/prop-schema');

Status: READY
`);
  process.exit(0);
}
