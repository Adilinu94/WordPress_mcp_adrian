# Changelog — framer-v4-pipeline-v2

## [v0.15.0] — 2026-06-14

### Sprint 9 Complete — Pipeline Hardening & Plugin Fixes

- **ENH-16 FramerExport CLI**: Wizard --non-interactive, spawnWithRetry shell:true, S14 E2E
- **Schema-Sync REST Endpoint**: GET /novamira/v1/prop-schema + V4_Props::get_schema() (12 types, 13 props)
- **UV_HANDLE_CLOSING Fix**: undici dispatcher destroy + process.exitCode (statt process.exit)
- **WCAG 2.2 Threshold**: 0.03928→0.04045 in V4_Color_Contrast (aligns with spec + V4_Color_Contrast_22)
- **Contrast Ratio Test**: #949494→#959595 (WCAG threshold 0.04045 gives 3.03 for old color)
- **Extraction Exit Codes**: 4 scripts exit 0 for non-critical results (no URLs, missing fonts, unknown BPs, unmapped tokens)
- **Windows Path Fix**: fileURLToPath prevents C:\C:\ double-prefix
- **PHPUnit Infrastructure**: Composer + php.ini + WP mock functions (52 tests, 145 assertions)
- **V4PropsSchemaTest**: 31 tests for get_schema() REST endpoint schema
- **Docs**: .planning/STATE.md, ROADMAP.md updated, PR #1 (sprint-9-fixes → master)

### Test-Status
- npm test → 114/114 ✅
- npm run test:e2e → 18/18 ✅
- PHPUnit → 52/52 ✅ (145 assertions)
- Total: 184 tests, 100% passing

## [v0.14.0] — 2026-06-14

### Sprint 9 — ENH-16: FramerExport CLI Integration

- **ENH-16 FramerExport CLI**: FramerExport v4.3.8 lokal installiert. Wizard --non-interactive läuft vollständig durch (FramerExport → 7 Extraktionen → Manifest).
- **spawnWithRetry**: `shared.js` — 3-stufige Eskalation für Windows/bash-Kompatibilität: .cmd → bare name → shell:true. Behebt EINVAL/ENOENT in Git Bash/MSYS2.
- **S14 E2E Tests**: 3 Tests für FramerExport CLI (Verzeichnis-Prüfung, HTML-Validierung, Extraktions-Outputs). E2E: 15→18 Tests.

### Test-Status
- `npm test` → 114/114 ✅
- `npm run test:e2e` → 18/18 ✅ (+3 S14 ENH-16)
- `npm run test:all` → 139 Tests total

## [v0.13.0] — 2026-06-14

### Sprint 9 — Performance, A11y & Security (110→114 Pipeline Tests)

- **ENH-14 `profile-pipeline.js`** (NEU): Misst Laufzeit von 7 Pipeline-Phasen. `--bottleneck` Flag identifiziert die 3 langsamsten Phasen mit `pct_of_total`. `--help`, `--timeout`, `--output` Flags.
- **ENH-15 axe-core A11y**: `visual-qa.js` — `--a11y` Flag (explizites Enable), `--a11y-output` Flag (standalone deduplizierter A11y-Report), `A11Y_ENABLED` Prioritätslogik (--a11y > default-ON > --skip-a11y).
- **FIX-15 WCAG 2.2 PHPUnit**: `tests/V4ColorContrast22Test.php` (NEU) — 16 Assertions: Target Size (2.5.8), Focus Appearance (2.4.11), Contrast Ratio, Edge Cases.
- **FIX-16/17 Media-Security**: `class-media-upload.php` — `guard_filename()` mit `sanitize_file_name()` + Extension-Whitelist, `guard_file_content()` + `guard_mime_buffer()` Magic-Bytes. Batch-Uploader nachgezogen.

### npm-Scripts (Neu)
- `profile-pipeline`, `visual-qa-a11y`

### Test-Status
- `npm test` → 114/114 ✅ (von 105 → 114, +9 Tests: 5 S35 + 4 S36)
- 36 Test-Suiten (von 33), Sprint 9 abgeschlossen
- `npm run test:e2e` → 15/15 ✅
- `npm run test:all` → 136 Tests total

## [v0.11.0] — 2026-06-13

