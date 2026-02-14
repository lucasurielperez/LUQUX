(function () {
  const API = 'admin/api.php';
  const STORAGE_KEYS = {
    deviceId: 'device_id',
    playerId: 'player_id',
    publicCode: 'public_code',
    displayName: 'display_name',
  };

  const newPlayerEl = document.getElementById('newPlayer');
  const knownPlayerEl = document.getElementById('knownPlayer');
  const knownNameEl = document.getElementById('knownName');
  const displayNameInput = document.getElementById('displayName');
  const renameInput = document.getElementById('renameInput');
  const renameWrap = document.getElementById('renameWrap');
  const statusEl = document.getElementById('status');

  const startBtn = document.getElementById('startBtn');
  const continueBtn = document.getElementById('continueBtn');
  const changeNameBtn = document.getElementById('changeNameBtn');
  const saveNameBtn = document.getElementById('saveNameBtn');

  let player = null;

  function setStatus(msg) {
    statusEl.textContent = msg || '';
  }

  function uuidV4() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = Math.random() * 16 | 0;
      const v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function getDeviceId() {
    let deviceId = localStorage.getItem(STORAGE_KEYS.deviceId);
    if (!deviceId) {
      deviceId = uuidV4();
      localStorage.setItem(STORAGE_KEYS.deviceId, deviceId);
    }
    return deviceId;
  }

  async function apiGet(action, query) {
    const params = new URLSearchParams(query || {});
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}&${params.toString()}`);
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  async function apiPost(action, body) {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
  }

  function savePlayer(p) {
    player = p;
    localStorage.setItem(STORAGE_KEYS.playerId, String(p.id));
    localStorage.setItem(STORAGE_KEYS.publicCode, String(p.public_code));
    localStorage.setItem(STORAGE_KEYS.displayName, String(p.display_name));
  }

  function showKnownPlayer(p) {
    knownNameEl.textContent = p.display_name;
    renameInput.value = p.display_name;
    newPlayerEl.classList.add('hidden');
    knownPlayerEl.classList.remove('hidden');
  }

  function showRegistration() {
    knownPlayerEl.classList.add('hidden');
    newPlayerEl.classList.remove('hidden');
  }

  function goToGame() {
    if (!player) return;
    location.href = 'admin/sumador.html';
  }

  async function bootstrap() {
    setStatus('Verificando jugador...');
    const deviceId = getDeviceId();

    try {
      const data = await apiGet('player_me', { device_id: deviceId });
      if (data.player) {
        savePlayer(data.player);
        showKnownPlayer(data.player);
        setStatus('Dispositivo reconocido.');
      } else {
        showRegistration();
        setStatus('Ingresá tu nombre para arrancar.');
      }
    } catch (err) {
      showRegistration();
      setStatus(`No se pudo verificar el dispositivo: ${err.message}`);
    }
  }

  startBtn.addEventListener('click', async function () {
    const name = String(displayNameInput.value || '').trim();
    const deviceId = getDeviceId();

    if (!name) {
      setStatus('Ingresá un nombre válido.');
      return;
    }

    startBtn.disabled = true;
    setStatus('Registrando jugador...');

    try {
      const data = await apiPost('player_register', { display_name: name, device_id: deviceId });
      savePlayer(data.player);
      goToGame();
    } catch (err) {
      setStatus(`No se pudo registrar: ${err.message}`);
    } finally {
      startBtn.disabled = false;
    }
  });

  continueBtn.addEventListener('click', function () {
    goToGame();
  });

  changeNameBtn.addEventListener('click', function () {
    renameWrap.classList.toggle('hidden');
  });

  saveNameBtn.addEventListener('click', async function () {
    if (!player) return;

    const newName = String(renameInput.value || '').trim();
    if (!newName) {
      setStatus('Ingresá un nombre válido.');
      return;
    }

    saveNameBtn.disabled = true;
    setStatus('Guardando nombre...');

    try {
      const data = await apiPost('player_rename', {
        player_id: player.id,
        device_id: getDeviceId(),
        display_name: newName,
      });
      savePlayer(data.player);
      showKnownPlayer(data.player);
      renameWrap.classList.add('hidden');
      setStatus('Nombre actualizado.');
    } catch (err) {
      setStatus(`No se pudo actualizar: ${err.message}`);
    } finally {
      saveNameBtn.disabled = false;
    }
  });

  bootstrap();
})();
