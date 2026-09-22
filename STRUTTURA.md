# _Ykan — Struttura Progetto

> Ultimo aggiornamento: 2026-07-13
> Per AI: mappa completa dei file e delle loro responsabilità.

---

## Tech Stack

| Layer | Tecnologia |
|-------|-----------|
| Backend | PHP 8.2+ (no framework, single-file) |
| Frontend | HTML/CSS/JS inline (tutto dentro _Ykan.php) |
| Database | Nessuno — file JSON (`_Ykan_data.json`) |
| MCP Server | PHP (JSON-RPC 2.0 over HTTPS, stateless) |
| AI | Google Gemini API (gemini-2.5-flash) |
| VCS | GitHub integration via API |
| Hosting | OVH shared hosting |
| Auth sito | Basic Auth via `.htaccess` |
| Auth MCP | Secret nell'URL (HTTPS only) |

---

## Albero file

```
_Ykan/
│
├── _Ykan.php              # IL FILE — tutto il Kanban (~4000 righe)
│                          #   ├── PHP: data functions, API handler, themes, scanner
│                          #   ├── HTML: full page structure
│                          #   ├── CSS: complete stylesheet (light+dark)
│                          #   └── JS: board logic, drag&drop, AI, GitHub, UI
│
├── _Ykan_data.json        # Dati board (auto-generato, MAI committare)
│                          #   ├── config (API keys, settings)
│                          #   ├── columns (array)
│                          #   ├── swimlanes (array, con path/url progetto)
│                          #   ├── cards (array, attive + archiviate)
│                          #   └── labels (array)
│
├── mcp.php                # MCP endpoint — server JSON-RPC 2.0 per Claude
│                          #   ├── Auth via MCP_SECRET
│                          #   ├── 6 board tools (list/add/move/complete)
│                          #   ├── 5 file tools (list/read/edit/write/search)
│                          #   ├── Path safety (canonical, symlink check)
│                          #   ├── Backup before write
│                          #   └── Audit log
│
├── index.php              # Entry point — redirect a _Ykan.php
│
├── .env                   # Secrets (MCP_SECRET, MCP_ROOT) — NON committare
│
├── .htaccess              # Basic Auth (esclude mcp.php)
│
├── themes/                # Temi custom (JSON)
│   ├── _TEMPLATE.jsonc    # Template documentato per creare temi
│   └── *.json             # Temi utente
│
├── _ykan_mcp_audit.log    # Log operazioni MCP (auto-generato)
│
├── README.md              # Documentazione pubblica GitHub
├── MCP_SETUP.md           # Guida setup MCP
│
└── .claude/
    └── settings.local.json # Permessi Claude Code per questo progetto
```

---

## Anatomia di _Ykan.php (~4000 righe)

Il file è un monolite intenzionale. Ecco le sezioni principali:

### PHP Backend (righe 1–1400 circa)

| Sezione | Righe | Responsabilità |
|---------|-------|----------------|
| Constants & defaults | 1-90 | `DATA_FILE`, `DEFAULT_DATA`, theme keys |
| Data functions | 95-110 | `loadData()`, `saveData()`, `generateId()` |
| MCP project root | 112-175 | `ykanMcpRoot()`, `ykanCanonical()`, `ykanProjectBase()`, `ykanSafePath()` |
| Theme system | 177-265 | `ykanThemeSlug()`, `ykanNormalizeTheme()`, `ykanListThemes()`, `ykanThemeCss()` |
| Project scanner | 268-340 | `scanProject()` — analisi codebase per AI |
| API handler | 343-1400 | `match($action)` con tutte le API REST |

### API handler breakdown

| Gruppo | API | Descrizione |
|--------|-----|-------------|
| Config | `save_config` | Salva settings |
| Columns | `add/update/delete/reorder_column(s)` | Gestione colonne |
| Swimlanes | `add/update/delete/reorder_swimlane(s)` | Gestione swimlane + project link |
| Cards | `add/update/move/archive/restore/delete_card` | CRUD card |
| Labels | `add/update/delete_label` | Gestione etichette |
| Themes | `list/save/delete_themes` | Gestione temi custom |
| Files | `list_project_files` | Browse file progetto collegato |
| AI Summary | `get_summary` | Board summary plain-text per AI |
| AI Task | `complete_task`, `move_task` | Task by title (per AI) |
| AI Gemini | `gemini_analyze`, `analyze_project`, `verify_task` | Analisi AI |
| AI Quick | `ai_categorize`, `ai_estimate`, `ai_standup` | Categorizzazione, stime, standup |
| Utility | `scan_todos`, `burndown_data` | TODO scanner, burndown |
| GitHub | `github_issues`, `github_prs`, `github_commits` | Integrazione GitHub |

### HTML (dopo il PHP)
- Full page structure con header, board, modali, settings panel
- Tutto inline nel PHP via heredoc/echo

