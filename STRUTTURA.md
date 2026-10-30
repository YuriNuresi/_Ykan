# _Ykan — Struttura Progetto

> Ultimo aggiornamento: 2026-10-30
> Per AI: **dove sta il codice** e come si modifica. Cosa fa ogni funzione: DOCUMENTAZIONE.md.
> I numeri di riga sono indicativi: cerca le intestazioni `// === NOME ===`.

---

## Tech stack
- **Board**: PHP 8.4, un solo file; JS vanilla e CSS inline; nessun build step. MySQL (online) o file JSON (locale).
- **Bridge**: Node 22 (`http` + `ws` + `node-pty`), impacchettato come Node SEA (`ykan-bridge.exe`) con `npm run build`.
- **MCP**: `mcp.php`, JSON-RPC 2.0 stateless.
- **Estensione Chrome**: `extension/` (badge consumo Claude).

## Albero file
```
_Ykan.php              board: backend + HTML + CSS + JS (~13.500 righe)
index.php              ingresso
mcp.php                server MCP (task, file, DB, mail, memoria, thread)
auth_callback.php      login Google
gmail_*.php            invio mail (OAuth Gmail)
tasks_sync.php         sincronizzazione Google Tasks
themes/                temi custom (.jsonc)
bridge/
  bridge-server.js     Bridge (~3.300 righe)
  build-dist.js        build Windows: exe SEA + node_modules → dist/ykan-bridge + zip in downloads/
  build-mac.py         pacchetto Mac
  tray.ps1             icona nella tray (Riavvia/Aggiorna/Log)
  autostart-hidden.vbs, install-autostart.bat, start-bridge.bat
  wa-loader.cjs        bot WhatsApp (Baileys)
extension/             estensione Chrome
downloads/             zip del Bridge e dell'estensione serviti dalla board
```

## Anatomia di `_Ykan.php`

### PHP (righe ~1–3800)
| Sezione | Cosa |
|---|---|
| `STORAGE` (~94) | JSON o MySQL; `loadData()` / `saveData()` |
| `LOGIN GOOGLE` (~213) | utenti, sessione |
| `PC COLLEGATI` (~304) | tabelle `ykan_bridges`, `ykan_bridge_cmds`, `YKAN_RELAY_PATHS`, endpoint `?bridge=poll/result/update_download` |
| `RIGHE VERSIONE` (~811) | `ykanLaneVersionsInit`, `ykanActiveVersions`, `ykanCardVersion`, `ykanSetCardVersion` |
| `SHORT TASK IDS` (~882) | `#25` |
| `ANOTHER AI` (~933) | `YKAN_AI_PROVIDERS`, `ykanAiSettings`, `ykanAiAsk`, `ykanAiJson` |
| `PM LAYER` (~1098) | PRD/epic/card |
| `API HANDLER` (~1446) | `match($action)`: ogni API è una closure con `use (&$data, $input)`, chiama `saveData($data)` e ritorna un array |
| `LOAD DATA FOR HTML` (~3782) | `$dataJson` iniettato in JS come `boardData` |

### HTML e CSS
`<style>` da ~3804; poi le pagine (Home, Kanban, Progetti, Claude, Settings) e i modali (card, terminale, progetti…).

### JavaScript (da ~5550)
| Sezione | Cosa |
|---|---|
| `STATE`, `API`, `RENDER` | `boardData`, `api(action, payload)`, `render()` |
| `RIGHE VERSIONE` | `laneVersions`, `renderVersionRow`, `promoteVersion`, `deleteVersionRow` |
| `MODALITÀ DI LAVORO` | `workModes`, `MODE_PRESETS`, `modesHtml`, `setLaneMode`, `suggestModeFor`, `cardSuggestMode` |
| `MENU DEL TASTO DESTRO` | `ctxMenu`, `cardContextItems`, `versionContextItems`, `laneContextItems` |
| `ARCHIVIO` | pagina archivio |
| `PROJECTS` | collegamenti del progetto, `saveProjectLink`, cartelle per PC (`lanePaths`) |
| `DASHBOARD` | schede Progetti; Git (`dashGitLoad`, `dashGitHtml`) e finestra commit (`gitCommitOpen`) |
| `PC COLLEGATI` | `bridgeFetch` (diretto o relay), `bridgeTarget`, `ctxCall` |
| `TERMINAL` | `xtermLoad`, `openTerminalModal` |
| `PANNELLO CLAUDE` | `claudeRenderBody` e una sezione per scheda (skill, plugin, memoria, contesto, RC, routine, novità, WhatsApp) |
| `HOME` | cruscotto |

