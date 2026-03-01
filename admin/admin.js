const API = '../api.php';

function token() {
  return localStorage.getItem('admin_token') || '';
}

function setToken(t) {
  localStorage.setItem('admin_token', t);
}

async function call(action, method = 'GET', body = null) {
  const headers = {
    Authorization: `Bearer ${token()}`,
  };

  if (body !== null) {
    headers['Content-Type'] = 'application/json';
  }

  const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
    method,
    headers,
    body: body !== null ? JSON.stringify(body) : null,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.ok) {
    throw new Error(data.error || `HTTP ${res.status}`);
  }
  return data;
}

const el = (id) => document.getElementById(id);
const rowsOrEmpty = (rows) => (Array.isArray(rows) ? rows : []);


function toggleQrInputs() {
  const type = el('qrType').value;
  el('qrGame').style.display = type === 'game' ? '' : 'none';
  el('qrPoints').style.display = type === 'secret' ? '' : 'none';
}

async function createQrs() {
  const qr_type = el('qrType').value;
  const payload = {
    qr_type,
    count: Number(el('qrCount').value || 1),
    points_delta: Number(el('qrPoints').value || 0),
    game_code: el('qrGame').value || '',
  };

  const result = await call('admin_qr_create', 'POST', payload);
  const rows = rowsOrEmpty(result.rows);
  const wrap = el('qrGenerated');
  wrap.innerHTML = '';

  rows.forEach((r) => {
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `
      <div><strong>${esc(r.code)}</strong></div>
      <div class="muted" style="word-break:break-all">${esc(r.url)}</div>
      <div class="qr-img" style="margin:8px 0"></div>
      <button data-copy="${esc(r.url)}">Copiar link</button>
    `;
    wrap.appendChild(card);
    // eslint-disable-next-line no-new
    new QRCode(card.querySelector('.qr-img'), {
      text: r.url,
      width: 128,
      height: 128,
    });
  });

  wrap.querySelectorAll('button[data-copy]').forEach((btn) => {
    btn.onclick = async () => {
      await navigator.clipboard.writeText(btn.dataset.copy || '');
      el('qrMsg').textContent = 'Link copiado';
    };
  });

  el('qrMsg').textContent = `Generados: ${rows.length}`;
  await loadQrList();
}

async function toggleQr(id, is_active) {
  await call('admin_qr_toggle', 'POST', { id, is_active });
  await loadQrList();
}

function buildClaimQrUrl(code) {
  const claimUrl = new URL('../qr.html', window.location.href);
  claimUrl.searchParams.set('code', code || '');
  return claimUrl.toString();
}

function triggerDownloadFromDataUrl(dataUrl, filename) {
  const link = document.createElement('a');
  link.href = dataUrl;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

async function generateQrImage(code) {
  if (!code) throw new Error('Código QR inválido');

  const container = document.createElement('div');
  container.style.position = 'fixed';
  container.style.left = '-9999px';
  container.style.top = '-9999px';
  document.body.appendChild(container);

  try {
    // eslint-disable-next-line no-new
    new QRCode(container, {
      text: buildClaimQrUrl(code),
      width: 512,
      height: 512,
    });

    await new Promise((resolve) => setTimeout(resolve, 30));

    const canvas = container.querySelector('canvas');
    if (canvas) {
      triggerDownloadFromDataUrl(canvas.toDataURL('image/png'), `qr-${code}.png`);
      return;
    }

    const img = container.querySelector('img');
    if (img?.src) {
      triggerDownloadFromDataUrl(img.src, `qr-${code}.png`);
      return;
    }

    throw new Error('No se pudo generar la imagen del QR');
  } finally {
    document.body.removeChild(container);
  }
}

async function loadQrList() {
  const { rows } = await call('admin_qr_list');
  const items = rowsOrEmpty(rows);
  const tb = el('qrList').querySelector('tbody');
  tb.innerHTML = items.map((r) => `
    <tr>
      <td>${esc(r.created_at || '')}</td>
      <td>${esc(r.code || '')}</td>
      <td>${esc(r.qr_type || '')}</td>
      <td>${r.qr_type === 'game' ? esc(r.game_code || '') : Number(r.points_delta || 0)}</td>
      <td>${Number(r.is_active) === 1 ? '🟢' : '🔴'}</td>
      <td><button data-qid="${r.id}" data-next="${Number(r.is_active) === 1 ? 0 : 1}">${Number(r.is_active) === 1 ? 'Desactivar' : 'Activar'}</button></td>
      <td><button data-generate-qr="${esc(r.code || '')}">Generar</button></td>
    </tr>
  `).join('');

  tb.querySelectorAll('button[data-qid]').forEach((btn) => {
    btn.onclick = () => toggleQr(Number(btn.dataset.qid), Number(btn.dataset.next) === 1).catch((e) => alert(e.message));
  });

  tb.querySelectorAll('button[data-generate-qr]').forEach((btn) => {
    btn.onclick = () => generateQrImage(btn.dataset.generateQr || '').catch((e) => {
      el('qrMsg').textContent = `Error: ${e.message}`;
    });
  });
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[c]));
}

