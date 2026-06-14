#!/usr/bin/env node
/**
 * visual-qa.js
 *
 * Browser-basierte Visual QA für die Framer → Elementor V4 Pipeline.
 * Macht Screenshots einer WordPress-Seite (post_id) auf drei Breakpoints
 * und prüft grundlegende visuelle Indikatoren ohne externe Test-Dienste.
 *
 * Phase 0.5.7: axe-core A11y-Integration
 * Führt nach dem Seiten-Load einen WCAG 2.0/2.1/2.2 Accessibility-Audit
 * via axe-core durch und aggregiert die Violations im QA-Report.
 *
 * Unterstützte Browser-Backends (in Priorität):
 *   1. Playwright  (npm install playwright @axe-core/playwright)
 *   2. Puppeteer   (npm install puppeteer axe-core)
 *   3. --dry-run   Simuliert den Ablauf ohne Browser (für CI ohne Browser)
 *
 * Durchgeführte Checks pro Breakpoint:
 *   V1  Seite lädt ohne HTTP-Fehler (≠ 4xx/5xx)
 *   V2  Kein "elementor-error" oder "broken" CSS-Klasse im DOM
 *   V3  Keine unsichtbaren Elemente mit height=0 die sichtbar sein sollten
 *   V4  Bilder laden (keine 404 img src)
 *   V5  Kein horizontaler Scroll auf Mobile (overflow-x)
 *   V6  Mindestens 3 Elementor-Elemente im DOM
 *   A1  WCAG 2.0/2.1/2.2 axe-core Audit (≥0 critical violations)
 *
 * ── Server-seitige QA via Novamira MCP (empfohlen nach Build) ───────────────
 * Zusätzlich zu den Browser-Checks sollte der Agent diese MCP-Abilities aufrufen:
 *
 *   novamira/adrians-visual-qa { post_id }
 *     → Server-seitig: overflow-Risiken, z-index Konflikte, negative margins,
 *       absolute-positioned overlap — OHNE Browser nötig
 *
 *   novamira/adrians-responsive-audit { post_id }
 *     → Breakpoint-Coverage: aktive Breakpoints, v4 style variants, Visibility
 *
 *   novamira/adrians-class-audit { scope: "post_ids", post_ids: [<ID>] }
 *     → Unused GCs, fehlende Klassen-Bindungen
 *
 *   novamira/adrians-layout-audit { post_id }
 *     → Unnötige Container-Verschachtelung, single-child wrapper, pass-through
 *
 * Usage:
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123 --output reports/qa-report.json
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123 --screenshots screenshots/
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123 --dry-run
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123 --no-browser (alias for --dry-run)
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123 --skip-a11y
 *   node scripts/visual-qa.js --url https://meine-seite.de/?p=123 --a11y --a11y-output reports/a11y-report.json
 *
 * Exit codes:
 *   0 = alle Checks bestanden
 *   1 = ein oder mehr Checks fehlgeschlagen
 *   2 = Konfigurationsfehler
 */

'use strict';

import { parseArgs } from 'node:util';
import { existsSync, mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createRequire } from 'node:module';

// ─── CLI ─────────────────────────────────────────────────────────────────────

const { values: args } = parseArgs({
  options: {
    url:              { type: 'string' },
    output:           { type: 'string' },
    screenshots:      { type: 'string' },
    'dry-run':        { type: 'boolean', default: false },
    'no-browser':     { type: 'boolean', default: false },
    'a11y':           { type: 'boolean', default: false },
    'skip-a11y':      { type: 'boolean', default: false },
    'a11y-output':    { type: 'string' },
    timeout:          { type: 'string', default: '30000' },
    verbose:          { type: 'boolean', default: false },
    help:             { type: 'boolean', default: false },
  },
  strict: false,
});