### Sprint 7 — Quality Hardening (88→100 Tests)

- **FIX-10 `--format markdown`**: `extract-framer-dark-mode.js` — `--format markdown` produziert formatierte Variablen-Tabelle. `--format json` (default) unveraendert.
- **FIX-11 Wizard `--help`**: Alle 6 cmd-*.js mit konsistentem `printHelp()` Export. `wizard.js help <sub>` und `wizard.js <sub> --help` funktionieren.
- **FIX-12 `token_name` Dedup**: `suggestDarkTokenName()` mit Property-Suffix (`dark-{base}-{selector}-{property}`). Verhindert Kollisionen bei gleichem Selektor mit unterschiedlichen Properties.

## [v0.12.0] — 2026-06-14

### Sprint 8 — Live Integration (100→105 Pipeline / 12→15 E2E / 4→7 Integration)

- **ENH-12 Wizard `--non-interactive`**: `wizard.js --non-interactive --url <url> --post-id <ID>` — automatisierter Pipeline-Durchlauf ohne Prompts. Schema-Sync, FramerExport, Extraktion, Manifest.
- **ENH-13 `measure-quality-metrics.js`** (NEU): Misst 6 Qualitaets-Metriken: DOM-Tiefe, GC-Coverage, GV-Substitution (Color+Font), Grid-Nutzung, Components. `--compare` Flag.
- **FIX-13 Integration `--live`**: `tests/integration.test.js --live` — Live MCP-Tests gegen solar.local mit Preflight-Check (4 neue Live-Tests).
- **FIX-14 CI `test-all`**: `.github/workflows/ci.yml` — neuer `test-all` Job (127 Tests). `package.json` — `measure-quality`, `test:integration-live` Scripts.

### Docs Sync
- `.planning/REQUIREMENTS.md`: Sprint 7+8 (FIX-10/11/12, ENH-12/13, FIX-13/14, 23→30 Requirements)
- `.planning/ROADMAP.md`: Sprint 7 Quality Hardening + Sprint 8 Live Integration Phasen
- `.planning/STATE.md`: v0.11.0, Sprint 8 als aktiver Fokus
- `.planning/PROJECT.md`: v0.11.0, 7 Sprints, 105 Pipeline-Tests

### npm-Scripts (Neu)
- `measure-quality`, `test:integration-live`

### Test-Status
- `npm test` → 105/105 ✅ (von 49 → 105, +56 Tests ueber 8 Sprints)
- 33 Test-Suiten, 30 Requirements, 100% Complete
- `npm run test:e2e` → 15/15 ✅ (+3 S13 ENH-12 Tests)
- `npm run test:integration` → 7 Tests (4 pass, 3 skip ohne --live)
- `npm run test:all` → 127 Tests total


### Sprint 5 — Audit-Gap Remediation (77→83 Tests)

- **FIX-7 p-limit**: `callParallel()` Worker-Pool mit `concurrency=3`. `McpBridge.defaultConcurrency` via Constructor + `MCP_CONCURRENCY` env var. Verhindert Race-Conditions bei 10+ parallelen Requests.
- **ENH-10 `extract-framer-dark-mode.js`** (NEU): Extrahiert `@media (prefers-color-scheme: dark)` Blöcke. Brace-Counting für nested-rule-safe Parsing. V4 Dark Mode Variable-Set JSON mit Light-Token-Matching.
- **ENH-11 convert-xml-to-v4.js JSDoc**: `@param`/`@returns` für 9 Kernfunktionen (`tokenizeXml`, `buildTree`, `determineWidgetType`, `buildStyleProps`, `resolveColor`, `extractComponentText`, `convertNode`, `substituteTokensWithGvIds`, `analyzeTokenUsage`). 0 Behavioral Change.

### Sprint 6 — Wizard Modularisierung (83→88 Tests)

- **`scripts/preflight-check.js`** (NEU): Standalone 8-System-Checks. `--help`, `--json`. Wiederverwendet `runPreflight()` aus `cmd-preflight.js`.
- **`wizard.js batch`** (NEU): `--pages file1.xml,file2.xml --post-ids 42,43`. Multi-Page in 1 Durchlauf. Leere-Pages-Guard + Datei-Existenz-Validation. Batch-Summary JSON.
- **Wizard Modular**: 905→~300 Zeilen. 7 Module in `scripts/wizard/`: shared, cmd-preflight, cmd-dry-run, cmd-preview, cmd-promote, cmd-serve, cmd-batch. Thin Router.

