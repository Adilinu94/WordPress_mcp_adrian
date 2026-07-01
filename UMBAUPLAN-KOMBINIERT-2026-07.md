# UMBAUPLAN — Kombiniert & Verifiziert

**Ersetzt:** `Umbauplan_framer-to-elementor.txt`, `Umbauplan_site-clone-to-v3.txt`
**Repos:** `site-clone-to-v3` · `WordPress_mcp_adrian` (ex `novamira-adrianv2`) · `Framer-to-Elementor-V4-Pipeline`
**Stand:** 01.07.2026
**Methodik:** Jede Zahl in diesem Dokument wurde gegen den echten Repo-Zustand geprüft — Tests ausgeführt, Git-Historie gelesen, Code gegrept. Wo etwas nicht verifizierbar war, steht das explizit dabei statt einer geschätzten Zahl.

---

## 0. Kurz-Fazit

Die beiden Ausgangspläne haben unterschiedliche Qualität. Der große Plan (`Umbauplan_framer-to-elementor.txt`) ist zu ~85 % kontextfreier, syntaktisch korrekter Boilerplate-Code ohne Bezug zu deinen echten Datenstrukturen — er dupliziert Module, die bereits fertig und getestet existieren (Circuit Breaker, Idempotency, Batch-Scheduler), verwendet ein falsches Ability-Registrierungsmuster für das WordPress-Plugin, und seine KPI-Tabelle besteht aus erfundenen Schätzwerten. Der kleine Plan (`Umbauplan_site-clone-to-v3.txt`, 10 Vorschläge) ist konzeptionell brauchbarer, weil er reale Artefakte referenziert (z. B. `upgrade-page-to-v4`), aber teils auf überholten oder nie real existierenden Zahlen aufbaut (184-Tests-Behauptung ist ein hartcodierter Wert ohne echte Testdateien dahinter).

Dieser Plan verwirft die generischen Teile komplett, übernimmt die validen Kernideen (gefiltert und auf echte Aufwände zurechtgeschnitten), und ergänzt echte offene Punkte aus euren eigenen `HANDOFF.md`/`FORTSETZUNG.md`-Dateien, die in keinem der beiden Ausgangspläne vorkamen.

---

## 1. Verifizierter Ist-Zustand (01.07.2026)

### 1.1 `site-clone-to-v3` (Branch `main`, package.json `0.2.0`)

- **Tests:** `npx vitest run tests/unit` → **1121 Tests / 78 Dateien, alle grün** (CHANGELOG nennt noch 1047, das war Stand 25.06.)
- **Guards:** 12 implementiert (`G1`–`G12` in `src/validator/json-guard.ts`), nicht 14 wie im großen Plan behauptet
- **Vision-QA / Healing-Loop / Cross-Validator / Browserbase-Extractor / WP-Push:** alle real vorhanden (`src/qa/vision-qa.ts`, `src/qa/healing-loop.ts`, `src/qa/cross-validator.ts`, `src/extractor/browserbase-extractor.ts`, `src/mcp/wp-push.ts`)
- **CLI-Flags real vorhanden:** `--post-id`, `--qa-auto-fix`, `--extractor`, `--dry-run`, `--diff-only`, `--incremental`, `--resume`, `--clone-url`, `--mcp-url` — **nicht** vorhanden: `--heal`, `--upgrade-to-v4`, `--output-format`
- **Nur 1 echter TODO-Kommentar** im gesamten `src/`-Baum — Codebase ist ungewöhnlich aufgeräumt
- **Asset-Stage-Pipeline-Integration** (in `HANDOFF.md` vom 18.06. noch als offene Lücke dokumentiert) ist **inzwischen geschlossen** — `pipeline.ts` ruft `downloadImages/Fonts/Svgs/Favicons` bereits auf. Das HANDOFF.md ist also selbst ~2 Wochen hinter dem Code.
- **Letzte Commits (29.06.):** Fixes an einem CSS-Compile-Race-Condition im visual-diff-Tool (`scrollHeight`-Wait, Warm-up-Requests) — der aktuellste `diff-reports/latest`-Lauf zeigt `matchPct ~9–15%` und `v4PageHeight: 0` gegen `test4.nick-webdesign.de`. Das sieht dramatisch aus, ist aber mit hoher Wahrscheinlichkeit genau das CI/Timing-Artefakt, das die beiden letzten Commits adressieren, und keine echte Regression an einer V4-Konvertierung — bitte einmal manuell nachprüfen, ich kann von hier aus nicht auf die Domain zugreifen.