async function loadGames() {
  const { rows } = await call('games');
  const items = rowsOrEmpty(rows);
  const gameSelect = el('qrGame');
  gameSelect.innerHTML = items.map((g) => `<option value="${esc(g.code || '')}">${esc(g.name || g.code || '')}</option>`).join('');

  const tb = el('games').querySelector('tbody');
  tb.innerHTML = items.map((g) => `
    <tr>
      <td>${g.id}</td>
      <td>${g.is_active ? '🟢' : '🔴'}</td>
      <td>${esc(g.code || '')}</td>
      <td>${esc(g.name || '')}</td>
      <td><button data-id="${g.id}" data-next="${g.is_active ? 0 : 1}">
        ${g.is_active ? 'Desactivar' : 'Activar'}
      </button></td>
    </tr>
  `).join('');

  tb.querySelectorAll('button[data-id]').forEach((btn) => {
    btn.onclick = async () => {
      await call('games_toggle', 'POST', {
        id: Number(btn.dataset.id),
        is_active: Number(btn.dataset.next) === 1,
      });
      await loadGames();
    };
  });
}

function setupSectionJump() {
  const sectionJump = el('sectionJump');
  if (!sectionJump) return;

  const sections = Array.from(document.querySelectorAll('.card[id][data-title]'));
  sectionJump.innerHTML = `<option value="">Seleccionar…</option>${sections.map((section) => (
    `<option value="${esc(section.id)}">${esc(section.dataset.title || section.id)}</option>`
  )).join('')}`;

  sectionJump.onchange = () => {
    const sectionId = sectionJump.value;
    if (!sectionId) return;
    const target = document.getElementById(sectionId);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
}

async function loadPlayers() {
  const { rows } = await call('players');
  const items = rowsOrEmpty(rows);
  const tb = el('players').querySelector('tbody');
  tb.innerHTML = items.map((p) => `
    <tr>
      <td>${p.id}</td>
      <td>${esc(p.display_name || '')}</td>
      <td>${esc(p.public_code || '')}</td>
      <td><button data-del="${p.id}">Borrar</button></td>
    </tr>
  `).join('');

  tb.querySelectorAll('button[data-del]').forEach((btn) => {
    btn.onclick = async () => {
      if (!confirm(`Borrar player_id=${btn.dataset.del}?`)) return;
      await call('players_delete', 'POST', { id: Number(btn.dataset.del) });
      await loadPlayers();
    };
  });
}

async function loadEvents() {
  const { rows } = await call('events_recent');
  const items = rowsOrEmpty(rows);
  const tb = el('events').querySelector('tbody');
  tb.innerHTML = items.map((r) => `
    <tr>
      <td>${r.id}</td>
      <td>${esc(r.created_at || '')}</td>
      <td>${esc(r.display_name || '')} (${esc(r.public_code || '')})</td>
      <td>${esc(r.event_type || '')}</td>
      <td>${r.points_delta ?? ''}</td>
      <td>${esc(r.game_code || '')}</td>
      <td>${esc(r.qr_code || '')}</td>
      <td>${esc(r.note || '')}</td>
    </tr>
  `).join('');
}

let scoringEnabled = true;

let virusActive = false;

async function loadVirusLeaderboard() {
  const { rows, is_active } = await call('admin_virus_leaderboard');
  virusActive = !!is_active;
  const items = rowsOrEmpty(rows);
  const tb = el('virusLb').querySelector('tbody');
  tb.innerHTML = items.map((r, i) => `
    <tr>
      <td>${i + 1}</td>
      <td>${esc(r.display_name || '')}</td>
      <td>${esc(r.public_code || '')}</td>
      <td>${esc(r.role || '')}</td>
      <td>${r.power ?? 0}</td>
      <td>${r.matches ?? 0}</td>
    </tr>
  `).join('');
  el('virusMsg').textContent = virusActive ? 'Virus activo' : 'Virus finalizado';
  el('virusToggle').textContent = virusActive ? 'Apagar Virus' : 'Encender Virus';
}

async function refreshScoringStatus() {
  const r = await call('scoring_status');
  scoringEnabled = !!r.enabled;
  const multiplier = [1, 2, 3].includes(Number(r.qr_multiplier)) ? Number(r.qr_multiplier) : 1;
  el('qrMul').value = String(multiplier);
  el('btnScoringToggle').textContent = scoringEnabled ? 'Pausar puntaje (ON)' : 'Reanudar puntaje (OFF)';
}


async function runPanelLoad() {
  const tasks = [
    loadGames(),
    refreshScoringStatus(),
    loadVirusLeaderboard(),
    loadQrList(),
  ];

  const results = await Promise.allSettled(tasks);
  const firstError = results.find((r) => r.status === 'rejected');
  if (firstError) {
    el('status').textContent = `Error: ${firstError.reason?.message || 'No se pudo cargar el panel'}`;
  } else {
    el('status').textContent = 'panel cargado';
  }
}

async function loadLeaderboard() {
  const { rows } = await call('leaderboard');
  const items = rowsOrEmpty(rows);
  const tb = el('lb').querySelector('tbody');
  tb.innerHTML = items.map((r, i) => `
    <tr>
      <td>${i + 1}</td>
      <td>${esc(r.display_name || '')}</td>
      <td>${esc(r.public_code || '')}</td>
      <td>${r.total_points ?? 0}</td>
    </tr>
  `).join('');
}

document.addEventListener('DOMContentLoaded', () => {
  setupSectionJump();
  el('token').value = token();

  el('saveToken').onclick = () => {
    setToken(el('token').value.trim());
    el('status').textContent = 'token guardado';
  };

  el('ping').onclick = async () => {
    try {
      const r = await call('ping');
      el('status').textContent = r.msg || 'pong';
      await runPanelLoad();
    } catch (e) {
      el('status').textContent = `Error: ${e.message}`;
    }
  };

  el('qrType').onchange = () => toggleQrInputs();
  el('qrCreate').onclick = () => createQrs().catch((e) => { el('qrMsg').textContent = `Error: ${e.message}`; });
  toggleQrInputs();

  el('loadPlayers').onclick = () => loadPlayers().catch((e) => alert(e.message));
  el('loadLb').onclick = () => loadLeaderboard().catch((e) => alert(e.message));
  el('loadEvents').onclick = () => loadEvents().catch((e) => alert(e.message));

  el('setMul').onclick = async () => {
    try {
      const value = Number(el('qrMul').value);
      await call('set_qr_multiplier', 'POST', { value });
      el('mulMsg').textContent = 'ok';
    } catch (e) {
      el('mulMsg').textContent = `Error: ${e.message}`;
    }
  };

  el('adjGo').onclick = async () => {
    try {
      const player_id = Number(el('adjPid').value);
      const points_delta = Number(el('adjPts').value);
      const note = el('adjNote').value || '';
      await call('adjust_points', 'POST', { player_id, points_delta, note });
      el('adjMsg').textContent = 'aplicado';
      await loadLeaderboard();
    } catch (e) {
      el('adjMsg').textContent = `Error: ${e.message}`;
    }
  };

  el('resetGo').onclick = async () => {
    try {
      const player_id = Number(el('resetPid').value);
      if (!player_id) return;
      if (!confirm(`Reset TOTAL de player_id=${player_id}? (borra puntajes, QRs y partidas para habilitar rejugar)`)) return;
      const result = await call('reset_player', 'POST', { player_id });
      el('resetMsg').textContent = `ok · score_events=${result.deleted_score_events ?? 0}, qr_claims=${result.deleted_qr_claims ?? 0}, game_plays=${result.deleted_game_plays ?? 0}, virus_states=${result.deleted_virus_states ?? 0}, virus_interactions=${result.deleted_virus_interactions ?? 0}`;
      await loadLeaderboard();
      await loadPlayers();
      await loadEvents();
    } catch (e) {
      el('resetMsg').textContent = `Error: ${e.message}`;
    }
  };


  el('virusLbLoad').onclick = () => loadVirusLeaderboard().catch((e) => alert(e.message));

  el('virusToggle').onclick = async () => {
    try {
      await call('admin_virus_toggle', 'POST', { enabled: !virusActive });
      await loadVirusLeaderboard();
    } catch (e) {
      el('virusMsg').textContent = `Error: ${e.message}`;
    }
  };

  el('virusReset').onclick = async () => {
    try {
      await call('admin_virus_reset_session', 'POST', {});
      await loadVirusLeaderboard();
      el('virusMsg').textContent = 'Sesión virus reiniciada';
    } catch (e) {
      el('virusMsg').textContent = `Error: ${e.message}`;
    }
  };

  runPanelLoad();

  el('btnScoringToggle').onclick = async () => {
    try {
      await call('set_scoring_enabled', 'POST', { enabled: !scoringEnabled });
      await refreshScoringStatus();
      await loadVirusLeaderboard();
      el('scoringMsg').textContent = scoringEnabled ? 'puntaje activo' : 'puntaje pausado';
    } catch (e) {
      el('scoringMsg').textContent = `Error: ${e.message}`;
    }
  };
});
