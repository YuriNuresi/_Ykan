const $ = id => document.getElementById(id);

function fmtPct(v) { return (v === null || v === undefined || Number.isNaN(v)) ? '—' : `${Math.round(v)}%`; }

function setBar(el, pct) {
  const p = Math.max(0, Math.min(100, Number(pct) || 0));
  el.style.width = p + '%';
  el.classList.remove('warn', 'danger');
  if (pct >= 90) el.classList.add('danger');
  else if (pct >= 70) el.classList.add('warn');
}

// Tempo mancante al reset, es. "1h 33m" / "5g 3h"; '' se il dato non c'è.
function fmtLeft(iso) {
  if (!iso) return '';
  const diffMs = new Date(iso).getTime() - Date.now();
  if (diffMs <= 0) return 'ora';
  const min = Math.floor(diffMs / 60000);
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr > 0 ? hr + 'h ' : ''}${min % 60}m`;
  return `${Math.floor(hr / 24)}g ${hr % 24}h`;
}

function fmtReset(iso, at) {
  const left = fmtLeft(iso);
  if (!left) return '';
  if (left === 'ora') return '↺ Reset <b>adesso</b>';
  const when = new Date(iso).toLocaleString('it-IT', at === 'time'
    ? { hour: '2-digit', minute: '2-digit' }
    : { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  return `↺ Reset tra <b>${left}</b> · ${when}`;
}

function render(stored) {
  const errBox = $('errorBox');
  if (!stored || (!stored.usage && stored.errorKind === 'auth')) {
    errBox.style.display = 'block';
    errBox.textContent = 'Non sei loggato su claude.ai in questo browser.';
  } else if (stored && stored.error) {
    errBox.style.display = 'block';
    errBox.textContent = 'Lettura fallita: ' + stored.error;
  } else {
    errBox.style.display = 'none';
  }

  const fh = (stored && stored.usage && stored.usage.fiveHour) || {};
  const wk = (stored && stored.usage && stored.usage.weekly) || {};
  $('fiveHourPct').textContent = fmtPct(fh.utilization);
  setBar($('fiveHourFill'), fh.utilization);
  $('fiveHourReset').innerHTML = fmtReset(fh.resets_at, 'time');
  $('weeklyPct').textContent = fmtPct(wk.utilization);
  setBar($('weeklyFill'), wk.utilization);
  $('weeklyReset').innerHTML = fmtReset(wk.resets_at);

  $('lastUpdated').textContent = stored && stored.fetchedAt
    ? 'Aggiornato ' + Math.max(0, Math.round((Date.now() - stored.fetchedAt) / 60000)) + ' min fa'
    : '';
}

async function load() {
  const res = await chrome.storage.local.get('ykanUsageData');
  render(res.ykanUsageData || null);
}

// Il conto alla rovescia avanza anche a popup aperto.
setInterval(load, 30000);

$('refreshBtn').addEventListener('click', async () => {
  $('refreshBtn').disabled = true;
  try {
    const resp = await chrome.runtime.sendMessage({ type: 'refresh-usage', force: true });
    render(resp && resp.stored);
  } finally {
    $('refreshBtn').disabled = false;
  }
});

load();

