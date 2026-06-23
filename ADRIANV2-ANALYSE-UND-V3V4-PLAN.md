# novamira-adrianv2 — Plugin-Analyse & V3→V4-Konvertierungs-Konzept

> **Stand:** 2026-06-21 — Aktualisiert nach Codebase-Review  
> **Plugin-Version:** 1.0.0 (public)  
> **Ziel:** Vollständiger Überblick über implementierte Features & verbleibende Lücken

---

## 1. Was Novamira macht — Architekturüberblick

Das **Novamira Core Plugin** (`use-novamira/novamira`) ist der MCP-Server-Kern: er gibt KI-Agenten über die WordPress Abilities API + MCP Adapter vollständigen Zugriff auf eine WP-Installation (PHP-Ausführung, Filesystem, WP-CLI-Brücke). Er registriert sich als `novamira/*`-Namespace.

Das **AdrianV2-Plugin** ist das eigentliche Arbeitstier darüber: es liefert 120+ spezialisierte Abilities im Namespace `novamira-adrianv2/*`, primär für Elementor V4 Atomic, Media, Audit, SEO, WPCode und mehr.

```
Claude/KI-Agent
    │
    ▼
MCP Adapter (Novamira Core) ──── wp_abilities_api
    │
    ├── novamira/*          (Core: execute-php, run-wp-cli, filesystem …)
    │
    └── novamira-adrianv2/* (AdrianV2: Elementor, Media, Audit, Variables …)
                │
                ├── includes/helpers/    ← 24 geteilte Helfer
                ├── includes/abilities/  ← 18 Sub-Domains, 120+ Abilities
                └── includes/skills/    ← 9 SKILL.md-Dateien (Wissen für den Agenten)
```

---

## 2. ✅ Komplett umgesetzt — Was hervorragend funktioniert

### 2.1 V4-Infrastruktur

`Elementor_Version_Resolver` — Single-Source-of-Truth für V3/V4-Erkennung per Page und site-wide. `V4_Props` deckt alle Prop-Typen ab (`string`, `size`, `color`, `image-attachment-id` etc.) und behandelt Invariante IV (kein `url`-Key wenn `id` gesetzt ist) korrekt.

### 2.2 ✅ Kit-Konvertierung (Phase 1–4) — `kit-convert-v3-to-v4`

- Phase 1: V3-Farben → V4 Color Variables (skip/overwrite/rename)
- Phase 2: V3-Schriften → V4 Font- und Size-Variables (dedupliziert, semantische Benennung)
- Phase 3: V3-Typography-Presets → V4 Global Classes (mit `$$type`-Wrapping)
- Phase 4: Responsive Varianten (tablet/mobile) auf die neuen Global Classes

### 2.3 ✅ Page-Level V3→V4-Konvertierung — `convert-page-v3-to-v4`

**VOLLSTÄNDIG IMPLEMENTIERT** (entgegen älterer Analyse). Die Ability in `class-convert-page-v3-to-v4.php` (324 Zeilen):

- Liest `_elementor_data` von einem beliebigen Post
- Unterstützt `dry_run`, `target_post_id`, `run_kit_convert` (optionaler Kit-Vorkonvertierung)
- Delegiert die Baum-Konvertierung an `V3_To_V4_Converter` (stateless, rekursiv)
- Nutzt `variable_map` + `semantic_classes` für Design-System-Bindung
- Baut `semantic_classes` aus Kit-Result (heading/body/button → Global Class IDs)
- Unterstützt `unknown_widget_strategy`: `keep_v3` | `skip` | `error`
- Post-Conversion: `Conversion_Auditor::audit()` + optional `Conversion_AutoFixer::run()`
- Sicherer Write via `Elementor_Document_Saver::save_data()`
- Backup der V3-Daten in `_novamira_v3_backup`
- Ausführliches Output-Schema mit Stats, Warnings, Audit-Issues

**`V3_To_V4_Converter`** — der eigentliche Konvertierungs-Motor. Enthält:

