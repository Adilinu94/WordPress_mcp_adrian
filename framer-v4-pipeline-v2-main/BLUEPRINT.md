# 🚀 Framer → Elementor V4 Pipeline V2: Master Blueprint

> **Version:** v0.14.0 | **Stand:** 2026-06-14

## 🎯 Overview
Ziel: Umsetzung eines stabilen, token-effizienten Framer-zu-V4-Workflows basierend auf einer **3-Wege-Symbiose**:
1. **Unframer MCP**: Liest die Live-Struktur, Texte und Komponenten-Hierarchie direkt von der Framer-URL.
2. **FramerExport (Lokal)**: Lädt Assets (Bilder, Fonts, Videos) herunter, bereinigt das HTML/CSS und liefert die Basis für Style-Extraktion.
3. **Novamira MCP**: Nimmt den fusionierten, validierten V4-Tree und führt den sicheren Build in WordPress aus.

Alle Komponenten dieser optimierten Pipeline sind in diesem Ordner (`framer-v4-pipeline-v2/`) gekapselt, um das Haupt-Repository sauber zu halten.

---

## 📂 Projektstruktur (Standalone) — SINGLE SOURCE OF TRUTH

> **Hinweis (Fix 5):** Diese Datei (BLUEPRINT.md) ist der Master für alle Pipeline-Dokumentationen.
> README.md und framer-v4-pipeline.md referenzieren hierher. Bei Änderungen NUR diese Datei bearbeiten.
> Neue Module (mcp-bridge.js, rollback.js, split-large-tree.js) sind unten dokumentiert.
```text
framer-v4-pipeline-v2/
├── BLUEPRINT.md                          # Dieser Bauplan
├── package.json                          # Node ESM + npm-Script-Shortcuts
├── .env.example                          # Konfigurierbare Umgebungsvariablen
├── .gitignore                            # Artefakte und .env nie committen
├── wizard.js                             # Interaktiver CLI-Einstieg (Phase 1)
├── schemas/
│   └── v4-prop-type-schema.json          # Widget-Pflichtfelder fuer Validator
├── tests/
│   └── pipeline.test.js                  # 114 Regressionstests in 36 Suiten, node --test
├── tests/
│   └── e2e.test.js                       # 18 E2E-Tests (inkl. 3 S14 ENH-16)
└── scripts/
    ├── lib/
    │   ├── framer-utils.js               # Gemeinsame Utilities (wrapSize, walkTree, ...)
    │   ├── mcp-client.js                  # Resilienter HTTP-Client (Exponential-Backoff, Retry)
    │   ├── mcp-bridge.js                  # JSON-RPC 2.0 MCP-Bridge (Session-Handshake, REST-Fallback)
    │   ├── rollback.js                    # MCP-Plan-Generator: Backup/Restore vor Build
    │   └── split-large-tree.js            # MCP-Plan-Generator: Section-Split großer Trees
    ├── convert-xml-to-v4.js              # Framer XML -> V4 Widget-Tree JSON
    ├── extract-framer-styles.js          # CSS-Properties + Variablen aus HTML-Export
    ├── extract-framer-components.js      # A1: Component Extraction (wiederholte Muster)
    ├── extract-framer-interactions.js    # A2: Interaction Extraction (CSS → V4 Pro)
    ├── extract-framer-dark-mode.js       # Dark-Mode-CSS → V4 Variable-Set (ENH-10)
    ├── extract-framer-forms.js           # A3: Form Extraction (→ V4 Atomic Forms)
    ├── preflight-check.js                # Standalone Preflight System-Checks (S6)
    ├── measure-quality-metrics.js        # ENH-13: Quality Metrics (DOM, GC, GV, Grid, Components)
    ├── profile-pipeline.js               # ENH-14: Pipeline Performance Profiler (7 Phasen + Bottleneck)
    └── wizard/
        ├── shared.js                     # Shared helpers (log, runFile, recovery)
        ├── cmd-preflight.js              # Preflight sub-command
        ├── cmd-dry-run.js                # Dry-run sub-command
        ├── cmd-preview.js                # Preview sub-command
        ├── cmd-promote.js                # Promote sub-command
        ├── cmd-serve.js                  # Serve sub-command
        └── cmd-batch.js                  # Batch Multi-Page Build (S6)
    ├── extract-image-urls.js             # Bild-URLs aus HTML-Export
    ├── extract-responsive-breakpoints.js # Breakpoints aus CSS
    ├── resolve-fonts.js                  # Font-Referenzen aufloesen (FR;/GF; Prefix)
    ├── design-token-extractor.js         # CSS Custom Properties -> token-mapping.json
    ├── generate-global-classes.js        # GC-Vorschlaege + --execute (direkte Erstellung via McpBridge)
    ├── auto-scale-responsive.js          # Mobile/Tablet-Varianten injizieren
    ├── framer-pre-build-validate.js      # 12-Guard Pre-Build-Validierung (Score >= 85%)
    ├── framer-animation-extractor.js     # Animation-Extraktion → animation-plan.json
    ├── post-build-auto-fix.js            # QA-Report → Auto-Fix MCP-Plan
    ├── sync-schema.js                    # Prop-Schema-Sync vom V2-Plugin (Fail-Fast)
    ├── inject-animation-code.js          # Animation-Plan → MCP-Code-Injection
    ├── visual-qa.js                      # Browser-Visual-QA + axe-core A11y-Audit
    ├── section-compare.js                # Framer-vs-Elementor Screenshot-Vergleich
    ├── patch-v4-tree-media-ids.js        # Framer-URLs -> WP Media IDs (Invariant IV)
    ├── verify-build-binding.js           # Post-Build: Invariant I (Styles gebunden?)
    ├── validate-v4-tree.js               # Vollstaendiger Schema-Validator (Invariant I-V)
    ├── cross-validate-sources.js         # URL-zu-WP-ID Konsistenzcheck
    ├── asset-to-wp-media.js              # Asset-Upload + --execute (Batch-Upload via McpBridge)
    ├── build-dependency-graph.js         # Section-Abhaengigkeits-Graph
    └── export-mcp-xml.js                 # MCP Build-Plan aus Dependency-Graph
```
*Hinweis: Die Pipeline-Scripts sind eigenständig. Für echte Exporte wird weiterhin ein lokaler FramerExport-Checkout benötigt (`FRAMER_EXPORT_DIR`, `tools/framer-export` oder `FramerExport`).*

