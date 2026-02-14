(function () {
  const API = 'api.php';
  const playerId = Number(localStorage.getItem('player_id') || 0);
  const playerToken = localStorage.getItem('player_token') || '';

  const statusEl = document.getElementById('status');
  const pendingListEl = document.getElementById('pendingList');
  const progressEl = document.getElementById('progress');
  const qrcodeEl = document.getElementById('qrcode');
  const searchEl = document.getElementById('search');
  const revealOverlay = document.getElementById('revealOverlay');
  const revealText = document.getElementById('revealText');
  const endedCard = document.getElementById('endedCard');
  const readerWrap = document.getElementById('readerWrap');

  let statusCache = null;
  let scanner = null;

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
    if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  }

  function authPayload() {
    if (playerToken) return { player_token: playerToken };
    return { player_id: playerId };
  }

  function renderPending() {
    const term = String(searchEl.value || '').toLowerCase().trim();
    const rows = (statusCache?.opponents_pending || []).filter((r) => r.display_name.toLowerCase().includes(term));
    pendingListEl.innerHTML = rows.map((r) => `<div class="pending-item">${r.display_name}</div>`).join('') || '<div class="muted">Sin pendientes</div>';
    progressEl.textContent = `Interacciones: ${statusCache?.interacted_count || 0}/${statusCache?.total_opponents || 0}`;
  }

  function renderReveal(result) {
    const preMe = result.pre_state.me;
    const preOther = result.pre_state.other;
    const postMe = result.post_state.me;
    const postOther = result.post_state.other;

    revealText.innerHTML = `
      <p>Vos: <b>${preMe.role}</b> · ${preMe.power} ➜ ${postMe.power}</p>
      <p>Oponente: <b>${preOther.role}</b> · ${preOther.power} ➜ ${postOther.power}</p>
      <p><b>${result.message}</b></p>
    `;

    revealOverlay.classList.add('visible');
    setTimeout(() => revealOverlay.classList.remove('visible'), 1000);
  }

  async function loadStatus() {
    if (!playerId && !playerToken) {
      setStatus('No hay identidad de jugador en este dispositivo. Volvé al inicio.');
      return;
    }

    const st = await call('virus_status', 'GET', authPayload());
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

  async function processScan(payload) {
    const result = await call('virus_scan', 'POST', {
      ...authPayload(),
      qr_payload_string: payload,
    });

    renderReveal(result);
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
      setStatus(`No se pudo abrir cámara: ${err.message}`);
    }
  }

  document.getElementById('scanBtn').addEventListener('click', () => openScanner());
  document.getElementById('manualBtn').addEventListener('click', async () => {
    const payload = prompt('Pegá qr_payload_string');
    if (!payload) return;
    try {
      await processScan(payload.trim());
    } catch (err) {
      setStatus(err.message);
    }
  });
  searchEl.addEventListener('input', renderPending);

  (async function init() {
    try {
      await loadStatus();
      await loadQr();
      setInterval(loadStatus, 10000);
      setInterval(loadQr, 30000);
    } catch (err) {
      setStatus(err.message);
    }
  })();
})();