- **Container-Mapping:** `section` → `e-flexbox`, `column` → `e-div-block`, `container` → `e-flexbox`
- **Widget-Mapping:**
  | V3 | V4 | Status |
  |---|---|---|
  | `heading` | `e-heading` | ✅ |
  | `text-editor` | `e-paragraph` | ✅ |
  | `button` | `e-button` | ✅ |
  | `image` | `e-image` | ✅ |
  | `spacer` | `e-div-block` (padding) | ✅ |
  | `divider` | `e-divider` | ✅ |
  | `icon` | `e-svg` | ✅ |
  | `video` | `e-youtube` / `e-self-hosted-video` (via type-dispatch) | ✅ Implementiert |
  | `youtube` | → Typ-Feld in `video`-Widget, kein eigener V3-Widget-Typ | ✅ via `video` |
  | `icon-box` | — | ❌ kein V4-Äquivalent, bleibt V3 |
  | `image-box` | — | ❌ kein V4-Äquivalent, bleibt V3 |
- **Style-Migration:** V3 `settings` → V4 `styles` mit `$$type`-Wrapping (color, font-family, font-size, font-weight, background, border, margin, padding, box-shadow)
- **Color-Index:** Baut aus `variable_map` eine `normalized_hex → var(--id)` Lookup — wird nur einmal pro Conversion aufgebaut
- **Semantic Classes:** Heading → heading[], Text/Button → body[], Button → button[] (gemerged)
- **Responsive Overrides:** Extrahiert `_tablet`/`_mobile`-Suffix-Keys aus V3-Settings
- **Global-Color-Resolution:** Löst `__globals__` Referenzen zu Hex-Werten auf

### 2.4 ✅ `detect-elementor-version`

**IMPLEMENTIERT** in `class-atomic-layouts.php`. Gibt site-level V4-Capabilities + page-level Detection zurück.

### 2.5 ✅ `elementor-check-setup`

**IMPLEMENTIERT** in `class-elementor-check-setup.php` (262 Zeilen). Probt:
- Elementor-Version + Pro-Status
- Active Kit ID + Label
- V3/V4-Mode (über Version + Resolver)
- Global Classes + Design Tokens Count
- Current-User Permissions
- Issues-Liste (Probleme)

### 2.6 WPCode-Integration — 8 Abilities

`wpcode-check-setup`, `list-wpcode-snippets`, `get-wpcode-snippet`, `create-wpcode-snippet`, `update-wpcode-snippet`, `set-wpcode-snippet-status`, `duplicate-wpcode-snippet`, `delete-wpcode-snippet`. Schreibt korrekt über `WPCode_Snippet::save()`. `bypass_kses` mit `try/finally` korrekt implementiert.

### 2.7 Rollback + Inject-Calibrated-Page

`elementor-inject-calibrated-page` — routet über `Elementor_Document_Saver::save_data()`, prüft `wp_check_post_lock`, seed boot-Meta, unterstützt `merge_by_id` (DFS-Rekursion).

### 2.8 Tests — 67 PHPUnit-Tests

- **V3ToV4ConverterTest** (39 Tests) — heading-minimal, heading-mit-header_size, typography-und-color-styles, text-editor-basic, button-basic, image-mit-attachment-id, url-fallback, section-mit-padding, background, border, nested-children, spacer-default/custom/responsive, unknown-widget-keep/skip, responsive-overrides, color-index-resolution, semantic-classes, widget-map-keys, mixed-types, non-array-skipping
- **ConversionAuditorTest** (28 Tests) — empty-tree, empty-container-flag, missing-heading/button/image, deeply-nested-flag, dangling-class-reference, orphan-style, duplicate-style, responsive-missing-variant, identical-override, filter-by-type/severity, combined-filters, komplexe Baum-Audits

### 2.9 Weitere implementierte Abilities (Auswahl)

