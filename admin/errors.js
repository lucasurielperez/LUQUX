const API = '../api.php';
const POLL_MS = 5000;
let timer = null;

const el = (id) => document.getElementById(id);

function token() {
  return localStorage.getItem('admin_token') || '';
}

function setToken(t) {
  localStorage.setItem('admin_token', t);
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[c]));
}

async function call(action, params = {}, method = 'GET') {
  const query = new URLSearchParams({ action, ...params });
  const res = await fetch(`${API}?${query.toString()}`, {
    method,
    headers: {
      Authorization: `Bearer ${token()}`,
      'Content-Type': 'application/json',
    },
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data;
}

function rowDetail(row) {
  const extra = row.extra_json ?? row.extra ?? null;
  if (typeof extra === 'string') {
    try {
      return JSON.stringify(JSON.parse(extra), null, 2);
    } catch (_e) {
      return extra;
    }
  }

  return JSON.stringify(extra ?? {}, null, 2);
}

function getTime(row) {
  return row.timestamp || row.created_at || '';
}

async function loadLogs() {
  const level = el('level').value;
  const source = el('source').value;
  const data = await call('admin_error_logs', { limit: '200', level, source });
  const rows = Array.isArray(data.rows) ? data.rows : [];

  el('meta').textContent = `Último request_id: ${data.request_id || '-'} · Errores: ${rows.length} · Source: ${data.source || source}`;

  el('rows').innerHTML = rows.map((row) => {
    const message = String(row.message || '').slice(0, 160);
    const location = row.file ? `${row.file}:${row.line || ''}` : '-';
    const requestId = row.request_id || '-';
    const levelText = String(row.level || 'INFO').toUpperCase();

    return `<tr>
      <td>${esc(getTime(row))}</td>
      <td><span class="badge ${esc(levelText)}">${esc(levelText)}</span></td>
      <td>${esc(message)}</td>
      <td>${esc(location)}</td>
      <td><button data-request="${esc(requestId)}">${esc(requestId)}</button></td>
      <td><button data-detail='${esc(JSON.stringify(row))}'>Ver detalle</button></td>
    </tr>`;
  }).join('');

  document.querySelectorAll('button[data-detail]').forEach((btn) => {
    btn.onclick = () => {
      const row = JSON.parse(btn.dataset.detail || '{}');
      el('detailContent').textContent = rowDetail(row);
      el('detailModal').showModal();
    };
  });
}

async function clearLogs() {
  await call('admin_error_log_clear', {}, 'POST');
  await loadLogs();
}

function setupPolling() {
  if (timer) {
    clearInterval(timer);
    timer = null;
  }

  if (el('polling').checked) {
    timer = setInterval(() => {
      loadLogs().catch((e) => {
        el('meta').textContent = e.message;
      });
    }, POLL_MS);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  el('token').value = token();
  el('saveToken').onclick = () => setToken(el('token').value.trim());
  el('refresh').onclick = () => loadLogs().catch((e) => { el('meta').textContent = e.message; });
  el('clear').onclick = () => clearLogs().catch((e) => { el('meta').textContent = e.message; });
  el('polling').onchange = setupPolling;
  el('closeModal').onclick = () => el('detailModal').close();

  loadLogs().catch((e) => {
    el('meta').textContent = e.message;
  });
});