---

## ⚙️ Der Workflow (Schritt für Schritt)

### Phase 0: MCP-Verbindungs-Check (PFLICHT vor jedem Start!)
⚠️ **KRITISCHE REGEL:** Bevor irgendein Script oder Build gestartet wird, muss der Agent aktiv prüfen, ob alle benötigten MCP-Server verbunden sind.
1. Prüfe Verfügbarkeit von **Unframer MCP** (z. B. via Tool-Liste oder Test-Call).
2. Prüfe Verfügbarkeit von **Novamira MCP** (`novamira/adrians-setup-v4-foundation`).
3. *Falls ein MCP fehlt:* Sofort abbrechen und den User auffordern, die Umgebung (`.mcp.json`) neu zu laden oder die Verbindung zu prüfen. Kein Blindflug!

### Phase 1: Interaktive Steuerung & Orchestrierung
1. Starte den Wizard: `node framer-v4-pipeline-v2/wizard.js`
2. Der Wizard fragt nach:
   - Framer-URL
   - Scope (Ganze Seite oder Abschnitte)
   - Ziel-WordPress-Umgebung (`testseite` oder `treetsshop`)
   - Ziel-Post-ID (oder "new")
3. Der Wizard erkennt den lokalen FramerExport-Checkout, führt den Export aus und verwendet den neu erzeugten Ordner mit `index.html`.