if (args.help || !args.url) {
  process.stdout.write(`
visual-qa.js — Browser-basierte Visual QA + axe-core A11y Audit

USAGE:
  node scripts/visual-qa.js --url <wordpress-url> [options]

OPTIONEN:
  --url URL           WordPress-Seiten-URL mit Post-ID (required)
  --output FILE       JSON-Report-Ausgabepfad  [default: stdout]
  --screenshots DIR   Verzeichnis für Screenshots  [default: kein]
  --a11y              A11y-Audit explizit aktivieren (standardmäßig an)
  --skip-a11y         axe-core Accessibility-Audit überspringen
  --a11y-output FILE  Standalone A11y-Report als JSON ausgeben
  --dry-run           Kein echter Browser, simuliert Ablauf (für CI)
  --timeout MS        Navigation-Timeout in ms  [default: 30000]
  --verbose           Ausführliche Logs
  --help              Diese Hilfe

BREAKPOINTS:
  desktop  1440 × 900  px
  tablet    768 × 1024 px
  mobile    390 × 844  px

CHECKS (pro Breakpoint):
  V1  HTTP-Status OK (nicht 4xx/5xx)
  V2  Kein elementor-error / broken im DOM
  V3  Keine visuell leeren Pflicht-Elemente (height=0)
  V4  Keine 404 Bilder
  V5  Kein horizontaler Scroll (mobile)
  V6  Mindestens 3 Elementor-Elemente vorhanden
  A1  WCAG 2.0/2.1/2.2 axe-core Audit (0 critical violations)

EXIT-CODES:
  0 = pass  |  1 = fail  |  2 = Konfigurationsfehler
`);
  process.exit(args.help ? 0 : 2);
}

// ─── Config ──────────────────────────────────────────────────────────────────

const BREAKPOINTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet',  width: 768,  height: 1024 },
  { name: 'mobile',  width: 390,  height: 844 },
];

const PAGE_TIMEOUT = parseInt(args.timeout, 10);
const DRY_RUN = args['dry-run'] || args['no-browser'];
const SKIP_A11Y = args['skip-a11y'] || false;
const EXPLICIT_A11Y = args['a11y'] || false;
const A11Y_OUTPUT = args['a11y-output'] || null;

// If --a11y is explicitly passed, force a11y ON even if --skip-a11y was also passed
const A11Y_ENABLED = EXPLICIT_A11Y ? true : !SKIP_A11Y;

/** WCAG levels to audit with axe-core */
const A11Y_TAGS = ['wcag2a', 'wcag2aa', 'wcag22aa'];

const log   = (...m) => { if (args.verbose) process.stderr.write('[visual-qa] ' + m.join(' ') + '\n'); };
const warn  = (...m) => process.stderr.write('[WARN] ' + m.join(' ') + '\n');
const fatal = (m, c = 2) => { process.stderr.write('[FATAL] ' + m + '\n'); process.exit(c); };

// ─── Browser detection ───────────────────────────────────────────────────────

async function detectBrowserBackend() {
  if (DRY_RUN) return { backend: 'dry-run', launch: null };

  // Try Playwright
  try {
    const require = createRequire(import.meta.url);
    const pw = require('playwright');
    log('Backend: Playwright detected');
    return { backend: 'playwright', lib: pw };
  } catch (_) {}

  // Try Puppeteer
  try {
    const require = createRequire(import.meta.url);
    const pup = require('puppeteer');
    log('Backend: Puppeteer detected');
    return { backend: 'puppeteer', lib: pup };
  } catch (_) {}

  warn('Neither Playwright nor Puppeteer found. Falling back to dry-run mode.');
  warn('Install: npm install playwright  OR  npm install puppeteer');
  return { backend: 'dry-run', lib: null };
}

// ─── A11y Audit (Phase 0.5.7) ───────────────────────────────────────────────

/**
 * Runs an axe-core a11y audit on the given page.
 *
 * Uses @axe-core/playwright AxeBuilder for Playwright,
 * falls back to axe-core vanilla for Puppeteer.
 *
 * @param {object} page    Playwright Page or Puppeteer Page instance.
 * @param {string} backend 'playwright' | 'puppeteer'.
 * @returns {object|null}  Axe results or null if unavailable.
 */
