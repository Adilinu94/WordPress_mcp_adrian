#!/usr/bin/env node
/**
 * extract-image-urls.js  —  Phase 4: Framer Asset URL Extraction
 *
 * Extrahiert alle Bild-/Video-/SVG-URLs aus Framer-Quellen (HTML, Element-Tree JSON,
 * MCP XML), entfernt Duplikate, erstellt image-manifest.json.
 *
 * Usage:
 *   node scripts/extract-image-urls.js \
 *     --html FramerExport/framer-passionate-papaya-042575/index.html \
 *     --element-tree FramerExport/element-tree/homepage-element-tree.json \
 *     --output FramerExport/assets/image-manifest.json \
 *     --format json
 */

import fs   from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';

// ─────────────────────────────────────────────
// CLI ARGS
// ─────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    html:            { type: 'string'  },
    'element-tree':  { type: 'string'  },
    'unframer-xml':  { type: 'string'  },
    output:          { type: 'string'  },
    format:          { type: 'string',  default: 'json'  },
    only:            { type: 'string',  default: 'all'   },
    'local-only':    { type: 'boolean', default: false   },
    verbose:         { type: 'boolean', default: false   },
  },
  strict: false,
});

// Help
if (process.argv.includes('--help') || process.argv.includes('-h')) { console.log('Usage: node scripts/extract-image-urls.js [--help for options]'); console.log('Run with --help for full usage.'); process.exit(0); }

const log = (...msg) => { if (args.verbose) process.stderr.write('[verbose] ' + msg.join(' ') + '\n'); };

if (!args.html && !args['element-tree'] && !args['unframer-xml']) {
  process.stderr.write('Error: At least one input required: --html, --element-tree, or --unframer-xml\n');
  process.exit(2);
}

// ─────────────────────────────────────────────
// URL CLASSIFICATION
// ─────────────────────────────────────────────

function classifyUrl(url) {
  const lower = url.toLowerCase().split('?')[0];
  if (/\.(mp4|webm|mov|ogg|avi)$/.test(lower))                   return 'video';
  if (/\.(svg)$/.test(lower) || lower.startsWith('data:image/svg')) return 'svg';
  if (/\.(png|jpg|jpeg|gif|webp|avif|bmp|ico|tiff?)$/.test(lower)) return 'image';
  if (lower.includes('framerusercontent.com/images/'))             return 'image';
  if (lower.includes('framerusercontent.com/assets/')) {
    return /\.(mp4|webm|mov)/.test(lower) ? 'video' : 'image';
  }
  return 'other';
}

function getExtension(url) {
  const base = url.split('?')[0];
  return path.extname(base).replace('.', '').toLowerCase() || 'unknown';
}

function getFilename(url) {
  const base = url.split('?')[0];
  return path.basename(base) || url.slice(-20);
}

// ─────────────────────────────────────────────
// HTML EXTRACTION
// ─────────────────────────────────────────────

function extractFromHtml(html) {
  const found = [];

  const push = (url, src) => {
    if (url && !url.startsWith('data:') && url.startsWith('http')) found.push({ url, source: src });
  };

  // <img src="...">
  const imgSrcRe = /<img[^>]+src=["']([^"']+)["']/gi;
  let m;
  while ((m = imgSrcRe.exec(html)) !== null) push(m[1], 'html:img[src]');

  // <img srcset="...">
  const srcsetRe = /<img[^>]+srcset=["']([^"']+)["']/gi;
  while ((m = srcsetRe.exec(html)) !== null) {
    for (const part of m[1].split(',')) {
      const u = part.trim().split(/\s+/)[0];
      push(u, 'html:img[srcset]');
    }
  }

  // background-image: url(...)  (inline styles + style blocks)
  const bgRe = /background(?:-image)?\s*:[^;]*url\(\s*["']?([^"')]+)["']?\s*\)/gi;
  while ((m = bgRe.exec(html)) !== null) push(m[1].trim(), 'html:background-image');

  // <video src="...">
  const videoRe = /<video[^>]+src=["']([^"']+)["']/gi;
  while ((m = videoRe.exec(html)) !== null) push(m[1], 'html:video[src]');

  // <source src="...">
  const sourceRe = /<source[^>]+src=["']([^"']+)["']/gi;
  while ((m = sourceRe.exec(html)) !== null) push(m[1], 'html:source[src]');

  // @font-face src: url(...)  →  skip (fonts, not images)
  // Filter those out
  return found;
}

// ─────────────────────────────────────────────
// ELEMENT-TREE EXTRACTION  (recursive)
// ─────────────────────────────────────────────

function extractFromTree(node, results, nodeId) {
  if (!node || typeof node !== 'object') return;

  // Direct image_src check
  const canonicalImageSrc = node?.settings?.['image-src']?.value?.url;
  const imageUrl =
    canonicalImageSrc?.value ||
    canonicalImageSrc ||
    node?.settings?.image_src?.url ||
    node?.settings?.['image-src']?.url ||
    node?.image_src?.url;

  if (imageUrl && typeof imageUrl === 'string' && imageUrl.startsWith('http')) {
    const id = node.id || node.name || nodeId || '?';
    results.push({ url: imageUrl, source: `element-tree:${id}` });
  }

  // Recurse into arrays and objects
  for (const val of Object.values(node)) {
    if (Array.isArray(val)) {
      val.forEach((item, i) => extractFromTree(item, results, `${nodeId}[${i}]`));
    } else if (val && typeof val === 'object') {
      extractFromTree(val, results, nodeId);
    }
  }
}

