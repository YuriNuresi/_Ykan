// Background service worker per Ykan Usage Badge.
//
// Legge il consumo da claude.ai e aggiorna il badge della toolbar con la
// percentuale della finestra di 5 ore. Il popup e il content-script (che lo
// inietta nella dashboard Ykan) leggono lo stesso dato cachato in
// chrome.storage.local — non fanno fetch propri.
//
// Regole anti-abuso (da rispettare sempre, non aggirarle "per fare prima"):
//   1. Un solo alarm periodico guida i refresh automatici.
//   2. Refresh manuali (popup) passano dalla stessa soglia MIN_FETCH_INTERVAL_MS.
//   3. Se un fetch è già in corso, chi lo richiede riusa la stessa promise.
//   4. NESSUN listener su cookies.onChanged: claude.ai ruota il cookie di
//      sessione ad ogni risposta, ascoltarlo crea un loop infinito fetch→rotazione→fetch.

const ALARM_NAME = 'ykan-usage-refresh';
const REFRESH_MINUTES = 1; // minimo consentito da chrome.alarms — non si può andare più frequenti
const MIN_FETCH_INTERVAL_MS = 45 * 1000; // margine di sicurezza sotto il minuto, evita doppie chiamate se un refresh manuale capita vicino all'alarm
const STORAGE_KEY = 'ykanUsageData';
const ORG_STORAGE_KEY = 'ykanUsageOrgId';
const LAST_FETCH_KEY = 'ykanUsageLastFetch';

class AuthError extends Error {
  constructor(msg) { super(msg); this.name = 'AuthError'; }
}

async function getActiveOrganizationId() {
  const cached = await chrome.storage.local.get(ORG_STORAGE_KEY);
  if (cached[ORG_STORAGE_KEY]) return cached[ORG_STORAGE_KEY];

  const res = await fetch('https://claude.ai/api/organizations', {
    credentials: 'include',
    headers: { 'Accept': 'application/json' }
  });
  if (!res.ok) {
    if (res.status === 401 || res.status === 403) throw new AuthError(`organizations -> ${res.status}`);
    throw new Error(`organizations -> ${res.status}`);
  }
  const orgs = await res.json();
  if (!Array.isArray(orgs) || orgs.length === 0) throw new Error('nessuna organizzazione');
  const pick = orgs.find(o => Array.isArray(o.capabilities) && o.capabilities.includes('chat')) || orgs[0];
  const orgId = pick.uuid || pick.id;
  if (!orgId) throw new Error('organizzazione senza uuid');
  await chrome.storage.local.set({ [ORG_STORAGE_KEY]: orgId });
  return orgId;
}

async function fetchUsage() {
  const orgId = await getActiveOrganizationId();
  const res = await fetch(`https://claude.ai/api/organizations/${orgId}/usage`, {
    credentials: 'include',
    headers: { 'Accept': 'application/json' }
  });
  if (!res.ok) {
    if (res.status === 401 || res.status === 403) {
      await chrome.storage.local.remove(ORG_STORAGE_KEY);
      throw new AuthError(`usage -> ${res.status}`);
    }
    throw new Error(`usage -> ${res.status}`);
  }
  const data = await res.json();
  return {
    fiveHour: data.five_hour || null,        // { utilization, resets_at }
    weekly: data.seven_day || null           // { utilization, resets_at }
  };
}

function pickBadgeColor(pct) {
  if (pct >= 90) return '#C8533C';
  if (pct >= 70) return '#D98639';
  return '#3A6FD9';
}

async function applyBadge(usage, errorKind) {
  if (errorKind === 'auth') {
    await chrome.action.setBadgeText({ text: '?' });
    await chrome.action.setBadgeBackgroundColor({ color: '#999999' });
    await chrome.action.setTitle({ title: 'Ykan Usage — accedi a claude.ai' });
    return;
  }
  if (errorKind === 'generic') {
    await chrome.action.setBadgeText({ text: '!' });
    await chrome.action.setBadgeBackgroundColor({ color: '#999999' });
    await chrome.action.setTitle({ title: 'Ykan Usage — lettura fallita' });
    return;
  }
  const pct = Math.round(usage?.fiveHour?.utilization ?? 0);
  await chrome.action.setBadgeText({ text: Number.isFinite(pct) ? String(pct) : '' });
  await chrome.action.setBadgeBackgroundColor({ color: pickBadgeColor(pct) });
  if (chrome.action.setBadgeTextColor) { try { await chrome.action.setBadgeTextColor({ color: '#FFFFFF' }); } catch (_) {} }
  const wk = Math.round(usage?.weekly?.utilization ?? 0);
  await chrome.action.setTitle({ title: `Ykan Usage — 5h: ${pct}% · Settimana: ${wk}%` });
}

let inFlight = null;

async function doRefresh() {
  try {
    const usage = await fetchUsage();
    await chrome.storage.local.set({
      [STORAGE_KEY]: { usage, fetchedAt: Date.now(), error: null, errorKind: null },
      [LAST_FETCH_KEY]: Date.now()
    });
    await applyBadge(usage, null);
    return { ok: true };
  } catch (err) {
    const isAuth = err && err.name === 'AuthError';
    console.warn('[Ykan Usage] refresh fallito:', err);
    await chrome.storage.local.set({
      [STORAGE_KEY]: { usage: null, fetchedAt: Date.now(), error: String(err && err.message || err), errorKind: isAuth ? 'auth' : 'generic' },
      [LAST_FETCH_KEY]: Date.now()
    });
    await applyBadge(null, isAuth ? 'auth' : 'generic');
    return { ok: false };
  }
}