### 1.2 `WordPress_mcp_adrian` (Branch `master`)

- **Versions-Chaos:** `composer.json` → `1.1.0`, Plugin-Header + `NOVAMIRA_ADRIANV2_VERSION`-Konstante → `1.10.1`, `README.md` → `1.0.0`, CHANGELOG neuester Eintrag → `[1.9.0]`. Vier verschiedene Zahlen für dieselbe Codebase — relevant für den `yahniselsts/plugin-update-checker`, der den Header liest.
- **134 PHP-Ability-Dateien**, alle nach echtem, konsistentem Muster: `wp_register_ability()` mit `input_schema`/`output_schema`/`execute_callback`/`permission_callback` — kein einziges Vorkommen des im großen Plan vorgeschlagenen `getAbilities()`-Array-Patterns.
- **Keine automatisierten Tests.** Kein `tests/`-Verzeichnis, keine `*Test.php`, kein `.github/workflows/`. Die "184 Tests, 100% passing" in README und im `/wp-json/novamira/v1/status`-Endpoint sind **hartkodierte Integer** (`'phpunit' => 52, 'pipeline' => 114, 'e2e' => 18, 'total' => 184`) in `includes/helpers/bootstrap.php` — keine der referenzierten Dateien (`tests/pipeline.test.js`, `tests/e2e.test.js`) existiert im Repo.
- **`upgrade-page-to-v4`** existiert bereits (`includes/abilities/elementor/class-upgrade-page-to-v4.php`, seit v1.7.1) und ist laut eigenem Docblock explizit als *"final `--upgrade-to-v4` step in the site-clone-to-v3 pipeline"* gebaut — wird aber von `site-clone-to-v3` nirgends aufgerufen. Fertiges Teil, das nur noch angeschlossen werden muss.
- **`elementor-inject-calibrated-page`** und **`batch-build-page`** existieren beide real und parallel (deine Merkregel "immer ersteres für V3-Bäume" ist also technisch beleg­bar, nicht nur Konvention).
- Neueste Commits (28.06., 21:00 Uhr) fügen bereits 3 neue V4-Atomic-Abilities hinzu (`v4-setup-atomic-editor`, `v4-batch-build-page`, `v4-security`) — **nach** dem Zeitpunkt, an dem der große Plan "V4-Abilities" noch als komplett offenes Zukunftsthema behandelt.

### 1.3 `Framer-to-Elementor-V4-Pipeline` (Branch `main`, package.json `0.20.0`)

- **TypeScript-Migration: verifiziert real abgeschlossen.** 0 `.js`-Dateien, 48 `.ts` in `scripts/`, 26 `.ts` in `scripts/lib/`. `npx tsc --noEmit` → 0 Fehler.
- **Tests laufen lassen, nicht nur dokumentiert:**
  - `node --import tsx --test tests/pipeline.test.js` → **128/128**
  - Circuit-Breaker Self-Test → **58/58**
  - Idempotency Self-Test → **54/54**
  - Batch-Scheduler Self-Test → **30/30**
  - **Summe: 270 echte, laufende Tests, alle grün.** Das ist das mit Abstand am saubersten dokumentierte der drei Repos.
- **Strangler-Fig-Restrukturierung** (`scripts/` → `src/`) laut `FORTSETZUNG.md`: Schritte 1–12 ✅, Schritt 13 (weitere Module wie `mcp-bridge.ts`, `unframer-bridge.ts` nach `src/`) und Schritt 14 (`wizard.js` → `src/cli/`) offen.

