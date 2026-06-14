#!/usr/bin/env node
/**
 * profile-pipeline.js  —  Sprint 9: Pipeline Performance Profiler (ENH-14)
 *
 * Misst die Ausfuehrungszeit jeder Pipeline-Phase anhand eines v4-tree.json
 * und identifiziert Bottlenecks.
 *
 * USAGE:
 *   node scripts/profile-pipeline.js --tree v4-tree.json [--output profile.json] [--bottleneck]
 *   node scripts/profile-pipeline.js --tree v4-tree.json --timeout 600000
 *
 * MEASURED PHASES:
 *   1. Token-Extraktion   (design-token-extractor.js)
 *   2. Konvertierung      (convert-xml-to-v4.js)
 *   3. Auto-Scaling       (auto-scale-responsive.js)
 *   4. GC-Generierung     (generate-global-classes.js)
 *   5. Media-Patching     (patch-v4-tree-media-ids.js)
 *   6. Validierung        (validate-v4-tree.js)
 *   7. Quality-Metrics    (measure-quality-metrics.js)
 *
 * OUTPUT:
 *   pipeline-profile.json  — { phases[], total_ms, bottleneck[], generated }
 */

import fs from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { performance } from 'node:perf_hooks';
import { fileURLToPath } from 'node:url';

const execFileAsync = promisify(execFile);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const pipelineDir = path.resolve(__dirname, '..');

const { values: args } = parseArgs({
  options: {
    tree:       { type: 'string', default: 'v4-tree.json' },
    output:     { type: 'string', default: 'pipeline-profile.json' },
    timeout:    { type: 'string', default: '300000' },
    bottleneck: { type: 'boolean', default: false },
    help:       { type: 'boolean', default: false },
  },
  allowPositionals: true,
  strict: false,
});

// ── HELP ──────────────────────────────────────────────────────────
if (args.help) {
  console.log(`profile-pipeline.js — ENH-14 Pipeline Performance Profiler

USAGE:
  node scripts/profile-pipeline.js --tree v4-tree.json [--output profile.json]
  node scripts/profile-pipeline.js --tree v4-tree.json --bottleneck

OPTIONS:
  --tree <path>       Pfad zum v4-tree.json (default: v4-tree.json)
  --output <path>     Ausgabepfad fuer JSON-Report (default: pipeline-profile.json)
  --timeout <ms>      Timeout pro Phase in ms (default: 300000 = 5 min)
  --bottleneck        Zeige Top-3 langsamste Phasen im Summary

MEASURED PHASES:
  1. token-extraction     design-token-extractor.js
  2. conversion           convert-xml-to-v4.js
  3. auto-scale           auto-scale-responsive.js
  4. gc-generation        generate-global-classes.js
  5. media-patching       patch-v4-tree-media-ids.js
  6. validation           validate-v4-tree.js
  7. quality-metrics      measure-quality-metrics.js

EXIT CODES:
  0  Profiling abgeschlossen (einzelne Phasen duerfen fehlschlagen)
  2  Ungueltige Argumente / Tree-Datei nicht gefunden
`);
  process.exit(0);
}

const treePath = args.tree;
const resolvedTreePath = path.resolve(treePath);
if (!fs.existsSync(resolvedTreePath)) {
  console.error(`Error: Tree file not found: ${resolvedTreePath}`);
  console.error(`  Provide a valid v4-tree.json with --tree <path>`);
  process.exit(2);
}

const timeoutMs = parseInt(args.timeout, 10);
if (isNaN(timeoutMs) || timeoutMs < 1000) {
  console.error(`Error: Invalid timeout: ${args.timeout}. Must be >= 1000ms.`);
  process.exit(2);
}

