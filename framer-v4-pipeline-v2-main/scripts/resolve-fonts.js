#!/usr/bin/env node
/**
 * resolve-fonts.js  —  Phase 0.7: Font Resolution
 * Löst Framer Font-Prefixes auf, mappt sie auf lokale .woff2-Dateien,
 * generiert Google Fonts Fallback-URLs für fehlende Fonts.
 *
 * Usage:
 *   node scripts/resolve-fonts.js \
 *     --html   FramerExport/framer-passionate-papaya-042575/index.html \
 *     --fonts-dir FramerExport/framer-passionate-papaya-042575/assets/fonts/ \
 *     --mcp-json  FramerExport/tokens/mcp-colors.json \
 *     --output    FramerExport/tokens/font-resolution.json
 */

import fs   from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';
import {
  parseFramerPrefix, generateGoogleFontsUrl, expectedFontFilenames, WEIGHT_NAME_MAP,
} from './lib/framer-utils.js';

// ─────────────────────────────────────────────
// CLI
// ─────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    html:        { type: 'string'  },
    'fonts-dir': { type: 'string'  },
    'mcp-json':  { type: 'string'  },
    output:      { type: 'string'  },
    verbose:     { type: 'boolean', default: false },
  },
  strict: false,
});

// Help
if (process.argv.includes('--help') || process.argv.includes('-h')) { console.log('Usage: node scripts/resolve-fonts.js [--help for options]'); console.log('Run with --help for full usage.'); process.exit(0); }

const log = (...m) => { if (args.verbose) process.stderr.write('[verbose] ' + m.join(' ') + '\n'); };

if (!args.html && !args['mcp-json']) {
  process.stderr.write('Error: --html oder --mcp-json erforderlich\n');
  process.exit(2);
}

// ─────────────────────────────────────────────
// CSS PARSING — @font-face blocks
// ─────────────────────────────────────────────

function extractFontFaces(html) {
  const faces = [];
  // Strip <style> tags to get CSS content, or scan the full file
  const blockRe = /@font-face\s*\{([^}]+)\}/gi;
  let block;
  while ((block = blockRe.exec(html)) !== null) {
    const inner   = block[1];
    const familyM = inner.match(/font-family\s*:\s*['"]?([^;'"]+)['"]?/i);
    const weightM = inner.match(/font-weight\s*:\s*([^;]+)/i);
    const styleM  = inner.match(/font-style\s*:\s*([^;]+)/i);
    const srcM    = inner.match(/src\s*:[^;]*url\(['"]?([^'")\s]+)['"]?\)/i);
    if (!familyM) continue;
    const weight = (weightM ? weightM[1] : '400').trim();
    faces.push({
      family: familyM[1].trim(),
      weight: /^\d+$/.test(weight) ? weight : (WEIGHT_NAME_MAP[weight] ?? '400'),
      style:  (styleM ? styleM[1] : 'normal').trim(),
      srcUrl: srcM ? srcM[1].trim() : null,
    });
  }
  // Deduplicate by family+weight
  const seen = new Map();
  for (const f of faces) {
    const key = `${f.family}::${f.weight}`;
    if (!seen.has(key)) seen.set(key, f);
  }
  return [...seen.values()];
}

// ─────────────────────────────────────────────
// FONT FILE LOOKUP
// ─────────────────────────────────────────────

function scanFontsDir(dir) {
  if (!dir || !fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).filter(f => /\.(woff2?|ttf|otf)$/i.test(f));
}

function findLocalFile(family, weight, fontFiles) {
  const candidates = expectedFontFilenames(family, weight);
  for (const c of candidates) {
    const match = fontFiles.find(f => f.toLowerCase() === c.toLowerCase());
    if (match) return match;
  }
  return null;
}

function toFramerPrefix(family, weight) {
  const fc = family.replace(/\s+/g, '');
  const wn = WEIGHT_NAME_MAP[weight] || weight;
  return `FR;${fc}-${wn}`;
}

// ─────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────

const fontsDir  = args['fonts-dir'] || null;
const fontFiles = scanFontsDir(fontsDir);
log(`Font files in directory: ${fontFiles.length}`);

const entries = new Map(); // `Family::weight` → entry