### npm-Scripts (Neu)
- `preflight-check`, `wizard-batch`, `extract-dark-mode`

### Test-Status
- `npm test` → 88/88 ✅ (von 49 → 88, +39 Tests über 6 Sprints (Sprint 1-6))
- 30 Test-Suiten, 23 Requirements, 100% Complete

## [v0.10.0] — 2026-06-13

### Sprint 1 — Quick Wins + Root-Cause Fix (57→61 Tests)

- **C2 Strict Grid Mapping**: `display:grid`/`grid-template-columns` → `e-div-block` in `convert-xml-to-v4.js`
- **C4 Semantic GC Naming**: `suggestNameSemantic()` mit BEM-Pattern + Token-Awareness in `generate-global-classes.js`
- **C5 Breakpoint-aware Scaling**: `--breakpoints` Flag + `getElementScaleFactors()` aus `breakpoints.json`
- **C6 Token-to-GV Substitution**: `substituteTokensWithGvIds()` Pass — Root-Cause Fix für #111111×45 Problem
- **D3 GRID_VS_FLEXBOX_COVERAGE**: `checkGridVsFlexboxCoverage()` in `validate-v4-tree.js`

### Sprint 2 — Components & Interactions (61→67 Tests)

- **A1 `extract-framer-components.js`** (NEU): Wiederholte Card-Muster → V4 Component Blueprints
- **A2 `extract-framer-interactions.js`** (NEU): CSS Transitions + Framer Appear → V4 Pro Interactions
- **C1 Component Preservation**: `componentId`/`componentName` → `e-component` Widget in `convert-xml-to-v4.js`
- **C3 Easing Fix**: `mapEasingToGSAP` → Elementor-native easing names in `framer-animation-extractor.js`
- **D1 COMPONENT_REUSE_POTENTIAL**: `checkComponentReusePotential()` in `validate-v4-tree.js`

### Sprint 3 — Forms & Validation (67→71 Tests)

- **A3 `extract-framer-forms.js`** (NEU): `<form>`/`<input>`/`<button>` → V4 Atomic Forms
- **B4 `create-atomic-form`**: MCP-Routing-Doku + npm-Script
- **D2 NATIVE_INTERACTION_COVERAGE**: `--animation-plan` Flag + `checkNativeInteractionCoverage()`

### Sprint 4 — Code-Review Remediation (71→77 Tests)

- **C3 Native Routing Complete**: `--native` Flag → `type:'v4-native'` output, `mapEasingToElementor`, dual-mode `buildTransitionInteractions()`
- **structuralHash Dedup**: Einmalig in `framer-utils.js` (mit `includeTag`/`nullOnSmall` Optionen), A1+D1 importieren
- **A2 v4-tree Mode**: Tree-Walker erkennt opacity/transform Styles → V4-native interactions
- **Regex-Fix**: `extractAnimatedRules` erkennt `transition:` shorthand korrekt

### DX — `--help` Blocks & CLI Vereinheitlichung

- A1, A2, A3: Vollständige `--help` Blöcke (ZVECK, OPTIONEN, BEISPIELE, EXIT-CODES)
- Einheitliches CLI-Pattern: `parseArgs` mit `help` Option + `args.help || !requiredArgs` Check

### Test-Status
- `npm test` → 77/77 ✅ (von 49 → 77, +28 Tests über 4 Sprints)
- 24 Test-Suiten, 17 Requirements, 100% Complete

## [v0.7.0] — 2026-06-12