### Phase 2: Pre-Build Processing & Asset-Management
4. **Extraktion**: Der Wizard ruft die bestehenden Repo-Scripts auf (`extract-image-urls.js`, `resolve-fonts.js`, etc.) auf dem FramerExport-Mirror. Outputs landen im Exportordner unter `assets/` und `tokens/`.
5. **Auto-Skalierung**: `node framer-v4-pipeline-v2/scripts/auto-scale-responsive.js v4-tree.json`
   - Erkennt `font-size > 28px` oder `padding > 20px` und injiziert automatisch skalierte Mobile/Tablet-Varianten.
6. **Media-Patching**: Nach dem Asset-Upload führt der Agent `node framer-v4-pipeline-v2/scripts/patch-v4-tree-media-ids.js` aus.
   - Ersetzt nackte URLs durch `{"$$type": "image-src", "value": {"id": WP_MEDIA_ID}}` (ohne `url`-Key, per Invariant IV).

### Phase 3: Validierung & Dry-Run
7. **12-Guard Check**: Der Wizard führt automatisch `scripts/framer-pre-build-validate.js` aus.
   - Sammelt *alle* Verstöße (kein Fail-Fast) und blockiert den Build bei einem Score < 85%.

### Phase 4: Execution & Post-Build QA (Agenten-Aufgabe)
8. **Foundation**: Agent ruft `novamira/adrians-setup-v4-foundation { post_id: <ID> }` auf.
9. **Build**: Agent ruft `novamira/elementor-set-content` auf (⚠️ **NIEMALS** `adrians-batch-build-page` für Framer, um V3-Wrapper-Fehler zu vermeiden).
10. **Slim Binding Check**: Agent speichert den Dump und führt aus:
    `node framer-v4-pipeline-v2/scripts/verify-build-binding.js elementor-dump.json`
    - Gibt *nur* die Elemente aus, bei denen Styles definiert, aber nicht in `settings.classes` gebunden sind (Invariant I). Spart tausende Tokens.

---

## 🛠️ Implementierungsstatus

