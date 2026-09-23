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

Si aggiorna da sola ogni 10 minuti (throttle minimo 30s anche sui refresh manuali,
per non martellare claude.ai). Aprendo il popup o il badge su Ykan forza un
refresh se il dato in cache ha più di un minuto.

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
