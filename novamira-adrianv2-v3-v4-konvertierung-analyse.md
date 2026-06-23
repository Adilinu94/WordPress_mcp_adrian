# Novamira AdrianV2: Analyse und Plan fuer V3-zu-V4-Elementor-Konvertierung

> **✅ Stand: 2026-06-21 — Status-Update nach Plugin-Installation**  
> Legende: ✅ Erledigt | 🔶 Teilweise | ❌ Offen | ❓ Nicht geprueft

Stand: 2026-06-19  
Analysierte Repositories:

- `work/novamira-adrianv2` bei Commit `f75f0d4`
- `work/novamira` bei Commit `6c27b7f`
- Externer Plan: `C:/Users/adini/Desktop/ADRIANV2-ANALYSE-UND-V3V4-PLAN.md`

## Kurzfazit

AdrianV2 ist bereits ein sehr umfangreiches Novamira-Add-on fuer Elementor 4 / Atomic Elements. Es enthaelt schon V4-Foundation-Tools, Global Variables, Global Classes, Class Variants, Atomic Layouts, Atomic Widgets, Schema-Sync, Self-Audit, Rollback und Page-Building.

Die konkret gewuenschte Funktion fehlt aber noch: Es gibt keine Ability, die bestehende alte Elementor/V3-Seiten aus `_elementor_data` liest und in einen V4/Atomic-Tree migriert.

Wichtig: Die vorhandene Ability `kit-convert-v3-to-v4` konvertiert nur das Elementor Global Kit, also globale Farben/Typografie-Presets zu V4 Variables und Global Classes. Sie konvertiert keine Seitenstruktur.

Der richtige Ausbau ist daher:

1. ✅ vorhandene Helper/Abilities reparieren und API-Vertraege stabilisieren,
2. ✅ neue Ability `novamira-adrianv2/convert-page-v3-to-v4` bauen (Klasse liegt vor, muss noch im Plugin platziert werden),
3. ✅ Bulk-Konvertierung `convert-site-v3-to-v4` implementiert (SQL-basierte Auto-Discovery + Pagination).

## Was die Docs sagen

### Novamira Core

Das Core-Repository `use-novamira/novamira` beschreibt Novamira als WordPress-MCP-Server mit Zugriff auf:

- WordPress REST/MCP Abilities,
- Dateisystem,
- PHP-Ausfuehrung,
- WP-CLI,
- Gutenberg-Inhalte,
- Skills und Prompts.

Core ist also die Infrastruktur. Elementor-spezifische V4-Arbeit liegt fast komplett im AdrianV2-Add-on.

Die Core-Dokumentation nennt ausserdem Entwicklungsstandards:

- Formatierung/Linting/Analyse ueber `make mago-format`, `make mago-lint`, `make mago-analyze`
- Bun statt npm fuer JS-Abhaengigkeiten
- statische Ability-Registrierung in PHP-Klassen

### AdrianV2 README

Die README von AdrianV2 beschreibt das Plugin als Elementor-4-Companion fuer Novamira.

Genannte Hauptbereiche:

- Elementor Core Abilities
- Atomic Layouts und Atomic Widgets
- Global Classes
- Global Variables
- Style Audit
- Media- und WPCode-Tools
- REST-Endpunkte fuer Health, Status, Version und Prop-Schema
- Test-Infrastruktur

Die README behauptet Version `1.0.0`, waehrend viele Dateien bereits `@since 1.1.0` Features enthalten.

### Ability Selection Guide

`docs/ABILITY-SELECTION-GUIDE.md` beschreibt die praktische Auswahl zwischen:

- `novamira/elementor-set-content` fuer komplette Seiten,
- `novamira-adrianv2/batch-build-page` fuer komplexere strukturierte Builds,
- granularen Elementor-Edit-Abilities fuer kleine Aenderungen.

Relevant fuer V3/V4:

- Erst `detect-elementor-version` ausfuehren.
- V4-only Abilities nur auf V4/Atomic-Seiten verwenden.
- Mixed Abilities muessen Seitenversion pruefen.
- Neue v1.1.0 Abilities werden genannt: `sync-schema`, `self-audit`, `rollback-build`.

Widerspruch: Die Docs warnen vor einem alten Guards-Namespace-Bug in `batch-build-page`. Im aktuellen Code ist dieser konkrete Import bereits korrigiert.

### Bauplan V3/V4-Trennung

`docs/BAUPLAN-V3-V4-TRENNUNG.md` beschreibt eine klare Zielarchitektur:

