# Ykan Usage Badge

Estensione Chrome/Edge minimale: legge il consumo Claude (5 ore / settimanale)
da claude.ai — stesso meccanismo delle estensioni "Claude Usage Meter" già in
circolazione (riusa il cookie di sessione del browser, nessun token da
incollare) — e lo mostra:

- come badge numerico sulla toolbar (5 ore)
- nel popup dell'icona (5 ore + settimanale, con refresh manuale)
- **nell'header della dashboard Ykan**, quando è aperta: un content-script
  spinge i dati dentro la pagina via `postMessage`, letti da `_Ykan.php`
  (badge accanto alle icone Gemini/Archive/GitHub). Senza l'estensione
  installata quel badge resta semplicemente nascosto — Ykan non fa nessuna
  chiamata da sé.

## Installazione (developer mode — non è sul Web Store)

1. Apri `chrome://extensions` (o `edge://extensions`)
2. Attiva "Modalità sviluppatore" (in alto a destra)
3. "Carica estensione non pacchettizzata" → seleziona questa cartella (`extension/`)
4. Assicurati di essere loggato su https://claude.ai in quel browser — l'estensione
   riusa la sessione già attiva, non chiede credenziali

Si aggiorna da sola ogni minuto (il minimo che Chrome permette per un alarm —
è il "come se cliccassi aggiorna ogni 60 secondi" chiesto esplicitamente).
Un throttle di 45s protegge comunque da doppie chiamate se un refresh manuale
capita a ridosso di quello automatico.

## File

- `manifest.json` — Manifest V3, permessi minimi (`storage`, `alarms`,
  host permission solo su claude.ai e ykan.portale3d.it)
- `background.js` — service worker: fetch periodico, badge, cache
- `content-script.js` — gira solo su ykan.portale3d.it, inietta i dati nella pagina
- `popup.html` / `popup.js` — riepilogo al click sull'icona

## Note

Endpoint usato: `https://claude.ai/api/organizations/{orgId}/usage` — non
documentato pubblicamente, è quello che usa la web app di claude.ai stessa
(e le estensioni open-source equivalenti, es. github.com/Nachtalb/claude-usage-meter,
da cui questa è adattata). Se Anthropic lo cambia, l'estensione smette di
funzionare finché non si aggiorna il parsing in `background.js`.

## Cattura richieste (per trovare l'endpoint delle sessioni cloud)

Sezione nel popup che osserva (passivamente, nessun blocco/modifica) le
richieste XHR/fetch che il browser fa verso `claude.ai`, per scoprire quale
endpoint interno usa `claude.ai/code` per elencare le sessioni cloud — non è
documentato, quindi va scoperto guardando cosa chiama davvero la pagina.

Uso:
1. Ricarica l'estensione (`chrome://extensions` → icona ↻ su "Ykan Usage Badge").
   Al primo reload dopo questa modifica Chrome può chiedere di confermare il
   nuovo permesso `webRequest` (solo osservazione, scoped a claude.ai/ykan).
2. Vai su `claude.ai/code`, apri la lista delle sessioni (quella che nella UI
   mostra le sessioni cloud), lasciala caricare.
3. Apri il popup dell'estensione: sotto "Richieste catturate su claude.ai"
   trovi le URL osservate (esclusi rumore noto: usage, telemetria, stream di
   completion, statsig, sentry...).
4. Per ciascuna, "Anteprima" ri-legge quella URL (stesso cookie di sessione,
   nessun altro dato) e mostra i primi ~4000 caratteri della risposta — utile
   per capire al volo se è quella giusta senza aprire DevTools.
5. "📋" copia l'intera lista catturata come JSON negli appunti.

La lista resta in `chrome.storage.local` (max 150 voci, le più vecchie
vengono scartate) finché non premi "🗑 Svuota" — nessun dato lascia il
browser se non quando lo copi/incolli tu stesso.
