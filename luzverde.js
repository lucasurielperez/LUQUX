(function () {
  const API = 'api.php';
  const POLL_MS = 1000;
  const MOTION_MS = 400;
  const SENSOR_STALE_MS = 1200;
  const KEEP_AWAKE_VIDEO_SRC = 'assets/keepalive.mp4';

  const appEl = document.getElementById('app');
  const titleEl = document.getElementById('title');
  const messageEl = document.getElementById('message');
  const sensorBtn = document.getElementById('sensorBtn');
  const offlineEl = document.getElementById('offline');

  let player = null;
  let session = null;
  let me = { status: 'alive', armed: false };
  let sensorEnabled = false;
  let permissionRequested = false;
  let motionBuffer = [];
  let lastMotionSentAt = 0;
  let lastMagnitude = null;
  let lastOrientation = null;
  let lastSensorEventAt = 0;
  let statusTimer = null;
  let motionTimer = null;
  let wakeLockSentinel = null;
  let keepAwakeVideo = null;

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

  function isAliveActiveRound() {
    return session?.state === 'ACTIVE' && me?.status === 'alive';
  }

  function sensorsAreLive() {
    return sensorEnabled && Date.now() - lastSensorEventAt <= SENSOR_STALE_MS;
  }

  function ensureKeepAwakeVideo() {
    if (keepAwakeVideo) return keepAwakeVideo;
    const video = document.createElement('video');
    video.src = KEEP_AWAKE_VIDEO_SRC;
    video.muted = true;
    video.loop = true;
    video.playsInline = true;
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');
    video.style.position = 'fixed';
    video.style.width = '1px';
    video.style.height = '1px';
    video.style.opacity = '0';
    video.style.pointerEvents = 'none';
    document.body.appendChild(video);
    keepAwakeVideo = video;
    return keepAwakeVideo;
  }

  async function acquireWakeLock() {
    if (!('wakeLock' in navigator) || wakeLockSentinel) return;
    try {
      wakeLockSentinel = await navigator.wakeLock.request('screen');
      wakeLockSentinel.addEventListener('release', () => {
        wakeLockSentinel = null;
      });
    } catch (_err) {
      wakeLockSentinel = null;
    }
  }

  async function startKeepAwake() {
    await acquireWakeLock();
    const video = ensureKeepAwakeVideo();
    try {
      await video.play();
    } catch (_err) {
      // iOS may require explicit gesture; this is retried on button click.
    }
  }

  async function stopKeepAwake() {
    if (wakeLockSentinel) {
      try {
        await wakeLockSentinel.release();
      } catch (_err) {
        // no-op
      }
      wakeLockSentinel = null;
    }

    if (keepAwakeVideo && !keepAwakeVideo.paused) {
      keepAwakeVideo.pause();
    }
  }

  async function armParticipant() {
    if (!sensorEnabled || me?.armed) return;
    if (!sensorsAreLive()) return;

    try {
      const data = await api('luzverde_arm', 'POST', {
        ...identityPayload(),
        client_ts: Date.now(),
      });
      if (data.armed) {
        me.armed = true;
      }
    } catch (_err) {
      // retried by heartbeat loop
    }
  }

  function handleMotion(ev) {
    if (!sensorEnabled) return;

    lastSensorEventAt = Date.now();

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

    if (isAliveActiveRound()) {
      motionBuffer.push(score);
    }
  }

  async function postBackgroundPenalty(reason) {
    if (!isAliveActiveRound() || !me.armed) return;

    try {
      await api('luzverde_motion', 'POST', {
        ...identityPayload(),
        motion_score: 9999,
        reason,
        client_ts: Date.now(),
      });
    } catch (_err) {
      // Server offline timeout is source of truth.
    }
  }

  async function sendHeartbeatLoop() {
    if (!isAliveActiveRound() || !me.armed) return;
    const now = Date.now();
    if (now - lastMotionSentAt < MOTION_MS) return;
    lastMotionSentAt = now;

    if (!sensorsAreLive()) {
      offlineEl.classList.remove('hidden');
      return;
    }

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
        sensor_ok: true,
        client_ts: now,
      });

      offlineEl.classList.add('hidden');
      if (data.eliminated) {
        me.status = 'eliminated';
        sensorEnabled = false;
        await stopKeepAwake();
        setScreen('red', 'ELIMINADO', 'Perdiste por moverte o salir de la app.');
      }
    } catch (_err) {
      offlineEl.classList.remove('hidden');
    }
  }

  async function pollStatus() {
    try {
      const data = await api('luzverde_status', 'POST', identityPayload());
      session = data.session;
      me = data.me || { status: 'alive', armed: false };
      offlineEl.classList.add('hidden');

      if (!session) {
        await stopKeepAwake();
        setScreen('neutral', 'Conectando…', data.message || 'Esperando sesión activa.');
        return;
      }

      if (!me.armed) {
        setScreen('neutral', 'Pendiente: habilitar sensores', 'Sin sensores activos no participás de la ronda.');
        return;
      }

      if (session.state === 'ACTIVE' && me.status === 'alive') {
        await startKeepAwake();
        setScreen('green', 'EN JUEGO – QUEDATE QUIETO', data.message || 'No te muevas ni salgas de la app.');
      } else if (me.status === 'eliminated') {
        await stopKeepAwake();
        setScreen('red', 'ELIMINADO', 'Esperá a que termine la ronda.');
      } else if (session.state === 'REST') {
        await startKeepAwake();
        setScreen('neutral', 'Descanso', data.message || 'Esperando próxima ronda…');
      } else if (session.state === 'FINISHED') {
        sensorEnabled = false;
        motionBuffer = [];
        await stopKeepAwake();
        const winner = session.winner_name ? `Ganador: ${session.winner_name}` : 'Juego terminado';
        setScreen('neutral', 'Juego terminado', winner);
      } else {
        await startKeepAwake();
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

    await startKeepAwake();

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
    window.addEventListener('deviceorientation', handleMotion, { passive: true });
  }

  function bindBackgroundGuards() {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        postBackgroundPenalty('VISIBILITY_HIDDEN');
      } else {
        acquireWakeLock();
      }
    });

    window.addEventListener('pagehide', () => postBackgroundPenalty('PAGEHIDE'));
    window.addEventListener('blur', () => postBackgroundPenalty('BLUR'));
    document.addEventListener('freeze', () => postBackgroundPenalty('FREEZE'));
  }

  async function init() {
    try {
      player = await window.PlayerContext.ensureActivePlayerForThisDevice();
      const joined = await api('luzverde_join', 'POST', identityPayload());
      me.armed = !(joined.needs_arm ?? true);
      setScreen('neutral', 'Esperando que arranque la ronda…', 'Conectado al juego. Mantené la pantalla prendida y no salgas de la app.');

      const needsButton = typeof DeviceMotionEvent !== 'undefined'
        && typeof DeviceMotionEvent.requestPermission === 'function';

      if (needsButton) {
        sensorBtn.classList.remove('hidden');
      } else {
        requestSensorPermission();
      }

      motionTimer = setInterval(async () => {
        await armParticipant();
        await sendHeartbeatLoop();
      }, MOTION_MS);
      statusTimer = setInterval(pollStatus, POLL_MS);
      bindBackgroundGuards();
      pollStatus();
    } catch (err) {
      setScreen('neutral', 'No se pudo iniciar', err.message || 'Error');
    }
  }

  sensorBtn.addEventListener('click', requestSensorPermission);
  init();
})();
