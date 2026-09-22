# _Ykan — Documentazione Progetto

> Ultimo aggiornamento: 2026-07-13
> Per AI: questo file è il contesto completo per lavorare su _Ykan.

---

## Cos'è _Ykan

_Ykan è un **Kanban board single-file in PHP** con superpoteri AI. Un unico file PHP (`_Ykan.php`, ~4000 righe) contiene tutto: backend, frontend, CSS, JavaScript. Nessun database — i dati stanno in un file JSON (`_Ykan_data.json`).

**URL produzione:** ykan.portale3d.it
**Repository:** github.com/YuriNuresi/_Ykan
**Versione:** 1.8.0
**Licenza:** MIT

---

## Funzionalità principali

### Core Kanban
- **Single-file deployment** — un solo file PHP, no database
- **Drag & Drop** — sposta card tra colonne e swimlane
- **Swimlanes** — organizza task per progetto (accordion collassabili)
- **Labels & Priorità** — color-coded (Bug, Feature, Task, Urgent, Recurring)
- **Due dates** — scadenze con alert overdue
- **Dark/Light theme** — built-in
- **Auto-regenerating tasks** — task ricorrenti che si ricreano all'archiviazione

### Sistema Temi Custom
- Temi JSON nella cartella `themes/`
- Switcher live nell'header
- Editor temi dalla UI (crea/salva/elimina)
- Override colori, layout (densità/forma) e superfici (gradienti, glass)
- Template documentato: `themes/_TEMPLATE.jsonc`

### MCP — Controllo remoto da mobile
- **mcp.php** espone un server MCP (JSON-RPC 2.0 over HTTPS, stateless)
- Gestibile dall'app Claude mobile e da claude.ai
- Gestione board + editing file dei progetti collegati
- Autenticazione via secret nell'URL (HTTPS only)
- Configurazione via `.env` (MCP_SECRET, MCP_ROOT)
- Funziona su hosting condiviso OVH (no VPS)

### AI Integration (Gemini)
- Analisi progetto — scansiona codebase e suggerisce task
- Verifica task — AI controlla se un task è completato leggendo i file associati
- Auto-categorizzazione — AI suggerisce label e priorità
- Stima tempi — AI stima complessità e durata
- Daily Standup — genera report Agile
- Multi-lingua — AI risponde in IT, EN, ES, FR, DE, PT

### GitHub Integration
- Visualizza Issues, Pull Requests, Commits
- Statistiche repo (stelle, fork, watchers)
- Importa issue GitHub come card Kanban

### Produttività
- Ricerca istantanea + filtri avanzati (label, priorità, scadenza)
- Supporto Markdown nelle descrizioni
- Export JSON/CSV
- Template card (Bug, Feature, Task, Docs, Refactor)
- TODO Scanner — trova TODO/FIXME nel codice
- Burndown Chart — traccia velocità e progresso

---

## Progetti collegati (Swimlanes)

Ogni swimlane nel board rappresenta un progetto. Ogni progetto può essere collegato a:
- **Folder** — path relativo a MCP_ROOT sul server (es. `skanno`, `3d`)
- **URL** — URL pubblico del progetto

Una swimlane con folder collegata mostra un badge 🔗 e permette:
- Browsing dei file del progetto dalla UI
- Operazioni file via MCP (read, edit, write, search)
- Tagging di file doc/struttura per le AI

---

## MCP Tools esposti

### Board (Phase 1)
| Tool | Descrizione |
|------|-------------|
| `list_projects` | Lista progetti con folder e task count |
| `board_summary` | Summary leggibile del board (filtro per progetto) |
| `list_tasks` | Lista task attivi (filtro progetto/colonna) |
| `add_task` | Aggiungi task a un progetto |
| `move_task` | Sposta task in altra colonna |
| `complete_task` | Archivia task (rispetta auto-regenerate) |