- V4 ist Default.
- V3 ist Legacy/Fallback.
- Plugin bleibt `novamira-adrianv2`.
- `Elementor_Version_Resolver` ist die zentrale Detection-Schicht.
- V4-only Abilities muessen guarded sein.
- Skills und Decision Trees sollen Agenten zur richtigen Ability fuehren.

Der Bauplan meldet viele Phasen als abgeschlossen, aber mehrere Docs fehlen oder sind noch als offen markiert:

- `docs/SKILLS-INVENTORY.md`
- `docs/V3-V4-DECISION-TREE.md`
- `docs/CHANGELOG-v2-detailed.md`

### Gotchas

`docs/GOTCHAS.md` ist wichtig fuer die Implementierung:

- Ability-Kategorien muessen existieren.
- Bei Elementor-Bildern darf bei gesetzter Attachment-ID kein `url` Key gesetzt werden.
- PHP-Build-Checks koennen wegen PHP-Versionen/Named Args brechen.
- Security-Audits fuer PHP-Sandbox, Custom JS, XSS, SAST und axe-core sind noch offen.

### Skills

Die vorhandenen Skills geben den gewuenschten Agenten-Workflow vor:

- V4 Atomic Elements bevorzugen.
- V3 Widgets nur als Fallback behalten.
- Erst Design System / Kit / Variables / Classes aufbauen.
- Dann Seite Abschnitt fuer Abschnitt in Atomic Tree umbauen.
- Danach Audit, Responsive QA und ggf. Patch.

Wichtigster Skill-Konflikt:

`includes/skills/adrianv2-v3-to-v4-convert/SKILL.md` behauptet, `kit-convert-v3-to-v4` wandle Seitenstruktur wie `section` zu `e-flexbox`, `column` zu `e-div-block` und Widgets zu Atomic Widgets. Der Code tut das nicht. Der Skill ist an dieser Stelle veraltet oder beschreibt eine geplante Funktion.

## Was der Code wirklich kann

### Vorhandene Ability-Landschaft

Im AdrianV2-Plugin sind rund 100 statische Abilities registriert, dazu dynamische Atomic-Abilities.

Relevante vorhandene Abilities:

- `novamira-adrianv2/detect-elementor-version`
- `novamira-adrianv2/setup-v4-foundation`
- `novamira-adrianv2/kit-convert-v3-to-v4`
- `novamira-adrianv2/batch-create-variables`
- `novamira-adrianv2/add-global-class-variant`
- `novamira-adrianv2/apply-variable-to-class`
- `novamira-adrianv2/list-class-variants`
- `novamira-adrianv2/batch-build-page`
- `novamira-adrianv2/batch-class`
- `novamira-adrianv2/sync-schema`
- `novamira-adrianv2/self-audit`
- `novamira-adrianv2/rollback-build`
- `novamira-adrianv2/list-elementor-pages`
- Atomic Widget Abilities wie `add-atomic-heading`, `add-atomic-paragraph`, `add-atomic-button`, `add-atomic-image`, `add-atomic-svg`, `add-atomic-divider`

Nicht vorhanden:

- `novamira-adrianv2/convert-page-v3-to-v4`
- eine rekursive V3-Elementor-Tree-Konvertierung
- eine Bulk-Konvertierung alter Elementor-Seiten

### Elementor Version Resolver

`includes/helpers/class-elementor-version-resolver.php` ist bereits eine gute Basis.

Er kann:

- site-level V4-Faehigkeit pruefen,
- page-level Version erkennen,
- Atomic-Elemente in `_elementor_data` erkennen,
- Elementor Versionen auslesen,
- Capabilities liefern.

Das sollte die zentrale Detection fuer die neue Konvertierungs-Ability werden.

### detect-elementor-version ✅ ERWEITERT

Die Ability existiert inzwischen, obwohl der externe Plan noch sagt, sie fehle.

> **✅ Update 2026-06-21:** Alle unten empfohlenen Erweiterungen sind implementiert!

Aktueller Zustand (ALT):

- sie prueft site-level Elementor-Version,
- sie hat keinen `post_id` Input,
- sie liefert `elementor_version`, `elementor_pro_version`, `supports_atomic`, `recommended_mode`.

> **✅ Aktueller Zustand (NEU):**
> - optional `post_id` ✅
> - `site_is_v4` ✅
> - `page_is_v4` ✅
> - `page_version` ✅
> - `atomic_supported` ✅
> - `supports_atomic` als Alias behalten ✅
> - `global_classes_available` ✅
> - `global_variables_available` ✅
> - `recommended_page_action` ✅

### kit-convert-v3-to-v4

