---
name: adrianv2-site-clone-import
description: Handoff-Guide for deploying site-clone-to-v3 TypeScript pipeline output to WordPress via novamira. Use when receiving V3 JSON tree or dryrun-page-v4.json from the site-clone-to-v3 Node.js CLI, to push the cloned page, handle media, and run repair/audit after import.
---

# AdrianV2 Skill: site-clone-to-v3 → WordPress Deploy

> **Plugin:** novamira-adrianv2
> **Elementor-Welt:** V3 (primary) oder V4 (via dryrun-page-v4.json + V4-Pipeline)
> **Required Abilities:** `novamira/elementor-set-content`, `novamira-adrianv2/repair-clonerlabs-page`, `novamira-adrianv2/batch-media-upload`, `novamira-adrianv2/layout-audit`, `novamira-adrianv2/clear-cache`

## Wann aktivieren

- Der Agent empfängt Output aus dem `site-clone-to-v3` TypeScript/Node.js CLI
- Eine geklonte Seite (`cloned-page-v3.json`) soll in Elementor V3 deployed werden
- Ein `dryrun-page-v4.json` soll in die V4-Pipeline weitergereicht werden
- Der User sagt: "deploy den Clone", "klone diese Seite nach Elementor", "site-clone Output pushen"

## Output-Formate von site-clone-to-v3

```
research/
  tokens.json          ← Extrahierte Design-Tokens
  dom-snapshot.json    ← DOM-Struktur
cloned-page-v3.json    ← Elementor V3 JSON (deployment-ready)
dryrun-page-v4.json    ← V4-Intermediate-Tree (für V4-Pipeline)
state.json             ← Pipeline-State (für --resume)
```

## V3-Deployment-Ablauf (Standardfall)

### Schritt 1: Seite anlegen
```json
{
  "ability": "novamira/create-post",
  "parameters": { "post_type": "page", "title": "<Klon-Titel>", "status": "draft" }
}
```

### Schritt 2: Medien hochladen (ZUERST, vor dem Tree-Push)
Kritisch: Im geklonten V3-JSON sind externe URLs — diese müssen zuerst in die WP-Mediathek.
```json
{
  "ability": "novamira-adrianv2/batch-media-upload",
  "parameters": {
    "urls": ["https://original-site.com/image1.jpg", "..."],
    "return_id_map": true
  }
}
```
→ Gibt `{ "https://original.com/img.jpg": 456 }` zurück.
→ Im V3-JSON alle `url`-Werte durch `{ "id": 456, "url": "<WP-URL>" }` ersetzen.

### Schritt 3: V3-Tree deployen
```json
{
  "ability": "novamira/elementor-set-content",
  "parameters": {
    "post_id": 1234,
    "content": "<CLONED_V3_JSON_ARRAY>"
  }
}
```
**Wichtig:** `content` ist immer ein Array, nie ein Objekt.

### Schritt 4: Reparieren
```json
{
  "ability": "novamira-adrianv2/repair-clonerlabs-page",
  "parameters": { "post_id": 1234, "auto_fix": true }
}
```
Behebt: CSS-Klassen-Konflikte, fehlende Widget-IDs, orphaned Columns, falsche responsive Einstellungen.

### Schritt 5: Cache leeren
```json
{
  "ability": "novamira-adrianv2/clear-cache",
  "parameters": { "post_id": 1234, "include_nested": true }
}
```

### Schritt 6: Audit
```json
{ "ability": "novamira-adrianv2/layout-audit", "parameters": { "post_id": 1234 } }
```
Score < 75%: `design-repair` ausführen oder User fragen.

## V4-Pfad (via dryrun-page-v4.json)

Wenn das Ziel-WordPress V4 Atomic nutzt:

1. `dryrun-page-v4.json` von site-clone-to-v3 als Intermediate-Tree verwenden
2. Übergabe an Framer-to-Elementor-V4-Pipeline: `node wizard.js --input dryrun-page-v4.json --input-format v4-json`
3. Pipeline validiert, generiert GCs, liefert Deploy-Package
4. Deploy-Package via `adrianv2-framer-pipeline-import`-Skill deployen

## Cross-Reference: Pipeline-Bridge

```
site-clone-to-v3 CLI Output
    ├── cloned-page-v3.json  ──→ direkt via elementor-set-content (V3)
    └── dryrun-page-v4.json  ──→ V4-Pipeline ──→ adrianv2-framer-pipeline-import
```

## Häufige Probleme

| Problem | Ursache | Fix |
|---------|---------|-----|
| Externe Bilder nicht sichtbar | URLs nicht gemappt | `batch-media-upload` vor Set-Content |
| Background-Images fehlen | CSS `background-image` nicht extrahiert | Manuell in `custom_css` setzen |
| Fonts falsch | Font-Intercept-Gap im Clone | `register-google-font` für fehlende Fonts |
| V3-JSON als Objekt | Falsches Format | `content` muss Array sein |
| Deploy auf V4-Site | V3 auf V4-Schema | V4-Pfad verwenden |