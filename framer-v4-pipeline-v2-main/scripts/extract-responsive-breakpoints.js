#!/usr/bin/env node
/**
 * extract-responsive-breakpoints.js  —  Phase 0.8: Responsive Breakpoints (CSS Mode)
 * Extrahiert @media Queries + CSS Properties aus Framer CSS-Export.
 * Gibt V4 Variant-Format mit Delta-Logik aus (nur geänderte Properties).
 *
 * Usage:
 *   node scripts/extract-responsive-breakpoints.js \
 *     --css FramerExport/framer-passionate-papaya-042575/index.html \
 *     --output FramerExport/tokens/responsive-breakpoints.json
 *
 *   # Mehrere CSS-Dateien:
 *   node scripts/extract-responsive-breakpoints.js \
 *     --css styles/main.css --css styles/tokens.css \
 *     --output FramerExport/tokens/responsive-breakpoints.json
 */

import fs   from 'node:fs';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { wrapSize, wrapType } from './lib/framer-utils.js';

// ─────────────────────────────────────────────
// CLI
// ─────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    css:      { type: 'string',  multiple: true },
    'css-dir':{ type: 'string'  },
    output:   { type: 'string'  },
    verbose:  { type: 'boolean', default: false },
  },
  strict: false,
});

// Help
if (process.argv.includes('--help') || process.argv.includes('-h')) { console.log('Usage: node scripts/extract-responsive-breakpoints.js [--help for options]'); console.log('Run with --help for full usage.'); process.exit(0); }

const log = (...m) => { if (args.verbose) process.stderr.write('[verbose] ' + m.join(' ') + '\n'); };

if (!args.css?.length && !args['css-dir']) {
  process.stderr.write('Error: --css <datei> oder --css-dir <ordner> erforderlich\n'); process.exit(2);
}

// ─────────────────────────────────────────────
// LOAD CSS CONTENT
// ─────────────────────────────────────────────

function loadCssSources() {
  const sources = [];

  // Files passed via --css
  for (const f of (args.css || [])) {
    if (!fs.existsSync(f)) { process.stderr.write(`Warning: Datei nicht gefunden: ${f}\n`); continue; }
    const content = fs.readFileSync(f, 'utf8');
    // If HTML file: extract <style> blocks
    if (f.endsWith('.html') || f.endsWith('.htm')) {
      const styleRe = /<style[^>]*>([\s\S]*?)<\/style>/gi;
      let m;
      while ((m = styleRe.exec(content)) !== null) sources.push(m[1]);
      log(`Extracted CSS from HTML: ${f}`);
    } else {
      sources.push(content);
      log(`Loaded CSS file: ${f}`);
    }
  }

  // Directory scan
  if (args['css-dir']) {
    const dir = args['css-dir'];
    if (!fs.existsSync(dir)) { process.stderr.write(`Error: css-dir nicht gefunden: ${dir}\n`); process.exit(2); }
    const files = fs.readdirSync(dir).filter(f => /\.css$/i.test(f));
    for (const f of files) {
      sources.push(fs.readFileSync(path.join(dir, f), 'utf8'));
      log(`Loaded from css-dir: ${f}`);
    }
  }

  return sources.join('\n');
}

// ─────────────────────────────────────────────
// MEDIA QUERY PARSING
// ─────────────────────────────────────────────

function parseMediaQuery(query) {
  // "(min-width: 810px) and (max-width: 1199px)" → { min: 810, max: 1199 }
  const minM = query.match(/min-width\s*:\s*([\d.]+)px/);
  const maxM = query.match(/max-width\s*:\s*([\d.]+)px/);
  return {
    min: minM ? parseFloat(minM[1]) : null,
    max: maxM ? parseFloat(maxM[1]) : null,
  };
}

function classifyBreakpoint(min, max) {
  if (max !== null && max <= 809) return 'mobile';
  if (min !== null && min >= 810 && (max === null || max <= 1199)) return 'tablet';
  if (min !== null && min >= 1200) return 'desktop';
  // Ambiguous – try to classify by dominant boundary
  if (max !== null && max <= 1199) return 'tablet';
  return 'unknown';
}

// ─────────────────────────────────────────────
// CSS BLOCK EXTRACTION
// ─────────────────────────────────────────────

function extractCssDeclarations(ruleBody) {
  const decls = {};
  const re = /([\w-]+)\s*:\s*([^;!\n}]+)/g;
  let m;
  while ((m = re.exec(ruleBody)) !== null) {
    const prop = m[1].trim();
    const val  = m[2].trim();
    if (prop && val && !prop.startsWith('//')) decls[prop] = val;
  }
  return decls;
}