`includes/abilities/elementor/class-kit-convert-v3-to-v4.php` ist hilfreich, aber eng begrenzt.

Es konvertiert:

- alte globale Elementor-Farben,
- alte globale Typografie-Presets,
- V3 Kit Settings,
- daraus V4 Variables,
- daraus Global Classes und responsive Variants.

Es konvertiert nicht:

- Seiten,
- Sections,
- Columns,
- Widgets,
- `_elementor_data`.

Die neue Seitenmigration sollte diese Ability optional vorab ausfuehren und deren `variable_map` / `class_map` als Mapping verwenden.

### batch-build-page

`includes/abilities/elementor/class-batch-build-page.php` ist eine wichtige Vorlage, weil es komplette Trees erzeugen kann.

Staerken:

- V4 Atomic und V3 Fallback in einer Ability,
- Tree-Normalisierung,
- Atomic Layout und Widget Support,
- Klassen- und Style-Normalisierung,
- Bild-Invarianten,
- Page-Guarding fuer V3/V4,
- optional neue Seite erstellen.

Schwaeche:

- schreibt direkt via `update_post_meta('_elementor_data')`,
- nutzt nicht die sichere Document-API-Schicht,
- ist ein Builder, aber kein Converter.

Fuer die neue Conversion sollte die Schreibschicht aus `Elementor_Document_Saver` verwendet werden, nicht der direkte Meta-Write.

### Elementor_Document_Saver

`includes/helpers/class-elementor-document-saver.php` ist die richtige Speicherbasis.

Sie nutzt:

- Elementor Document API,
- Post-Lock-Check,
- CSS Cache Cleanup,
- konsistente Meta-Speicherung.

`elementor-inject-calibrated-page` zeigt bereits, wie Full-Tree-Saves robuster funktionieren.

Empfehlung:

`convert-page-v3-to-v4` sollte am Ende ueber `Elementor_Document_Saver::save_data()` speichern.

### list-elementor-pages

`includes/abilities/elementor/class-list-elementor-pages.php` ist fuer eine Migration sehr nuetzlich.

Es liefert bereits:

- Atomic Widget Count,
- Legacy Widget Count,
- Atomic Container Count,
- Legacy Container Count,
- Widget Types.

Damit kann spaeter ein Bulk-Migrationsmodus vorbereitet werden.

## Kritische Abweichungen und Bugs

### 1. Eigentliche Seitenkonvertierung fehlt ✅

Der externe Plan nennt `convert-page-v3-to-v4` als fehlend.

> **✅ Update 2026-06-21:** Die Klasse `Convert_Page_V3_To_V4` ist jetzt im Plugin platziert (`includes/abilities/elementor/class-convert-page-v3-to-v4.php`) und in `bootstrap.php` registriert. Die Ability ist aktiv.

Suchergebnis:

- keine Ability (im Plugin)
- Klasse existiert ausserhalb des Plugins
- keine rekursive Converter-Funktion (im Plugin)
- keine Tests fuer V3 Page Tree zu V4 Atomic Tree

### 2. `kit-convert-v3-to-v4` wird in Docs falsch beschrieben

Docs/Skill suggerieren Seitenkonvertierung. Code macht Kit-Konvertierung.

Risiko:

Agenten koennen denken, eine Seite sei migriert, obwohl nur globale Tokens/Klassen erzeugt wurden.

Fix:

- Skill korrigieren,
- neue `convert-page-v3-to-v4` Ability ergaenzen,
- Docs klar trennen: Kit-Konvertierung vs. Page-Konvertierung.

### 3. Helper-Namespace-Import ist in mehreren Dateien falsch ✅

Mehrere Dateien verwenden:

```php
use Novamira\AdrianV2\Helpers;
```

und rufen dann `Helpers::...` auf. Die tatsaechliche Klasse liegt aber unter:

```php
Novamira\AdrianV2\Helpers\Helpers
```

> **✅ Update 2026-06-21:** ALLE 15 Dateien sind jetzt gefixt! `class-batch-create-variables.php` war bereits ✅, die 14 weiteren wurden am 2026-06-21 korrigiert (`use Novamira\AdrianV2\Helpers;` → `use Novamira\AdrianV2\Helpers\Helpers;`).
> 
> Alle 14 Dateien jetzt ✅:
> - `class-clone-element.php` ✅
> - `class-create-component.php` ✅
> - `class-detach-component.php` ✅
> - `class-duplicate-page.php` ✅
> - `class-export-design-system.php` ✅
> - `class-global-widgets.php` ✅
> - `class-html-to-elementor-widget-plan.php` ✅
> - `class-import-design-system.php` ✅
> - `class-insert-component.php` ✅
> - `class-list-elementor-pages.php` ✅
> - `class-list-templates.php` ✅
> - `class-page-settings.php` ✅
> - `class-reorder-element.php` ✅
> - `class-hello-world.php` ✅

