# _Ykan — Documentazione Progetto

> Ultimo aggiornamento: 2026-10-30 · versione board 2.2.x · Bridge 0.9.5
> Per AI: questo file descrive **cosa fa** Ykan. Per **dove sta il codice** vedi STRUTTURA.md.

---

## Cos'è _Ykan

_Ykan è un **Kanban single-file in PHP** che fa anche da centro di controllo per Claude Code.
- **Board** (`_Ykan.php`): backend PHP, HTML, CSS e JS in un unico file. Online su `ykan.portale3d.it` (OVH).
- **Bridge** (`bridge/bridge-server.js`, porta `127.0.0.1:51820`): piccolo server Node sul PC. Legge e scrive ciò che sta sul computer (sessioni di Claude Code, git, skill, plugin, terminale) e lo espone alla board.
- **MCP** (`mcp.php`): tool per Claude (task, file, DB, mail) usati dalle sessioni e dalle routine.

**Repository:** github.com/YuriNuresi/_Ykan · **Licenza:** MIT

## Dove stanno i dati

| Modalità | Dove | Note |
|---|---|---|
| Online (con login Google) | MySQL `ykan_boards` (una riga JSON per utente) | Dal 28/09/2026. `_Ykan_data.json` su OVH è una copia ferma |
| Locale (senza login) | `_Ykan_data.json` accanto a `_Ykan.php` | Stesso formato |
| PC collegati | `ykan_bridges`, `ykan_bridge_cmds` | Registro dei Bridge e coda del relay |
| Cartelle dei progetti per PC | `~/.ykan-lane-paths.json` sul PC | Tenute dal Bridge, prevalgono su quella della board |

---

## Funzionalità

### Kanban
- **Progetti** (swimlane) con colore, collegamenti (cartella OVH, URL, cartella locale, repo GitHub, Project di claude.ai).
- **Righe versione** dentro ogni progetto: la prima è sempre "attuale", le altre partono chiuse. Trascinare una card tra righe ne cambia la versione; ogni card ha lo storico delle versioni con le date. Da riga 2 in giù: "promuovi task a". Eliminando una riga si sceglie dove spostare i task aperti; quelli in Done vanno in archivio. Le versioni eliminate restano come archiviate.
- **Card**: priorità, etichetta, scadenza, file, descrizione, ID breve (`#25`), task autorigeneranti, **categoria di lavoro** (icona sulla card).
- **Menu del tasto destro** su card, celle, righe versione e intestazioni dei progetti; su mobile lo stesso menu si apre col 🔧.
- **Archivio** come pagina dentro il kanban, raggruppato per progetto, con ricerca e filtri (progetto, versione, etichetta, periodo).
- Export JSON/CSV, temi chiaro/scuro e temi custom (`themes/`).

### Home e Progetti (dashboard)
- **Home**: cruscotto con stato dei progetti, andamento e righe cliccabili.
- **Progetti**, schede: Focus · PM · Da riprendere · Ultimi 7 giorni · Revisione sessioni · Git · Graphify.
- **Git**: stato di ogni repository (branch, modifiche, ahead/behind, ultimo commit) + GitHub del progetto scelto (Commits, PR, Issues con badge). Bottone **📝 Commit** su ogni repo.
- **PM**: layer spec-driven (PRD → epic → card con dipendenze, stime, acceptance). Vedi skill `ykan-pm`.

### Commit e push dalla board
Finestra con i file cambiati (spunta), diff colorato, messaggio.
- **✨ Proponi messaggio** e **🔍 Revisione Claude** (verdetto ok/attenzione/blocca con file e riga), **🧪 test** se il progetto ha Pest, PHPUnit o `npm test`.
- Regole: solo i file scelti; `.env`, chiavi, `_Ykan_data.json` e file oltre 5 MB bloccati; push mai `--force` e saltato se GitHub è più avanti.