- **Audit:** `audit-page`, `audit-class`, `audit-layout`, `audit-responsive`, `audit-variable`, `audit-visual-qa`
- **A11Y:** `audit-page-a11y`, `fix-color-contrast`, `add-alt-text-from-context`
- **Design-Audit:** `evaluate-design`, `score-distinctiveness`, `suggest-design-fixes`
- **Design-Utilities:** 12 Reparatur-Abilities (zero-container-padding, fix-gap-rhythm, etc.)
- **Atomic Widgets:** 10 Convenience-Abilities (add-atomic-heading, add-atomic-paragraph, add-atomic-image, add-atomic-svg, etc.)
- **Atomic Layouts:** `add-flexbox`, `add-div-block`
- **Global Classes:** `list-global-classes`, `get-global-class`, `add-global-class-variant`, `apply-variable-to-class`, `edit-global-class-variant`, `list-class-variants`, `remove-global-class`
- **Templates:** 9 Abilities (CRUD + duplicate/import/export/restore/empty-trash)
- **Media:** 7 Abilities (upload, batch-upload, delete, edit, featured-image, list, media-usage)
- **Custom Code:** `add-custom-css`, `add-custom-js`, `add-site-wide-code`
- **SEO:** `audit-page-seo`, `extract-keywords-from-content`, `generate-meta-tags` (mit `apply:true` → schreibt in Yoast/Rank Math via `V4_Seo_Meta::write()`), `generate-schema-markup` (mit `apply:true` → inject als HTML-Widget)
- **PHP Sandbox:** 6 Abilities (validate, create, update, get, list, delete)
- **Elementor Pro:** `list-custom-code`, `get/update/delete/form-submissions`, `manage-display-conditions`
- **V4 Management:** `sync-schema`, `rollback-build`, `batch-create-variables`
- **Utilities:** `greet`, `self-audit`, `get-project-styles`, `execute-build-plan`
- **Batch:** `batch-build-page`, `batch-class`, `batch-get-content`, `batch-media-upload`

### 2.10 ✅ Local_Styles_Renderer — Frontend-CSS-Workaround (Elementor 4.1.x)

**Implementiert in:** `includes/helpers/class-local-styles-renderer.php` (v1.2.0)

**Problem:** Elementor 4.1.x feuert den Hook `elementor/atomic-widgets/styles/register` im Frontend nicht. Die Atomic Widget CSS Pipeline läuft daher nie, alle lokalen Style-Klassen (`.e-{style_id}`) haben kein CSS-Backing.

**Lösung:** `Local_Styles_Renderer` hookt sich in `wp_head` (Priorität 100), liest `_elementor_data` direkt via `$wpdb` aus der DB (kein `wp_unslash`-Problem), durchläuft den Element-Tree rekursiv, sammelt alle `element.styles`-Maps und emittiert einen inline `<style id="novamira-atomic-styles">`-Block.

**`prop_to_css()` Mapping:**

| `$$type` | CSS-Wert |
|---|---|
| `global-color-variable` | `var(--e-gv-{id})` |
| `color` | `#HEX` / `rgba()` / `hsl()` |
| `size` | `{n}{unit}` z.B. `16px` |
| `dimensions` | expandiert zu `padding-block-start` etc. |
| `string` | roher Wert (font-family, flex-direction …) |
| `number` | roher Zahlenwert (z-index, flex-grow …) |

**Responsive:** `desktop` = kein Media-Query, `tablet` = `max-width: 1024px`, `mobile` = `max-width: 767px`.

**Selector-Format:** `.{style_id}` wenn style_id bereits `e-` Prefix hat; sonst `.e-{style_id}`.

**Disable Gate:** Automatische Deaktivierung bei `ELEMENTOR_VERSION >= 4.2.0`. Außerdem per Filter override-bar:
```php
add_filter('novamira_adrianv2/local_styles_renderer/enabled', '__return_false');
```


---

## 3. ❌ Noch zu tun — Verbleibende Lücken

### 3.1 ✅ ERLEDIGT — Phase 4 Dokumentation

Dokumentations-Dateien existieren im Projekt-Root:
- `SKILLS-INVENTORY.md` ✅
- `V3-V4-DECISION-TREE.md` ✅
- `CHANGELOG-v2-detailed.md` ✅
- `atomic-css-pipeline.md` ✅ (neu)
- `BAUPLAN-OFFENE-PUNKTE.md` ✅ (neu)

### 3.2 ✅ ERLEDIGT — CI/CD

`.github/workflows/` enthält `phpunit.yml`, `phpcs.yml`, `psalm.yml`, `release.yml`.

### 3.3 🟠 Offene Security-Findings

| Finding | Status |
|---|---|
| PHP-Sandbox Audit (B8) | ⚠️ Nicht auditiert |
| XSS via `add-custom-js` (B9) | ⚠️ Nicht auditiert |
| SAST-Integration (`psalm --taint-analysis`) | ⚠️ Offen |
| axe-core in Visual-QA | ⚠️ Offen |

### 3.4 🟠 `check-setup`-Pattern nicht vollständig ausgerollt

Nur für WPCode (`wpcode-check-setup`) + Elementor (`elementor-check-setup`) vorhanden. Fehlt für:
- AIOSEO
- Yoast
- Rank Math
- WooCommerce

### 3.5 🟠 SEO-Mutations-Abilities