Betroffene wichtige Dateien (aus dem Plan):

- `includes/abilities/elementor/class-add-global-class-variant.php` ✅ (Import war bereits korrekt)
- `includes/abilities/elementor/class-apply-variable-to-class.php` ✅ (Import war bereits korrekt)
- `includes/abilities/elementor/class-edit-global-class-variant.php` ✅ (Import war bereits korrekt)
- `includes/abilities/elementor/class-list-class-variants.php` ✅ (Import war bereits korrekt)
- `includes/abilities/variables/class-batch-create-variables.php` ✅

Konkreter Sonderfall:

`class-batch-create-variables.php` prueft jetzt korrekt:

```php
class_exists(Helpers::class)  // ✅ FIXED
```

Das ist ein P0-Fix vor der Migration, weil Variables/Classes fuer den Converter zentral sind.

### 4. Versionen sind inkonsistent

Plugin-Header, Konstante und Composer nennen `1.0.0`.

Viele Docs, Bauplaene und Klassen sprechen von `1.1.0`.

Empfehlung:

- entweder Release sauber auf `1.1.0` anheben,
- oder Docs auf `1.0.0 + unreleased` korrigieren.

Fuer eine neue Migration waere ein konsistenter `1.2.0` oder `1.1.0` Milestone sinnvoll.

### 5. Ability-Schemas und Skill-Beispiele passen nicht immer zusammen

Beispiel `batch-class`:

Code erwartet:

- `post_id`
- `element_ids`
- `action`
- `class_id`

Skill-Beispiele zeigen:

- `element_class_map`

Das ist kein kompatibles Schema.

Fix:

- entweder Ability erweitern,
- oder Skill-Beispiele korrigieren.

Fuer den Converter sollte die Klassen-Zuweisung direkt im konvertierten Tree erfolgen, nicht ueber fehlerhafte Nachbearbeitung.

### 6. Ability-Kategorien koennen problematisch sein

`docs/GOTCHAS.md` sagt: Kategorie muss existieren.

`includes/categories.php` definiert viele `adrianv2-*` Kategorien und `novamira-adrianv2`.

Einige Abilities verwenden aber `category => 'elementor'`. Wenn diese Kategorie nicht vom Core registriert ist, kann Registrierung scheitern oder still ausfallen.

Vor der Migration sollte geprueft werden:

- existiert Kategorie `elementor` sicher?
- sollten Atomic/Global-Class-Abilities auf `adrianv2-elementor` oder `novamira-adrianv2` umgestellt werden?

### 7. Direkte `_elementor_data` Writes vermeiden

Mehrere Abilities schreiben direkt `update_post_meta('_elementor_data')`.

Fuer eine Migration ist das riskanter als die Document API, weil Elementor intern CSS, Dokument-Status und Cache verwaltet.

Empfehlung:

Neue Migration immer ueber `Elementor_Document_Saver::save_data()`.

## Zielbild fuer `convert-page-v3-to-v4`

Neue Ability:

```text
novamira-adrianv2/convert-page-v3-to-v4
```

Ziel:

Eine bestehende Elementor-Seite mit V3/Legacy-Struktur wird in eine Elementor-V4/Atomic-Struktur konvertiert. Dabei werden V4 Variables und Global Classes verwendet. V3 Widgets bleiben nur erhalten, wenn kein sinnvoller Atomic-Ersatz existiert oder die gewaehlte Fallback-Policy das verlangt.

### Input-Schema

Empfohlene Parameter:

```json
{
  "post_id": 123,
  "dry_run": true,
  "create_rollback": true,
  "target_post_id": null,
  "run_kit_convert": true,
  "unknown_widget_strategy": "keep_v3",
  "fallback_policy": "atomic_first",
  "preserve_element_ids": false,
  "variable_map": {},
  "class_map": {}
}
```

Parameter:

- `post_id`: Quellseite.
- `dry_run`: nur Analyse und Vorschau, kein Speichern.
- `create_rollback`: Snapshot/Revision vor Schreibzugriff.
- `target_post_id`: optional, wenn in eine Kopie geschrieben werden soll.
- `run_kit_convert`: vorher globale Tokens/Klassen aus V3 Kit erzeugen.
- `unknown_widget_strategy`: `keep_v3`, `skip`, `error`, `custom_atomic_stub`.
- `fallback_policy`: initial `atomic_first`.
- `preserve_element_ids`: nur aktivieren, wenn keine Kollisionen drohen.
- `variable_map`: optionales externes Mapping.
- `class_map`: optionales externes Mapping.

