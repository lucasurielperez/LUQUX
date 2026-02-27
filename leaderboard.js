(function () {
  const API_BASE = 'api.php';
  const API_URL = `${API_BASE}?action=public_leaderboard_top`;
  const ALLOWED_REFRESH_MS = [1000, 5000, 10000, 30000, 60000];

  const lbBody = document.getElementById('lbBody');
  const countdownEl = document.getElementById('countdown');
  const refreshSelect = document.getElementById('refreshSelect');
  const updatingEl = document.getElementById('updating');
  const errorMsgEl = document.getElementById('errorMsg');
  const lastOkEl = document.getElementById('lastOk');
  const celebrationEl = document.getElementById('celebration');
  const celebrationTopEl = document.getElementById('celebrationTop');
  const celebrationNameEl = document.getElementById('celebrationName');
  const celebrationPointsEl = document.getElementById('celebrationPoints');
  const confettiLayer = document.getElementById('confettiLayer');
  const photoOverlayEl = document.getElementById('photoOverlay');
  const photoImgEl = document.getElementById('photoImg');
  const photoCaptionEl = document.getElementById('photoCaption');
  const titleEl = document.getElementById('lbTitle');

  const photoAdminPanel = document.getElementById('photoAdminPanel');
  const photosEnabledInput = document.getElementById('photosEnabledInput');
  const photoDurationInput = document.getElementById('photoDurationInput');
  const photoAdminSaveBtn = document.getElementById('photoAdminSaveBtn');
  const photoAdminReloadBtn = document.getElementById('photoAdminReloadBtn');
  const photoAdminMsg = document.getElementById('photoAdminMsg');

  let prevRanksByPlayerId = new Map();
  let refreshMs = Number(localStorage.getItem('lb_refresh_ms')) || 60000;
  if (!ALLOWED_REFRESH_MS.includes(refreshMs)) {
    refreshMs = 60000;
  }

  let photosSettings = {
    enabled: localStorage.getItem('photos_enabled') === '1',
    duration_ms: Number(localStorage.getItem('photos_duration_ms')) || 5000,
  };

  const photoMode = {
    showing: false,
    until: 0,
    lastCheckAt: 0,
  };

  refreshSelect.value = String(refreshMs);
  if (refreshSelect.value !== String(refreshMs)) {
    refreshMs = 60000;
    refreshSelect.value = '60000';
  }

  let nextRefreshAt = Date.now() + refreshMs;
  let lastOkAt = null;
  let refreshTimer = null;
  let countdownTimer = null;
  let celebrationTimer = null;
  let photoTimer = null;
  let photoPollBusy = false;
  let titleTapCount = 0;
  let titleTapResetTimer = null;

  const gameNames = { sumador: 'Sumador', virus: 'Virus' };

  function setPhotoAdminMsg(msg, isError) {
    if (!photoAdminMsg) return;
    photoAdminMsg.textContent = msg;
    photoAdminMsg.style.color = isError ? '#fca5a5' : '#93c5fd';
  }

  function getAdminToken() {
    let token = localStorage.getItem('admin_token') || '';
    if (!token) {
      token = window.prompt('Ingresá admin token') || '';
      if (token) {
        localStorage.setItem('admin_token', token);
      }
    }
    return token.trim();
  }

  async function adminCall(action, payload) {
    const token = getAdminToken();
    if (!token) {
      throw new Error('Token admin requerido');
    }

    const hasPayload = payload !== undefined;
    const url = `${API_BASE}?action=${encodeURIComponent(action)}`;
    const res = await fetch(url, {
      method: hasPayload ? 'POST' : 'GET',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: hasPayload ? JSON.stringify(payload) : undefined,
    });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  async function fetchJson(action, options) {
    const res = await fetch(`${API_BASE}?action=${encodeURIComponent(action)}`, options || { cache: 'no-store' });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  function formatPointsDelta(delta) {
    if (typeof delta !== 'number' || Number.isNaN(delta)) return '';
    return `${delta >= 0 ? '+' : ''}${delta}`;
  }

  function humanLastEvent(lastEvent) {
    if (!lastEvent) return '—';

    const gameCode = String(lastEvent.game_code || '').trim().toLowerCase();
    const eventType = String(lastEvent.event_type || '').trim().toUpperCase();
    const gameName = gameNames[gameCode] || (gameCode ? gameCode.charAt(0).toUpperCase() + gameCode.slice(1) : 'Acción');
    const deltaText = formatPointsDelta(Number(lastEvent.points_delta));
    const note = String(lastEvent.note || '').trim();

    if (eventType === 'QR_SECRET') {
      const qrPoints = deltaText || '0';
      return `QR de ${qrPoints} puntos.`;
    }

    if (!deltaText && !note) return gameName;
    if (!note) return `${gameName} ${deltaText}`.trim();

    return `${gameName} ${deltaText}`.trim() + ` <span class="note">(${escapeHtml(note)})</span>`;
  }

  function escapeHtml(str) {
    return str
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function arrowForPlayer(playerId, position) {
    if (!prevRanksByPlayerId.size) return '🔵 =';
    const prevPos = prevRanksByPlayerId.get(playerId);
    if (typeof prevPos !== 'number') return '🔵 =';
    if (position < prevPos) return '🟢 ↑';
    if (position > prevPos) return '🔴 ↓';
    return '🔵 =';
  }

  function buildRows(rows) {
    lbBody.innerHTML = rows.map(function (row, idx) {
      const pos = idx + 1;
      const playerId = Number(row.player_id);
      const rankClass = pos === 1 ? 'rank-1' : (pos === 2 || pos === 3 ? 'rank-2' : 'rank-other');
      const arrow = arrowForPlayer(playerId, pos);
      const lastAction = humanLastEvent(row.last_event);

      return `
        <tr class="${rankClass}">
          <td class="col-arrow">${arrow}</td>
          <td class="col-pos">#${pos}</td>
          <td class="col-name">${escapeHtml(String(row.display_name || '—'))}</td>
          <td class="col-points">${Number(row.total_points || 0)}</td>
          <td class="col-last">${lastAction}</td>
        </tr>
      `;
    }).join('');
  }

  function updateCountdown() {
    if (photoMode.showing) {
      const remainingPhoto = Math.max(0, photoMode.until - Date.now());
      countdownEl.textContent = String(Math.ceil(remainingPhoto / 1000));
      updatingEl.textContent = ' · Pausado por foto';
      return;
    }

    const remaining = Math.max(0, nextRefreshAt - Date.now());
    countdownEl.textContent = String(Math.ceil(remaining / 1000));
  }

  function updateLastOkText() {
    if (!lastOkAt) {
      lastOkEl.textContent = 'Última actualización: --';
      return;
    }

    const elapsedSec = Math.floor((Date.now() - lastOkAt) / 1000);
    lastOkEl.textContent = `Actualizado hace: ${elapsedSec}s`;
  }

  function detectCelebration(rows) {
    if (!prevRanksByPlayerId.size || !rows.length) return null;

    let candidate = null;
    rows.slice(0, 15).forEach(function (row, idx) {
      const newPos = idx + 1;
      if (newPos > 3) return;

      const playerId = Number(row.player_id);
      const prevPos = prevRanksByPlayerId.get(playerId);
      if (typeof prevPos !== 'number') return;

      if (newPos < prevPos) {
        if (!candidate || newPos < candidate.newPos || prevPos > 3) {
          candidate = {
            display_name: String(row.display_name || 'Jugador'),
            total_points: Number(row.total_points || 0),
            newPos,
          };
        }
      }
    });

    return candidate;
  }

  function launchConfetti() {
    confettiLayer.innerHTML = '';
    const colors = ['#ffd266', '#86ffb5', '#7ab0ff', '#ff7fbf', '#ffeaa6'];
    for (let i = 0; i < 85; i += 1) {
      const piece = document.createElement('span');
      piece.className = 'confetti-piece';
      piece.style.left = `${Math.random() * 100}%`;
      piece.style.background = colors[i % colors.length];
      piece.style.animationDuration = `${2.1 + Math.random() * 1.3}s`;
      piece.style.animationDelay = `${Math.random() * 0.6}s`;
      confettiLayer.appendChild(piece);
    }
  }

  function showCelebration(candidate) {
    if (!candidate || photoMode.showing) return;

    celebrationTopEl.textContent = `🔥 SUBIÓ AL #${candidate.newPos}`;
    celebrationNameEl.textContent = candidate.display_name;
    celebrationPointsEl.textContent = `Puntaje: ${candidate.total_points}`;
    launchConfetti();

    celebrationEl.classList.add('show');
    clearTimeout(celebrationTimer);
    celebrationTimer = setTimeout(function () {
      celebrationEl.classList.remove('show');
    }, 3000);
  }

  function snapshotRanks(rows) {
    const next = new Map();
    rows.forEach(function (row, idx) {
      next.set(Number(row.player_id), idx + 1);
    });
    prevRanksByPlayerId = next;
  }

  async function refreshLeaderboard() {
    if (photoMode.showing) {
      return;
    }

    updatingEl.textContent = ' · Actualizando…';
    try {
      const data = await fetchJson('public_leaderboard_top', { cache: 'no-store' });
      const rows = Array.isArray(data.rows) ? data.rows.slice(0, 15) : [];
      const celebrationCandidate = detectCelebration(rows);

      buildRows(rows);
      snapshotRanks(rows);
      showCelebration(celebrationCandidate);

      lastOkAt = Date.now();
      updateLastOkText();
      errorMsgEl.textContent = '';
      errorMsgEl.classList.remove('error');
    } catch (err) {
      errorMsgEl.textContent = 'Sin conexión / Error actualizando';
      errorMsgEl.classList.add('error');
    } finally {
      if (!photoMode.showing) {
        updatingEl.textContent = '';
      }
      nextRefreshAt = Date.now() + refreshMs;
      updateCountdown();
    }
  }

  function stopRefreshTimer() {
    if (refreshTimer) {
      clearInterval(refreshTimer);
      refreshTimer = null;
    }
  }

  function startRefreshTimer() {
    stopRefreshTimer();
    nextRefreshAt = Date.now() + refreshMs;
    updateCountdown();
    refreshTimer = setInterval(function () {
      refreshLeaderboard();
    }, refreshMs);
  }

  async function fetchPhotoSettings() {
    try {
      const data = await fetchJson('photo_status', { cache: 'no-store' });
      photosSettings = {
        enabled: !!data.enabled,
        duration_ms: Number(data.duration_ms || photosSettings.duration_ms || 5000),
      };
      localStorage.setItem('photos_enabled', photosSettings.enabled ? '1' : '0');
      localStorage.setItem('photos_duration_ms', String(photosSettings.duration_ms));
    } catch (err) {
      // silencioso
    }
  }

  async function markPhotoShown(id) {
    try {
      await fetchJson('photo_mark_shown', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
    } catch (err) {
      // silencioso
    }
  }

  function showPhotoOverlay(item) {
    photoMode.showing = true;
    photoMode.until = Date.now() + Number(photosSettings.duration_ms || 5000);
    photoImgEl.src = `${item.url}${item.url.includes('?') ? '&' : '?'}v=${item.id}`;
    const displayName = String(item.display_name || '').trim();
    photoCaptionEl.textContent = displayName ? `📸 Foto de ${displayName}` : '📸 Foto en pantalla';
    photoOverlayEl.classList.add('show');
    celebrationEl.classList.remove('show');
    stopRefreshTimer();
    updateCountdown();

    window.setTimeout(async function () {
      photoOverlayEl.classList.remove('show');
      photoImgEl.src = '';
      photoMode.showing = false;
      updatingEl.textContent = '';
      startRefreshTimer();
      await refreshLeaderboard();
    }, Number(photosSettings.duration_ms || 5000));
  }

  async function checkPhotoQueue() {
    if (photoPollBusy || photoMode.showing) {
      return;
    }

    photoPollBusy = true;
    photoMode.lastCheckAt = Date.now();
    try {
      const data = await fetchJson('photo_peek_next', { cache: 'no-store' });
      if (!data.enabled) {
        photosSettings.enabled = false;
        return;
      }

      if (!data.has_photo || !data.item || !photosSettings.enabled) {
        return;
      }

      await markPhotoShown(Number(data.item.id));
      showPhotoOverlay(data.item);
    } catch (err) {
      // silencioso
      console.debug('photo_peek_next error', err);
    } finally {
      photoPollBusy = false;
    }
  }

  async function loadAdminPhotoSettings() {
    try {
      const data = await adminCall('admin_photos_settings_get');
      photosEnabledInput.checked = !!data.enabled;
      photoDurationInput.value = String(Number(data.duration_ms || 5000));
      setPhotoAdminMsg('Settings cargados');
    } catch (err) {
      setPhotoAdminMsg(String(err.message || err), true);
    }
  }

  async function saveAdminPhotoSettings() {
    try {
      const payload = {
        enabled: !!photosEnabledInput.checked,
        duration_ms: Number(photoDurationInput.value || 5000),
      };
      const data = await adminCall('admin_photos_settings_set', payload);
      photosSettings.enabled = !!data.enabled;
      photosSettings.duration_ms = Number(data.duration_ms || 5000);
      localStorage.setItem('photos_enabled', photosSettings.enabled ? '1' : '0');
      localStorage.setItem('photos_duration_ms', String(photosSettings.duration_ms));
      setPhotoAdminMsg('Guardado OK');
    } catch (err) {
      setPhotoAdminMsg(String(err.message || err), true);
    }
  }

  function toggleAdminPanel() {
    photoAdminPanel.classList.toggle('show');
    if (photoAdminPanel.classList.contains('show')) {
      loadAdminPhotoSettings();
    }
  }

  refreshSelect.addEventListener('change', function () {
    const selectedMs = Number(refreshSelect.value) || 60000;
    refreshMs = ALLOWED_REFRESH_MS.includes(selectedMs) ? selectedMs : 60000;
    refreshSelect.value = String(refreshMs);
    localStorage.setItem('lb_refresh_ms', String(refreshMs));
    startRefreshTimer();
    refreshLeaderboard();
  });

  if (titleEl) {
    titleEl.addEventListener('click', function () {
      titleTapCount += 1;
      clearTimeout(titleTapResetTimer);
      titleTapResetTimer = setTimeout(function () { titleTapCount = 0; }, 700);
      if (titleTapCount >= 3) {
        titleTapCount = 0;
        toggleAdminPanel();
      }
    });
  }

  document.addEventListener('keydown', function (ev) {
    if (ev.key && ev.key.toLowerCase() === 'a') {
      toggleAdminPanel();
    }
  });

  if (photoAdminSaveBtn) {
    photoAdminSaveBtn.addEventListener('click', saveAdminPhotoSettings);
  }
  if (photoAdminReloadBtn) {
    photoAdminReloadBtn.addEventListener('click', loadAdminPhotoSettings);
  }

  document.addEventListener('dblclick', function (ev) {
    ev.preventDefault();
  }, { passive: false });
  document.addEventListener('gesturestart', function (ev) {
    ev.preventDefault();
  }, { passive: false });
  document.addEventListener('touchmove', function (ev) {
    if (ev.scale && ev.scale !== 1) {
      ev.preventDefault();
    }
  }, { passive: false });

  window.addEventListener('beforeunload', function () {
    clearInterval(refreshTimer);
    clearInterval(countdownTimer);
    clearInterval(photoTimer);
    clearTimeout(celebrationTimer);
    clearTimeout(titleTapResetTimer);
  });

  startRefreshTimer();
  countdownTimer = setInterval(function () {
    updateCountdown();
    updateLastOkText();
  }, 250);
  photoTimer = setInterval(checkPhotoQueue, 1500);

  fetchPhotoSettings();
  refreshLeaderboard();
})();