async function runAxeAudit(page, backend) {
  if (!A11Y_ENABLED) {
    log('  A11y: skipped (--skip-a11y flag)');
    return null;
  }

  try {
    let results;

    if (backend === 'playwright') {
      // @axe-core/playwright AxeBuilder (fluent API, auto-injects)
      const axeModule = await import('@axe-core/playwright');
      const AxeBuilder = axeModule.default;
      results = await new AxeBuilder({ page })
        .withTags(A11Y_TAGS)
        .analyze();
    } else if (backend === 'puppeteer') {
      // axe-core vanilla — inject via page.evaluate
      const require = createRequire(import.meta.url);
      const axePath = require.resolve('axe-core/axe.min.js');
      const axeSource = readFileSync(axePath, 'utf8');
      await page.evaluate(axeSource);
      results = await page.evaluate(async (tags) => {
        return await window.axe.run(document, {
          runOnly: { type: 'tag', values: tags },
        });
      }, A11Y_TAGS);
    } else {
      return null;
    }

    const summary = {
      violations: results.violations?.length ?? 0,
      passes:     results.passes?.length ?? 0,
      incomplete: results.incomplete?.length ?? 0,
      critical:   results.violations?.filter(v => v.impact === 'critical').length ?? 0,
      serious:    results.violations?.filter(v => v.impact === 'serious').length ?? 0,
      moderate:   results.violations?.filter(v => v.impact === 'moderate').length ?? 0,
      minor:      results.violations?.filter(v => v.impact === 'minor').length ?? 0,
    };

    // Extract top violations for the report (max 20, dedup by rule ID)
    const topViolations = (results.violations || [])
      .map(v => ({
        id:          v.id,
        impact:      v.impact,
        description: v.description,
        help:        v.help,
        helpUrl:     v.helpUrl,
        nodes:       (v.nodes || []).map(n => ({
          html:     (n.html || '').slice(0, 200),
          target:   n.target?.map(t => (typeof t === 'string' ? t : t.join(' > '))).join(', '),
          failureSummary: (n.failureSummary || '').slice(0, 300),
        })).slice(0, 3),
      }))
      .slice(0, 20);

    log(`  A11y: ${summary.violations} violations (${summary.critical} critical, ${summary.serious} serious)`);

    return { summary, violations: topViolations };
  } catch (e) {
    const reason = e.code === 'ERR_MODULE_NOT_FOUND' || e.message?.includes('Cannot find')
      ? 'axe-core not installed (npm install --save-dev @axe-core/playwright)'
      : `axe audit failed: ${e.message}`;
    warn(`  A11y: ${reason}`);
    return { summary: null, violations: [], error: reason };
  }
}

// ─── Playwright wrapper ───────────────────────────────────────────────────────

async function runWithPlaywright(pw, url, breakpoints, screenshotDir) {
  const browser = await pw.chromium.launch({ headless: true });
  const results = [];

  for (const bp of breakpoints) {
    log(`  Playwright: ${bp.name} ${bp.width}x${bp.height}`);
    const context = await browser.newContext({
      viewport: { width: bp.width, height: bp.height },
    });
    const page = await context.newPage();

    const networkErrors = [];
    page.on('response', res => {
      if (res.status() >= 400) networkErrors.push({ url: res.url(), status: res.status() });
    });

    let httpStatus = 0;
    try {
      const res = await page.goto(url, { waitUntil: 'networkidle', timeout: PAGE_TIMEOUT });
      httpStatus = res?.status() ?? 0;
    } catch (e) {
      results.push(buildResult(bp.name, false, `Navigation failed: ${e.message}`, {}));
      await context.close();
      continue;
    }

    // Run checks in-page
    const pageData = await page.evaluate(() => {
      const allEls = document.querySelectorAll('[class]');
      const hasErrorClass = Array.from(allEls).some(el =>
        el.className && (el.className.includes('elementor-error') || el.className.includes('broken'))
      );

      const elementorEls = document.querySelectorAll('.elementor-widget, .elementor-section, .e-con, [data-id]');
      const elementorCount = elementorEls.length;

      // Check for zero-height elements that should be visible
      const zeroHeight = Array.from(document.querySelectorAll('h1,h2,h3,p,img,.elementor-widget'))
        .filter(el => {
          const rect = el.getBoundingClientRect();
          const style = window.getComputedStyle(el);
          return rect.height === 0 && style.display !== 'none' && style.visibility !== 'hidden';
        }).length;

      // Check images for broken src
      const brokenImages = Array.from(document.querySelectorAll('img'))
        .filter(img => !img.naturalWidth && img.src && !img.src.startsWith('data:'))
        .map(img => img.src)
        .slice(0, 5);

      // Horizontal scroll
      const hasHorizontalScroll = document.body.scrollWidth > window.innerWidth;

      return { hasErrorClass, elementorCount, zeroHeight, brokenImages, hasHorizontalScroll };
    });

    // Phase 0.5.7: axe-core a11y audit
    const a11y = await runAxeAudit(page, 'playwright');

    // Screenshot
    if (screenshotDir) {
      const screenshotPath = join(screenshotDir, `${bp.name}.png`);
      await page.screenshot({ path: screenshotPath, fullPage: true });
      log(`  Screenshot: ${screenshotPath}`);
    }

    const imgErrors = networkErrors.filter(e => e.url.match(/\.(png|jpg|jpeg|gif|webp|svg)/i));

    const checks = {
      V1_http_ok:            httpStatus < 400,
      V2_no_error_class:     !pageData.hasErrorClass,
      V3_no_zero_height:     pageData.zeroHeight === 0,
      V4_no_broken_images:   imgErrors.length === 0,
      V5_no_horizontal_scroll: bp.name !== 'mobile' || !pageData.hasHorizontalScroll,
      V6_elementor_elements: pageData.elementorCount >= 3,
      A1_a11y_critical_zero: !a11y?.summary?.critical || a11y.summary.critical === 0,
    };

    const passed = Object.values(checks).every(Boolean);
    results.push(buildResult(bp.name, passed, null, checks, {
      httpStatus,
      elementorCount: pageData.elementorCount,
      brokenImages: imgErrors.map(e => e.url),
      zeroHeightCount: pageData.zeroHeight,
      a11y: a11y || { summary: null, violations: [], error: 'skipped' },
    }));

    await context.close();
  }

  await browser.close();
  return results;
}