`generate-meta-tags` mit `apply:true` schreibt via `V4_Seo_Meta::write()` in Yoast/Rank Math — das **deckt** den Use-Case teilweise ab. Es gibt aber keine dedizierten `set-rank-math-meta` / `set-aioseo-meta` Abilities.

### 3.6 🟡 `class-v4-color-contrast-22.php` deprecated

Sauber als `extends V4_Color_Contrast` markiert, liefert aber keine eigenen Werte mehr. Könnte entfernt oder mit `_deprecated_file()` versehen werden.

### 3.7 ✅ ERLEDIGT — Bulk-Konvertierung `convert-site-v3-to-v4`

Implementiert in `includes/abilities/v4-management/`. Nutzt SQL-basierte Auto-Discovery mit Pagination.

### 3.8 🟡 `design-token-remap` Ability

Nicht implementiert. Scannt alle Seiten nach alten GV-Referenzen und aktualisiert sie bei Rebranding.

### 3.9 🟡 Widget-Mapping-Lücken im `V3_To_V4_Converter`

- `youtube` → kein V4-Mapping (wird als `kept_v3` behandelt)
- `video` → auf `null` gesetzt (kein V4-Äquivalent)
- `icon` → `e-svg` funktioniert, aber nur für svg/icon_class

---

## 4. Relevanz des Novamira Core

Das Core-Plugin (`use-novamira/novamira`) stellt die MCP-Infrastruktur:

```
execute-php, run-wp-cli, read-file, write-file, edit-file, list-directory, create-upload-link
```

Sowie Gutenberg-Batch-Editing (`add-pending-change`, `create-pending-batch`, `enable-batch-finalization`). AdrianV2 nutzt das nicht ("Gutenberg brauche ich nicht") und hat bewusst alle eigenen Abilities als echte PHP-Klassen gebaut, nicht als `execute-php`-Escape-Hatches.

---

## 5. Übersicht: `convert-page-v3-to-v4` — Bestehende Spezifikation

> Diese Ability ist **bereits implementiert** (siehe Section 2.3). Nachfolgend die aktuelle Dokumentation.

### 5.1 Design-Philosophie

**Atomic-first, V3-Fallback:** Jedes Widget wird in das V4-Atomic-Äquivalent konvertiert. Nur wenn kein V4-Äquivalent existiert, bleibt das V3-Widget mit Warning erhalten.

**Nicht-destruktiv:** Backup in `_novamira_v3_backup` + optionaler Kit-Convert vorab.

**Design-System-Integration:** Nutzt `variable_map` + `semantic_classes` aus `kit-convert-v3-to-v4`.

### 5.2 Widget-Mapping (implementiert)

| V3 | V4 | Status |
|---|---|---|
| `section` | `e-flexbox` | ✅ |
| `column` | `e-div-block` | ✅ |
| `container` | `e-flexbox` | ✅ |
| `heading` | `e-heading` | ✅ |
| `text-editor` | `e-paragraph` | ✅ |
| `button` | `e-button` | ✅ |
| `image` | `e-image` | ✅ (Invariante IV beachtet) |
| `spacer` | `e-div-block` (padding) | ✅ |
| `divider` | `e-divider` | ✅ |
| `icon` | `e-svg` | ✅ |
| `video` | `e-youtube` / `e-self-hosted-video` | ✅ Implementiert |
| `youtube` | Kein eigener V3-Typ — wird via `video.video_type` = youtube behandelt | ✅ |
| `icon-box` | — | ⚠️ bleibt V3 |
| `image-box` | — | ⚠️ bleibt V3 |

### 5.3 Style-Migration (implementiert)

```
V3 settings.color              → styles.color   {$$type:'color', value:'#HEX'} / {$$type:'global-color-variable', value:'var(--e-gv-…)'}
V3 settings.typography_*       → styles.font-*  {$$type:'string'|'size', …}
V3 settings._margin/_padding   → styles.margin/padding {$$type:'dimensions', block-start/end/inline-start/end}
V3 settings.background_color   → styles.background-color
V3 settings.border_*           → styles.border-*
V3 settings.text_align         → styles.text-align
```

**Responsive Breakpoints:** Extrahiert `_tablet`/`_mobile`-Keys → landen als responsive Overrides.

### 5.4 Input-Schema