### Abgeschlossen
- [x] Blueprint, Ordnerstruktur, `.gitignore`, `.env.example`
- [x] Interaktiver CLI-Wizard (`wizard.js`) mit Phase 0-1.4 (Pre-Build, Schema-Sync, Rollback, Split)
- [x] `package.json` mit ESM, `"type":"module"`, `engines.node>=18`, 20+ npm-Script-Shortcuts
- [x] `mcp-client.js`: Resilienter HTTP-Client mit Exponential-Backoff + Jitter
- [x] `mcp-bridge.js`: JSON-RPC 2.0 Protokoll + Session-Handshake + REST-Fallback (Fixes A+H)
- [x] `rollback.js`: MCP-Plan-Generator für Backup/Restore (statt totem mcp.call())
- [x] `split-large-tree.js`: MCP-Plan-Generator für Section-Split (statt totem mcp.call())
- [x] `sync-schema.js`: Prop-Schema-Sync vom V2-Plugin via REST (Fail-Fast in wizard.js)
- [x] `visual-qa.js`: Browser-Visual-QA + axe-core WCAG 2.0/2.1/2.2 A11y-Audit
- [x] `framer-animation-extractor.js`: Animation-Extraktion aus Framer HTML → animation-plan.json
- [x] `post-build-auto-fix.js`: QA-Report → Auto-Fix MCP-Plan (contrast, alt-text, SEO, layout)
- [x] `inject-animation-code.js`: Animation-Plan → MCP-Code-Injection (GSAP/CSS/JS)
- [x] `section-compare.js`: Zombie-Browser-Fix (Bug 1) + Scroll-X-Fix (Bug 2)
- [x] `framer-utils.js`: wrapSize, wrapDimensions, generateStyleId, walkTree, getWrappedSizeNumber, scaleWrappedSize, **structuralHash**
- [x] `convert-xml-to-v4.js`: Framer XML -> V4 Tree, C2 Grid Detection, C6 GV-Substitution, C1 Component Preservation
- [x] `design-token-extractor.js`: CSS Custom Properties -> token-mapping + variables-plan
- [x] `generate-global-classes.js`: Duplikat-Erkennung, C4 Semantic GC Naming, --execute (Fix E)
- [x] `auto-scale-responsive.js`: V4 $$type-bewusstes Scaling, C5 Breakpoint-aware, --tree/--output Flags
- [x] `framer-pre-build-validate.js`: 12 Guards, Score >= 85%, g12 seenPaths-Dedup, walk styles+settings
- [x] `patch-v4-tree-media-ids.js`: Invariant IV compliant (image-attachment-id Wrapper, kein url:null)
- [x] `verify-build-binding.js`: Invariant I, gc- Filter (kein false positive bei Global Classes)
- [x] `validate-v4-tree.js`: Vollstaendiger Schema-Validator, D1/D2/D3 Checks, --animation-plan Flag
- [x] `cross-validate-sources.js`, `asset-to-wp-media.js` (inkl. --execute Fix B), `build-dependency-graph.js`, `export-mcp-xml.js`
- [x] `schemas/v4-prop-type-schema.json` → via V2-Plugin REST-Endpoint + lokales Fixture für Tests
- [x] **`extract-framer-components.js`** (A1): Component Extraction — wiederholte Muster → Blueprints
- [x] **`extract-framer-interactions.js`** (A2): CSS Transitions + Framer Appear → V4 Pro Interactions
- [x] **`extract-framer-dark-mode.js`** (ENH-10): Dark-Mode-CSS → V4 Variable-Set (Brace-Counting, Light-Token-Matching)
- [x] **`preflight-check.js`** (S6): Standalone 8-System-Checks (--help, --json)
- [x] **`scripts/wizard/`** (S6): 7 Modul-Dateien — Wizard von 905→~300 Zeilen reduziert
- [x] **FIX-7**: `mcp-bridge.js` callParallel() p-limit (concurrency=3, Worker-Pool, MCP_CONCURRENCY env var)
- [x] **ENH-11**: `convert-xml-to-v4.js` JSDoc für 9 Kernfunktionen
- [x] **wizard.js batch**: `--pages` + `--post-ids` Multi-Page Subcommand (S6)
- [x] `tests/pipeline.test.js`: **114 Tests in 36 Suiten** (node --test), alle gruen
- [x] `tests/e2e.test.js`: **15 Tests**, alle gruen (+3 S13 ENH-12)
- [x] `tests/integration.test.js`: **7 Tests** (4 pass, 3 skip ohne --live)
- [x] **Sprint 9**: ENH-14 Profile-Pipeline, ENH-15 axe-core A11y (--a11y/--a11y-output), FIX-15 WCAG 2.2 PHPUnit, FIX-16/17 Media-Security
- [x] **ENH-16 FramerExport CLI**: Wizard --non-interactive läuft vollständig durch. FramerExport v4.3.8, spawnWithRetry mit shell:true-Eskalation für Bash/Windows-Kompatibilität.
- [x] GSD-Projekt: `.planning/` mit PROJECT.md, REQUIREMENTS.md, ROADMAP.md, PLAN-1-7.md, STATE.md, config.json
- [x] `--help` Blocks: A1, A2, A3 mit einheitlichem CLI-Pattern (parseArgs help Option)

### Phase 0.5.x Security & QA (abgeschlossen)
- [x] **0.5.3:** PHP-Sandbox-Security-Audit — B8-CRITICAL Bug in `is_available()` gefixt, Permission-Callbacks entkoppelt
- [x] **0.5.7:** axe-core-Integration in visual-qa.js — WCAG 2.0/2.1/2.2 Audit via @axe-core/playwright + axe-core vanilla

### Phase 0.2 Schema-Dedup (abgeschlossen)
- [x] V2-Plugin: `V4_Props::get_schema()` + REST-Endpoint `wp-json/novamira-adrianv2/v1/prop-schema`
- [x] Pipeline: `sync-schema.js` mit Fail-Fast HTTP-Fetch → `schemas/v4-prop-type-schema.json`