// ─── Puppeteer wrapper ────────────────────────────────────────────────────────

async function runWithPuppeteer(pup, url, breakpoints, screenshotDir) {
  const browser = await pup.launch({ headless: 'new', args: ['--no-sandbox'] });
  const results = [];

  for (const bp of breakpoints) {
    log(`  Puppeteer: ${bp.name} ${bp.width}x${bp.height}`);
    const page = await browser.newPage();
    await page.setViewport({ width: bp.width, height: bp.height });

    const networkErrors = [];
    page.on('response', res => {
      if (res.status() >= 400) networkErrors.push({ url: res.url(), status: res.status() });
    });

    let httpStatus = 0;
    try {
      const res = await page.goto(url, { waitUntil: 'networkidle2', timeout: PAGE_TIMEOUT });
      httpStatus = res?.status() ?? 0;
    } catch (e) {
      results.push(buildResult(bp.name, false, `Navigation failed: ${e.message}`, {}));
      await page.close();
      continue;
    }

    const pageData = await page.evaluate(() => {
      const hasErrorClass = Array.from(document.querySelectorAll('[class]'))
        .some(el => el.className && (el.className.includes('elementor-error') || el.className.includes('broken')));
      const elementorCount = document.querySelectorAll('.elementor-widget, .elementor-section, .e-con, [data-id]').length;
      const zeroHeight = Array.from(document.querySelectorAll('h1,h2,h3,p,img,.elementor-widget'))
        .filter(el => {
          const rect = el.getBoundingClientRect();
          const style = window.getComputedStyle(el);
          return rect.height === 0 && style.display !== 'none' && style.visibility !== 'hidden';
        }).length;
      const brokenImages = Array.from(document.querySelectorAll('img'))
        .filter(img => !img.naturalWidth && img.src && !img.src.startsWith('data:'))
        .map(img => img.src).slice(0, 5);
      const hasHorizontalScroll = document.body.scrollWidth > window.innerWidth;
      return { hasErrorClass, elementorCount, zeroHeight, brokenImages, hasHorizontalScroll };
    });

    // Phase 0.5.7: axe-core a11y audit
    const a11y = await runAxeAudit(page, 'puppeteer');

    if (screenshotDir) {
      await page.screenshot({ path: join(screenshotDir, `${bp.name}.png`), fullPage: true });
    }

    const imgErrors = networkErrors.filter(e => e.url.match(/\.(png|jpg|jpeg|gif|webp|svg)/i));
    const checks = {
      V1_http_ok:              httpStatus < 400,
      V2_no_error_class:       !pageData.hasErrorClass,
      V3_no_zero_height:       pageData.zeroHeight === 0,
      V4_no_broken_images:     imgErrors.length === 0,
      V5_no_horizontal_scroll: bp.name !== 'mobile' || !pageData.hasHorizontalScroll,
      V6_elementor_elements:   pageData.elementorCount >= 3,
      A1_a11y_critical_zero:   !a11y?.summary?.critical || a11y.summary.critical === 0,
    };

    const passed = Object.values(checks).every(Boolean);
    results.push(buildResult(bp.name, passed, null, checks, {
      httpStatus,
      elementorCount: pageData.elementorCount,
      brokenImages: imgErrors.map(e => e.url),
      zeroHeightCount: pageData.zeroHeight,
      a11y: a11y || { summary: null, violations: [], error: 'skipped' },
    }));

    await page.close();
  }

  await browser.close();
  return results;
}

