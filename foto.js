(function () {
  const API = 'api.php';
  const photoInput = document.getElementById('photoInput');
  const preview = document.getElementById('preview');
  const uploadBtn = document.getElementById('uploadBtn');
  const statusEl = document.getElementById('status');
  const enabledTextEl = document.getElementById('enabledText');

  let photosEnabled = false;

  function setStatus(msg, type) {
    statusEl.textContent = msg;
    statusEl.className = type || '';
  }

  async function apiGet(action) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, { cache: 'no-store' });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) {
      throw new Error(data.code || data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  async function refreshPhotoStatus() {
    try {
      const data = await apiGet('photo_status');
      photosEnabled = !!data.enabled;
      if (!photosEnabled) {
        enabledTextEl.textContent = 'Fotos deshabilitadas por el admin.';
        uploadBtn.disabled = true;
      } else {
        enabledTextEl.textContent = `Habilitado. Duración en pantalla: ${Math.round(Number(data.duration_ms || 5000) / 1000)}s · En cola: ${Number(data.queue_len || 0)}`;
        uploadBtn.disabled = false;
      }
    } catch (err) {
      enabledTextEl.textContent = 'No se pudo consultar el estado de fotos.';
      uploadBtn.disabled = true;
    }
  }

  photoInput.addEventListener('change', function () {
    const file = photoInput.files && photoInput.files[0];
    if (!file) {
      preview.style.display = 'none';
      preview.src = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = function () {
      preview.src = String(reader.result || '');
      preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
  });

  uploadBtn.addEventListener('click', async function () {
    const file = photoInput.files && photoInput.files[0];
    if (!file) {
      setStatus('Elegí una foto primero.', 'error');
      return;
    }

    if (!photosEnabled) {
      setStatus('Fotos deshabilitadas por el admin.', 'error');
      return;
    }

    uploadBtn.disabled = true;
    setStatus('Subiendo...', 'muted');

    try {
      const form = new FormData();
      form.append('photo', file);

      if (window.PlayerContext && typeof window.PlayerContext.ensureActivePlayerForThisDevice === 'function') {
        try {
          const player = await window.PlayerContext.ensureActivePlayerForThisDevice();
          if (player && player.player_token) {
            form.append('player_token', String(player.player_token));
          }
        } catch (err) {
          // identidad opcional
        }
      }

      const res = await fetch(`${API}?action=photo_upload`, {
        method: 'POST',
        body: form,
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) {
        const code = String(data.code || '');
        if (code === 'PHOTOS_DISABLED') {
          throw new Error('Fotos deshabilitadas por el admin.');
        }
        if (code === 'PHOTO_TOO_LARGE') {
          throw new Error('Error: archivo muy pesado.');
        }
        if (code === 'PHOTO_BAD_MIME') {
          throw new Error('Error: formato inválido. Usá JPG/PNG/WEBP.');
        }
        throw new Error(data.error || 'Error subiendo foto.');
      }

      setStatus(`Subida OK, estás en la cola (#${Number(data.eta_position || 1)}).`, 'ok');
      photoInput.value = '';
      preview.style.display = 'none';
      preview.src = '';
      await refreshPhotoStatus();
    } catch (err) {
      setStatus(String(err.message || err), 'error');
    } finally {
      uploadBtn.disabled = !photosEnabled;
    }
  });

  refreshPhotoStatus();
})();
