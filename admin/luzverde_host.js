(function () {
  const API = '../api.php';

  const $ = (id) => document.getElementById(id);
  const elError = $('error');
  const elSens = $('sensitivity');
  const elSensVal = $('sensVal');
  const elBase = $('basePoints');
  const elRest = $('restSeconds');
  const elDiffStep = $('difficultyStep');
  const elDiffCap = $('difficultyCap');
  const elRoundMaxMs = $('roundMaxMs');
  const elStillWindowMs = $('stillWindowMs');
  const elStillMin = $('stillMin');
  const elStillGraceMs = $('stillGraceMs');
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
      base_sensitivity: Number(elSens.value || 15),
      difficulty_step: Number(elDiffStep.value || 2),
      difficulty_cap: Number(elDiffCap.value || 40),
      round_max_ms: Number(elRoundMaxMs.value || 25000),
      still_window_ms: Number(elStillWindowMs.value || 6000),
      still_min: Number(elStillMin.value || 0.015),
      still_grace_ms: Number(elStillGraceMs.value || 2000),
      base_points: Number(elBase.value || 10),
      rest_seconds: Number(elRest.value || 60),
    };
  }

  function playerLabel(p) {
    if (!p.armed) return 'No listo';
    if (!p.eliminated_at) return 'Vivo';
    if (p.eliminated_reason === 'SENSOR_OFFLINE') return 'Eliminado (OFFLINE)';
    if (p.eliminated_reason === 'TOO_STILL') return 'DEMASIADO QUIETO';
    return 'Eliminado (MOTION)';
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }

  function stageCols(count) {
    if (count <= 2) return 2;
    if (count <= 6) return 3;
    if (count <= 12) return 4;
    if (count <= 20) return 5;
    if (count <= 30) return 6;
    if (count <= 42) return 7;
    return 8;
  }

  function renderStage(session, totals, players) {
    const playing = session?.state === 'ACTIVE';
    document.body.classList.toggle('playing', playing);
    if (!playing) return;

    $('stageRound').textContent = session?.round_no || 0;
    $('stageAlive').textContent = totals.alive || 0;
    $('stageEliminated').textContent = totals.eliminated || 0;

    const grid = $('stageGrid');
    const cols = stageCols(players.length || 1);
    grid.style.setProperty('--stage-cols', String(cols));
    grid.innerHTML = players.map((p) => {
      const cls = [
        'stageCard',
        p.eliminated_at ? 'dead' : '',
        !p.armed ? 'notReady' : '',
      ].filter(Boolean).join(' ');
      return `
        <article class="${cls}">
          <div class="name">${escapeHtml(p.display_name)}</div>
          <div class="code">${escapeHtml(p.public_code)}</div>
          <div class="state">${playerLabel(p)}</div>
        </article>
      `;
    }).join('');

    const title = totals.alive <= 1 ? '¡Último sobreviviente en juego!' : 'No te muevas cuando se corte la música.';
    $('stageFooter').textContent = title;
  }

  function renderInformativeBanner(session, totals, eList, sList) {
    const state = session?.state || 'WAITING';
    const statusMessage = $('statusMessage');
    const waitingDetail = $('waitingDetail');

    if (state === 'WAITING') {
      statusMessage.textContent = `Esperando para jugar · ${totals.total || 0} jugadores conectados (${totals.alive || 0} listos).`;
      waitingDetail.textContent = (totals.not_ready || 0) > 0
        ? `${totals.not_ready} jugadores todavía no habilitaron sensores.`
        : 'Todos los jugadores conectados están listos para arrancar.';
      return;
    }

    if (state === 'REST') {
      const winners = sList.length ? sList.map((p) => p.display_name).join(', ') : 'Nadie';
      const losers = eList.length ? eList.map((p) => p.display_name).join(', ') : 'Nadie';
      statusMessage.textContent = `Ronda ${session.round_no} terminada. Ganaron: ${winners}.`;
      waitingDetail.textContent = `Perdieron: ${losers}. Ronda ${session.round_no} terminada. Próxima más difícil 😈`;
      return;
    }

    if (state === 'FINISHED') {
      const winners = sList.length ? sList.map((p) => p.display_name).join(', ') : 'Sin ganador';
      statusMessage.textContent = `Juego finalizado. Ganador(es): ${winners}.`;
      waitingDetail.textContent = `Eliminados totales: ${totals.eliminated || 0}.`;
      return;
    }

    statusMessage.textContent = `Ronda ${session?.round_no || 0} en curso.`;
    waitingDetail.textContent = '';
  }

  function renderState(data) {
    const session = data.session || null;
    const totals = data.totals || { total: 0, alive: 0, eliminated: 0, not_ready: 0 };
    $('state').textContent = session?.state || 'WAITING';
    $('alive').textContent = totals.alive;
    $('eliminated').textContent = totals.eliminated;
    $('total').textContent = totals.total;
    $('notReady').textContent = totals.not_ready || 0;
    $('round').textContent = session?.round_no || 0;
    $('currentSensitivity').textContent = session?.current_sensitivity || session?.sensitivity_level || 0;

    if (session) {
      if (!editingConfig) {
        elSens.value = session.base_sensitivity || session.sensitivity_level;
        elSensVal.textContent = elSens.value;
      }
      elDiffStep.value = session.difficulty_step ?? 2;
      elDiffCap.value = session.difficulty_cap ?? 40;
      elRoundMaxMs.value = session.round_max_ms ?? 25000;
      elStillWindowMs.value = session.still_window_ms ?? 6000;
      elStillMin.value = session.still_min ?? 0.015;
      elStillGraceMs.value = session.still_grace_ms ?? 2000;
      elBase.value = session.base_points;
      elRest.value = session.rest_seconds;

      if (session.state === 'ACTIVE') {
        $('roundTimer').textContent = `${Math.max(0, Math.ceil((session.round_time_left_ms || 0) / 1000))}s`;
      } else {
        $('roundTimer').textContent = '--';
      }

      if (session.state === 'REST' && session.rest_ends_at) {
        const endAt = new Date(session.rest_ends_at.replace(' ', 'T')).getTime();
        const left = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
        $('countdown').textContent = `${left}s`;
      } else {
        $('countdown').textContent = '--';
      }
    } else {
      $('roundTimer').textContent = '--';
      $('currentSensitivity').textContent = '0';
    }

    const players = Array.isArray(data.participants) ? data.participants : [];
    $('playersGrid').innerHTML = players.map((p) => `
      <div class="p ${p.eliminated_at ? 'dead' : ''}">
        <div><strong>${escapeHtml(p.display_name)}</strong></div>
        <div class="muted">${escapeHtml(p.public_code)}</div>
        <div class="muted">${playerLabel(p)}</div>
        <button class="remove-player" data-remove-player="${p.player_id}">Sacar jugador</button>
      </div>
    `).join('');

    const eList = Array.isArray(data.eliminated_this_round) ? data.eliminated_this_round : [];
    const sList = Array.isArray(data.survivors) ? data.survivors : [];

    $('elims').innerHTML = eList.map((p) => `<li>${escapeHtml(p.display_name)} (${escapeHtml(p.public_code)})</li>`).join('') || '<li>-</li>';
    $('survivors').innerHTML = sList.map((p) => `<li>${escapeHtml(p.display_name)} (${escapeHtml(p.public_code)})</li>`).join('') || '<li>-</li>';

    renderInformativeBanner(session, totals, eList, sList);
    renderStage(session, totals, players);
  }

  async function removeParticipant(playerId) {
    if (!playerId) return;
    const okRemove = window.confirm('¿Seguro que querés sacar este jugador de Luz Verde?');
    if (!okRemove) return;
    try {
      await call('admin_luzverde_remove_participant', 'POST', { player_id: Number(playerId) });
      await refresh();
    } catch (err) {
      setError(err.message);
    }
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


  document.body.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-remove-player]');
    if (!btn) return;
    const playerId = Number(btn.dataset.removePlayer || 0);
    removeParticipant(playerId);
  });

  setInterval(refresh, 1000);
  refresh();
})();