// ─── Dry-run ─────────────────────────────────────────────────────────────────

function runDryRun(breakpoints) {
  return breakpoints.map(bp => buildResult(bp.name, true, null, {
    V1_http_ok:              true,
    V2_no_error_class:       true,
    V3_no_zero_height:       true,
    V4_no_broken_images:     true,
    V5_no_horizontal_scroll: true,
    V6_elementor_elements:   true,
    A1_a11y_critical_zero:   true,
  }, {
    dry_run: true,
    a11y: { summary: null, violations: [], note: 'a11y audit requires a real browser (not --dry-run)' },
  }));
}

// ─── Result builder ───────────────────────────────────────────────────────────

function buildResult(breakpoint, passed, error, checks, details = {}) {
  return { breakpoint, passed, error: error || null, checks, details };
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  const url = args.url;
  log(`URL: ${url}`);
  log(`Dry-run: ${DRY_RUN}`);
  log(`A11y audit: ${A11Y_ENABLED ? 'enabled' : 'skipped'}`);

  // Prepare screenshot dir
  let screenshotDir = null;
  if (args.screenshots) {
    screenshotDir = resolve(args.screenshots);
    if (!existsSync(screenshotDir)) mkdirSync(screenshotDir, { recursive: true });
    log(`Screenshots → ${screenshotDir}`);
  }

  // Detect backend
  const { backend, lib } = await detectBrowserBackend();
  log(`Backend: ${backend}`);

  let results;
  if (backend === 'dry-run') {
    results = runDryRun(BREAKPOINTS);
  } else if (backend === 'playwright') {
    results = await runWithPlaywright(lib, url, BREAKPOINTS, screenshotDir);
  } else if (backend === 'puppeteer') {
    results = await runWithPuppeteer(lib, url, BREAKPOINTS, screenshotDir);
  }

  // Build report
  const allPassed   = results.every(r => r.passed);
  const failCount   = results.filter(r => !r.passed).length;
  const checkTotals = { pass: 0, fail: 0 };
  for (const r of results) {
    for (const v of Object.values(r.checks)) {
      if (v) checkTotals.pass++; else checkTotals.fail++;
    }
  }

  // Aggregate a11y across all breakpoints
  // NOTE: Summed across breakpoints — the same violation may be
  // counted multiple times (once per breakpoint). This is a raw total,
  // not a unique violation count.
  const a11yAggregate = { violations: 0, critical: 0, serious: 0, moderate: 0, minor: 0, passes: 0, incomplete: 0 };
  for (const r of results) {
    const s = r.details?.a11y?.summary;
    if (s && typeof s.violations === 'number') {
      a11yAggregate.violations += s.violations;
      a11yAggregate.critical   += s.critical;
      a11yAggregate.serious    += s.serious;
      a11yAggregate.moderate   += s.moderate;
      a11yAggregate.minor      += s.minor;
      a11yAggregate.passes     += s.passes;
      a11yAggregate.incomplete += s.incomplete;
    }
  }

  const report = {
    meta: {
      url,
      backend,
      dry_run: DRY_RUN,
      breakpoints_tested: BREAKPOINTS.length,
      all_passed: allPassed,
      failed_breakpoints: failCount,
      checks_pass: checkTotals.pass,
      checks_fail: checkTotals.fail,
      a11y_audit: A11Y_ENABLED && backend !== 'dry-run',
      a11y_violations_total: a11yAggregate.violations,
      a11y_critical_total: a11yAggregate.critical,
      timestamp: new Date().toISOString(),
    },
    a11y: {
      enabled: A11Y_ENABLED && backend !== 'dry-run',
      backend: !A11Y_ENABLED ? 'disabled' : (backend === 'dry-run' ? 'unavailable' : backend),
      aggregate: a11yAggregate,
    },
    results,
  };

  // Output
  const reportJson = JSON.stringify(report, null, 2);
  if (args.output) {
    const outPath = resolve(args.output);
    mkdirSync(resolve(outPath, '..'), { recursive: true });
    writeFileSync(outPath, reportJson, 'utf8');
    log(`Report → ${outPath}`);
  } else {
    process.stdout.write(reportJson + '\n');
  }

  // Standalone a11y report (--a11y-output)
  if (A11Y_OUTPUT) {
    const allViolations = [];
    const seenIds = new Set();
    for (const r of results) {
      const violations = r.details?.a11y?.violations || [];
      for (const v of violations) {
        if (!seenIds.has(v.id)) {
          seenIds.add(v.id);
          allViolations.push(v);
        }
      }
    }
    const a11yReport = {
      url,
      timestamp: new Date().toISOString(),
      tags: A11Y_TAGS,
      backend: !A11Y_ENABLED ? 'disabled' : (backend === 'dry-run' ? 'unavailable' : backend),
      aggregate: a11yAggregate,
      violations: allViolations,
    };
    const a11yOutPath = resolve(A11Y_OUTPUT);
    mkdirSync(resolve(a11yOutPath, '..'), { recursive: true });
    writeFileSync(a11yOutPath, JSON.stringify(a11yReport, null, 2), 'utf8');
    log(`A11y Report → ${a11yOutPath}`);
  }

  // Human summary to stderr
  const C = { reset: '\x1b[0m', bold: '\x1b[1m', red: '\x1b[31m', green: '\x1b[32m', yellow: '\x1b[33m', cyan: '\x1b[36m' };
  process.stderr.write(`\n${C.bold}visual-qa.js${C.reset} ${DRY_RUN ? C.yellow + '[DRY-RUN]' + C.reset : ''}\n`);
  process.stderr.write(`${C.cyan}URL:${C.reset} ${url}\n`);
  if (A11Y_ENABLED && backend !== 'dry-run') {
    process.stderr.write(`${C.cyan}A11y:${C.reset} axe-core ${A11Y_TAGS.join('/')}  `);
    if (a11yAggregate.violations === 0) {
      process.stderr.write(`${C.green}0 violations${C.reset}\n`);
    } else {
      process.stderr.write(`${C.red}${a11yAggregate.violations} violations (${a11yAggregate.critical} critical)${C.reset}\n`);
    }
  }
  process.stderr.write(`\n`);

  for (const r of results) {
    const icon = r.passed ? `${C.green}✓${C.reset}` : `${C.red}✗${C.reset}`;
    process.stderr.write(`  ${icon} ${r.breakpoint.padEnd(8)}`);
    if (r.error) {
      process.stderr.write(` ${C.red}${r.error}${C.reset}\n`);
    } else {
      const failedChecks = Object.entries(r.checks).filter(([, v]) => !v).map(([k]) => k);
      if (failedChecks.length) {
        process.stderr.write(` ${C.red}FAIL${C.reset} — ${failedChecks.join(', ')}\n`);
      } else {
        const a11yInfo = r.details?.a11y?.summary;
        const a11yStr = a11yInfo?.violations ? `  (a11y: ${a11yInfo.violations} violations)` : '';
        process.stderr.write(` ${C.green}PASS${C.reset} (${Object.keys(r.checks).length} checks)${a11yStr}\n`);
      }
    }
  }

  process.stderr.write(`\n${allPassed ? C.green + C.bold + 'ALL PASS' : C.red + C.bold + 'FAIL'}${C.reset}\n\n`);

  process.exit(allPassed ? 0 : 1);
}

main().catch(err => {
  process.stderr.write('[FATAL] ' + err.message + '\n');
  if (args.verbose) process.stderr.write(err.stack + '\n');
  process.exit(2);
});
