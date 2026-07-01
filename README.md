# Novamira AdrianV2

> WordPress Plugin — MCP-Abilities für Elementor V4, Media, Audit, SEO & mehr

**Version:** 1.10.1  
**Requires:** PHP 8.0+, WordPress 6.4+, [Novamira](https://novamira.dev), Elementor 4.x  
**License:** GPL-2.0-or-later

---

## Übersicht

Novamira AdrianV2 ist das **Fähigkeiten-Plugin** für den Novamira MCP Server auf solar.local. Es stellt ~44+ MCP-Abilities bereit, die von KI-Agenten (Claude, Codebuff) über JSON-RPC 2.0 aufgerufen werden können.

### Architektur

```
novamira-adrianv2/
├── novamira-adrianv2.php              # Main Plugin File
├── README.md                           # Diese Datei
├── CHANGELOG.md                        # Release-Historie
├── composer.json                       # PHP Dependencies
├── phpunit.xml.dist                    # PHPUnit Konfiguration
├── phpcs.xml                           # PHP CodeSniffer Konfiguration
├── scripts/
│   └── deploy-plugin.sh               # Deployment-Script → solar.local
├── tests/
│   ├── bootstrap.php                   # PHPUnit Bootstrap
│   ├── mock-functions.php              # 20+ WordPress Mock-Funktionen
│   ├── V4PropsSchemaTest.php           # 31 Tests — REST-Endpoint Schema
│   ├── V4ColorContrast22Test.php       # 16 Tests — WCAG 2.2
│   └── SetupV4FoundationTest.php       # 5 Tests — V4 Foundation
└── includes/
    ├── bootstrap.php                  # Ability-Registrierung (11 Sub-Module)
    ├── categories.php                 # Ability-Kategorien
    ├── class-build-versioning.php     # Build-Versionierung (CPT elementor_build)
    ├── helpers/                       # 14 Hilfsklassen
    │   ├── class-v4-props.php         # V4 Prop-Type Schema
    │   ├── class-v4-color-contrast.php # WCAG 2.2 Kontrast-Prüfung
    │   ├── class-v4-color-contrast-22.php # @deprecated BC-Extension
    │   └── ...
    └── abilities/
        ├── a11y/          (2)         # Accessibility-Prüfung
        ├── atomic/        (3)         # V4 Atomic Widgets
        ├── audit/         (7)         # QA & Audit-Tools
        ├── custom-code/   (2)         # Code-Snippet-Injection
        ├── elementor/     (29)        # Elementor-Kernfunktionen
        ├── global-classes/(2)         # Global Classes
        ├── media/         (8)         # Media Library
        ├── php-sandbox/   (2)         # PHP-Sandbox (Code-Ausführung)
        ├── seo/           (2)         # SEO-Meta
        ├── utilities/     (2)         # Utilities & Diagnostics
        ├── variables/     (2)         # Global Variables
        └── wpcode/        (7)         # WPCode Snippets (HTML/CSS/JS/PHP)
```

---

## REST Endpoints

### `GET /wp-json/novamira/v1/prop-schema`

Liefert das kanonische V4 Property-Type Schema — konsumiert von der Framer-V4-Pipeline (`sync-schema.js`) zur Validierung von Widget-Trees.

**Response:**
```json
{
  "version": "1.0.0",
  "types": [...12 widgets...],
  "properties": {...13 definitions...}
}
```

- **Registrierung:** `includes/helpers/bootstrap.php` → `register_rest_route('novamira/v1', '/prop-schema', ...)`
- **Quelle:** `V4_Props::get_schema()` in `includes/helpers/class-v4-props.php`
- **Test:** 31 Tests in `tests/V4PropsSchemaTest.php`

### `GET /wp-json/novamira/v1/health`

Health Check — liefert Status, PHP/WP-Version und Timestamp.

```json
{
  "status": "ok",
  "timestamp": "2026-06-14T12:00:00+00:00",
  "php": "8.2.23",
  "wp": "6.9"
}
```

### `GET /wp-json/novamira/v1/status`

Detaillierter Status — Plugin-Info, Schema-Übersicht, Test-Counts.

```json
{
  "plugin": { "name": "novamira-adrianv2", "version": "1.10.1" },
  "schema": { "version": "1.0.0", "types": 12, "props": 13 },
  "tests": { "phpunit": 52, "pipeline": 114, "e2e": 18, "total": 184 },
  "php": "8.2.23",
  "time": "2026-06-14T12:00:00+00:00"
}
```

### `GET /wp-json/novamira/v1/version`

Versions-Info — Plugin, PHP, WordPress.

```json
{
  "plugin": "1.10.1",
  "php": "8.2.23",
  "wp": "6.9"
}
```

---

## Abilities (Auswahl)

### 🔧 Elementor Core

| Ability | Beschreibung |
|---------|-------------|
| `novamira-adrianv2/setup-v4-foundation` | V4-Kit-Grundstruktur anlegen (Variables, GCs) |
| `novamira-adrianv2/batch-build-page` | Batch-Build von Atomic Widget Trees |
| `novamira-adrianv2/patch-element-styles` | Styles an bestehenden Elementen patchen |
| `novamira-adrianv2/export-design-system` | Design-System exportieren (read-only) |
| `novamira-adrianv2/import-design-system` | Design-System importieren |
| `novamira-adrianv2/execute-build-plan` | Mega-Ability: 1 Call statt 18+ Agent-Turns |

### 🎨 Global Classes & Variables

| Ability | Beschreibung |
|---------|-------------|
| `novamira-adrianv2/batch-class` | Global Classes erstellen/aktualisieren |
| `novamira-adrianv2/add-global-class-variant` | Varianten pro Breakpoint hinzufügen |
| `novamira-adrianv2/apply-variable-to-class` | GV-ID an Global Class binden |
| `novamira-adrianv2/batch-create-variables` | Global Variables batch-erstellen |

### 🔍 Audit & QA

| Ability | Beschreibung |
|---------|-------------|
| `novamira-adrianv2/layout-audit` | DOM-Tiefe, Nesting, Overflow prüfen |
| `novamira-adrianv2/visual-qa` | Visuelle QA (Contrast, Spacing) |
| `novamira-adrianv2/responsive-audit` | Breakpoint-Coverage prüfen |
| `novamira-adrianv2/variable-audit` | GV-ID Drift Detection |
| `novamira-adrianv2/class-audit` | Ungenutzte Global Classes finden |
| `novamira-adrianv2/page-audit` | SEO + Performance + A11y |

### 🖼️ Media

| Ability | Beschreibung |
|---------|-------------|
| `novamira-adrianv2/batch-media-upload` | 30 Dateien/Batch, 10MB/Datei |
| `novamira-adrianv2/media-upload` | Einzel-Upload |
| `novamira-adrianv2/list-media` | Media Library durchsuchen |

### 🧩 WPCode Snippets

| Ability | Beschreibung |
|---------|-------------|
| `novamira-adrianv2/list-wpcode-snippets` | Snippets auflisten (filterbar nach Status / code_type / location) |
| `novamira-adrianv2/get-wpcode-snippet` | Voller Snippet-Record inkl. `last_error`, schedule, conditions |
| `novamira-adrianv2/create-wpcode-snippet` | Neuen Snippet anlegen (Default: inaktiver Draft) |
| `novamira-adrianv2/update-wpcode-snippet` | Teil-Meng Update beliebiger Felder; respektiert WPCode Auto-Demote |
| `novamira-adrianv2/set-wpcode-snippet-status` | Aktiv/Inaktiv umschalten (zusätzlich `wpcode_activate_snippets`) |
| `novamira-adrianv2/duplicate-wpcode-snippet` | Snippet in neuen inaktiven Draft klonen |
| `novamira-adrianv2/delete-wpcode-snippet` | Snippet permanent löschen (destructive) |

---

## Installation

```bash
# 1. Plugin in WordPress installieren
ln -s /pfad/zu/novamira-adrianv2 wp-content/plugins/novamira-adrianv2
wp plugin activate novamira-adrianv2

# 2. Composer dependencies (Dev only)
cd wp-content/plugins/novamira-adrianv2
php composer.phar install

# 3. PHP CodeSniffer
./vendor/bin/phpcs --standard=phpcs.xml

# 4. Deployment nach solar.local
bash scripts/deploy-plugin.sh          # Incremental (nur geänderte Dateien)
bash scripts/deploy-plugin.sh --force   # Alle Dateien kopieren
bash scripts/deploy-plugin.sh --dry-run # Vorschau
```

---

## Test-Infrastruktur

### PHPUnit (52 Tests, 145 Assertions)

```bash
# Alle Tests
php composer.phar vendor/bin/phpunit
php composer.phar vendor/bin/phpunit --testdox   # Mit Test-Namen

# Einzelne Testklassen
php composer.phar vendor/bin/phpunit tests/V4PropsSchemaTest.php
php composer.phar vendor/bin/phpunit tests/V4ColorContrast22Test.php
php composer.phar vendor/bin/phpunit tests/SetupV4FoundationTest.php
```

**Test-Suiten:**

| Datei | Tests | Assertions | Gegenstand |
|-------|-------|------------|------------|
| `V4PropsSchemaTest.php` | 31 | 91 | REST-Endpoint `GET /novamira/v1/prop-schema` — Version, Typen, Properties, Edge Cases |
| `RestEndpointsTest.php` | 16 | 43 | REST-Endpoints `/health`, `/status`, `/version` (Sprint 13) |
| `V4ColorContrast22Test.php` | 16 | 49 | WCAG 2.2 — Target Size (2.5.8), Focus Appearance (2.4.11), Contrast Ratio |
| `SetupV4FoundationTest.php` | 5 | 5 | `setup-v4-foundation` Ability — Parameter-Validierung |

**Mock-Infrastruktur:** `tests/mock-functions.php` stellt 20+ WordPress-Funktionen bereit (`add_action`, `add_filter`, `get_option`, `wp_insert_post`, `register_rest_route`, etc.) — keine echte WordPress-Installation nötig.

**Konfiguration:** `phpunit.xml.dist` mit PHPUnit 10.5+, Bootstrap `tests/bootstrap.php`, Test-Suite `Novamira AdrianV2`.

### Pipeline CI (11 Jobs)

Alle Tests laufen in GitHub Actions (`framer-v4-pipeline-v2-main/.github/workflows/ci.yml`):

| Job | Typ | Befehl |
|-----|-----|--------|
| `test` | Node | `node --test tests/pipeline.test.js` (114 Tests) |
| `test-e2e` | Node | `node --check tests/e2e.test.js` (18 Tests) |
| `test-schema` | Node | `node --check scripts/validate-v4-tree.js` |
| `test-mcp-mock` | Node | `node tests/integration.test.js` |
| `test-visual` | Node | `node --check scripts/visual-qa.js` |
| `lint` | Node | `npm run lint:version` |
| `syntax` | Node | `node --check wizard.js scripts/**/*.js` |
| `phpunit` | PHP | `./vendor/bin/phpunit --testdox` (52 Tests) |
| `phpcs` | PHP | `./vendor/bin/phpcs --standard=phpcs.xml` |
| `psalm` | PHP | `./vendor/bin/psalm --no-progress` |
| `test-all` | Gate | Alle 10 Jobs müssen passen (main/master) |

**Gesamt:** 184 Tests (114 Pipeline + 18 E2E + 52 PHPUnit), 100% passing.

---

## Deployment

```bash
# Vom Projekt-Root aus:
bash novamira-adrianv2/scripts/deploy-plugin.sh

# Oder per npm-Script:
npm run deploy-plugin    # (aus framer-v4-pipeline-v2-main/)
```

Das Script kopiert geänderte Plugin-Dateien vom Projekt-Root (`novamira-adrianv2/`) nach `Local Sites/solar/app/public/wp-content/plugins/novamira-adrianv2/`. Es trackt den letzten Deployment-Zeitpunkt via `.deploy-marker`.

**Modi:**
- **Incremental** (default): Nur Dateien neuer als `.deploy-marker` kopieren
- **`--force`**: Alle 77 Plugin-Dateien kopieren
- **`--dry-run`**: Vorschau ohne Änderungen

---

## Entwicklung

```bash
# Linting
composer lint

# Auto-Fix
composer lint:fix

# Statische Analyse
composer analyze

# Tests
php composer.phar vendor/bin/phpunit
php composer.phar vendor/bin/phpunit --testdox   # Mit Test-Namen
```

### Coding Standards

- PHP 8.0+, WordPress Coding Standards
- Namespace: `Novamira\AdrianV2\{Helpers,Abilities\{...}}`
- Bootstrap-Pattern: `class_exists` Guard + `require_once` + `Adrians_Registry::register()`
- Tests: `#[CoversClass]` Attribute, PHPUnit 10.5+

---

## Distribution

Es gibt zwei Wege, das Plugin zu beziehen:

**Szenario 1 — WordPress-Admin-Upload (Standardfall):**
`git archive --format=zip HEAD --output=novamira-adrianv2.zip` erzeugt ein Dist-ZIP ohne vendor-lose Dev-Dateien (`.github/`, `tests/`, `scripts/`, `.claude/`). Das ZIP kann direkt unter *Plugins → Neu hinzufügen → ZIP hochladen* installiert werden.

```bash
git archive HEAD --format=zip --output=novamira-adrianv2.zip
# dann in WP-Admin hochladen, oder per WP-CLI:
wp plugin install novamira-adrianv2.zip --activate
```

**Szenario 2 — Composer (`composer/installers`):**
```bash
composer require adilinu94/novamira-adrianv2
```
`composer/installers` legt das Plugin automatisch unter `wp-content/plugins/novamira-adrianv2/` ab. Das `composer.lock` ist im Dist enthalten für reproduzierbare Builds.

---

## Abhängigkeiten

- **[Novamira](https://novamira.dev)** — MCP Server Basis-Plugin
- **[Elementor](https://elementor.com)** 4.x — Page Builder
- **[PHPUnit](https://phpunit.de)** 10.x (Dev) — Testing
- **[Psalm](https://psalm.dev)** (Dev) — Statische Analyse
- **[PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)** (Dev) — Coding Standards

---

## Changelog

Siehe [CHANGELOG.md](./CHANGELOG.md)