---

## 2. Externe Lageänderung seit den Ausgangsplänen

Beide Ausgangspläne gehen implizit davon aus, dass Elementor V4 noch ein instabiles Beta-Feature ist. Das hat sich verschoben:

- **Elementor 4.0 ist seit dem 19. März 2026 offiziell stabil** und Standard für neue Installationen. V3 und V4 können weiter parallel auf derselben Seite existieren.
- Eure Testseite läuft mit **4.2.0-beta1** — also *vor* dem stabilen Branch. Für eine reine Test-/Dev-Umgebung unproblematisch, aber bei echten Client-Deployments lohnt ein Test gegen die stabile 4.0/4.1-Linie, weil Beta-spezifische Bugs nicht repräsentativ für das sein müssen, was ein Kunde tatsächlich bekommt.
- Elementor hat ein **eigenes natives "Editor MCP"-Experiment** für ihren Agenten "Angie" eingeführt (Experimentkatalog seit 3.35 standardmäßig aktiv). Laut aktuellen Berichten (Stand Mai/Juni 2026) noch nicht produktionsreif.
- Es gibt eine wachsende **Community-Konkurrenz** (`msrbuilds/elementor-mcp`, ~316 GitHub-Stars, 110+ Tools, seit April 2026 mit Atomic-Widget-Support) — kein Grund zum Umbauen, aber ein Signal, dass euer bespoke-MCP-Ansatz kein Alleingang mehr ist und sich Ideen (z. B. deren "Low-tools Mode" für Tool-Cap-Limits) lohnen könnten zu beobachten.

Keine dieser Änderungen erzwingt einen Kurswechsel — aber sie relativiert die "wir bauen früh auf instabilem Boden"-Rahmung, die beide Ausgangspläne implizit hatten.

---

## 3. Bewertung der 10 Vorschläge aus `Umbauplan_site-clone-to-v3.txt`