## Struttura dati (una riga di `ykan_boards` o `_Ykan_data.json`)
```jsonc
{
  "config": {
    "ai": { "provider": "groq", "providers": { "groq": { "api_key": "", "model": "" } } },
    "github_token": "", "theme": "dark",
    "work_modes": [{ "id": "p-fix", "name": "…", "icon": "🔧", "description": "regole", "plugins": { "ecc@ecc": ["ecc:error-handling"] }, "skills": ["ovh-yuri"] }],
    "mode_pinned": { "plugins": {}, "skills": [] }
  },
  "columns":   [{ "id": "col_…", "name": "To Do", "position": 0 }],
  "swimlanes": [{
    "id": "lane_…", "name": "_Ykan", "position": 0, "color": "#…",
    "path": "ykan", "url": "https://…", "local_path": "C:\\…", "github_repo": "owner/repo",
    "versions": [{ "id": "ver_…", "name": "V2.0" }], "archived_versions": [],
    "mode_id": "p-feature", "mode_pinned": {}, "mode_contexts": {}
  }],
  "cards": [{
    "id": "card_…", "seq": 25, "title": "…", "description": "…",
    "column_id": "col_…", "swimlane_id": "lane_…", "version_id": "ver_…",
    "version_history": [{ "id": "ver_…", "name": "V2.0", "from": "…", "to": "…" }],
    "priority": "high|medium|low", "label_id": "…", "work_mode_id": "p-fix",
    "files": [], "archived": false
  }],
  "labels": [], "routines": []
}
```
`local_path` nella board è il default: se il Bridge del PC ha una cartella per quel progetto (`~/.ykan-lane-paths.json`) vince quella.

## Anatomia di `bridge/bridge-server.js`
| Sezione | Cosa |
|---|---|
| Collegamento, profili, relay (~45–330) | account Ykan online, biglietti, `RELAY_PATHS`, `runRelayCommand` |
| Aggiornamento automatico (~335) | `selfUpdate` (solo pacchetto zip) |
| Skill, plugin, memoria, contesto (~407–1100) | lettura/scrittura in `~/.claude` e `.claude/` del progetto |
| Modalità di lavoro (~1105) | `applyWorkMode`, `clearWorkMode`, `modeCatalog`, `proposeWorkMode`, `workModeContext`, `suggestWorkMode` |
| Sessioni e analisi (~1330–1800) | trascrizioni, analisi con `runClaudePrint`, archiviazione in Desktop |
| Git (~1802, ~1961) | `gitInfo`, `gitInit`, `gitChanges`, `gitDiff`, `gitCommit`, `gitPush`, `gitRunTests`, `gitAi` |
| Routine locali, cartelle per PC, vendor (~1912) | `listScheduled`, `writeScheduled`, `readLanePaths`, `writeLanePath`, `VENDOR_FILES` (xterm) |
| PTY, Desktop, Remote Control (~2109) | terminale, `claude --desktop --resume`, `claude remote-control` |
| HTTP + WS server (~2307) | controlli d'accesso (Origin, biglietto, cartelle) e `handleRequest` con tutte le rotte |

## Come fare modifiche comuni
- **Nuova API della board**: aggiungi `'nome' => (function() use (&$data, $input) { …; saveData($data); return ['success' => true]; })(),` nel `match($action)`; dal JS `await api('nome', {...})`.
- **Nuova rotta del Bridge**: in `handleRequest`; le scritture solo `POST` + `isAllowedOrigin` + `application/json`. Per usarla da remoto aggiungila a `RELAY_PATHS` e a `YKAN_RELAY_PATHS`. Poi `npm run build` e sostituisci l'exe.
- **Nuovo tool MCP**: in `mcp.php` (definizione nel `tools/list` + handler).
- **Tema custom**: copia `themes/_TEMPLATE.jsonc`.

## Pattern e convenzioni
- File unico, monolitico per scelta; nessun build per la board.
- API = `?api=nome` in POST JSON; risposta `{ success, … }`.
- Testi dell'interfaccia e commenti in italiano.
- Backup prima di scrivere (DB: tabella di copia; file: `.ykan-bak` o backup con data).
- Claude in locale sempre con `runClaudePrint` e strumenti minimi (nessuno, oppure `Read,Grep,Glob`).
- Script PowerShell solo ASCII (PS 5.1 legge senza BOM come ANSI); JSON con accenti da leggere con Python/Node, non con PS 5.1.