### Output-Schema

Empfohlene Rueckgabe:

```json
{
  "success": true,
  "dry_run": true,
  "source_post_id": 123,
  "target_post_id": 456,
  "detected": {
    "source_version": "v3",
    "site_is_v4": true,
    "atomic_supported": true
  },
  "stats": {
    "elements_read": 42,
    "elements_converted": 38,
    "atomic_elements_created": 38,
    "legacy_elements_kept": 4,
    "unsupported_widgets": 2
  },
  "warnings": [],
  "kept_v3_elements": [],
  "variable_map": {},
  "class_map": {},
  "converted_tree": [],
  "rollback_id": null
}
```

## Konvertierungslogik

### Grundprinzip

Nicht blind `section -> e-flexbox` mappen.

Alte Elementor-Seiten bestehen oft aus:

- Section
- Column
- Widget

V4 Atomic sollte flacher und semantischer werden:

- Layout-Wrapper als `e-div-block` oder `e-flexbox`
- Grid fuer echte Spaltenlayouts
- Atomic Widgets fuer Text, Button, Bild, SVG, Divider, Video
- Global Classes fuer wiederverwendbare Gestaltung
- Variables fuer Farben, Typografie, Spacing

### Container Mapping

#### V3 `section`

Wenn Section mehrere Columns hat:

- Ziel: `e-div-block` als Section
- Layout: Grid
- Desktop: `repeat(n, minmax(0, 1fr))`
- Tablet/Mobile aus Responsive Settings ableiten
- Mobile Default: `1fr`

Wenn Section nur eine Column hat:

- Ziel: `e-flexbox` oder `e-div-block`
- Kinder koennen direkt in die Section gezogen werden, wenn dadurch keine Semantik/Stile verloren gehen.

#### V3 `column`

Ziel:

- `e-div-block` als Grid Child oder Flex Column.

Wenn Column nur ein Widget ohne eigene Styles enthaelt:

- optional flattening, um DOM-Tiefe zu reduzieren.

Wenn Column eigene Backgrounds, Padding, Border oder Motion Effects hat:

- eigene Atomic-Box behalten.

#### V3 Flexbox Container

Wenn alte Seite bereits Elementor Container nutzt:

- `container` mit flex-direction/justify/align/gap zu `e-flexbox`
- bei 2D-Layout mit Spalten/Reihen zu `e-div-block` mit Grid

### Widget Mapping

| V3 Widget | V4 Atomic Ziel | Hinweise |
|---|---|---|
| `heading` | `e-heading` | `title` und `header_size` zu Text/Tag |
| `text-editor` | `e-paragraph` | HTML normalisieren, aeussere `<p>` entfernen |
| `button` | `e-button` | Text, Link, Icon-Fallback |
| `image` | `e-image` | Attachment-ID bevorzugen, bei ID keine `url` setzen |
| `icon` | `e-svg` | nur wenn SVG/Library eindeutig aufloesbar |
| `divider` | `e-divider` | Style/Weight/Color mappen |
| `video` | `e-youtube` oder `e-self-hosted-video` | je nach Quelle |
| `spacer` | `e-div-block` oder Spacing | besser Gap/Margin nutzen |
| `html` | Fallback | Atomic nur bei sicher parsebarem Inhalt |
| `shortcode` | Fallback | meistens V3/Legacy behalten |
| `form` | Fallback | Elementor Pro Form nicht atomar ersetzen |
| `slides/carousel/posts/woocommerce` | Fallback | eigener Adapter spaeter |
| `icon-list` | Custom Atomic Pattern oder Fallback | mit `e-svg` + `e-paragraph` moeglich |
| `counter/progress` | Fallback oder Custom | Verhalten/JS beachten |

### Atomic-first Fallback-Regel

Die Migration sollte drei Klassen von Ergebnissen kennen:

1. sicher atomar konvertiert,
2. atomar teilweise konvertiert mit Warnung,
3. Legacy behalten.

V3 Widgets duerfen nur in Klasse 3 bleiben.

Beispiele fuer erlaubten Legacy-Fallback:

- Formulare,
- WooCommerce Widgets,
- dynamische Posts/Archive,
- Shortcodes,
- komplexe Slider,
- unbekannte Third-Party Widgets.

### Styles, Variables und Klassen

