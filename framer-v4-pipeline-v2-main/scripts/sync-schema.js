#!/usr/bin/env node
/**
 * sync-schema.js — Phase 0.2 Schema-Dedup (+ Phase 1.2 Retry)
 *
 * Fetches the canonical V4 Property Type Schema from the V2-Plugin's
 * REST endpoint and writes it to schemas/v4-prop-type-schema.json.
 *
 * Uses McpClient (Phase 1.2) for HTTP calls with exponential-backoff
 * retry on 5xx / network errors / timeouts.
 *
 * SOURCE OF TRUTH: wp-json/novamira/v1/prop-schema
 * TARGET:          schemas/v4-prop-type-schema.json
 *
 * Fail-Fast: exit code 1 if the endpoint is unreachable after all
 * retries, returns non-200, or the response is not valid JSON.
 *
 * Usage:
 *   node scripts/sync-schema.js
 *   node scripts/sync-schema.js --url http://solar.local
 *   node scripts/sync-schema.js --output schemas/v4-prop-type-schema.json
 *
 * Environment variables:
 *   WP_API_URL  — WordPress REST base URL (falls back to --url arg)
 *   PIPELINE_WORKSPACE — pipeline workspace root (default: ./.pipeline)
 *
 * Exit codes:
 *   0 = schema synced successfully
 *   1 = sync failed (endpoint error)
 *   2 = configuration error
 */

'use strict';

import { parseArgs } from 'node:util';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { McpClient } from './lib/mcp-client.js';

// ─── CLI ─────────────────────────────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    url:      { type: 'string' },
    output:   { type: 'string' },
    timeout:  { type: 'string', default: '15000' },
    verbose:  { type: 'boolean', default: false },
    help:     { type: 'boolean', default: false },
  },
  strict: false,
});

if (args.help) {
  process.stdout.write(`
sync-schema.js — Fetch canonical V4 prop schema from V2-Plugin

USAGE:
  node scripts/sync-schema.js [options]

OPTIONS:
  --url URL      WordPress base URL (e.g. http://solar.local)
                 Falls back to WP_API_URL env var
  --output FILE  Output path [default: schemas/v4-prop-type-schema.json]
  --timeout MS   HTTP timeout in ms [default: 15000]
  --verbose      Verbose logging
  --help         This help

ENV:
  WP_API_URL     WordPress REST base URL
`);
  process.exit(0);
}

const log   = (...m) => { if (args.verbose) process.stderr.write('[sync-schema] ' + m.join(' ') + '\n'); };
const warn  = (...m) => process.stderr.write('[sync-schema] WARN: ' + m.join(' ') + '\n');
const fatal = (m, c = 1) => { process.stderr.write('[sync-schema] FATAL: ' + m + '\n'); process.exit(c); };

// ─── Config ──────────────────────────────────────────────────────────────────

const WP_BASE_URL  = args.url || process.env.WP_API_URL || '';
const OUTPUT_PATH  = args.output
  ? resolve(args.output)
  : resolve(dirname(new URL(import.meta.url).pathname), '..', 'schemas', 'v4-prop-type-schema.json');
const TIMEOUT_MS   = parseInt(args.timeout, 10);
const API_PATH     = '/wp-json/novamira/v1/prop-schema';

if (!WP_BASE_URL) {
  fatal(
    'No WordPress URL configured. Set --url or WP_API_URL env var.\n' +
    '  Example: node scripts/sync-schema.js --url http://solar.local\n' +
    '  Or set:  export WP_API_URL=http://solar.local'
  , 2);
}

// ─── Fetch (Phase 1.2: resilient McpClient) ──────────────────────────────────

async function fetchSchema() {
  const client = new McpClient(WP_BASE_URL, {
    maxRetries: 3,
    baseDelayMs: 1000,
    timeout: TIMEOUT_MS,
    verbose: args.verbose,
  });

  log(`Fetching schema from ${client.baseUrl}${API_PATH} (retry: up to ${client.maxRetries}x)...`);

  let schema;
  try {
    schema = await client.get(API_PATH);
  } catch (e) {
    const reason = e.message || String(e);
    if (e.status && e.status >= 400) {
      fatal(
        `Server returned HTTP ${e.status} from ${client.baseUrl}${API_PATH}\n` +
        `  Is the Novamira AdrianV2 plugin activated?`
      );
    }
    fatal(
      `Could not reach ${client.baseUrl}${API_PATH}\n` +
      `  ${reason}\n` +
      `  Is the WordPress site running at ${WP_BASE_URL}?`
    );
  }

  // Basic schema validation
  if (!schema || typeof schema !== 'object') {
    fatal('Response is not a valid schema object');
  }
  if (!schema.types || typeof schema.types !== 'object') {
    fatal("Schema is missing required 'types' key");
  }
  if (!schema.properties || typeof schema.properties !== 'object') {
    fatal("Schema is missing required 'properties' key");
  }

  return schema;
}

// ─── Write ───────────────────────────────────────────────────────────────────

function writeSchema(schema) {
  const dir = dirname(OUTPUT_PATH);
  if (!existsSync(dir)) {
    mkdirSync(dir, { recursive: true });
  }

  const json = JSON.stringify(schema, null, 2);
  writeFileSync(OUTPUT_PATH, json, 'utf8');

  log(`Schema written to ${OUTPUT_PATH}`);
  log(`  Version: ${schema.version || 'unknown'}`);
  log(`  Types:   ${Object.keys(schema.types || {}).length}`);
  log(`  Props:   ${Object.keys(schema.properties || {}).length}`);
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  const schema = await fetchSchema();

  // Ensure output dir exists
  const outputDir = dirname(OUTPUT_PATH);
  if (!existsSync(outputDir)) {
    mkdirSync(outputDir, { recursive: true });
  }

  writeSchema(schema);

  process.stderr.write(`[sync-schema] ✅ Schema synced from ${WP_BASE_URL}\n`);
  process.stderr.write(`[sync-schema]    Version: ${schema.version || 'unknown'} → ${OUTPUT_PATH}\n`);
  process.exit(0);
}

main().catch(err => {
  process.stderr.write('[sync-schema] FATAL: ' + err.message + '\n');
  if (args.verbose) process.stderr.write(err.stack + '\n');
  process.exit(1);
});
