# adrianv2-clonerlabs — ClonerLabs Import Skill

## Wann verwenden
Wenn der Nutzer eine ClonerLabs-Extension JSON-Datei importieren will, eine geklonte Webseite in Elementor bauen will, oder gespeicherte ClonerLabs-Abschnitte importieren möchte.

---

## Abilities (Übersicht)

| Ability | Zweck |
|---|---|
| `novamira-adrianv2/import-clonerlabs-page` | **Haupt-Import** — vollständiger Export oder Saved Section |
| `novamira-adrianv2/import-clonerlabs-batch` | Mehrere Exports auf einmal importieren |
| `novamira-adrianv2/import-clonerlabs-library` | Saved Sections Library importieren |
| `novamira-adrianv2/repair-clonerlabs-page` | Importierte Seite reparieren / auto-fixen |
| `novamira-adrianv2/convert-html-to-elementor` | Roh-HTML (Fallback-Widgets) konvertieren |

---

## Kritische Fakten — ClonerLabs Export-Format

### Vollständiger Page-Export hat diese Struktur:
```json
{
  "content": [...],          ← Element-Tree
  "settings": {              ← Globale Farben/Typo (NICHT "global_styles"!)
    "system_colors": [...],
    "custom_colors": [...],
    "system_typography": [...],
    "custom_typography": []
  },
  "page_settings": {},
  "media_library": { "svgs": [] },
  "version": "0.4",
  "type": "page"             ← "page" ist normal (nicht "container")
}
```

### Saved Section (chrome.storage.local) hat diese Struktur:
```json
{
  "id": "sec_xxx",
  "name": "Hero Section",
  "elementorData": {...},    ← Container-Objekt (NICHT "mappedElements"!)
  "isGridMode": false,
  "widgetCount": 12
}
```
→ Import-Ability erkennt das Format **automatisch** (kein manuelles Umwandeln nötig).

---

## Haupt-Import: `import-clonerlabs-page`

### Pflichtparameter:
```json
{
  "cloner_data": { ... }     // vollständiger Export oder Saved Section
}
```

### Wichtige optionale Parameter:
```json
{
  "target":              "v3",        // "v3" (Standard) oder "v4" (V4 Atomic)
  "v4_strategy":         "keep_v3",  // "keep_v3" | "skip" | "error"  (NICHT "keep" oder "html"!)
  "status":              "draft",    // "draft" | "publish" | "private"
  "title":               "Meine Seite",
  "upload_media":        true,       // SVGs + externe Bilder sideloaden
  "apply_global_styles": true,       // Farben/Typo in Kit mergen
  "cleanup_styles":      true,       // getComputedStyle-Rauschen entfernen
  "regenerate_ids":      true,       // Element-IDs neu generieren (Kollisions-Prävention)
  "create_template":     false,      // auch als Elementor Library Template speichern
  "post_id":             0           // vorhandene Seite überschreiben (0 = neue erstellen)
}
```

### Typischer Ablauf:
```json
// Schritt 1: Seite als Draft importieren
{
  "ability": "novamira-adrianv2/import-clonerlabs-page",
  "input": {
    "cloner_data": <export_json>,
    "title": "Geklonte Seite",
    "target": "v3",
    "status": "draft",
    "upload_media": true,
    "apply_global_styles": true
  }
}

// Schritt 2: Bei Bedarf reparieren
{
  "ability": "novamira-adrianv2/repair-clonerlabs-page",
  "input": {
    "page_id": <post_id aus Schritt 1>,
    "dry_run": false
  }
}
```

---

## Widget-Typen — Bekannte Besonderheiten

| Widget | Elementor widgetType | V4? | Hinweis |
|---|---|---|---|
| Container | *(elType: container)* | ✅ | Normal |
| heading | `heading` | ✅→`e-heading` | |
| text-editor | `text-editor` | ✅→`e-paragraph` | |
| image | `image` | ✅→`e-image` | `settings.image.id` immer 0, URL wird sideloaded |
| button | `button` | ✅→`e-button` | |
| divider | `divider` | ✅→`e-divider` | |
| icon | `icon` | ❌ V3 only | **Platzhalter-Stern** — muss manuell gewählt werden |
| e-svg | `e-svg` | ✅ | `svg_content` → automatisch sideloaded + V4-Format |
| **accordion** | **`nested-accordion`** | ✅ nativ V4 | Hat `isLocked` Kind-Container → werden nicht angefasst |
| custom-widget | `html` | – | Wird automatisch zu `html` Widget normalisiert |
| icon-list | `icon-list` | ❌ V3 only | |
| image-carousel | `image-carousel` | ❌ V3 only | |

---

## Was der Import-Report enthält

```json
{
  "success": true,
  "post_id": 123,
  "permalink": "https://...",
  "edit_url": "https://.../wp-admin/post.php?post=123&action=elementor",
  "stats": {
    "total_elements": 45,
    "media_uploaded": 8,
    "media_replaced": 12,
    "global_colors_applied": 6,
    "global_typography_applied": 3,
    "styles_cleaned": 340,
    "ids_regenerated": 45,
    "gsap_scripts_collected": 2,
    "icon_placeholders": 3
  },
  "warnings": [
    "Icon widgets (3) contain placeholder star icons — manual selection required",
    "Font 'Inter' used but not in kit typography"
  ],
  "manual_adjustments": [...],
  "summary": "Page 'Geklonte Seite' (#123) created with 45 elements (V3). 8 media files uploaded."
}
```

---

## Batch-Import: `import-clonerlabs-batch`

```json
{
  "exports": [
    { "cloner_data": {...}, "title": "Seite 1" },
    { "cloner_data": {...}, "title": "Seite 2" },
    { "cloner_data": {...}, "title": "Seite 3" }
  ],
  "target": "v3",
  "status": "draft",
  "apply_global_styles": false,  // Im Batch standardmäßig false
  "batch_name": "Website-Import 2025"
}
```
→ Erstellt automatisch Rollback-Snapshot (`snapshot_id` im Response).

---

## Library-Import: `import-clonerlabs-library`

```json
{
  "library_data": {
    "sections": [
      {
        "id": "sec_abc",
        "name": "Hero",
        "elementorData": { ... },
        "isGridMode": false
      }
    ]
  },
  "target": "v3",
  "filter_names": ["Hero", "Features"]  // optional: nur diese importieren
}
```
→ Jeder Abschnitt → Elementor Library Template.

---

## Fehlerquellen / Warnsignale

- **`icon` Widgets** → immer Platzhalter-Stern, manuell nachbearbeiten
- **GSAP-Animationen** (`_gsapCode`) → werden als Custom JS in Page-Settings injiziert, müssen getestet werden
- **`nested-accordion`** → V4-nativ, braucht keinen Converter-Durchlauf
- **Google Fonts** → werden in Warnings gelistet wenn nicht im Kit; `apply_global_styles: true` schreibt sie ins Kit
- **Externe Bilder** → nur mit `upload_media: true` sideloaded; ohne bleiben externe URLs

---

## Session-Start

Kein `adrians-setup-v4-foundation` nötig für ClonerLabs-Import (V3-Modus).
Nur bei `target: "v4"`: zuerst `novamira-adrianv2/setup-v4-foundation` ausführen um stabile Variable-IDs zu haben.