### Claude
Pannello con schede: Skill · Plugin · Memoria · Contesto · **Modalità** · Remote Control · Routine · Novità · WhatsApp.
- **Contesto**: cosa entra in ogni sessione (plugin, skill, MCP, CLAUDE.md) con interruttori per progetto.
- **Modalità di lavoro**: insiemi di plugin e skill per tipo di lavoro (Manutenzione, Feature, UI, Prototipo, Dati, Analisi, Videogiochi, Commerciale…). Una per progetto alla volta; il Bridge la applica in `.claude/settings.local.json` e un hook SessionStart inietta il contesto generato da Claude. **Fissi** globali e per progetto restano sempre accesi. "✨ Modalità suggerite" crea le predefinite; "Proponi con Claude" sceglie le skill.
- **Categoria del task** = una modalità: se diversa da quella del progetto, al lancio Ykan chiede quale usare. "✨ Suggerisci categoria": prima Claude dal Bridge (legge il progetto), poi l'AI della board.
- **Routine**: quelle cloud di claude.ai (con «Esegui ora» via trigger API) e quelle **locali di Claude Desktop** (`~/.claude/scheduled-tasks`, prompt leggibile e modificabile; orario e pausa restano in Desktop).

### Lavorare con Claude su un task
Dalla card: Claude Terminale (terminale dentro Ykan), Claude Desktop (deep link + resume), Claude Web, Claude Agent API, AI Verify. Le sessioni Claude Code del progetto si vedono, si riprendono e si archiviano dalla board.

### Terminale
Shell vera via Bridge (node-pty + WebSocket). xterm.js viene servito dal Bridge (funziona senza internet), con CDN come riserva.

### PC collegati (multi-PC)
Login Google; ogni PC si collega con un codice e la board sceglie su quale PC lavorare. Da fuori casa i comandi passano dal **relay** OVH ↔ Bridge (timeout ~20 s: le operazioni lunghe di Claude funzionano solo in locale).

### Another AI
Assistente del pannello e funzioni AI della board con Gemini, Groq, ChatGPT od Ollama (Settings → Another AI; chiavi anche da `.env`). Alcune funzioni provano in ordine i provider che hanno una chiave.

### Altro
Estensione Chrome (badge consumo Claude), sincronizzazione Google Tasks, mail di riepilogo via Gmail, bot WhatsApp via Bridge, Graphify (grafo del progetto).

---

## Bridge

- Avvio: `ykan-bridge.exe` (pacchetto zip) con icona nella tray (`tray.ps1`: Riavvia, Aggiorna, Log); dai sorgenti `start-bridge.bat`.
- **Attenzione**: se nella cartella dei sorgenti gira `ykan-bridge.exe`, il codice è quello compilato, non `bridge-server.js`. Dopo ogni modifica: `npm run build` e copiare `dist/ykan-bridge/ykan-bridge.exe` (l'aggiornamento automatico vale solo per il pacchetto zip).
- Sicurezza: solo `127.0.0.1`; Origin ammessa obbligatoria; con un account collegato serve il biglietto (`X-Ykan-Ticket`); le cartelle devono essere permesse al profilo; le scritture solo via POST JSON. Le chiamate remote passano solo se il percorso è in `RELAY_PATHS` (Bridge) **e** `YKAN_RELAY_PATHS` (board).

## Sicurezza della board
- Online: login Google, dati per utente; azioni distruttive con conferma.
- Chiavi in `.env` (fuori dal file dati quando possibile); i token GitHub/AI in config restano sul server.
- `.htaccess`: niente `ServerTokens` (su OVH manda in 500 tutto il sottodominio).

## Configurazione (`.env` principale)
`ANTHROPIC_KEY`, `GITHUB_TOKEN`, `GEMINI_API_KEY`, `GROQ_API_KEY` / `GROQ_API_URL`, `OPENAI_API_KEY`, `OLLAMA_URL` / `OLLAMA_MODEL`, `MCP_ROOT`, credenziali MySQL e Google OAuth.

## Flusso di lavoro
1. Modifica in locale → lint (`php -l` con Herd PHP 8.4; JS: estrai lo script e `node --check`).
2. Diff col server se il file live può essere più avanti → upload su OVH (`ykan/_Ykan.php`).
3. Yuri prova → ritocchi → **solo allora** commit e push.