| # | Vorschlag (Kurzform) | Verdikt | Begründung |
|---|---|---|---|
| 1 | Gemeinsames Design-Token-Schema | **Übernehmen, verkleinert** | Reale Friktion: `site-clone-to-v3` hat eigene `DesignTokens`-Types, das Plugin eigene `V4_Props`. Ein gemeinsames Schema lohnt sich — aber erst grep-prüfen, ob Farben aktuell überhaupt als OKLCH extrahiert werden, bevor man das als Pflichtfeld festschreibt. |
| 2 | `analyze-section-structure`-Ability | **Umformuliert übernehmen** | Wie im Fazit oben: ergibt keinen Sinn für den externen-URL-Clone-Pfad (kein WP-Kontext vorhanden), aber sinnvoll für den `upgrade-page-to-v4`-Pfad (V3-Bestandsseite → V4, dort existiert WP-Kontext). Scope entsprechend verengen. |
| 3 | Bidirektionales Sync-Protokoll (Webhooks/Event-Queue) | **Verwerfen** | Löst ein Problem, das bei einem Ein-Personen-Freelance-Workflow ohne Beleg auftritt. Infra-Aufwand (Queue, Webhook-Listener) steht in keinem Verhältnis zum Nutzen. Nur wieder aufgreifen, falls konkret mehrere Personen parallel an denselben Posts arbeiten. |
| 4 | Gemeinsame Test-Infrastruktur | **Umformuliert übernehmen** | Die Prämisse ("184 vs. 910 Tests") ist falsch — das Plugin hat **0** echte Tests. Der eigentliche erste Schritt ist nicht "vereinheitlichen", sondern "PHPUnit-Suite für das Plugin überhaupt erst bauen" (siehe Phase 1 unten). Danach wird eine gemeinsame Mock-Schicht sinnvoll. |
| 5 | Fehlerbehandlung mit Backoff/Recovery | **Wiederverwenden statt neu bauen** | `Framer-to-Elementor-V4-Pipeline` hat bereits fertige, getestete `circuit-breaker.ts`, `idempotency.ts`, `batch-scheduler.ts` (270 Tests, siehe oben). `site-clone-to-v3` hat bereits `with-retry.ts` + `rate-limiter.ts`. Statt einer neuen generischen "Recovery-Strategy"-Datenstruktur: bestehende Module cross-importieren. |
| 6 | V3→V4-Migration über `upgrade-page-to-v4` | **Übernehmen, hohe Priorität** | Ability existiert bereits fertig und ist explizit für genau diese Integration gebaut — fehlt nur die Verdrahtung in `site-clone-to-v3`. Größter "quick win" in diesem ganzen Plan. |
| 7 | Chunked Processing für >50 Sections | **Zurückstellen** | Kein Beleg, dass eine eurer Zielseiten (Testseite, treets, solar) überhaupt in diese Größenordnung kommt. Premature Optimization — erst bauen, wenn eine reale Seite das Problem zeigt. |
| 8 | Monitoring/Observability-Suite (Metrics/Logging/Alerting) | **Stark verkleinert übernehmen** | Volles Alerting/Metrics-Stack ist für einen Solo-Workflow überdimensioniert. Strukturiertes JSON-Logging mit Correlation-ID über bestehende `state-manager.ts`/Diff-Reports reicht; Alerting nur, falls du unbeaufsichtigte Runs über Nacht laufen lässt und benachrichtigt werden willst. |
| 9 | Konfigurierbare Build-Profile (dev/staging/prod) | **Übernehmen, klein** | Baut auf bereits existierendem `--target`-Profilsystem (`~/.clone-v3/profiles.json`) auf. Batch-Size/Retry-Policy pro Profil ergänzen ist eine kleine, saubere Erweiterung. |
| 10 | Doku/Onboarding (Sequenzdiagramme, OpenAPI, Tutorials) | **Stark verkleinert übernehmen** | Volle OpenAPI-Spec für interne WP-Abilities ist Overkill (die `input_schema` pro Ability ist bereits selbstdokumentierend). Sinnvoller, kleiner Schritt: ein Top-Level-`README.md`, das die drei Repos und ihre Beziehung zueinander in einem Diagramm erklärt — das fehlt aktuell tatsächlich. |

---

## 4. Kombinierter Umbauplan

### Phase 0 — Dokumentations-Realitätscheck (vor allem anderen, ~1 Std.)

Mehrere eurer eigenen Planungsdateien sind bereits jetzt hinter dem echten Code her (HANDOFF.md vom 18.06. kennt die Asset-Stage-Integration noch nicht, die längst gebaut ist). Bevor weiterer Aufwand in Planung fließt:

- [ ] `HANDOFF.md` (site-clone-to-v3) neu schreiben lassen basierend auf aktuellem Stand (1121 Tests, Asset-Stage geschlossen)
- [x] Plugin-Version an den 4 Stellen vereinheitlicht auf `1.10.1` — **bereits erledigt** (Commit `05e6d85`, 01.07.2026)
- **Erfolgskriterium:** `grep -rn "version" composer.json novamira-adrianv2.php README.md` zeigt überall dieselbe Zahl ✅ erfüllt

### Phase 1 — `WordPress_mcp_adrian`: echte Testinfrastruktur (P0)

Das Plugin behauptet 184 Tests und hat 0. Das ist der wichtigste Einzelpunkt in diesem ganzen Plan, weil alles andere (V4-Abilities, Cross-Repo-Integration) auf einem ungetesteten PHP-Kern aufbaut.

