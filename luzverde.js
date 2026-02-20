(function () {
  const API = 'api.php';
  const POLL_MS = 1000;
  const MOTION_MS = 300;

  const appEl = document.getElementById('app');
  const titleEl = document.getElementById('title');
  const messageEl = document.getElementById('message');
  const sensorBtn = document.getElementById('sensorBtn');
  const offlineEl = document.getElementById('offline');

  let player = null;
  let session = null;
  let me = { status: 'alive' };
  let statusTimer = null;
  let sensorEnabled = false;
  let permissionRequested = false;
  let motionBuffer = [];
  let lastMotionSentAt = 0;
  let lastMagnitude = null;
  let lastOrientation = null;

  function setScreen(mode, title, msg) {
    appEl.className = `screen ${mode}`;
    titleEl.textContent = title;
    messageEl.textContent = msg || '';
  }

  async function api(action, method, body) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: method || 'GET',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : null,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  function identityPayload() {
    return {
      player_token: player?.player_token || localStorage.getItem('player_token') || '',
      player_id: Number(player?.id || localStorage.getItem('player_id') || 0),
    };
  }

  function handleMotion(ev) {
    if (session?.state !== 'ACTIVE') return;
    if (!sensorEnabled || me?.status !== 'alive') return;

    const acc = ev.accelerationIncludingGravity || ev.acceleration || { x: 0, y: 0, z: 0 };
    const x = Number(acc.x || 0);
    const y = Number(acc.y || 0);
    const z = Number(acc.z || 0);
    const magnitude = Math.sqrt(x * x + y * y + z * z);

    const rot = ev.rotationRate || { alpha: 0, beta: 0, gamma: 0 };
    const orientation = Math.abs(Number(rot.alpha || 0)) + Math.abs(Number(rot.beta || 0)) + Math.abs(Number(rot.gamma || 0));

    let score = 0;
    if (lastMagnitude !== null) {
      score += Math.abs(magnitude - lastMagnitude) * 3.2;
    }
    if (lastOrientation !== null) {
      score += Math.abs(orientation - lastOrientation) * 0.08;
    }

    lastMagnitude = magnitude;
    lastOrientation = orientation;

    motionBuffer.push(score);
  }

  async function sendMotionLoop() {
    if (!sensorEnabled || session?.state !== 'ACTIVE' || me?.status !== 'alive') return;
    const now = Date.now();
    if (now - lastMotionSentAt < MOTION_MS) return;
    lastMotionSentAt = now;

    let motionScore = 0;
    if (motionBuffer.length) {
      const sum = motionBuffer.reduce((a, b) => a + b, 0);
      motionScore = sum / motionBuffer.length;
      motionBuffer = [];
    }

    try {
      const data = await api('luzverde_motion', 'POST', {
        ...identityPayload(),
        motion_score: Number(motionScore.toFixed(3)),
        client_ts: now,
      });

      offlineEl.classList.add('hidden');
      if (data.eliminated) {
        me.status = 'eliminated';
        sensorEnabled = false;
        setScreen('red', 'ELIMINADO', 'Te moviste durante la ronda.');
      }
    } catch (_err) {
      offlineEl.classList.remove('hidden');
    }
  }

  async function pollStatus() {
    try {
      const data = await api('luzverde_status', 'POST', identityPayload());
      session = data.session;
      me = data.me || { status: 'alive' };
      offlineEl.classList.add('hidden');

      if (!session) {
        setScreen('neutral', 'Conectando…', data.message || 'Esperando sesión activa.');
        return;
      }

      if (session.state === 'ACTIVE' && me.status === 'alive') {
        setScreen('green', 'EN JUEGO – QUEDATE QUIETO', data.message || 'No te muevas.');
      } else if (me.status === 'eliminated') {
        setScreen('red', 'ELIMINADO', 'Esperá a que termine la ronda.');
      } else if (session.state === 'REST') {
        setScreen('neutral', 'Descanso', data.message || 'Esperando próxima ronda…');
      } else if (session.state === 'FINISHED') {
        sensorEnabled = false;
        motionBuffer = [];
        const winner = session.winner_name ? `Ganador: ${session.winner_name}` : 'Juego terminado';
        setScreen('neutral', 'Juego terminado', winner);
      } else {
        setScreen('neutral', 'Esperando que arranque la ronda…', data.message || 'Preparado.');
      }
    } catch (_err) {
      offlineEl.classList.remove('hidden');
      setScreen('neutral', 'Sin conexión', 'Reintentando…');
    }
  }

  async function requestSensorPermission() {
    if (permissionRequested) return;
    permissionRequested = true;

    if (typeof DeviceMotionEvent !== 'undefined' && typeof DeviceMotionEvent.requestPermission === 'function') {
      try {
        const result = await DeviceMotionEvent.requestPermission();
        if (result !== 'granted') {
          setScreen('neutral', 'Permiso denegado', 'Necesitás habilitar sensores para jugar.');
          return;
        }
      } catch (_err) {
        setScreen('neutral', 'Permiso de sensores', 'No se pudo solicitar permiso.');
        return;
      }
    }

    sensorEnabled = true;
    sensorBtn.classList.add('hidden');
    window.addEventListener('devicemotion', handleMotion, { passive: true });
    setInterval(sendMotionLoop, MOTION_MS);
  }

  async function init() {
    try {
      player = await window.PlayerContext.ensureActivePlayerForThisDevice();
      await api('luzverde_join', 'POST', identityPayload());
      setScreen('neutral', 'Esperando que arranque la ronda…', 'Conectado al juego.');

      const needsButton = typeof DeviceMotionEvent !== 'undefined'
        && typeof DeviceMotionEvent.requestPermission === 'function';

      if (needsButton) {
        sensorBtn.classList.remove('hidden');
      } else {
        requestSensorPermission();
      }

      statusTimer = setInterval(pollStatus, POLL_MS);
      pollStatus();
    } catch (err) {
      setScreen('neutral', 'No se pudo iniciar', err.message || 'Error');
    }
  }

  sensorBtn.addEventListener('click', requestSensorPermission);
  init();
})();