### File (Phase 2, confinati alla folder del progetto)
| Tool | Descrizione |
|------|-------------|
| `list_files` | Lista file/cartelle del progetto |
| `read_file` | Leggi file di testo (max 500KB) |
| `edit_file` | Find-and-replace con backup automatico |
| `write_file` | Crea/sovrascrivi file con backup (max 2MB) |
| `search_files` | Cerca testo nei file del progetto |

---

## API REST (_Ykan.php)

Endpoint via `?api=<action>` (POST):

### Board
| API | Descrizione |
|-----|-------------|
| `get_data` | Tutti i dati del board (JSON) |
| `get_summary` | Summary in plain text (per AI) |
| `save_config` | Salva configurazione |

### Colonne
`add_column`, `update_column`, `delete_column`, `reorder_columns`

### Swimlanes
`add_swimlane`, `update_swimlane`, `delete_swimlane`, `reorder_swimlanes`, `list_project_files`

### Card
`add_card`, `update_card`, `move_card`, `archive_card`, `restore_card`, `delete_card`

### Labels
`add_label`, `update_label`, `delete_label`

### Temi
`list_themes`, `save_theme`, `delete_theme`

### AI (Gemini)
`gemini_analyze`, `analyze_project`, `verify_task`, `ai_categorize`, `ai_estimate`, `ai_standup`

### Utility
`complete_task` (by title), `move_task` (by title), `scan_todos`, `burndown_data`

### GitHub
`github_issues`, `github_prs`, `github_commits`

---

## Sicurezza

- **Basic Auth** via `.htaccess` sul sito (mcp.php escluso per il connettore Claude)
- **MCP secret-gated** — accesso via secret nell'URL, solo HTTPS
- **Folder confinement** — file tools toccano solo folder esplicitamente collegati
- **Path traversal protection** — `../` e symlink fuori root rifiutati
- **Backup automatico** — ogni write crea backup in `.ykan_backups/`
- **Audit log** — `_ykan_mcp_audit.log` registra ogni operazione di scrittura
- **Size limits** — read max 500KB, write max 2MB

---

## Configurazione

| Setting | Dove | Descrizione |
|---------|------|-------------|
| Project Name | UI Settings | Nome mostrato nell'header |
| AI Language | UI Settings | Lingua risposte AI (en/it/es/fr/de/pt) |
| Gemini API Key | UI Settings | Per funzioni AI |
| GitHub Token | UI Settings | Per integrazione GitHub |
| GitHub Repo | UI Settings | Formato `owner/repo` |
| MCP_SECRET | `.env` | Password per accesso MCP |
| MCP_ROOT | `.env` | Path assoluto root progetti |

---

## Colonne default

1. **To Do** — task da fare
2. **In Progress** — task in corso
3. **Done** — task completati (prima dell'archiviazione)

Le colonne sono personalizzabili e riordinabili.

---

## Labels default

| Label | Colore | Uso |
|-------|--------|-----|
| Bug | Rosso (#ef4444) | Bug e fix |
| Feature | Verde (#22c55e) | Nuove funzionalità |
| Task | Blu (#3b82f6) | Task generici |
| Urgent | Arancione (#f97316) | Priorità urgente |
| Recurring | Viola (#8b5cf6) | Task ricorrenti |

---

## Requisiti

- PHP 8.2+
- Estensione cURL (per AI e GitHub)
- Permessi di scrittura (per `_Ykan_data.json`)
- Hosting: OVH condiviso (no VPS necessario)

---

## Changelog highlights

| Versione | Data | Novità principali |
|----------|------|-------------------|
| 1.8.0 | Luglio 2026 | Temi custom, MCP remote control, Projects |
| 1.7.0 | Dic 2025 | UI inglese, lingua AI configurabile |
| 1.6.0 | Dic 2025 | Integrazione GitHub |
| 1.5.0 | Dic 2025 | Search, filtri, Markdown, export, templates, TODO scanner, burndown |
| 1.0.0 | Dic 2025 | Release iniziale |
