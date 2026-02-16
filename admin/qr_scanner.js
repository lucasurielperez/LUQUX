(function () {
  const LOCK_MS = 1500;
  const statusEl = document.getElementById('status');
  const lastReadEl = document.getElementById('lastRead');
  const autoOpenEl = document.getElementById('autoOpen');
  const startBtn = document.getElementById('startBtn');
  const resumeBtn = document.getElementById('resumeBtn');

  let scanner = null;
  let cameraRunning = false;
  let busy = false;
  let lastCode = '';
  let lastCodeAt = 0;

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

  function extractCode(decodedText) {
    const text = String(decodedText || '').trim();
    if (!text) return '';

    try {
      const parsed = new URL(text);
      return (parsed.searchParams.get('code') || '').trim();
    } catch (_err) {
      return text;
    }
  }

  async function stopScanner() {
    if (!scanner || !cameraRunning) return;
    try {
      await scanner.stop();
    } catch (_err) {
      // ignore stop race conditions
    }
    cameraRunning = false;
  }

  async function redirectToClaim(code) {
    const basePath = inferBasePath();
    const claimPath = `${basePath}/qr.html`;
    const returnTo = `${basePath}/admin/qr_scanner.html`;
    const params = new URLSearchParams({
      code,
      return_to: returnTo,
    });

    if (autoOpenEl.checked) {
      params.set('return_mode', 'auto');
    }

    const finalClaimUrl = `${claimPath}?${params.toString()}`;

    busy = true;
    setStatus('Redirigiendo al claim…');
    await stopScanner();
    window.location.href = finalClaimUrl;
  }

  async function onDecode(decodedText) {
    const code = extractCode(decodedText);
    if (!code) {
      setStatus('QR sin code válido. Probá con otro QR.');
      return;
    }

    const now = Date.now();
    if (code === lastCode && now - lastCodeAt < LOCK_MS) {
      return;
    }

    if (busy) return;

    lastCode = code;
    lastCodeAt = now;
    lastReadEl.textContent = code;
    await redirectToClaim(code);
  }

  async function startScanner() {
    if (busy) {
      setStatus('Esperando redirección…');
      return;
    }

    if (!scanner) {
      scanner = new Html5Qrcode('reader');
    }

    if (cameraRunning) {
      setStatus('La cámara ya está activa.');
      return;
    }

    try {
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: 240 },
        onDecode,
        () => {}
      );
      cameraRunning = true;
      setStatus('Cámara activa. Esperando QR secreto…');
    } catch (err) {
      setStatus(`No se pudo abrir cámara: ${err.message}`);
    }
  }

  startBtn.addEventListener('click', async () => {
    busy = false;
    await startScanner();
  });

  resumeBtn.addEventListener('click', async () => {
    busy = false;
    await startScanner();
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      setStatus('Volviste al escáner. Tocá “Reanudar cámara” si no se activó sola.');
    }
  });

  setStatus('Listo para iniciar cámara');
})();
