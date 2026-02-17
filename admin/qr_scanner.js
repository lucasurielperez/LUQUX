(function () {
  const LOCK_MS = 1500;
  const statusEl = document.getElementById('status');
  const lastReadEl = document.getElementById('lastRead');
  const startBtn = document.getElementById('startBtn');
  const stopBtn = document.getElementById('stopBtn');
  const switchCamBtn = document.getElementById('switchCamBtn');
  const simulateInputEl = document.getElementById('simulateInput');
  const simulateBtn = document.getElementById('simulateBtn');
  const debugStateEl = document.getElementById('debugState');
  const debugAnalysisEl = document.getElementById('debugAnalysis');
  const debugFpsEl = document.getElementById('debugFps');
  const debugFailureRateEl = document.getElementById('debugFailureRate');
  const debugCameraEl = document.getElementById('debugCamera');
  const debugLastErrorEl = document.getElementById('debugLastError');
  const debugLogEl = document.getElementById('debugLog');
  const recDotEl = document.getElementById('recDot');
  const analysisWarningEl = document.getElementById('analysisWarning');

  let scanner = null;
  let state = 'idle';
  let cameras = [];
  let selectedCamera = null;
  let selectedCameraIndex = 0;
  let metricsTimer = null;
  let failureEventsSinceTick = 0;
  let decodeEventsSinceTick = 0;
  let lastAnalysisAt = 0;
  let lastFailure = '-';
  let busy = false;
  let lastCode = '';
  let lastCodeAt = 0;
  let handlingDecode = false;
  const logs = [];

  function setStatus(message) {
    statusEl.textContent = message;
  }

  function inferBasePath() {
    const path = window.location.pathname;
    const marker = '/admin/qr_scanner.html';
    if (path.endsWith(marker)) {
      return path.slice(0, -marker.length);
    }
    const idx = path.lastIndexOf('/admin/');
    if (idx >= 0) {
      return path.slice(0, idx);
    }
    return '';
  }

  function addLog(event, detail) {
    const ts = new Date().toLocaleTimeString('es-AR', { hour12: false });
    logs.unshift(`[${ts}] ${event}${detail ? `: ${detail}` : ''}`);
    if (logs.length > 70) logs.length = 70;
    debugLogEl.textContent = logs.join('\n');
  }

  function setState(nextState, detail) {
    state = nextState;
    debugStateEl.textContent = nextState;
    addLog(`state=${nextState}`, detail || '');
  }

  function refreshAnalysisIndicator() {
    const active = Date.now() - lastAnalysisAt <= 1700;
    debugAnalysisEl.textContent = active ? 'SI' : 'NO';
    recDotEl.classList.toggle('rec-dot--active', active);
    analysisWarningEl.classList.toggle('show', state === 'scanning' && !active);
  }

  function setLastError(message) {
    lastFailure = message || '-';
    debugLastErrorEl.textContent = lastFailure;
  }

  function updateCameraDebug() {
    if (!selectedCamera) {
      debugCameraEl.textContent = '-';
      return;
    }
    debugCameraEl.textContent = `${selectedCamera.id} | ${selectedCamera.label || '(sin label)'}`;
  }

  function tickMetrics() {
    const analyzed = failureEventsSinceTick + decodeEventsSinceTick;
    debugFpsEl.textContent = String(analyzed);
    debugFailureRateEl.textContent = String(failureEventsSinceTick);
    failureEventsSinceTick = 0;
    decodeEventsSinceTick = 0;
    refreshAnalysisIndicator();
  }

  function ensureMetricsTimer() {
    if (metricsTimer) return;
    metricsTimer = setInterval(tickMetrics, 1000);
  }

  function clearMetricsTimer() {
    if (!metricsTimer) return;
    clearInterval(metricsTimer);
    metricsTimer = null;
  }

  function computeQrbox() {
    const rect = document.getElementById('reader').getBoundingClientRect();
    const minSide = Math.max(1, Math.min(rect.width || 320, rect.height || 320));
    const size = Math.max(200, Math.min(320, Math.floor(minSide * 0.6)));
    return { width: size, height: size };
  }

  function extractCode(decodedText) {
    const text = String(decodedText || '').trim();
    if (!text) return '';

    try {
      const parsed = new URL(text);
      const fromParam = (parsed.searchParams.get('code') || '').trim();
      if (fromParam) return fromParam;
      const pathParts = parsed.pathname.split('/').filter(Boolean);
      return pathParts[pathParts.length - 1] || text;
    } catch (_err) {
      return text;
    }
  }

  async function ensureCameraList() {
    addLog('cameras', 'solicitando listado');
    cameras = await Html5Qrcode.getCameras();
    if (!Array.isArray(cameras) || cameras.length === 0) {
      throw new Error('No se detectaron cámaras en el dispositivo.');
    }

    if (!selectedCamera) {
      const preferred = cameras.findIndex((cam) => /back|rear|environment/i.test(String(cam.label || '')));
      selectedCameraIndex = preferred >= 0 ? preferred : 0;
      selectedCamera = cameras[selectedCameraIndex];
    } else {
      const currentIndex = cameras.findIndex((cam) => cam.id === selectedCamera.id);
      selectedCameraIndex = currentIndex >= 0 ? currentIndex : 0;
      selectedCamera = cameras[selectedCameraIndex];
    }

    updateCameraDebug();
    addLog('camera_selected', `${selectedCamera.id} ${selectedCamera.label || ''}`);
  }

  async function stopScanner(options = {}) {
    if (state !== 'scanning' && state !== 'starting') {
      return;
    }

    setState('stopping', options.reason || 'stop solicitado');

    try {
      if (scanner && scanner.isScanning) {
        await scanner.stop();
      }
    } catch (err) {
      setLastError(`Error al detener: ${err.message}`);
      addLog('stop_error', err.message);
    }

    try {
      if (scanner) {
        await scanner.clear();
      }
    } catch (err) {
      addLog('clear_error', err.message);
    }

    clearMetricsTimer();
    setState('idle', 'scanner detenido');
    refreshAnalysisIndicator();
  }

  async function redirectToClaim(code) {
    const basePath = inferBasePath();
    const claimPath = `${basePath}/qr.html`;
    const finalClaimUrl = `${claimPath}?code=${encodeURIComponent(code)}`;

    busy = true;
    setStatus(`QR detectado: ${code}`);
    addLog('redirect', finalClaimUrl);
    await stopScanner({ reason: 'QR detectado' });
    await new Promise((resolve) => setTimeout(resolve, 300));
    window.location.href = finalClaimUrl;
  }

  async function handleDecodedText(decodedText) {
    if (handlingDecode) return;
    handlingDecode = true;

    const code = extractCode(decodedText);
    if (!code) {
      setStatus('QR sin code válido. Probá con otro QR.');
      setLastError('QR sin code válido');
      handlingDecode = false;
      return;
    }

    const now = Date.now();
    if (code === lastCode && now - lastCodeAt < LOCK_MS) {
      handlingDecode = false;
      return;
    }

    if (busy) {
      handlingDecode = false;
      return;
    }

    lastCode = code;
    lastCodeAt = now;
    decodeEventsSinceTick += 1;
    lastAnalysisAt = Date.now();
    lastReadEl.textContent = `Último QR: ${code}`;
    addLog('decoded', code);
    await redirectToClaim(code);
    handlingDecode = false;
  }

  async function startScanner() {
    if (state !== 'idle') {
      addLog('start_skip', `estado=${state}`);
      return;
    }
    if (busy) {
      setStatus('Esperando redirección…');
      return;
    }

    setState('starting', 'iniciando cámara');
    ensureMetricsTimer();

    try {
      await ensureCameraList();

      if (!scanner) {
        scanner = new Html5Qrcode('reader');
      }

      const qrbox = computeQrbox();
      const config = {
        fps: 12,
        qrbox,
        aspectRatio: 1.333334,
      };
      addLog('start_config', JSON.stringify({ qrbox, fps: config.fps, camera: selectedCamera.id }));

      await scanner.start(
        { deviceId: { exact: selectedCamera.id } },
        config,
        (decodedText) => {
          handleDecodedText(decodedText);
        },
        (errorMessage) => {
          failureEventsSinceTick += 1;
          lastAnalysisAt = Date.now();
          if (errorMessage && errorMessage !== lastFailure) {
            setLastError(errorMessage);
          }
        }
      );

      setState('scanning', 'scanner activo');
      setStatus('Cámara activa. Apuntá al QR.');
      setLastError('-');
    } catch (err) {
      clearMetricsTimer();
      setLastError(err.message);
      setStatus(`No se pudo abrir cámara: ${err.message}`);
      addLog('start_error', err.message);
      setState('idle', 'falló start');
    }
  }

  async function switchCamera() {
    try {
      await ensureCameraList();
      selectedCameraIndex = (selectedCameraIndex + 1) % cameras.length;
      selectedCamera = cameras[selectedCameraIndex];
      updateCameraDebug();
      addLog('switch_camera', `${selectedCamera.id} ${selectedCamera.label || ''}`);

      const wasScanning = state === 'scanning';
      if (wasScanning) {
        await stopScanner({ reason: 'cambio de cámara' });
      }
      await startScanner();
    } catch (err) {
      setLastError(err.message);
      setStatus(`No se pudo cambiar cámara: ${err.message}`);
      addLog('switch_error', err.message);
    }
  }

  startBtn.addEventListener('click', () => {
    busy = false;
    startScanner();
  });

  stopBtn.addEventListener('click', () => {
    stopScanner();
  });

  switchCamBtn.addEventListener('click', () => {
    switchCamera();
  });

  simulateBtn.addEventListener('click', async () => {
    const fakeText = String(simulateInputEl.value || '').trim();
    if (!fakeText) {
      setStatus('Ingresá un texto para simular decodedText.');
      return;
    }
    addLog('simulate', fakeText);
    await handleDecodedText(fakeText);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      setStatus('Volviste al escáner. Tocá “Iniciar” si no se activó la cámara.');
    }
  });

  setState('idle', 'esperando interacción del usuario');
  setLastError('-');
  setStatus('Listo para iniciar cámara.');
  addLog('ready', 'Iniciar debe tocarse manualmente para iOS.');
})();