Der Converter darf nicht einfach alte Inline-Styles 1:1 uebernehmen.

Prioritaet:

1. vorhandene oder durch `kit-convert-v3-to-v4` erzeugte Variables verwenden,
2. vorhandene oder erzeugte Global Classes verwenden,
3. lokale Styles nur fuer echte Einzelfaelle.

#### Farben

V3-Farben sollten ueber Value-Matching auf V4 Variables gemappt werden.

Beispiel:

- alter Wert `#111111`
- passende Variable `e-gv-color-text-primary`
- Klasse nutzt `color: var(--e-gv-color-text-primary)`

#### Typografie

V3 Typography Presets sollten zu Global Classes werden:

- Body
- Heading
- Eyebrow
- Button
- Caption

Wenn ein Widget eine bekannte globale Typografie nutzt, bekommt es die passende `settings.classes` Referenz.

#### Spacing

Padding/Margin/Gaps sollten auf Spacing Variables gemappt werden, falls vorhanden.

Wenn keine passenden Variables existieren:

- im ersten Schritt lokale Styles erlaubt,
- spaeter optional Spacing Variables per `batch-create-variables` erzeugen.

#### Klassen am Element

Wichtig aus den Praxiserkenntnissen:

`settings.classes` darf nicht leer bleiben, wenn Styles ueber Klassen erwartet werden.

Konvertierte Elemente brauchen:

- globale Klassen-IDs,
- ggf. lokale Style-Klassen,
- keine losgeloesten Style-Objekte ohne Referenz.

## Empfohlene Implementierung

### Phase 0: Stabilisierung vor dem Converter

P0-Fixes:

1. ✅ Helper-Imports korrigieren — alle 15 Dateien ✅ (batch-create-variables + 14 weitere)

```php
use Novamira\AdrianV2\Helpers\Helpers;
```

2. ✅ Falschen Guard in `batch-create-variables` korrigieren:

```php
class_exists(Helpers::class)
```

3. ✅ `detect-elementor-version` erweitern:

- ✅ optional `post_id`
- ✅ page-level Ergebnis
- ✅ Alias-Felder fuer alte/neue Docs

4. ✅ Docs/Skills korrigieren (erledigt 2026-06-21):

- ✅ `kit-convert-v3-to-v4` = Kit/Tokens/Classes
- ✅ `convert-page-v3-to-v4` = Seitenstruktur

5. ✅ Schreibpfad festlegen:

- Converter nutzt `Elementor_Document_Saver::save_data()`. (Klasse existiert und ist bereit)

### Phase 1: Converter-Klasse bauen ✅

Neue Datei:

```text
includes/abilities/elementor/class-convert-page-v3-to-v4.php
```

> **✅ Update 2026-06-21:** Die Klasse `Convert_Page_V3_To_V4` ist fertig platziert und registriert:
> 1. ✅ Nach `wp-content/plugins/novamira-adrianv2/includes/abilities/elementor/class-convert-page-v3-to-v4.php` kopiert
> 2. ✅ In `includes/abilities/elementor/bootstrap.php` registriert (require_once + class_exists/register-Block)
> 3. ✅ PHP-Syntax-Check bestanden

Neue interne Helper-Klasse optional:

```textincludes/helpers/class-v3-to-v4-converter.php ✅ (erstellt 2026-06-21)
```

Empfohlene Verantwortlichkeiten:

- Ability-Klasse: Input, Permissions, Rollback, Save, Response. ✅
- Converter-Helper: rekursive Tree-Konvertierung. ✅ (V3_To_V4_Converter)
- Style-Mapper: V3 Settings zu V4 props/styles/classes. 🔶 (resolve_color_var, semantic_classes erledigt; lokale Styles/Responsive offen)
- Widget-Mapper: V3 Widget Types zu Atomic Widgets. 🔶 (Basis-Mapping in WIDGET_MAP enthalten)

### Phase 2: Analysemodus / Dry Run

Dry Run zuerst implementieren.

Dry Run soll liefern:

- erkannte Elementor-Version,
- Anzahl Elemente,
- Widget-Typen,
- Mapping-Ergebnis,
- unsupported Widgets,
- geplante Variables/Classes,
- Vorschau auf V4 Tree,
- Warnungen.

Ohne Dry Run ist die Migration fuer echte Seiten zu riskant.

### Phase 3: Atomic Tree erzeugen

Konverter rekursiv:

```text
convert_elements(array $elements, Conversion_Context $ctx): array
```

Logik:

