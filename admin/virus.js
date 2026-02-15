(function () {
  const API = 'api.php';
  const OVERLAY_MS = 3000;
  const MAX_PARTICLES = 10;
  const ROLE_ICON = { virus: '🦠', antidote: '💉' };
  const MATCHUP_TO_VARIANT = { VV: 'vv', AA: 'aa', VA: 'va' };

  const statusEl = document.getElementById('status');
  const pendingListEl = document.getElementById('pendingList');
  const progressEl = document.getElementById('progress');
  const qrcodeEl = document.getElementById('qrcode');
  const searchEl = document.getElementById('search');
  const fullOverlay = document.getElementById('fullOverlay');
  const overlayContent = document.getElementById('overlayContent');
  const endedCard = document.getElementById('endedCard');
  const readerWrap = document.getElementById('readerWrap');

  let statusCache = null;
  let scanner = null;
  let overlayTimer = null;

  document.addEventListener('dblclick', (event) => event.preventDefault(), { passive: false });

  function setStatus(msg) { statusEl.textContent = msg; }

  async function call(action, method = 'GET', body = null) {
    const query = method === 'GET' && body ? '&' + new URLSearchParams(body).toString() : '';
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}${query}`, {
      method,
      headers: body && method !== 'GET' ? { 'Content-Type': 'application/json' } : {},
      body: body && method !== 'GET' ? JSON.stringify(body) : null,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      const error = new Error(data.error || `HTTP ${res.status}`);
      error.payload = data;
      throw error;
    }
    return data;
  }

  function authPayload() {
    const currentPlayer = window.CURRENT_PLAYER || {};
    const playerId = Number(currentPlayer.id || localStorage.getItem('player_id') || 0);
    const playerToken = String(currentPlayer.player_token || localStorage.getItem('player_token') || '');
    if (playerToken) return { player_token: playerToken };
    return { player_id: playerId };
  }

  function randomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
  }

  function makeParticles(type, count) {
    return Array.from({ length: count }, () => {
      const size = randomInt(14, 48);
      const left = randomInt(2, 95);
      const delay = (Math.random() * 2.5).toFixed(2);
      const duration = (Math.random() * 2.8 + 3).toFixed(2);
      const drift = randomInt(-18, 18);
      const rotate = randomInt(-22, 22);
      return `<span class="overlay__particle overlay__particle--${type}" style="--size:${size}px;--left:${left}%;--delay:${delay}s;--dur:${duration}s;--drift:${drift}px;--rot:${rotate}deg"></span>`;
    }).join('');
  }

  function renderOverlayResult(payload) {
    const me = payload.pre_state.me;
    const other = payload.pre_state.other;
    const postMe = payload.post_state.me;
    const postOther = payload.post_state.other;
    return `
      <div class="overlay__content-body">
        <div class="overlay-result">${payload.view_result || 'EMPATE'}</div>
        <div class="overlay-vs">${ROLE_ICON[me.role] || '❓'} ⚔️ ${ROLE_ICON[other.role] || '❓'}</div>
        <div class="overlay-players">
          <div class="overlay-card">
            <div class="overlay-icon">${ROLE_ICON[me.role] || '❓'}</div>
            <div class="overlay-handle">${me.handle || 'Jugador'}</div>
            <div class="overlay-score">${me.power} → ${postMe.power}</div>
          </div>
          <div class="overlay-card">
            <div class="overlay-icon">${ROLE_ICON[other.role] || '❓'}</div>
            <div class="overlay-handle">${other.handle || 'Jugador'}</div>
            <div class="overlay-score">${other.power} → ${postOther.power}</div>
          </div>
        </div>
      </div>
    `;
  }

  function renderOverlayAlreadyPlayed(payload) {
    const handles = payload.players || [];
    const pair = handles.length >= 2 ? `${handles[0].handle} vs ${handles[1].handle} ya ocurrió` : '';
    return `
      <div class="overlay__content-body">
        <div class="overlay-result">⚠️ YA INTERACTUARON</div>
        <div class="overlay-sub">Buscá a otra persona</div>
        <div class="overlay-sub">${pair}</div>
      </div>
    `;
  }

  function renderOverlayError(payload) {
    return `
      <div class="overlay__content-body">
        <div class="overlay-result">⛔ ${payload.title || 'ERROR'}</div>
        <div class="overlay-sub">${payload.message || 'No se pudo procesar el scan'}</div>
      </div>
    `;
  }

  function showOverlay(payload) {
    if (overlayTimer) {
      clearTimeout(overlayTimer);
      overlayTimer = null;
    }

    const variant = payload.variant || '';
    const bubbles = makeParticles('bubble', randomInt(6, MAX_PARTICLES));
    const crosses = makeParticles('cross', randomInt(6, MAX_PARTICLES));
    const content = payload.type === 'result'
      ? renderOverlayResult(payload)
      : payload.type === 'already_played'
        ? renderOverlayAlreadyPlayed(payload)
        : renderOverlayError(payload);

    fullOverlay.className = `overlay overlay--show overlay--type-${payload.type}${variant ? ` overlay--variant-${variant}` : ''}`;
    overlayContent.innerHTML = `
      <div class="overlay__bg overlay__bg--bubbles" aria-hidden="true">${bubbles}</div>
      <div class="overlay__bg overlay__bg--crosses" aria-hidden="true">${crosses}</div>
      <div class="overlay__impact" aria-hidden="true">✨</div>
      ${content}
    `;

    overlayTimer = setTimeout(() => {
      fullOverlay.className = 'overlay';
      overlayContent.innerHTML = '';
    }, OVERLAY_MS);
  }

  function mapErrorCode(payload, fallback) {
    const code = payload?.code || '';
    const map = {
      INVALID_QR: 'QR inválido',
      QR_EXPIRED: 'QR expirado',
      SELF_SCAN: 'No podés escanearte a vos mismo',
      GAME_INACTIVE: 'Juego apagado',
      PLAYER_NOT_FOUND: 'Jugador no encontrado',
      ALREADY_INTERACTED: 'YA INTERACTUARON',
    };
    return map[code] || fallback || 'Error de scan';
  }

  function renderPending() {
    const term = String(searchEl.value || '').toLowerCase().trim();
    const rows = (statusCache?.opponents_pending || []).filter((r) => r.display_name.toLowerCase().includes(term));
    pendingListEl.innerHTML = rows.map((r) => `<div class="pending-item">${r.display_name}</div>`).join('') || '<div class="muted">Sin pendientes</div>';
    progressEl.textContent = `Interacciones: ${statusCache?.interacted_count || 0}/${statusCache?.total_opponents || 0}`;
  }

  async function loadStatus() {
    const payload = authPayload();
    const playerId = Number(payload.player_id || 0);
    const playerToken = String(payload.player_token || '');
    if (!playerId && !playerToken) {
      setStatus('No hay identidad de jugador activa en este dispositivo.');
      return;
    }

    const st = await call('virus_status', 'GET', payload);
    statusCache = st;

    if (!st.is_active) {
      endedCard.style.display = 'block';
      setStatus('Juego Virus inactivo.');
      qrcodeEl.innerHTML = '<p class="muted">Sin sesión activa</p>';
      pendingListEl.innerHTML = '';
      progressEl.textContent = '';
      return;
    }

    endedCard.style.display = 'none';
    setStatus('Juego activo. Escaneá para enfrentarte.');
    renderPending();
  }

  async function loadQr() {
    if (!statusCache?.is_active) return;
    const data = await call('virus_my_qr', 'GET', authPayload());
    qrcodeEl.innerHTML = '';
    new QRCode(qrcodeEl, {
      text: data.qr_payload,
      width: 220,
      height: 220,
      correctLevel: QRCode.CorrectLevel.M,
    });
  }

  function resultVariant(result) {
    if (result.matchup_type && MATCHUP_TO_VARIANT[result.matchup_type]) {
      return MATCHUP_TO_VARIANT[result.matchup_type];
    }

    const meRole = result?.pre_state?.me?.role;
    const otherRole = result?.pre_state?.other?.role;
    if (meRole === 'virus' && otherRole === 'virus') return 'vv';
    if (meRole === 'antidote' && otherRole === 'antidote') return 'aa';
    return 'va';
  }

  async function processScan(payload) {
    try {
      const result = await call('virus_scan', 'POST', {
        ...authPayload(),
        qr_payload_string: payload,
      });

      showOverlay({
        type: 'result',
        variant: resultVariant(result),
        pre_state: result.pre_state,
        post_state: result.post_state,
        view_result: result.view_result,
      });
    } catch (err) {
      const apiPayload = err.payload || {};
      if (apiPayload.code === 'ALREADY_INTERACTED') {
        showOverlay({
          type: 'already_played',
          players: apiPayload.players || [],
        });
      } else {
        showOverlay({
          type: 'error',
          title: mapErrorCode(apiPayload, 'SCAN INVÁLIDO'),
          message: apiPayload.error || err.message,
        });
      }
      setStatus(apiPayload.error || err.message);
    }

    await loadStatus();
    await loadQr();
  }

  async function openScanner() {
    if (!statusCache?.is_active) return;
    readerWrap.style.display = 'block';

    if (!scanner) {
      scanner = new Html5Qrcode('reader');
    }

    try {
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 220 },
        async (decodedText) => {
          await scanner.stop();
          readerWrap.style.display = 'none';
          await processScan(decodedText);
        }
      );
    } catch (err) {
      showOverlay({ type: 'error', title: 'CÁMARA', message: `No se pudo abrir cámara: ${err.message}` });
      setStatus(`No se pudo abrir cámara: ${err.message}`);
    }
  }

  document.getElementById('scanBtn').addEventListener('click', () => openScanner());
  document.getElementById('manualBtn').addEventListener('click', async () => {
    const payload = prompt('Pegá qr_payload_string');
    if (!payload) return;
    await processScan(payload.trim());
  });
  searchEl.addEventListener('input', renderPending);

  async function iniciarJuego() {
    await loadStatus();
    await loadQr();
    setInterval(loadStatus, 10000);
    setInterval(loadQr, 30000);
  }

  (async () => {
    try {
      const player = await window.PlayerContext.ensureActivePlayerForThisDevice();
      window.CURRENT_PLAYER = player;
      await iniciarJuego();
    } catch (err) {
      setStatus(err.message);
    }
  })();
})();