/**
 * Extract all rules from CSS grouped by @media context.
 * Returns: [{ breakpoint: null|"tablet"|"mobile", selector, decls }]
 */
function extractAllRules(css) {
  const rules = [];

  // Remove font-faces to avoid false positives
  const cleanCss = css.replace(/@font-face\s*\{[^}]+\}/gi, '');

  // ── @media blocks ──
  // Match outer @media { ... } - handles nested braces manually
  let i = 0;
  while (i < cleanCss.length) {
    const mediaIdx = cleanCss.indexOf('@media', i);
    if (mediaIdx === -1) { i = cleanCss.length; break; }

    // Find the opening brace of the media block
    const braceOpen = cleanCss.indexOf('{', mediaIdx);
    if (braceOpen === -1) break;

    const query = cleanCss.slice(mediaIdx + 6, braceOpen).trim();
    const bpInfo = parseMediaQuery(query);
    const bp     = classifyBreakpoint(bpInfo.min, bpInfo.max);

    // Find matching closing brace (handle nested)
    let depth = 1;
    let j     = braceOpen + 1;
    while (j < cleanCss.length && depth > 0) {
      if (cleanCss[j] === '{') depth++;
      else if (cleanCss[j] === '}') depth--;
      j++;
    }

    const mediaContent = cleanCss.slice(braceOpen + 1, j - 1);

    // Extract rules inside media block
    const ruleRe = /([^{}]+)\{([^{}]+)\}/g;
    let rm;
    while ((rm = ruleRe.exec(mediaContent)) !== null) {
      const sel   = rm[1].trim();
      const decls = extractCssDeclarations(rm[2]);
      if (sel && Object.keys(decls).length > 0) {
        rules.push({ breakpoint: bp, query, min: bpInfo.min, max: bpInfo.max, selector: sel, decls });
      }
    }

    i = j;
  }

  // ── Base rules (outside @media) ──
  // Strip @media blocks first
  let baseCSS = cleanCss;
  const mediaRe = /@media[^{]*\{/g;
  let mM;
  const toRemove = [];
  while ((mM = mediaRe.exec(cleanCss)) !== null) {
    let depth = 1;
    let k     = mM.index + mM[0].length;
    while (k < cleanCss.length && depth > 0) {
      if (cleanCss[k] === '{') depth++;
      else if (cleanCss[k] === '}') depth--;
      k++;
    }
    toRemove.push([mM.index, k]);
  }
  // Apply removals in reverse to preserve indices
  let baseParts = cleanCss;
  for (const [start, end] of [...toRemove].reverse()) {
    baseParts = baseParts.slice(0, start) + baseParts.slice(end);
  }

  const baseRuleRe = /([^{}@]+)\{([^{}]+)\}/g;
  let br;
  while ((br = baseRuleRe.exec(baseParts)) !== null) {
    const sel   = br[1].trim();
    const decls = extractCssDeclarations(br[2]);
    if (sel && !sel.startsWith('@') && Object.keys(decls).length > 0) {
      rules.push({ breakpoint: null, selector: sel, decls });
    }
  }

  return rules;
}

// ─────────────────────────────────────────────
// WRAP PROPERTY VALUE  →  V4 typed AST
// ─────────────────────────────────────────────

const SIZE_PROPS = new Set([
  'font-size', 'line-height', 'letter-spacing', 'width', 'height',
  'max-width', 'min-width', 'padding', 'margin', 'gap', 'border-radius',
  'top', 'right', 'bottom', 'left',
]);

function wrapCssProp(prop, val) {
  if (SIZE_PROPS.has(prop) && /^-?[\d.]+(?:px|%|em|rem|vw|vh)$/.test(val.trim())) {
    return wrapSize(val.trim());
  }
  return wrapType('string', val);
}

// ─────────────────────────────────────────────
// BUILD VARIANT NODES
// ─────────────────────────────────────────────

function calculateDeltas(base, override) {
  const delta = {};
  for (const [prop, val] of Object.entries(override)) {
    if (base[prop] !== val) delta[prop] = val; // only changed props
  }
  return delta;
}

function buildNodes(rules) {
  // Group by selector
  const selectorMap = new Map();

  for (const rule of rules) {
    if (!selectorMap.has(rule.selector)) {
      selectorMap.set(rule.selector, { base: {}, tablet: {}, mobile: {}, desktop: {} });
    }
    const entry = selectorMap.get(rule.selector);
    const bp    = rule.breakpoint || 'base';
    if (bp === 'base')    Object.assign(entry.base, rule.decls);
    else if (bp === 'tablet')  Object.assign(entry.tablet, rule.decls);
    else if (bp === 'mobile')  Object.assign(entry.mobile, rule.decls);
    else if (bp === 'desktop') Object.assign(entry.desktop, rule.decls);
  }

  const nodes = [];

  for (const [selector, data] of selectorMap.entries()) {
    const hasTablet  = Object.keys(data.tablet).length > 0;
    const hasMobile  = Object.keys(data.mobile).length > 0;
    const hasDesktop = Object.keys(data.desktop).length > 0;
    const hasResponsive = hasTablet || hasMobile || hasDesktop;

    // Derive a human name from the selector
    const name = selector
      .replace(/\./g, '').replace(/#/g,'').replace(/\[.*?\]/g,'')
      .trim().replace(/^framer-styles-preset-/, 'Preset ') || selector;

    const variants = [];

    // Base variant (desktop, no breakpoint)
    const baseProps = {};
    const effectiveBase = { ...data.base, ...data.desktop }; // desktop rules override base
    for (const [prop, val] of Object.entries(effectiveBase)) {
      baseProps[prop] = wrapCssProp(prop, val);
    }
    if (Object.keys(baseProps).length > 0) {
      variants.push({ meta: { breakpoint: null, state: null }, props: baseProps });
    }

    // Tablet delta
    if (hasTablet) {
      const delta = calculateDeltas(effectiveBase, data.tablet);
      const tabletProps = {};
      for (const [prop, val] of Object.entries(delta)) tabletProps[prop] = wrapCssProp(prop, val);
      if (Object.keys(tabletProps).length > 0) {
        variants.push({ meta: { breakpoint: 'tablet', state: null }, props: tabletProps });
      }
    }

    // Mobile delta
    if (hasMobile) {
      const delta = calculateDeltas(effectiveBase, data.mobile);
      const mobileProps = {};
      for (const [prop, val] of Object.entries(delta)) mobileProps[prop] = wrapCssProp(prop, val);
      if (Object.keys(mobileProps).length > 0) {
        variants.push({ meta: { breakpoint: 'mobile', state: null }, props: mobileProps });
      }
    }

    if (variants.length > 0) {
      nodes.push({ name, selector, hasResponsive, variants });
    }
  }

  return nodes;
}

// ─────────────────────────────────────────────
// DETECT BREAKPOINT BOUNDARIES FROM CSS
// ─────────────────────────────────────────────

function detectBreakpoints(rules) {
  const tablet  = new Set();
  const mobile  = new Set();
  const desktop = new Set();
  for (const r of rules) {
    if (r.breakpoint === 'tablet')  { if (r.min) tablet.add(r.min); if (r.max) tablet.add(r.max); }
    if (r.breakpoint === 'mobile')  { if (r.max) mobile.add(r.max); }
    if (r.breakpoint === 'desktop') { if (r.min) desktop.add(r.min); }
  }
  const tabletMin  = tablet.size  ? Math.min(...tablet)  : 810;
  const mobileMax  = mobile.size  ? Math.max(...mobile)  : 809;
  const desktopMin = desktop.size ? Math.min(...desktop) : 1200;
  return {
    desktop: { min: desktopMin, max: null },
    tablet:  { min: tabletMin,  max: desktopMin - 1 },
    mobile:  { min: 0,          max: mobileMax },
  };
}

// ─────────────────────────────────────────────
// MAIN
// ─────────────────────────────────────────────

const css   = loadCssSources();
const rules = extractAllRules(css);

log(`Total CSS rule blocks parsed: ${rules.length}`);
log(`  base: ${rules.filter(r=>!r.breakpoint).length}`);
log(`  tablet: ${rules.filter(r=>r.breakpoint==='tablet').length}`);
log(`  mobile: ${rules.filter(r=>r.breakpoint==='mobile').length}`);

if (rules.length === 0) {
  process.stderr.write('⚠ Warning: Keine CSS Rules gefunden. Prüfe die Input-Dateien.\n');
  process.exit(0);
}

const breakpoints  = detectBreakpoints(rules);
const nodes        = buildNodes(rules);
const unknownCount = rules.filter(r => r.breakpoint === 'unknown').length;

const result = {
  meta: {
    source:      (args.css || []).join(', ') + (args['css-dir'] || ''),
    extractedAt: new Date().toISOString(),
    breakpoints,
    stats: {
      totalRules: rules.length,
      totalNodes: nodes.length,
      withResponsive: nodes.filter(n => n.hasResponsive).length,
      unknownMediaQueries: unknownCount,
    },
  },
  nodes,
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

process.stderr.write(
  `✓ ${nodes.length} selectors (${nodes.filter(n=>n.hasResponsive).length} responsive)\n`
);

process.exit(0);
// NB: unknown media queries produce stderr warnings but exit 0 —
//     exotic breakpoint formats are not a pipeline error.
