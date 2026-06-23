# Project Memory

## Codebase Memory MCP

- **Tool:** `codebase-memory-mcp` (v0.8.1)
- **Binary:** `C:\Users\adini\AppData\Local\Programs\codebase-memory-mcp\codebase-memory-mcp.exe`
- **Port:** 9749 (UI enabled via `--ui=true`)
- **Repo path:** `C:\Users\adini\Local Sites\treets\app\public\wp-content\plugins\novamira-adrianv2`

### Nach Änderungen am Projekt

Nach relevanten Änderungen den Graph neu indexieren:

```cmd
codebase-memory-mcp cli index_repository "{\"repo_path\":\"C:\\Users\\adini\\Local Sites\\treets\\app\\public\\wp-content\\plugins\\novamira-adrianv2\"}"
```

UI starten (wenn nicht bereits laufend):

```cmd
start /B "" "C:\Users\adini\AppData\Local\Programs\codebase-memory-mcp\codebase-memory-mcp.exe" --ui=true --port=9749
```

## WordPress GitHub Auto-Updater

- **Library:** `yahniselsts/plugin-update-checker` (v5p7)
- **Location:** `vendor/yahniselsts/plugin-update-checker/`
- **GitHub Repo:** `https://github.com/Adilinu94/novamira-adrianv2`
- **Wie es funktioniert:** Der Update-Checker läuft auf `init` (Priority 5) und prüft GitHub Releases auf neue Versionen. Release `v1.6.0` wurde am 2026-06-23 erstellt. Das Update sollte jetzt im WordPress Dashboard erscheinen.

### Release erstellen (für Update im Dashboard)

1. Version im Plugin erhöhen (Plugin-Header + `NOVAMIRA_ADRIANV2_VERSION`-Konstante)
2. `composer install --no-dev` (optional, für saubere vendor/)
3. Alles committen und pushen
4. Auf GitHub ein **neues Release** erstellen mit:
   - Tag: `v1.7.0` (oder höher, aktuell: v1.6.0)
   - Titel: `v1.7.0`
   - Beschreibung: Changelog
   - **Kein ZIP hochladen nötig** — der Checker nutzt den Source-Code-Tag

### Automatische Regeln (für den Assistant)

- Nach jeder Antwort 3 kurze Followup-Fragen anhängen
- Beim Context >= 300k den User an /compact erinnern
- Nach relevanten Änderungen an den Re-Index erinnern
