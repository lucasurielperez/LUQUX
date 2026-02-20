(function () {
  const API = '../api.php';

  const $ = (id) => document.getElementById(id);
  const elError = $('error');
  const elSens = $('sensitivity');
  const elSensVal = $('sensVal');
  const elBase = $('basePoints');
  const elRest = $('restSeconds');
  const sound = $('sound');
  let editingConfig = false;

  function token() {
    return localStorage.getItem('admin_token') || '';
  }

  async function call(action, method = 'GET', body = null) {
    const headers = { Authorization: `Bearer ${token()}` };
    if (body) headers['Content-Type'] = 'application/json';

    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : null,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  function setError(msg) {
    elError.textContent = msg || '';
  }

  function updateConfigBody() {
    return {
      sensitivity_level: Number(elSens.value || 15),
      base_points: Number(elBase.value || 10),
      rest_seconds: Number(elRest.value || 60),
    };
  }

  function renderState(data) {
    const session = data.session || null;
    const totals = data.totals || { total: 0, alive: 0, eliminated: 0 };
    $('state').textContent = session?.state || 'WAITING';
    $('alive').textContent = totals.alive;
    $('eliminated').textContent = totals.eliminated;
    $('total').textContent = totals.total;
    $('round').textContent = session?.round_no || 0;

    if (session) {
      if (!editingConfig) {
        elSens.value = session.sensitivity_level;
        elSensVal.textContent = session.sensitivity_level;
      }
      elBase.value = session.base_points;
      elRest.value = session.rest_seconds;

      if (session.state === 'REST' && session.rest_ends_at) {
        const endAt = new Date(session.rest_ends_at.replace(' ', 'T')).getTime();
        const left = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
        $('countdown').textContent = `${left}s`;
      } else {
        $('countdown').textContent = '--';
      }
    }

    const players = Array.isArray(data.participants) ? data.participants : [];
    $('playersGrid').innerHTML = players.map((p) => `
      <div class="p ${p.eliminated_at ? 'dead' : ''}">
        <div><strong>${p.display_name}</strong></div>
        <div class="muted">${p.public_code}</div>
        <div class="muted">${p.eliminated_at ? 'Eliminado' : 'Vivo'}</div>
      </div>
    `).join('');

    const eList = Array.isArray(data.eliminated_this_round) ? data.eliminated_this_round : [];
    const sList = Array.isArray(data.survivors) ? data.survivors : [];

    $('elims').innerHTML = eList.map((p) => `<li>${p.display_name} (${p.public_code})</li>`).join('') || '<li>-</li>';
    $('survivors').innerHTML = sList.map((p) => `<li>${p.display_name} (${p.public_code})</li>`).join('') || '<li>-</li>';
  }

  async function refresh() {
    try {
      const data = await call('admin_luzverde_state');
      renderState(data);
      setError('');
    } catch (err) {
      setError(err.message);
    }
  }

  async function doAction(action, withConfig) {
    try {
      await call(action, 'POST', withConfig ? updateConfigBody() : {});
      await refresh();
    } catch (err) {
      setError(err.message);
    }
  }

  $('btnUpdate').onclick = () => doAction('admin_luzverde_update_config', true);
  $('btnReset').onclick = () => doAction('admin_luzverde_reset_session', true);
  $('btnStart').onclick = async () => {
    await doAction('admin_luzverde_start_round', false);
    sound.play().catch(() => {});
  };
  $('btnEnd').onclick = () => doAction('admin_luzverde_end_round', false);
  $('btnFinish').onclick = () => doAction('admin_luzverde_finish_game', false);
  $('btnSound').onclick = () => sound.play().catch(() => {});

  elSens.oninput = () => { elSensVal.textContent = elSens.value; };
  elSens.addEventListener('mousedown', () => { editingConfig = true; });
  elSens.addEventListener('touchstart', () => { editingConfig = true; });
  elSens.addEventListener('mouseup', () => { editingConfig = false; });
  elSens.addEventListener('touchend', () => { editingConfig = false; });

  setInterval(refresh, 1000);
  refresh();
})();