async function refreshAndUpdateBadge({ force = false } = {}) {
  if (inFlight) return inFlight;
  const { [LAST_FETCH_KEY]: last = 0 } = await chrome.storage.local.get(LAST_FETCH_KEY);
  if (!force && Date.now() - last < MIN_FETCH_INTERVAL_MS) return { ok: true, skipped: true };
  inFlight = doRefresh().finally(() => { inFlight = null; });
  return inFlight;
}

function ensureAlarm() {
  chrome.alarms.get(ALARM_NAME, existing => {
    // Ricrea l'alarm anche se esiste già ma con un periodo diverso (es. installazioni
    // precedenti a quando REFRESH_MINUTES è cambiato) — altrimenti resta bloccato al vecchio.
    if (!existing || existing.periodInMinutes !== REFRESH_MINUTES) {
      chrome.alarms.create(ALARM_NAME, { periodInMinutes: REFRESH_MINUTES, delayInMinutes: 0 });
    }
  });
}

chrome.runtime.onInstalled.addListener(() => { ensureAlarm(); refreshAndUpdateBadge(); });
chrome.runtime.onStartup.addListener(() => { ensureAlarm(); refreshAndUpdateBadge(); });
chrome.alarms.onAlarm.addListener(alarm => { if (alarm.name === ALARM_NAME) refreshAndUpdateBadge(); });

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg && msg.type === 'refresh-usage') {
    refreshAndUpdateBadge({ force: !!msg.force })
      .then(async () => {
        const stored = await chrome.storage.local.get(STORAGE_KEY);
        sendResponse({ ok: true, stored: stored[STORAGE_KEY] || null });
      })
      .catch(err => sendResponse({ ok: false, error: String(err) }));
    return true; // risposta asincrona
  }
  if (msg && msg.type === 'get-usage') {
    chrome.storage.local.get(STORAGE_KEY).then(stored => sendResponse(stored[STORAGE_KEY] || null));
    return true;
  }
  if (msg && msg.type === 'get-captured') {
    chrome.storage.local.get(CAPTURE_KEY).then(stored => sendResponse(stored[CAPTURE_KEY] || []));
    return true;
  }
  if (msg && msg.type === 'clear-captured') {
    chrome.storage.local.set({ [CAPTURE_KEY]: [] }).then(() => sendResponse({ ok: true }));
    return true;
  }
  if (msg && msg.type === 'probe-url') {
    probeUrl(msg.url).then(sendResponse);
    return true;
  }
});

// === CATTURA RICHIESTE — per scoprire l'endpoint (non documentato) che claude.ai/code
// usa per elencare le sessioni cloud. Stesso principio di fetchUsage(): nessun token,
// solo osservazione passiva di richieste che il browser fa già col cookie di sessione.
// Non blocca né modifica nulla (nessun listener "blocking"), legge solo url/metodo.
const CAPTURE_KEY = 'ykanCapturedRequests';
const CAPTURE_LIMIT = 150;
// Rumore noto da escludere: telemetria, lo stream di completion (enorme e frequente),
// lo usage (già coperto sopra), asset statici.
const IGNORE_SUBSTR = ['/usage', 'statsig', 'sentry', 'ingest', 'telemetry', 'completion', 'typing', '/gate', 'doubleclick', 'analytics', 'segment.'];

function shouldIgnoreUrl(url) {
  return IGNORE_SUBSTR.some(s => url.includes(s));
}

async function logCapturedRequest(entry) {
  const { [CAPTURE_KEY]: list = [] } = await chrome.storage.local.get(CAPTURE_KEY);
  if (list.some(e => e.url === entry.url && e.method === entry.method)) return; // già vista
  list.unshift(entry);
  if (list.length > CAPTURE_LIMIT) list.length = CAPTURE_LIMIT;
  await chrome.storage.local.set({ [CAPTURE_KEY]: list });
}

chrome.webRequest.onBeforeRequest.addListener(
  details => {
    if (details.type !== 'xmlhttprequest') return;
    if (shouldIgnoreUrl(details.url)) return;
    logCapturedRequest({ url: details.url, method: details.method, ts: Date.now() });
  },
  { urls: ['https://claude.ai/*'] }
);

// Ri-legge una URL già catturata (solo GET, stesso cookie del browser) e ne restituisce
// un'anteprima — così si capisce dal popup, senza aprire DevTools, se è quella giusta.
async function probeUrl(url) {
  try {
    const res = await fetch(url, { credentials: 'include', headers: { 'Accept': 'application/json' } });
    const text = await res.text();
    let preview = text.slice(0, 4000);
    try { preview = JSON.stringify(JSON.parse(text), null, 2).slice(0, 4000); } catch (_) { /* non JSON, va bene il testo grezzo */ }
    return { ok: res.ok, status: res.status, preview };
  } catch (err) {
    return { ok: false, status: 0, preview: '', error: String(err && err.message || err) };
  }
}