| Parameter | Typ | Default | Beschreibung |
|---|---|---|---|
| `post_id` | integer | — | **Pflicht.** Die V3-Seite |
| `dry_run` | boolean | `true` | Preview ohne Schreiben |
| `target_post_id` | integer | `null` | In anderen Post schreiben statt Überschreiben |
| `unknown_widget_strategy` | string | `keep_v3` | `keep_v3` / `skip` / `error` |
| `run_kit_convert` | boolean | `false` | Kit vorab konvertieren |
| `auto_fix` | boolean | `false` | Audit-Issues automatisch fixen |

### 5.5 Output-Schema

```json
{
  "success": true,
  "dry_run": true,
  "source_post_id": 123,
  "target_post_id": null,
  "stats": {
    "elements_read": 42,
    "converted": 38,
    "kept_v3": 3,
    "skipped": 1,
    "unsupported_widgets": ["icon-box"]
  },
  "warnings": ["..."],
  "audit": {
    "total_issues": 5,
    "by_severity": { "error": 1, "warning": 3, "info": 1 },
    "by_type": { "layout": 2, "class": 1, "responsive": 2 },
    "issues": [...]
  },
  "auto_fix": false,
  "fixes_applied": 0,
  "run_kit_convert": false,
  "kit": { "variable_map": {}, "semantic_classes": {}, "class_map": {} }
}
```

### 5.6 Workflow (Agenten)

```
Schritt 1: Backup + Pre-Audit
  → elementor-inject-calibrated-page oder manuelles Backup
  → audit-page / audit-layout (Score festhalten)

Schritt 2: Design-System migrieren (optional, via run_kit_convert)
  → convert-page-v3-to-v4 { post_id, run_kit_convert: true, dry_run: false }

Schritt 3: Seite konvertieren
  → convert-page-v3-to-v4 { post_id, dry_run: true }
  → Warnings + Audit prüfen
  → convert-page-v3-to-v4 { post_id, dry_run: false }

Schritt 4: V4 Foundation
  → setup-v4-foundation {}

Schritt 5: Global Classes zuweisen
  → batch-class { post_id, element_class_map: { ... } }

Schritt 6: Post-Conversion Audits
  → audit-layout / audit-class / audit-variable / visual-qa
```

---

## 6. Prioritätenliste (aktuell)

| # | Aufgabe | Aufwand | Wert | Status |
|---|---|---|---|---|
| 1 | `convert-page-v3-to-v4` | L (3–5 Tage) | ⭐⭐⭐⭐⭐ | ✅ **Erledigt** |
| 1b | `Local_Styles_Renderer` — Frontend-CSS-Workaround für Elementor 4.1.x | S (2h) | ⭐⭐⭐⭐⭐ | ✅ **Erledigt** |
| 2 | `detect-elementor-version` | S (1h) | ⭐⭐⭐⭐ | ✅ **Erledigt** |
| 3 | `elementor-check-setup` | M (2–3h) | ⭐⭐⭐⭐ | ✅ **Erledigt** |
| 4 | Phase 4 Docs (SKILLS-INVENTORY, V3-V4-DECISION-TREE, CHANGELOG) | M (2–3h) | ⭐⭐⭐ | ✅ **Erledigt** |
| 5 | GitHub Actions CI | M (2h) | ⭐⭐⭐ | ✅ **Erledigt** |
| 6 | Security-Findings (B8, B9, SAST, axe-core) | M (pro Finding) | ⭐⭐⭐ | ⚠️ Offen |
| 7 | `check-setup` für AIOSEO/Yoast/Rank Math/WooCommerce | M (pro Plugin) | ⭐⭐ | ⚠️ Offen |
| 8 | `convert-site-v3-to-v4` (Bulk) | L (2–3 Tage) | ⭐⭐ | ✅ **Erledigt** |
| 9 | SEO-Mutation-Abilities (set-rank-math-meta) | M | ⭐⭐ | ⚠️ Teilweise via generate-meta-tags |
| 10 | `design-token-remap` | XL | ⭐⭐ | ❌ **Offen** |
| 11 | Widget-Mapping-Lücken (icon-box, image-box) | S | ⭐ | ⚠️ Offen — video/youtube ✅ via if-blocks |
| 12 | `class-v4-color-contrast-22.php` cleanup | S | ⭐ | ⚠️ Offen |

---

*Dokument erstellt durch Codebase-Review des Plugins am 2026-06-21. Alle Aussagen basieren auf tatsächlichem Source-Code, nicht auf Planungsdokumenten.*
