const $ = id => document.getElementById(id);

function fmtPct(v) { return (v === null || v === undefined || Number.isNaN(v)) ? '—' : `${Math.round(v)}%`; }

function setBar(el, pct) {
  const p = Math.max(0, Math.min(100, Number(pct) || 0));
  el.style.width = p + '%';
  el.classList.remove('warn', 'danger');
  if (pct >= 90) el.classList.add('danger');
  else if (pct >= 70) el.classList.add('warn');
}

function fmtReset(iso) {
  if (!iso) return '';
  const diffMs = new Date(iso).getTime() - Date.now();
  if (diffMs <= 0) return 'si azzera ora';
  const min = Math.floor(diffMs / 60000);
  const hr = Math.floor(min / 60);
  if (hr < 24) return `si azzera tra ${hr > 0 ? hr + 'h ' : ''}${min % 60}min`;
  const days = Math.floor(hr / 24);
  return `si azzera tra ${days}g`;
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
  $('fiveHourReset').textContent = fmtReset(fh.resets_at);
  $('weeklyPct').textContent = fmtPct(wk.utilization);
  setBar($('weeklyFill'), wk.utilization);
  $('weeklyReset').textContent = fmtReset(wk.resets_at);

  $('lastUpdated').textContent = stored && stored.fetchedAt
    ? 'Aggiornato ' + Math.max(0, Math.round((Date.now() - stored.fetchedAt) / 60000)) + ' min fa'
    : '';
}

async function load() {
  const res = await chrome.storage.local.get('ykanUsageData');
  render(res.ykanUsageData || null);
}

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
