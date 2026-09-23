// Content script per la dashboard Ykan.
// Non tocca il DOM di Ykan direttamente (troppo fragile se cambia il markup):
// passa solo i dati via window.postMessage. È _Ykan.php a decidere come/dove
// disegnarli — vedi l'handler "message" in _Ykan.php.

const MSG_SOURCE = 'ykan-usage-ext';

function postToPage(stored) {
  window.postMessage({
    source: MSG_SOURCE,
    type: 'usage',
    usage: stored ? stored.usage : null,
    fetchedAt: stored ? stored.fetchedAt : null,
    error: stored ? stored.error : null,
    errorKind: stored ? stored.errorKind : null
  }, window.location.origin);
}

// Stato iniziale appena la pagina carica.
chrome.runtime.sendMessage({ type: 'get-usage' }, stored => postToPage(stored));

// Si aggiorna da solo ogni volta che il background scrive un nuovo risultato
// (alarm periodico, o un refresh forzato da un'altra tab) — nessun polling qui.
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && changes.ykanUsageData) postToPage(changes.ykanUsageData.newValue);
});

// La pagina può chiedere un refresh immediato (es. bottone "↻" sul badge usage).
window.addEventListener('message', e => {
  if (e.source !== window || e.origin !== window.location.origin) return;
  if (e.data && e.data.source === 'ykan-usage-page' && e.data.type === 'refresh') {
    chrome.runtime.sendMessage({ type: 'refresh-usage', force: true }, stored => postToPage(stored && stored.stored));
  }
});