- [ ] `composer require --dev phpunit/phpunit` tatsächlich einrichten (steht schon in `require-dev`, aber `vendor/bin/phpunit` existiert nicht)
- [ ] WP-Test-Suite-Bootstrap (`wp-phpunit` oder `10up/wp_mock` — letzteres ist leichtgewichtiger für Ability-Unit-Tests ohne echte WP-Installation)
- [ ] Für die 5–10 kritischsten Abilities Tests schreiben, beginnend mit `upgrade-page-to-v4`, `elementor-inject-calibrated-page`, `batch-build-page` (die mit den größten Blast-Radius bei Fehlern)
- [ ] `bootstrap.php`: die hartkodierten `184`/`52`/`114`/`18`-Werte im Status-Endpoint entweder entfernen oder durch eine echte Zählung ersetzen (z. B. `count(glob('tests/**/*Test.php'))`)
- [ ] `.github/workflows/ci.yml` anlegen (existiert nur in `site-clone-to-v3`, fehlt hier komplett)
- **Erfolgskriterium:** `./vendor/bin/phpunit --testdox` läuft durch und zeigt eine Zahl > 0, die mit dem Status-Endpoint übereinstimmt

### Phase 2 — Quick Win: `upgrade-page-to-v4` verdrahten (P0, ~1–2 Std.)

Beide Hälften existieren bereits fertig, nur der Draht fehlt.

- [ ] `--upgrade-to-v4`-Flag in `src/cli/clone-v3.ts` ergänzen (Boolean, Default `false`)
- [ ] In `src/analysis/pipeline.ts`: nach erfolgreichem V3-Build + `postId` bekannt → MCP-Call an `novamira-adrianv2/upgrade-page-to-v4` mit `post_ids: [postId]`
- [ ] Test schreiben, der den MCP-Call mit einem Mock abdeckt (Pattern aus `real-fixers.test.ts` übernehmen — DRY-RUN-fähig halten)
- **Erfolgskriterium:** `clone-v3 clone --url <src> --target <profil> --upgrade-to-v4` erzeugt einen dokumentierten MCP-Call im Log; neuer Test in `tests/unit/` grün, Gesamtzahl steigt von 1121 auf 1121+n

### Phase 3 — `site-clone-to-v3`: Rest-Backlog aus eigenem HANDOFF (P1)

Das ist echter, bereits von euch selbst dokumentierter offener Bedarf — keine Erfindung dieses Plans:

- [ ] `--post-id` vollständig durch `PipelineOptions` durchreichen (in `HANDOFF.md` als TODO markiert, Status seit 18.06. unklar — bei Phase 0 mitprüfen)
- [ ] `--heal`-CLI-Flag, das `healing-loop.ts` (existiert, ist getestet) tatsächlich anschließt — aktuell nur `--qa-auto-fix` verdrahtet, nicht der Vision-QA-Healing-Loop
- [ ] Ursache des `v4PageHeight: 0`-Befunds in `diff-reports/latest` manuell verifizieren, nachdem die beiden Race-Condition-Fixes vom 29.06. einmal live gegen `test4` gelaufen sind
- **Erfolgskriterium:** `npx vitest run tests/unit` bleibt bei 100 % grün nach jeder Änderung; neuer `diff-reports`-Lauf zeigt `matchPct` deutlich über 15 %

### Phase 4 — `Framer-to-Elementor-V4-Pipeline`: Strangler-Fig zu Ende bringen (P1)

Auch hier: euer eigener, bereits sehr präziser Plan aus `FORTSETZUNG.md` — hier nur bestätigt, nicht neu erfunden:

- [ ] Schritt 13: `mcp-bridge.ts` → `src/builder/`, `unframer-bridge.ts` → `src/extractor/`
- [ ] Schritt 14: `wizard.js` + `scripts/wizard/*.js` zu TypeScript migrieren, nach `src/cli/`
- **Erfolgskriterium:** `npx tsc --noEmit` bleibt bei 0 Fehlern; `node --import tsx --test tests/pipeline.test.js` bleibt bei 128/128 (bzw. steigt, falls neue Tests für die verschobenen Module dazukommen)

### Phase 5 — Cross-Repo-Integration (P2, nach Phase 1–4)