### Phase 1.2-1.4 Resilienz & Integration (abgeschlossen)
- [x] **1.2:** `mcp-client.js` — Retry-Logik mit Exponential-Backoff + Jitter (5xx/429/Network)
- [x] **1.2+:** rollback.js, split-large-tree.js — tote `mcp.call()`-Aufrufe durch MCP-Plan-Generatoren ersetzt
- [x] **1.2+:** section-compare.js — Zombie-Browser-Fix (Bug 1) + Syntax-Fix
- [x] **1.3:** wizard.js — Rollback-Backup-Plan als Phase 1.3 integriert (Pre-Build)
- [x] **1.4:** wizard.js — Split-Large-Tree-Check als Phase 1.4 integriert (Pre-Build)

### Phase 1.5+ Post-Build Auto-Fix (abgeschlossen)
- [x] `framer-animation-extractor.js` — Framer HTML → animation-plan.json (Keyframes, GSAP, Appear)
- [x] `post-build-auto-fix.js` — QA-Report → Auto-Fix MCP-Plan (5 Kategorien, dedupliziert)

### Phase 2.0 — Integration Fixes A-H (abgeschlossen ✅)
- [x] **Fix A:** `mcp-bridge.js` — JSON-RPC 2.0 + Session-Handshake + Adapter-Wrapper
- [x] **Fix B:** `asset-to-wp-media.js` — `--execute` Batch-Upload via McpBridge
- [x] **Fix D:** `check-v4-requirements.js` — `--auto-call` via McpBridge + wizard.js 3-stufiger Fallback
- [x] **Fix E:** `generate-global-classes.js` — `--execute` direkte GC-Erstellung + Tree-Rückschreibung
- [x] **Fix G:** `novamira-skill/framer-v4-pipeline.md` — Cache-Regel + npm-Shortcuts aktualisiert
- [x] **Fix H:** `mcp-bridge.js` — WP REST Fallback für 12 Endpoints
- [x] **Bugfix:** Windows ESM `pathToFileURL()` in `pipeline.test.js`
- [x] **Schema:** `schemas/v4-prop-type-schema.json` Fixture für E2E-Tests
- [x] **Live getestet:** MCP-Bridge `--self-test` + check-v4 `--auto-call` gegen solar.local ✅

### Sprint 7+8 (abgeschlossen)
- [x] **Sprint 7** Quality Hardening: FIX-10/11/12, 88→100 Tests
- [x] **Sprint 8** Live Integration: ENH-12/13, FIX-13/14, 100→105 Pipeline / 12→15 E2E
- [x] End-to-End Test mit echter Framer-URL (--non-interactive Mode)
- [x] .planning/ Docs synchronisiert (REQUIREMENTS, ROADMAP, STATE, PROJECT)

### Phase 1.4+ — CI, Performance, UX, Advanced, A11y (abgeschlossen ✅)
- [x] **1.4:** `.github/workflows/ci.yml` — 7 Jobs (test, e2e, schema, mcp-mock, visual, lint, syntax)
- [x] **1.4:** `tests/mcp-mock-server.js` — lokaler Mock (15 Ability-Responses)
- [x] **2.1:** `class-execute-build-plan.php` — Mega-Ability (1 Call statt 18+ Agent-Turns)
- [x] **2.2:** `scripts/parallel-pre-build.js` — Promise.allSettled für 5 Sub-Steps
- [x] **2.3:** `scripts/lib/mcp-cache.js` — Discovery-Cache mit TTL + atomic write
- [x] **3.1:** `wizard.js dry-run` — Build-Plan ohne Schreibzugriff
- [x] **3.2:** `wizard.js preview/promote` — Preview-Page + Live-Promote via McpBridge
- [x] **3.3:** `wizard.js` interaktive Error Recovery — [R]etry/[S]kip/[F]ix/[A]bort
- [x] **3.2+4.1:** `wizard.js serve` — HTTP-API (GET /health, POST /build, GET /builds/:id)
- [x] **4.2:** `class-build-versioning.php` — CPT elementor_build mit Meta-Boxes
- [x] **4.5:** `class-execute-build-plan.php` — execute-pipeline-build Ability
- [x] **5.1.1:** `class-v4-color-contrast-22.php` — WCAG 2.2 Target Size + Focus Appearance
- [x] **5.1.2:** `resolve_background_color()` — Parent-Chain-Walking + `inconclusive` Flag
- [x] **5.1.3:** `fix-color-contrast preview` — HTML Side-by-Side Diff + Backward-Compat