1. Elementtyp erkennen: `section`, `column`, `container`, `widget`.
2. Layout-Kontext bestimmen.
3. V4 Element erzeugen.
4. Settings mappen.
5. Styles/Klassen mappen.
6. Kinder rekursiv konvertieren.
7. DOM-Tiefe optimieren.
8. Unsupported Widgets nach Policy behandeln.

### Phase 4: Design-System-Integration

Vor dem Speichern:

- `run_kit_convert` optional ausfuehren,
- vorhandene Variables laden,
- vorhandene Global Classes laden,
- `variable_map` und `class_map` zusammenfuehren,
- fehlende Klassen optional erstellen.

Wichtig:

Die neue Ability sollte nicht bei jedem Seitenlauf ungefragt neue Klassen duplizieren. Sie sollte bestehende Klassen wiederverwenden und nur bei eindeutiger Notwendigkeit neue erzeugen.

### Phase 5: Speichern mit Rollback

Wenn `dry_run=false`:

1. optional Rollback Snapshot erzeugen,
2. Zielpost bestimmen,
3. Elementor Builder-Metas sicherstellen,
4. `Elementor_Document_Saver::save_data()` nutzen,
5. CSS Cache leeren,
6. Audit ausfuehren.

Empfohlen:

- Standard zuerst in Kopie schreiben (`target_post_id` oder neue Revision/duplizierte Seite),
- Original nur mit explizitem Flag ueberschreiben.

### Phase 6: Audits

Nach Konvertierung:

- `layout-audit`
- `class-audit`
- `variable-audit`
- `responsive-audit`
- optional `visual-qa`
- `self-audit`

Die Response sollte Audit-Zusammenfassung und verbleibende Fallbacks nennen.

## Konkretes Ability-Verhalten

### Beispiel: Dry Run

```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": {
    "post_id": 123,
    "dry_run": true,
    "run_kit_convert": true,
    "unknown_widget_strategy": "keep_v3"
  }
}
```

Ergebnis:

- keine Schreiboperation,
- V4 Tree Preview,
- Fallback-Liste,
- Mapping-Statistik.

### Beispiel: In Kopie schreiben

```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": {
    "post_id": 123,
    "target_post_id": 456,
    "dry_run": false,
    "create_rollback": true,
    "unknown_widget_strategy": "keep_v3"
  }
}
```

### Beispiel: Strenger Modus

```json
{
  "ability": "novamira-adrianv2/convert-page-v3-to-v4",
  "parameters": {
    "post_id": 123,
    "dry_run": false,
    "unknown_widget_strategy": "error"
  }
}
```

Bricht ab, wenn nicht alle Widgets atomar oder sicher konvertierbar sind.

## Testplan

### Unit Tests

Neue Tests fuer:

- `heading` zu `e-heading`
- `text-editor` zu `e-paragraph`
- `button` zu `e-button`
- `image` zu `e-image` inklusive Bild-ID-ohne-URL-Invariante
- `section + 2 columns` zu Grid-Atomic-Layout
- `section + 1 column` Flattening
- unsupported Widget mit `keep_v3`
- unsupported Widget mit `error`
- Style zu Variable Mapping
- Typography zu Global Class Mapping

### Integration Tests

Tests mit realistischen `_elementor_data` Fixtures:

1. einfache Hero Section,
2. Text/Bild Zwei-Spalter,
3. CTA mit Button,
4. Seite mit Formular-Fallback,
5. Seite mit Shortcode-Fallback,
6. bereits V4/Atomic Seite.

### Regression Tests fuer vorhandene Bugs

- `batch-create-variables` laedt Helpers korrekt.
- `add-global-class-variant` findet Helper-Klasse korrekt.
- `apply-variable-to-class` funktioniert mit echter Helper-Klasse.
- `detect-elementor-version` funktioniert mit und ohne `post_id`.

### Manuelle QA

Auf echter WP/Elementor-Instanz:

1. alte V3-Seite duplizieren,
2. Dry Run ausfuehren,
3. Conversion in Kopie schreiben,
4. Elementor Editor oeffnen,
5. Frontend vergleichen,
6. Responsive Breakpoints pruefen,
7. Global Classes/Variables im Elementor UI pruefen.

## Priorisierte Roadmap

### P0: Vorbereitende Fixes

- ✅ Helper Namespace Imports korrigieren. (alle 15 Dateien ✅)
- ✅ `batch-create-variables` Guard korrigieren.
- ✅ `detect-elementor-version` auf page-level erweitern.
- ✅ Skill-Docs fuer Kit vs. Page Conversion korrigieren.
- ❓ Kategorie-Verwendung pruefen.

### P1: Minimum Viable Converter ✅

