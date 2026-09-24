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

// === Richieste catturate (per scoprire l'endpoint delle sessioni cloud) ===
function fmtAgo(ts) {
  const min = Math.max(0, Math.round((Date.now() - ts) / 60000));
  return min < 1 ? 'ora' : `${min} min fa`;
}

function renderCaptured(list) {
  const box = $('captureList');
  if (!list || !list.length) {
    box.innerHTML = '<div class="cap-empty">Nessuna richiesta catturata ancora.</div>';
    return;
  }
  box.innerHTML = list.map((e, i) => `
    <div class="cap-row" data-idx="${i}">
      <div class="cap-url">${e.method} ${e.url}</div>
      <div class="cap-meta">${fmtAgo(e.ts)}</div>
      <div class="cap-actions">
        <button class="probe-btn" data-url="${encodeURIComponent(e.url)}">Anteprima</button>
        <button class="copy-one-btn" data-url="${encodeURIComponent(e.url)}">Copia URL</button>
      </div>
      <div class="cap-preview"></div>
    </div>
  `).join('');

  box.querySelectorAll('.probe-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const url = decodeURIComponent(btn.dataset.url);
      const preview = btn.closest('.cap-row').querySelector('.cap-preview');
      btn.disabled = true;
      preview.style.display = 'block';
      preview.textContent = 'Carico…';
      try {
        const res = await chrome.runtime.sendMessage({ type: 'probe-url', url });
        preview.textContent = res.error
          ? `Errore: ${res.error}`
          : `HTTP ${res.status}\n\n${res.preview || '(vuoto)'}`;
      } finally {
        btn.disabled = false;
      }
    });
  });
  box.querySelectorAll('.copy-one-btn').forEach(btn => {
    btn.addEventListener('click', () => navigator.clipboard.writeText(decodeURIComponent(btn.dataset.url)));
  });
}

async function loadCaptured() {
  const list = await chrome.runtime.sendMessage({ type: 'get-captured' });
  renderCaptured(list || []);
}

$('copyCapturedBtn').addEventListener('click', async () => {
  const list = await chrome.runtime.sendMessage({ type: 'get-captured' });
  await navigator.clipboard.writeText(JSON.stringify(list || [], null, 2));
  $('copyCapturedBtn').textContent = '✅';
  setTimeout(() => { $('copyCapturedBtn').textContent = '📋'; }, 1200);
});

$('clearCapturedBtn').addEventListener('click', async () => {
  await chrome.runtime.sendMessage({ type: 'clear-captured' });
  loadCaptured();
});

loadCaptured();
