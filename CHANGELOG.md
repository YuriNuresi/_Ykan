# Changelog

> Ultimo aggiornamento: 2026-10-30

Tutte le modifiche rilevanti di Ykan (board `_Ykan.php`, server MCP `mcp.php`, Bridge locale `bridge/`, estensione).
Formato [Keep a Changelog](https://keepachangelog.com/it/1.1.0/). Le versioni del Bridge sono indicate a parte.

## [Non rilasciato]

### Aggiunto
- Menu 📚 «Documentazione» sui progetti con cartella locale: crea o aggiorna DOCUMENTAZIONE.md, STRUTTURA.md, CHANGELOG.md e README.md aprendo Claude Code nel terminale con la skill `ykan-docs`.
- Il menu mostra lo stato di ogni documento: manca, aggiornato, o più vecchio dell'ultimo commit.
- «Note di progettazione della sessione»: si sceglie una sessione di Claude Code e Claude salva decisioni e prossimi passi in `docs/design/`.
- Bridge: endpoint `/docs/status` (anche via relay) che legge presenza, data e righe dei documenti del progetto.
- Questo `CHANGELOG.md` e un `CLAUDE.md` che rimanda ai documenti del progetto.

## [2026-10-30]

### Aggiunto
- Commit dalla board: finestra con diff, blocco dei segreti, messaggio e revisione scritti da Claude, test prima del commit, push senza force.
- Righe versione nel Kanban: ogni progetto ha più righe (la prima è la versione attuale); si aggiungono, rinominano, spostano e promuovono; i Done di una riga eliminata vanno in archivio. Storico delle versioni con date su ogni task e select «Versione» nel popup.
- Menu tasto destro (e chiave inglese) su task, celle, righe e progetti.
- Modalità di lavoro: plugin e skill per tipo di lavoro, globali o per progetto, applicate dal Bridge in `settings.local.json` con hook SessionStart; 8 modalità suggerite (tra cui Videogiochi e Commerciale).
- Categoria del task con icona nel Kanban e «Suggerisci» (Claude dal Bridge, poi l'AI della board con riserva tra provider).
- Routine locali di Claude Desktop nel pannello Routine, con prompt modificabile.
- GitHub per progetto in Progetti → Git (Commits/PR/Issues con badge) e test della connessione che verifica il token.

### Cambiato
- Archivio e PM diventano pagine (Archivio nel Kanban, per progetto e con filtri; PM in Progetti).
- Progetti sempre aperti: si aprono e chiudono le righe versione (all'avvio solo quella attuale del primo progetto).
- Le etichette «stable»/«prossima versione» migrano nelle righe versione, con backup.
- La cartella locale dei progetti è salvata nel Bridge di ogni PC.
- xterm.js servito dal Bridge, CDN solo come riserva.
- Bridge 0.9.5. Favicon inline. DOCUMENTAZIONE.md e STRUTTURA.md aggiornati.

### Rimosso
- Bottoni duplicati negli header (ora nel menu tasto destro).

## [2.2.0] - 2026-10-29

### Aggiunto
- Home: KPI con icona, tinta e andamento a 7 giorni; fasce token di oggi dal primo uso; consumo settimanale sul piano lun-ven (90%) + weekend (10%), ambra se si consuma più del previsto; griglia 3×3 di widget con righe che portano a progetto, card, chat di Claude Desktop o WhatsApp.
- Another AI: linguetta sul bordo destro in tutte le pagine, con tasti proposti per pagina.
- «Apri in Claude Desktop» da Home (ultime chat) e da Progetti → Revisione.
- Terminali: scelta tra cmd, PowerShell, Claude Code, cartella del progetto e sito online.
- Bridge 0.9.4: PowerShell, `/open-folder` (Windows/Mac), `/claude/ask`, istruzioni WhatsApp per numero, zip macOS in beta.

### Cambiato
- Stile «bento» in Kanban, Progetti, Claude e Settings: header dei progetti col loro colore (scelto con la matita, salvato in `swimlane.color`), card tinte per urgenza, popup del task largo a due colonne di riquadri, avvisi tinti per gravità.
- Sidebar fissa a tutta altezza; il pannello terminali si apre alla sua destra.
- I terminali non aprono più una shell in automatico.

### Corretto
- Aggiornamento automatico del Bridge.

## [2.1.0] - 2026-10-28

### Aggiunto
- WhatsApp nel Bridge: il PC si collega come dispositivo WhatsApp (QR code) e Claude Code risponde ai numeri in whitelist; pagina WhatsApp nel pannello Claude.
- Chat con Ykan dalla propria chat WhatsApp: card della board via `mcp.php`, file del progetto con `#progetto`, `#comandi` per l'elenco; niente shell, niente `.env`.
- Scheda Novità: repo più stellati e release di Claude Code.
- Bridge 0.9.0: profili (più account, cartelle scelte sul PC) e aggiornamento automatico dalla tray o dalla board.
- `mcp.php` accetta il token di un PC collegato (solo strumenti della board), anche nell'header `X-Ykan-Key`.

### Rimosso
- Webhook WhatsApp Cloud API di Meta, sostituito dal dispositivo collegato (Baileys).

## [2.0.0] - 2026-10-28

### Aggiunto
- Console leggibile: ogni riga dice chi ha agito (tu, Bridge, Claude, altri siti) e cosa; le chiamate MCP di Claude finiscono nel registro `ykan_activity` (7 giorni) letto da `?api=activity_feed`.
- Pagine Focus, Da riprendere, Attività, Revisione sessioni, Git e Graphify nel menu laterale, caricate un po' alla volta.
- Indicatori Claude (CLI via Bridge / chiave API sul server) e Git (locale via Bridge / token GitHub) sotto «Questo computer».
- Graphify leggibile: aree con nome, tutto schermo, esportazione PNG grande; iframe con codice monouso.
- Context Manager (23/10): inventario di plugin, skill e MCP, override per progetto, analisi di CLAUDE.md, ottimizzazione della memoria con `claude` sul PC via Bridge.
- Settings → Claude Code: pulsante di login della CLI (verde = ok, rosso = serve login).
- Bridge scaricabile con tray e avvio automatico nascosto.

### Cambiato
- Nuovo layout: sidebar in stile Claude Desktop, Kanban e Dashboard con la stessa testata e geometria; la sidebar segue i temi.

## [2026-09-23 – 2026-10-01] (confluito in 2.0.0)

### Aggiunto
- Pannello Claude: skill, plugin e memoria per progetto gestibili dalla board; thread di Claude Code collegati alle card; Remote Control per progetto; routine con «Esegui ora»; `claude --desktop`.
- Login Google per la board online (modalità MySQL) con board separate per utente, funzioni del server solo al proprietario e chiavi MCP personali.
- Archivio dati su file JSON o MySQL; cartella del `.env` in Settings.
- PC collegati: il Bridge si lega all'account online, relay OVH ↔ Bridge, avvio di Claude Code e Claude Desktop sul PC scelto.
- Bridge nella tray di Windows; pannello terminale persistente con Console.
- Estensione Chrome «Ykan Usage Badge»: barre d'uso nell'header, aggiornate ogni minuto, reset del limite di 5 ore in evidenza.
- Settings come scheda vera, con download del Bridge e dell'estensione.
- Scheda Focus: avvio sessioni in terminale/Desktop/cloud, «attivo ora» dalle sessioni locali, punteggio spiegato, ordinamento manuale, ambito «Stable».

### Cambiato
- Header e Settings riorganizzati: azioni divise per contesto.
- Pulsanti Claude del task su una riga a parte (Agent API, Web, Terminale, Desktop).

## [1.9.0] - 2026-09

### Aggiunto
- Dashboard trasversale ai progetti (Da riprendere, Attività, Revisione sessioni, Git).
- Bridge locale (`bridge/`, Node) su `127.0.0.1`: storico e terminale delle sessioni di Claude Code.
- Strumenti database (`db_query`, `db_exec`, `db_schema`, `db_dump_table`) e mail (`send_digest_mail`) in `mcp.php`.
- Sincronizzazione opzionale con Google Tasks (`sync_google_tasks`).
- Routine «Claude Do» (oraria) e «Sistema Vacanze» (notturna); etichetta «Claude» per i task creati dall'AI.

### Cambiato
- `GITHUB_TOKEN` si legge dal `.env` del server, con priorità sul valore nei dati della board.

### Corretto
- Layout mobile: i controlli vanno a capo invece di uscire dallo schermo; i pannelli laterali non causano scroll orizzontale.

## [1.8.0] - 2026-07

### Aggiunto
- Temi personalizzati: JSON in `themes/` con selettore dal vivo, editor dei temi e modello documentato `themes/_TEMPLATE.jsonc`.
- Controllo remoto via MCP (`mcp.php`): board e file dei progetti dall'app mobile di Claude.
- Progetti collegati a cartelle, con navigazione sicura dei file.

### Cambiato
- `mcp.php` legge `MCP_SECRET` e `MCP_ROOT` dal `.env` invece che da costanti nel codice.

## [1.2.0 – 1.7.0] - 2025-12

### Aggiunto
- 1.7.0: interfaccia tradotta in inglese; lingua delle risposte AI configurabile (EN, IT, ES, FR, DE, PT).
- 1.6.0: integrazione GitHub (issue, PR, commit), statistiche del repo, card create dalle issue.
- 1.5.0: ricerca istantanea, filtri avanzati, Markdown, export JSON/CSV, modelli di card, categorizzazione e stima tempi con AI, Daily Standup AI, TODO Scanner, Burndown Chart.
- 1.4.0: file associati ai task, verifica dei task con AI, pannello changelog.
- 1.3.0: task che si rigenerano, con contatore.
- 1.2.0: guida all'integrazione AI, analisi del progetto con AI, task suggeriti dalla scansione del codice.