// ─────────────────────────────────────────────
// MCP XML EXTRACTION
// ─────────────────────────────────────────────

function extractFromXml(xml) {
  const found = [];
  const re = /src=["']?(https?:\/\/framerusercontent\.com\/[^"'\s>]+)/gi;
  let m;
  while ((m = re.exec(xml)) !== null) {
    found.push({ url: m[1], source: 'mcp-xml:src' });
  }
  return found;
}

// ─────────────────────────────────────────────
// LOAD INPUTS
// ─────────────────────────────────────────────

const rawUrls   = [];
const sourcesUsed = [];

if (args.html) {
  if (!fs.existsSync(args.html)) {
    process.stderr.write(`Error: HTML file not found: ${args.html}\n`);
    process.exit(2);
  }
  log('Reading HTML:', args.html);
  const html      = fs.readFileSync(args.html, 'utf8');
  const extracted = extractFromHtml(html);
  log(`  Found ${extracted.length} URL references in HTML`);
  rawUrls.push(...extracted);
  sourcesUsed.push(args.html);
}

if (args['element-tree']) {
  const tp = args['element-tree'];
  if (!fs.existsSync(tp)) {
    process.stderr.write(`Error: Element-tree file not found: ${tp}\n`);
    process.exit(2);
  }
  log('Reading element-tree:', tp);
  let tree;
  try {
    tree = JSON.parse(fs.readFileSync(tp, 'utf8'));
  } catch (e) {
    process.stderr.write(`Error: JSON parse failed in ${tp}: ${e.message}\n`);
    process.exit(2);
  }
  const treeUrls = [];
  extractFromTree(tree, treeUrls, 'root');
  log(`  Found ${treeUrls.length} URL references in element-tree`);
  rawUrls.push(...treeUrls);
  sourcesUsed.push(tp);
}

if (args['unframer-xml']) {
  const xp = args['unframer-xml'];
  if (!fs.existsSync(xp)) {
    process.stderr.write(`Error: MCP XML file not found: ${xp}\n`);
    process.exit(2);
  }
  log('Reading MCP XML:', xp);
  const xml    = fs.readFileSync(xp, 'utf8');
  const xmlUrls = extractFromXml(xml);
  log(`  Found ${xmlUrls.length} URL references in XML`);
  rawUrls.push(...xmlUrls);
  sourcesUsed.push(xp);
}

// ─────────────────────────────────────────────
// FILTER
// ─────────────────────────────────────────────

let filtered = rawUrls;

if (args['local-only']) {
  filtered = filtered.filter(({ url }) => url.includes('framerusercontent.com'));
}

if (args.only && args.only !== 'all') {
  filtered = filtered.filter(({ url }) => classifyUrl(url) === args.only);
}

// ─────────────────────────────────────────────
// DEDUPLICATE
// ─────────────────────────────────────────────

const seen     = new Map(); // lowercase url → entry
let totalUrls  = 0;
let dupCount   = 0;

for (const { url, source } of filtered) {
  totalUrls++;
  const key = url.toLowerCase();
  if (seen.has(key)) {
    const existing = seen.get(key);
    if (!existing.sources.includes(source)) existing.sources.push(source);
    dupCount++;
  } else {
    seen.set(key, { url, sources: [source] });
  }
}

// ─────────────────────────────────────────────
// BUILD ASSET LIST
// ─────────────────────────────────────────────

const assets = [...seen.values()].map(({ url, sources }) => ({
  url,
  type:      classifyUrl(url),
  extension: getExtension(url),
  filename:  getFilename(url),
  sources,
  width:     null,
  height:    null,
}));

// ─────────────────────────────────────────────
// GUARD: no results
// ─────────────────────────────────────────────

if (assets.length === 0) {
  process.stderr.write('⚠ Warning: No URLs found in the provided sources.\n');
  process.stderr.write('  (FramerExport embeds assets locally — this is normal for local exports.)\n');
  process.exit(0);
}

// ─────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────

const images = assets.filter(a => a.type === 'image').length;
const videos = assets.filter(a => a.type === 'video').length;
const svgs   = assets.filter(a => a.type === 'svg').length;
const others = assets.filter(a => a.type === 'other').length;

const manifest = {
  source:             sourcesUsed.join(', '),
  extracted_at:       new Date().toISOString(),
  total_urls:         totalUrls,
  unique_urls:        assets.length,
  duplicates_removed: dupCount,
  assets,
  summary: {
    images,
    videos,
    svg:                   svgs,
    other:                 others,
    total_size_estimate_mb: null,
  },
};

// ─────────────────────────────────────────────
// OUTPUT
// ─────────────────────────────────────────────

const output = JSON.stringify(manifest, null, 2);

if (args.output) {
  fs.mkdirSync(path.dirname(path.resolve(args.output)), { recursive: true });
  fs.writeFileSync(args.output, output, 'utf8');
  process.stderr.write(`Saved to ${args.output}\n`);
} else {
  process.stdout.write(output + '\n');
}

process.stderr.write(`✓ ${assets.length} unique URLs extracted (${images} images, ${videos} videos, ${svgs} SVGs, ${others} other)\n`);
if (dupCount > 0) process.stderr.write(`⚠ ${dupCount} duplicate references removed\n`);

process.exit(0);