---

## 💡 Kritische Invarianten (Niemals brechen!)

| # | Name | Regel |
|---|------|-------|
| I | Rendering-Gate | Jede ID in `element.styles` MUSS in `settings.classes.value` existieren |
| II | Style-Werte in styles | Visuelle Props (color, font-size, padding...) NIEMALS in `settings` - nur in `styles` |
| III | Style-IDs ohne Hyphens | Lokale Style-IDs duerfen keine Bindestriche enthalten (`shero` nicht `s-hero`) |
| IV | Image-Src url-Key | Wenn `id` gesetzt ist, darf `url`-Key nicht existieren (nicht mal als `null`) |
| V | custom_css Format | `custom_css` immer `{"raw":"..."}` - nie plain String (crasht die Site) |

**wizard.js Phase-Übersicht (v0.11.0):**
| Phase | Beschreibung | Fail-Fast |
|-------|-------------|-----------|
| 0 | MCP Connector Check | ✅ |
| 0.2 | Schema-Sync via V2-Plugin REST | ✅ |
| 0a | V4 Atomic Check (3-stufig: auto-call → Datei → Guidance) | ✅ (wenn Score <85%) |
| — | User-Input (URL, Scope, WP-Env, Post-ID) | — |
| A | FramerExport Symbiose | ✅ |
| B | Asset & Structure Extraction (5 Scripts) | Nein |
| C | Pre-Build Validation (12 Guards) | ✅ (wenn Score <85%) |
| 1.3 | Rollback-Backup-Plan generieren | Nein |
| 1.4 | Split-Large-Tree-Check | Nein |
| D | Build-Manifest Generierung | — | Framer -> V4 = `elementor-set-content`. V3 -> V4 Migration = `adrians-batch-build-page`.

---

## ✅ Lokale Verifikation

```bash
npm test                # 114 pipeline tests (36 Suiten)
npm run test:e2e        # 18 e2e tests (inkl. 3 S14 ENH-16)
npm run test:all        # 139 tests total (114 pipeline + 18 e2e + 7 integration)
npm run test:integration # 7 integration tests (4 pass, 3 skip ohne --live)
npm run test:bridge     # mcp-bridge.js --self-test
npm run test:mcp-mock   # Integration tests gegen Mock-Server
npm run test:schema     # sync-schema.js --validate
npm run parallel        # 5 Pre-Build Steps parallel
npm run lint:version    # CHANGELOG.md vs package.json
npm run check-v4-auto   # check-v4-requirements.js --auto-call
npm run gc-execute      # generate-global-classes.js --execute
npm run post-build-qa   # run-post-build-qa.js
npm run extract-components  # A1: Component Extraction
npm run extract-interactions # A2: Interaction Extraction
npm run extract-dark-mode  # Dark-Mode CSS Extraction (ENH-10)
npm run extract-forms   # A3: Form Extraction
npm run preflight-check # Standalone Preflight Checks (S6)
npm run wizard-batch    # Multi-Page Batch Build (S6)
npm run measure-quality  # ENH-13: Quality Metrics Measurement
npm run test:integration-live # FIX-13: Live Integration Tests
node --check wizard.js
node --check scripts/lib/mcp-bridge.js
```