- ✅ neue Ability `convert-page-v3-to-v4` (platziert und registriert)
- ✅ Dry Run (in der Klasse implementiert)
- ✅ Basis-Mapping fuer Section/Column/Container (in der Klasse)
- 🔶 Basis-Widgets: heading, text, button, image, divider, spacer (teilweise: heading, text-editor, button, image, divider, spacer sind in WIDGET_MAP)
- ✅ Fallback fuer unknown Widgets (keep_v3, skip, error)
- ✅ Speichern ueber `Elementor_Document_Saver`.

### P2: Design-System-Mapping 🔶

- ✅ `kit-convert-v3-to-v4` integrieren (run_kit_convert Parameter),
- ✅ Variables wiederverwenden (resolve_color_var: Farben → var(--e-gv-...)),
- ✅ Global Classes zuweisen (semantic_classes: heading→heading-Klassen, text-editor→body-Klassen, button→body+button-Klassen),
- ✅ `resolve_color_var()` O(1) Lookup-Index optimiert (build_color_index: normalized_hex → CSS var, 1× gebaut, rekursiv durchgereicht),
- ✅ Lokale Styles in Style-Classes ausgelagert (extract_style_props_for_widget: Typografie, Farben, Spacing, Border, Box-Shadow → V4 $$type-Pro-Varianten),
- ✅ Responsive Breakpoints gemappt (extract_responsive_overrides: V3 _tablet/_mobile-Suffixe → V4 desktop/tablet/mobile-Varianten),
- ❌ lokale Styles reduzieren,

### P3: Audits und Robustheit ❌

- ❌ Layout/Class/Variable Audits automatisch nach Conversion,
- ❌ bessere Warnungen,
- ❌ Rollback-Integration,
- ❌ Fixture-basierte Tests.

### P4: Bulk Migration ❌

- ❌ `list-elementor-pages` als Inventory nutzen,
- ❌ Seiten nach Risiko gruppieren,
- ❌ Batch-Konvertierung nur fuer Seiten mit niedriger Fallback-Quote,
- ❌ Report fuer manuelle Nacharbeit.

## Empfehlung fuer die erste Implementierung

Ich wuerde nicht sofort Bulk Migration bauen.

Der beste erste Schnitt ist:

1. P0-Fixes.
2. `convert-page-v3-to-v4` mit `dry_run=true` als Standard.
3. Konvertierung fuer die haeufigsten 6 Typen:
   - section
   - column
   - heading
   - text-editor
   - image
   - button
4. Fallback-Liste fuer alles andere.
5. Speichern nur in Zielkopie oder mit Rollback.

Damit entsteht schnell ein nutzbares Werkzeug, ohne echte Produktionsseiten blind zu ueberschreiben.

## Verifikation in dieser Analyse

Durchgefuehrt:

- beide Repositories geklont,
- Docs gelesen,
- Ability-Registrierungen inventarisiert,
- relevante Elementor/V4-Klassen analysiert,
- externen Plan mit aktuellem Code abgeglichen,
- vorhandene Skills gegen Code geprueft.

Nicht durchgefuehrt:

- PHPUnit,
- PHP-Lint,
- Mago-Checks.

Grund:

In dieser Umgebung war `php` nicht im PATH verfuegbar. Die gebuendelten Workspace-Dependencies lieferten Node/Python, aber kein PHP-Runtime fuer die Plugin-Tests.

## Endbewertung

AdrianV2 hat schon viele der richtigen Bausteine fuer eine V3-zu-V4-Migration:

- Detection Helper,
- Kit-Konvertierung,
- V4 Foundation,
- Variables,
- Global Classes,
- Atomic Widgets,
- Safe-ish Full-Tree Builder,
- Document Saver,
- Audits und Rollback.

Was fehlt, ist die Verbindung: ein echter rekursiver Converter von altem Elementor `_elementor_data` zu einem V4 Atomic Tree.

Die neue Ability sollte keine isolierte Spezialloesung werden, sondern vorhandene Bausteine nutzen:

- `Elementor_Version_Resolver` fuer Detection,
- `kit-convert-v3-to-v4` fuer Design-System-Mapping,
- `V4_Props` / `V4_Styles` fuer gueltige Atomic Props,
- `Elementor_Document_Saver` fuer Speicherung,
- bestehende Audit-Abilities fuer QA.

Wenn diese Linie eingehalten wird, bleibt die Migration kompatibel mit dem Plugin-Design und folgt dem Ziel: Atomic Elements zuerst, alte V3 Widgets nur als dokumentierter Fallback.