### CSS (tag `<style>` inline)
- Light + dark theme via CSS custom properties
- Layout responsive (flexbox/grid)
- Temi custom override variabili CSS via `:root{}`

### JavaScript (tag `<script>` inline)
- Board rendering e drag&drop
- API calls (fetch)
- AI integration (Gemini calls)
- GitHub integration
- Search, filtri, export
- Theme switcher
- Burndown chart (Canvas)

---

## Struttura dati (_Ykan_data.json)

```json
{
  "config": {
    "gemini_api_key": "...",
    "github_token": "...",
    "github_repo": "owner/repo",
    "theme": "light|dark|<theme-file>",
    "project_name": "My Project",
    "ai_language": "en|it|es|fr|de|pt"
  },
  "columns": [
    { "id": "col_1", "name": "To Do", "position": 0 }
  ],
  "swimlanes": [
    {
      "id": "lane_1",
      "name": "Project Name",
      "position": 0,
      "path": "folder-name",      // relativo a MCP_ROOT
      "url": "https://...",        // URL pubblico (opzionale)
      "doc_files": ["file.md"]     // file doc per AI (opzionale)
    }
  ],
  "cards": [
    {
      "id": "card_xxx",
      "title": "Task title",
      "description": "Details (markdown)",
      "priority": "high|medium|low",
      "due_date": "2026-07-15",
      "label_id": "lbl_1",
      "column_id": "col_1",
      "swimlane_id": "lane_1",
      "archived": false,
      "auto_regenerate": false,
      "regenerate_delay_days": 7,
      "files": ["path/to/file.php"],
      "position": 0,
      "created_at": "2026-07-13 10:00:00",
      "regenerated_count": 0
    }
  ],
  "labels": [
    { "id": "lbl_1", "name": "Bug", "color": "#ef4444" }
  ]
}
```

---

## mcp.php — Anatomia

| Sezione | Responsabilità |
|---------|----------------|
| `.env` loader | Legge MCP_SECRET e MCP_ROOT da `.env` |
| Auth gate | Verifica secret via `?k=` o `Authorization: Bearer` |
| Data helpers | `mcp_load()`, `mcp_save()`, `mcp_id()`, `mcp_audit()` |
| Swimlane lookup | `mcp_find_lane()` — match fuzzy per nome |
| Path safety | `mcp_canonical()`, `mcp_project_dir()`, `mcp_safe_path()`, `mcp_backup()` |
| Tool definitions | `mcp_tool_defs()` — schema JSON per 11 tools |
| Tool execution | `mcp_run_tool()` — switch su tool name |
| JSON-RPC dispatch | `mcp_handle()` — initialize, ping, tools/list, tools/call |
| Request parsing | Body JSON-RPC, supporta batch e single |

---

## Flusso dati

```
[Browser UI]
    │
    ├── fetch('?api=add_card', POST) ──→ _Ykan.php ──→ _Ykan_data.json
    │
    └── fetch('?api=get_summary', POST) ──→ plain text summary

[Claude Mobile / claude.ai]
    │
    └── JSON-RPC POST ──→ mcp.php ──→ _Ykan_data.json
                                   └──→ project files (read/edit/write)

[Gemini AI]
    │
    └── _Ykan.php ──→ Gemini API ──→ analysis/suggestions/estimates
```

---

## Come fare modifiche comuni

### Aggiungere un nuovo tool MCP
1. Aggiungere definizione in `mcp_tool_defs()` (mcp.php)
2. Aggiungere case in `mcp_run_tool()` (mcp.php)
3. Aggiungere audit call se è un write

### Aggiungere una nuova API nel board
1. Aggiungere case nel `match($action)` in _Ykan.php (sezione API handler)
2. Aggiungere il call JS nel frontend (sezione `<script>`)

### Creare un tema custom
1. Copiare `themes/_TEMPLATE.jsonc` → `themes/mio-tema.json`
2. Modificare colori in `colors`, layout in `layout`, superfici in `surfaces`
3. Il tema appare automaticamente nello switcher

### Collegare un nuovo progetto
1. UI → toolbar → 🔗 Projects
2. Impostare Folder (path relativo a MCP_ROOT) e URL
3. Il progetto diventa accessibile via MCP file tools

---

## Pattern e convenzioni

- **Single-file PHP** — tutto in _Ykan.php, deliberatamente monolitico
- **No build step** — no npm, no webpack, no transpiler
- **No database** — JSON file flat, letto/scritto atomicamente
- **API = query param** — `?api=action_name` (POST)
- **MCP = JSON-RPC 2.0** — standard protocol, stateless
- **AI = Gemini** — tutte le chiamate AI passano per Gemini 2.5 Flash
- **Fuzzy match** — swimlane e task trovati per partial match (stripos)
- **Backup before write** — ogni modifica file crea backup timestamped
- **Audit trail** — log TSV per ogni operazione MCP di scrittura