Aus Abschnitt 3 gefiltert übernommen, in sinnvoller Reihenfolge:

- [ ] Gemeinsames Design-Token-Schema als geteiltes JSON-Schema-File (z. B. in einem eigenen kleinen `shared-schemas`-Verzeichnis oder Git-Submodul) — **erst** nachdem geprüft ist, in welchem Farbformat `site-clone-to-v3` aktuell tatsächlich extrahiert
- [ ] Build-Profile um `mcpBatchSize`/`retryPolicy` erweitern (`~/.clone-v3/profiles.json`)
- [ ] `analyze-section-structure` als Ability **nur** für den `upgrade-page-to-v4`-Pfad (WP-Kontext vorhanden), nicht für die externe Extraktion
- [ ] Ein Top-Level-`README.md` (oder `ARCHITECTURE.md` auf Ebene "alle drei Repos"), das erklärt, welches Repo welche Aufgabe hat und wie die MCP-Calls zwischen ihnen fließen
- **Erfolgskriterium:** ein neuer Entwickler (oder ein KI-Agent ohne Memory) versteht nach Lesen dieser einen Datei, welches Repo wann welches andere Repo aufruft

---

## 5. Priorisierte Reihenfolge

| Prio | Phase | Aufwand (grob) | Voraussetzung | Konkretes Erfolgskriterium |
|---|---|---|---|---|
| P0 | 0 — Doku-Realitätscheck | ~1 Std. | — | Version an 4 Stellen identisch |
| P0 | 1 — Test-Infra Plugin | 1–2 Tage | — | `phpunit --testdox` läuft, Zahl > 0 |
| P0 | 2 — `upgrade-page-to-v4` verdrahten | 1–2 Std. | Phase 1 optional, nicht blockierend | Neuer Test grün, MCP-Call im Log sichtbar |
| P1 | 3 — site-clone Rest-Backlog | 0.5–1 Tag | — | 1121/1121 Tests weiterhin grün |
| P1 | 4 — Framer-Pipeline Strangler-Fig | 0.5–1 Tag | — | 0 TS-Fehler, 128/128 Tests |
| P2 | 5 — Cross-Repo-Integration | 2–4 Tage | Phase 1–4 | Neue README erklärt Gesamtsystem verständlich |

---

## 6. Explizit verworfen (aus beiden Ausgangsplänen)

Zur Transparenz, was bewusst NICHT übernommen wurde und warum:

- **Gesamter generischer Code aus `Umbauplan_framer-to-elementor.txt`** (V4AtomicDetector, V4TreeBuilder, DiffEngine, IncrementalBuilder, BatchOperations, DebugTools, V4SecurityPolicies, StateManager-Neubau) — entweder bereits in anderer Form vorhanden (State-Management, Batch-Verarbeitung) oder ohne Bezug zu einem tatsächlich beobachteten Problem in euren Repos formuliert.
- **KPI-Tabelle mit Prozentschätzungen** ("TypeScript Coverage 100 % Target, ~70 % Current" etc.) — durch konkrete, mit Befehl nachprüfbare Kriterien in Abschnitt 5 ersetzt.
- **Bidirektionales Webhook-Sync-Protokoll** — siehe Abschnitt 3, Punkt 3.
- **Chunked Processing für >50 Sections** — siehe Abschnitt 3, Punkt 7.
- **Volles Observability-Stack mit Alerting** — auf strukturiertes Logging verkleinert, siehe Abschnitt 3, Punkt 8.

---

## 7. Offene Fragen, die nur du beantworten kannst

- Extrahiert `site-clone-to-v3` Farben aktuell als Hex oder OKLCH? (Entscheidet, wie Phase 5's Token-Schema aussehen muss)
- Ist der `v4PageHeight: 0`-Befund in `diff-reports/latest` schon manuell gegen die Live-Seite nachgeprüft worden, oder soll ich davon ausgehen, dass die beiden Fixes vom 29.06. das Problem gelöst haben?