// ── PHASE DEFINITIONS ─────────────────────────────────────────────
const phases = [
  {
    id: 'token-extraction',
    name: 'Token-Extraktion',
    script: 'design-token-extractor.js',
    args: [],
  },
  {
    id: 'conversion',
    name: 'Konvertierung',
    script: 'convert-xml-to-v4.js',
    args: [],
  },
  {
    id: 'auto-scale',
    name: 'Auto-Scaling',
    script: 'auto-scale-responsive.js',
    args: ['--tree', resolvedTreePath],
  },
  {
    id: 'gc-generation',
    name: 'GC-Generierung',
    script: 'generate-global-classes.js',
    args: ['--tree', resolvedTreePath],
  },
  {
    id: 'media-patching',
    name: 'Media-Patching',
    script: 'patch-v4-tree-media-ids.js',
    args: ['--tree', resolvedTreePath],
  },
  {
    id: 'validation',
    name: 'Validierung',
    script: 'validate-v4-tree.js',
    args: ['--tree', resolvedTreePath],
  },
  {
    id: 'quality-metrics',
    name: 'Quality-Metrics',
    script: 'measure-quality-metrics.js',
    args: [resolvedTreePath],
  },
];

// ── RUNNER ─────────────────────────────────────────────────────────

/**
 * Run a single pipeline phase and measure its wall-clock time.
 * @param {object} phase - Phase definition
 * @param {number} timeout - Timeout in ms
 * @returns {Promise<{name:string, duration_ms:number, status:string, error?:string}>}
 */
async function runPhase(phase, timeout) {
  const scriptPath = path.join(pipelineDir, 'scripts', phase.script);
  const nodeBin = process.execPath;

  const start = performance.now();
  try {
    await execFileAsync(nodeBin, [scriptPath, ...phase.args], {
      cwd: pipelineDir,
      timeout,
      maxBuffer: 50 * 1024 * 1024, // 50 MB
    });
    const end = performance.now();
    return {
      name: phase.name,
      duration_ms: Math.round(end - start),
      status: 'OK',
    };
  } catch (err) {
    const end = performance.now();
    const duration = Math.round(end - start);
    let error = '';
    if (err.killed) {
      error = 'TIMEOUT';
    } else if (err.code) {
      error = `Exit ${err.code}`;
    } else {
      error = (err.message || String(err)).slice(0, 200);
    }
    return {
      name: phase.name,
      duration_ms: duration,
      status: 'FAIL',
      error,
    };
  }
}

// ── MAIN ───────────────────────────────────────────────────────────

console.error(`[profile] Profiling ${phases.length} phases on ${resolvedTreePath}...`);
console.error(`[profile] Timeout per phase: ${timeoutMs}ms\n`);

const results = [];
const t0 = performance.now();

for (const phase of phases) {
  console.error(`[profile] Running ${phase.name} (${phase.script})...`);
  const result = await runPhase(phase, timeoutMs);
  results.push(result);
  const icon = result.status === 'OK' ? '\u2713' : '\u2717';
  console.error(`  ${icon} ${result.name}: ${result.duration_ms}ms ${result.status}${result.error ? ' - ' + result.error : ''}`);
}

const t1 = performance.now();
const totalMs = Math.round(t1 - t0);

// ── BOTTLENECK ANALYSIS ────────────────────────────────────────────
const succeeded = results.filter(r => r.status === 'OK');
const sorted = [...results].sort((a, b) => b.duration_ms - a.duration_ms);
const bottleneck = sorted.slice(0, 3).map(r => ({
  name: r.name,
  duration_ms: r.duration_ms,
  pct_of_total: totalMs > 0 ? Math.round((r.duration_ms / totalMs) * 100) : 0,
}));

const report = {
  generated: new Date().toISOString(),
  source: resolvedTreePath,
  phases: results,
  total_ms: totalMs,
  ok_count: succeeded.length,
  fail_count: results.length - succeeded.length,
  bottleneck,
};

// ── OUTPUT ─────────────────────────────────────────────────────────
const outputPath = path.resolve(args.output);
fs.writeFileSync(outputPath, JSON.stringify(report, null, 2), 'utf8');

console.error(`\n[profile] Report saved to ${args.output}`);
console.error(`[profile] Total: ${totalMs}ms | OK: ${succeeded.length}/${results.length}`);

if (args.bottleneck && bottleneck.length > 0) {
  console.error(`\n[profile] Bottleneck — Top 3 langsamste Phasen:`);
  for (const b of bottleneck) {
    console.error(`  ${b.name}: ${b.duration_ms}ms (${b.pct_of_total}% der Gesamtzeit)`);
  }
}

// JSON auf stdout fuer Piping
console.log(JSON.stringify(report, null, 2));
process.exit(0);