### Added
- `html-to-widget-plan.js`: Brücke zu `novamira/adrians-html-to-elementor-widget-plan` mit `--execute` (McpBridge) + Plan-Fallback + Wizard-Integration
- `widget-plan` + `widget-plan-execute` npm-Scripts
- `lint:version` Script: checkt `package.json` Version gegen `CHANGELOG.md`
- `.env.example`: 16 Variablen (Workspace, MCP, Validation, Performance)
- `wizard.js preflight` Subcommand: 8 Checks, farbige ✓/✗-Tabelle, `--format=json`
- `wizard.js dry-run` Subcommand: Build-Plan ohne Schreibzugriff
- `wizard.js preview` Subcommand: Preview-Page via McpBridge (get→create→set)
- `wizard.js promote` Subcommand: Backup + Content-Transfer auf Live-Seite
- `wizard.js` interaktive Error Recovery: [R]etry/[S]kip/[F]ix/[A]bort + `runWithRecovery()`
- `wizard.js serve` Subcommand: HTTP-API (`GET /health`, `POST /build`, `GET /builds/:id`)
- `scripts/parallel-pre-build.js`: `Promise.allSettled` für 5 parallele Sub-Steps
- `scripts/lib/mcp-cache.js`: MCP-Discovery-Cache mit TTL + atomic write
- `tests/mcp-mock-server.js`: Lokaler Mock mit 15 Ability-Responses
- `.github/workflows/ci.yml`: 7 CI-Jobs (test, e2e, schema, mcp-mock, visual, lint, syntax)

### V2-Plugin (novamira-adrianv2)
- `class-execute-build-plan.php`: Mega-Ability — 18+ Agent-Turns → 1 Turn
- `class-build-versioning.php`: CPT `elementor_build` mit Meta-Boxes
- `class-v4-color-contrast-22.php`: WCAG 2.2 — Target Size + Focus Appearance
- `resolve_background_color()`: Öffentliche A11y-Methode mit Parent-Chain-Walking + `inconclusive` Flag
- `fix-color-contrast` preview-Mode: HTML Side-by-Side Diff + Backward-Compat `proposed` Feld

### Changed
- **Versionsdrift behoben**: `package.json` → `0.7.0`, alle Doku-Stamps synchronisiert
- `wizard.js`: Preflight + Dry-Run + Serve Subcommands integriert
- `bootstrap.php` (main): `class-build-versioning.php` + `class-v4-color-contrast-22.php` geladen
- `bootstrap.php` (elementor): `class-execute-build-plan.php` in Dateiliste + Auto-Registration
- `BLUEPRINT.md`, `INTEGRATION-PLAN.md`, `SESSION-STATE.md`: auf v0.7.0 synchronisiert

### Infrastructure
- GSD-Projekt initialisiert (`.planning/`) — 20 Pläne, 7 Phasen, 100% completed
- Altes Plugin-Projekt archiviert (`.planning-novamira-plugin/`)
- `CHANGELOG.md` als Release-Historie

## [v0.6.0] — 2026-06-11

### Added
- **Integration Fixes A-H** (INTEGRATION-PLAN.md vollständig umgesetzt):
  - **Fix A**: `mcp-bridge.js` — JSON-RPC 2.0 Protokoll + Session-Handshake (`--self-test`)
  - **Fix B**: `asset-to-wp-media.js` — `--execute` Batch-Upload via McpBridge
  - **Fix D**: `check-v4-requirements.js` — `--auto-call` via McpBridge + `wizard.js` 3-stufiger Fallback
  - **Fix E**: `generate-global-classes.js` — `--execute` direkte GC-Erstellung + Tree-Rückschreibung
  - **Fix G**: `novamira-skill/framer-v4-pipeline.md` — Cache-Regel-Doku + npm-Shortcuts
  - **Fix H**: `mcp-bridge.js` — WP REST Fallback für 12 Endpoints
- npm-Scripts: `gc-execute`, `asset-upload`, `check-v4-auto`, `test:bridge`, `widget-plan`, `widget-plan-execute`
- `schemas/v4-prop-type-schema.json` Fixture für E2E-Tests
- `.mcp.json` Konfigurationsdatei für solar.local

### Fixed
- **Windows ESM Bug** (`ERR_UNSUPPORTED_ESM_URL_SCHEME`): `pipeline.test.js` nutzt `pathToFileURL()`
- **E2E-10 Check-Count**: 6→7 (A1 a11y hinzugekommen)
- `.gitignore`: `.mcp.json` hinzugefügt

### Tested
- MCP-Bridge `--self-test` + `check-v4 --auto-call` live gegen solar.local ✅
- `npm run test:all` → 56/56 ✅ | `npm run test:integration` → 4/4 ✅