// ── Source A: HTML @font-face declarations ──
if (args.html) {
  if (!fs.existsSync(args.html)) {
    process.stderr.write(`Error: HTML nicht gefunden: ${args.html}\n`); process.exit(2);
  }
  const html  = fs.readFileSync(args.html, 'utf8');
  const faces = extractFontFaces(html);
  log(`@font-face blocks: ${faces.length}`);
  for (const f of faces) {
    const key  = `${f.family}::${f.weight}`;
    const file = findLocalFile(f.family, f.weight, fontFiles);
    const gfUrl = generateGoogleFontsUrl(f.family, f.weight);
    entries.set(key, {
      family:       f.family,
      weight:       f.weight,
      style:        f.style,
      framerPrefix: toFramerPrefix(f.family, f.weight),
      localFile:    file ?? null,
      localPath:    file && fontsDir ? `./${path.relative(process.cwd(), path.join(fontsDir, file)).replace(/\\/g,'/')}` : null,
      status:       file ? 'RESOLVED' : 'MISSING',
      action:       file ? null : `Download from Google Fonts: ${gfUrl}`,
    });
  }
}

// ── Source B: MCP JSON fonts ──
if (args['mcp-json']) {
  if (!fs.existsSync(args['mcp-json'])) {
    process.stderr.write(`Error: MCP JSON nicht gefunden: ${args['mcp-json']}\n`); process.exit(2);
  }
  const mcp = JSON.parse(fs.readFileSync(args['mcp-json'], 'utf8'));
  for (const f of (mcp.fonts || [])) {
    const prefix = f.font || f.name || '';
    const parsed = parseFramerPrefix(prefix);
    const key    = `${parsed.family}::${parsed.weight}`;

    if (entries.has(key)) {
      // Override framerPrefix with the actual MCP value if more specific
      if (prefix) entries.get(key).framerPrefix = prefix;
      log(`MCP confirms existing font: ${key}`);
    } else {
      const file  = findLocalFile(parsed.family, parsed.weight, fontFiles);
      const gfUrl = generateGoogleFontsUrl(parsed.family, parsed.weight);
      entries.set(key, {
        family:       parsed.family,
        weight:       parsed.weight,
        style:        'normal',
        framerPrefix: prefix,
        localFile:    file ?? null,
        localPath:    file && fontsDir ? `./${path.relative(process.cwd(), path.join(fontsDir, file)).replace(/\\/g,'/')}` : null,
        status:       file ? 'RESOLVED' : 'MISSING',
        action:       file ? null : `Download from Google Fonts: ${gfUrl}`,
      });
      log(`MCP added font: ${parsed.family} ${parsed.weight}`);
    }
  }
}

if (entries.size === 0) {
  process.stderr.write('⚠ Warning: Keine Fonts gefunden. HTML könnte keine @font-face enthalten.\n');
  process.exit(0);
}

const fonts    = [...entries.values()];
const resolved = fonts.filter(f => f.status === 'RESOLVED');
const missing  = fonts.filter(f => f.status === 'MISSING');

const result = {
  meta: { totalFonts: fonts.length, resolved: resolved.length, missing: missing.length },
  fonts,
  summary: {
    resolvedCount: resolved.length,
    missingCount:  missing.length,
    missingFonts:  missing.map(f => ({
      family:         f.family,
      weight:         f.weight,
      googleFontsUrl: generateGoogleFontsUrl(f.family, f.weight),
    })),
  },
};

// ─────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────

const output = JSON.stringify(result, null, 2);
if (args.output) {
  fs.mkdirSync(path.dirname(path.resolve(args.output)), { recursive: true });
  fs.writeFileSync(args.output, output, 'utf8');
  process.stderr.write(`Saved to ${args.output}\n`);
} else {
  process.stdout.write(output + '\n');
}

process.stderr.write(`✓ ${resolved.length} fonts resolved, ${missing.length} missing\n`);
if (missing.length > 0) {
  for (const f of missing) process.stderr.write(`  ✗ ${f.family} ${f.weight} — ${f.action}\n`);
}

process.exit(0);
// NB: missing fonts produce stderr warnings but exit 0 — the font plan
//     includes Google Fonts URLs for download; this is not a pipeline error.
