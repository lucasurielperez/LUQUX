const API = 'api.php';

function token() {
  return localStorage.getItem('admin_token') || '';
}
function setToken(t) {
  localStorage.setItem('admin_token', t);
}

async function call(action, method='GET', body=null) {
  const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token()}`
    },
    body: body ? JSON.stringify(body) : null
  });
  const data = await res.json().catch(()=> ({}));
  if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

const el = (id) => document.getElementById(id);

function esc(s){return String(s).replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]))}

async function loadGames() {
  const {rows} = await call('games');
  const tb = el('games').querySelector('tbody');
  tb.innerHTML = rows.map(g => `
    <tr>
      <td>${g.id}</td>
      <td>${g.is_active ? '🟢' : '🔴'}</td>
      <td>${esc(g.code)}</td>
      <td>${esc(g.name)}</td>
      <td><button data-id="${g.id}" data-next="${g.is_active ? 0 : 1}">
        ${g.is_active ? 'Desactivar' : 'Activar'}
      </button></td>
    </tr>
  `).join('');

  tb.querySelectorAll('button[data-id]').forEach(btn => {
    btn.onclick = async () => {
      await call('games_toggle', 'POST', { id: Number(btn.dataset.id), is_active: Number(btn.dataset.next) === 1 });
      await loadGames();
    };
  });
}

async function loadPlayers() {
  const {rows} = await call('players');
  const tb = el('players').querySelector('tbody');
  tb.innerHTML = rows.map(p => `
    <tr>
      <td>${p.id}</td>
      <td>${esc(p.display_name)}</td>
      <td>${esc(p.public_code)}</td>
      <td><button data-del="${p.id}">Borrar</button></td>
    </tr>
  `).join('');

  tb.querySelectorAll('button[data-del]').forEach(btn => {
    btn.onclick = async () => {
      if (!confirm(`Borrar player_id=${btn.dataset.del}?`)) return;
      await call('players_delete', 'POST', { id: Number(btn.dataset.del) });
      await loadPlayers();
    };
  });
}
async function loadEvents() {
  const {rows} = await call('events_recent');
  const tb = el('events').querySelector('tbody');
  tb.innerHTML = rows.map(r => `
    <tr>
      <td>${r.id}</td>
      <td>${r.created_at}</td>
      <td>${esc(r.display_name)} (${esc(r.public_code)})</td>
      <td>${esc(r.event_type)}</td>
      <td>${r.points_delta}</td>
      <td>${r.game_code || ''}</td>
      <td>${r.qr_code || ''}</td>
      <td>${esc(r.note || '')}</td>
    </tr>
  `).join('');
}

let scoringEnabled = true;

async function refreshScoringStatus() {
  const r = await call('scoring_status');
  scoringEnabled = !!r.enabled;
  el('btnScoringToggle').textContent = scoringEnabled ? 'Pausar puntaje (ON)' : 'Reanudar puntaje (OFF)';
}

async function loadLeaderboard() {
  const {rows} = await call('leaderboard');
  const tb = el('lb').querySelector('tbody');
  tb.innerHTML = rows.map((r,i)=>`
    <tr>
      <td>${i+1}</td>
      <td>${esc(r.display_name)}</td>
      <td>${esc(r.public_code)}</td>
      <td>${r.total_points}</td>
    </tr>
  `).join('');
}

document.addEventListener('DOMContentLoaded', () => {
  el('token').value = token();

  el('saveToken').onclick = () => {
    setToken(el('token').value.trim());
    el('status').textContent = 'token guardado';
  };

  el('ping').onclick = async () => {
    try {
      const r = await call('ping');
      el('status').textContent = r.msg;
      await loadGames();
	  await refreshScoringStatus();

    } catch(e) {
      el('status').textContent = `Error: ${e.message}`;
    }
  };

  el('loadPlayers').onclick = () => loadPlayers().catch(e => alert(e.message));
  el('loadLb').onclick = () => loadLeaderboard().catch(e => alert(e.message));

  el('setMul').onclick = async () => {
    try {
      const value = Number(el('qrMul').value);
      await call('set_qr_multiplier', 'POST', { value });
      el('mulMsg').textContent = 'ok';
    } catch(e) {
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
    } catch(e) {
      el('adjMsg').textContent = `Error: ${e.message}`;
    }
  };
  el('loadEvents').onclick = () => loadEvents().catch(e => alert(e.message));

el('resetGo').onclick = async () => {
  try {
    const player_id = Number(el('resetPid').value);
    if (!player_id) return;
    if (!confirm(`Resetear player_id=${player_id}? (borra puntos y QRs de ese jugador)`)) return;
    await call('reset_player', 'POST', { player_id });
    el('resetMsg').textContent = 'reseteado';
    await loadLeaderboard();
    await loadPlayers();
  } catch (e) {
    el('resetMsg').textContent = `Error: ${e.message}`;
  }
};

el('btnScoringToggle').onclick = async () => {
  try {
    await call('set_scoring_enabled', 'POST', { enabled: !scoringEnabled });
    await refreshScoringStatus();
    el('scoringMsg').textContent = scoringEnabled ? 'puntaje activo' : 'puntaje pausado';
  } catch (e) {
    el('scoringMsg').textContent = `Error: ${e.message}`;
  }
};

});
