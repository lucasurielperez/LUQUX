(function () {
  const LOCK_MS = 1500;
  const statusEl = document.getElementById('status');
  const startBtn = document.getElementById('startBtn');
  const stopBtn = document.getElementById('stopBtn');
  const switchCamBtn = document.getElementById('switchCamBtn');

  let scanner = null;
  let state = 'idle';
  let cameras = [];
  let selectedCamera = null;
  let selectedCameraIndex = 0;
  let busy = false;
  let lastCode = '';
  let lastCodeAt = 0;
  let handlingDecode = false;

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

  function setState(nextState) {
    state = nextState;
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
    cameras = await Html5Qrcode.getCameras();
    if (!Array.isArray(cameras) || cameras.length === 0) {
      throw new Error('No se detectaron cámaras en el dispositivo.');
    }

    if (!selectedCamera) {
      const preferred = cameras.findIndex((cam) => /back|rear|environment/i.test(String(cam.label || '')));
      selectedCameraIndex = preferred >= 0 ? preferred : 0;
      selectedCamera = cameras[selectedCameraIndex];
      return;
    }

    const currentIndex = cameras.findIndex((cam) => cam.id === selectedCamera.id);
    selectedCameraIndex = currentIndex >= 0 ? currentIndex : 0;
    selectedCamera = cameras[selectedCameraIndex];
  }

  async function stopScanner() {
    if (state !== 'scanning' && state !== 'starting') {
      return;
    }

    setState('stopping');

    try {
      if (scanner && scanner.isScanning) {
        await scanner.stop();
      }
    } catch (err) {
      setStatus(`Error al detener: ${err.message}`);
    }

    try {
      if (scanner) {
        await scanner.clear();
      }
    } catch (err) {
      setStatus(`Error al limpiar: ${err.message}`);
    }

    setState('idle');
  }

  async function redirectToClaim(code) {
    const basePath = inferBasePath();
    const claimPath = `${basePath}/qr.html`;
    const finalClaimUrl = `${claimPath}?code=${encodeURIComponent(code)}`;

    busy = true;
    setStatus('QR detectado. Redirigiendo…');
    await stopScanner();
    await new Promise((resolve) => setTimeout(resolve, 300));
    window.location.href = finalClaimUrl;
  }

  async function handleDecodedText(decodedText) {
    if (handlingDecode) return;
    handlingDecode = true;

    const code = extractCode(decodedText);
    if (!code) {
      setStatus('QR sin code válido. Probá con otro QR.');
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
    await redirectToClaim(code);
    handlingDecode = false;
  }

  async function startScanner() {
    if (state !== 'idle') {
      return;
    }
    if (busy) {
      setStatus('Esperando redirección…');
      return;
    }

    setState('starting');

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

      await scanner.start(
        { deviceId: { exact: selectedCamera.id } },
        config,
        (decodedText) => {
          handleDecodedText(decodedText);
        },
        () => {
          // callback de error intencionalmente vacío para evitar ruido visual
        }
      );

      setState('scanning');
      setStatus('Cámara activa. Apuntá al QR.');
    } catch (err) {
      setStatus(`No se pudo abrir cámara: ${err.message}`);
      setState('idle');
    }
  }

  async function switchCamera() {
    try {
      await ensureCameraList();
      selectedCameraIndex = (selectedCameraIndex + 1) % cameras.length;
      selectedCamera = cameras[selectedCameraIndex];

      const wasScanning = state === 'scanning';
      if (wasScanning) {
        await stopScanner();
      }
      await startScanner();
    } catch (err) {
      setStatus(`No se pudo cambiar cámara: ${err.message}`);
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

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      setStatus('Volviste al escáner. Tocá “Iniciar” si no se activó la cámara.');
    }
  });

  setState('idle');
  setStatus('Listo para iniciar cámara.');
})();
