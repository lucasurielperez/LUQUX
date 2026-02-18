(function () {
  const API = 'api.php';
  const STORAGE_KEYS = {
    deviceId: 'device_id',
    playerId: 'player_id',
    publicCode: 'public_code',
    displayName: 'display_name',
    playerToken: 'player_token',
  };

  const newPlayerEl = document.getElementById('newPlayer');
  const knownPlayerEl = document.getElementById('knownPlayer');
  const knownNameEl = document.getElementById('knownName');
  const playerSelectorEl = document.getElementById('playerSelector');
  const playerSelectorListEl = document.getElementById('playerSelectorList');
  const displayNameInput = document.getElementById('displayName');
  const renameInput = document.getElementById('renameInput');
  const secondNameInput = document.getElementById('secondNameInput');
  const renameWrap = document.getElementById('renameWrap');
  const addSecondWrap = document.getElementById('addSecondWrap');
  const statusEl = document.getElementById('status');

  const startBtn = document.getElementById('startBtn');
  const continueBtn = document.getElementById('continueBtn');
  const changeNameBtn = document.getElementById('changeNameBtn');
  const saveNameBtn = document.getElementById('saveNameBtn');
  const addSecondBtn = document.getElementById('addSecondBtn');
  const saveSecondBtn = document.getElementById('saveSecondBtn');

  let player = null;
  let players = [];

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
    if (p.player_token) {
      localStorage.setItem(STORAGE_KEYS.playerToken, String(p.player_token));
    }
  }

  function showKnownPlayer(p, showAddSecond) {
    knownNameEl.textContent = p.display_name;
    renameInput.value = p.display_name;
    newPlayerEl.classList.add('hidden');
    playerSelectorEl.classList.add('hidden');
    knownPlayerEl.classList.remove('hidden');
    addSecondBtn.classList.toggle('hidden', !showAddSecond);
    addSecondWrap.classList.add('hidden');
  }

  function showRegistration() {
    player = null;
    knownPlayerEl.classList.add('hidden');
    playerSelectorEl.classList.add('hidden');
    newPlayerEl.classList.remove('hidden');
  }

  function showSelector(list) {
    newPlayerEl.classList.add('hidden');
    knownPlayerEl.classList.add('hidden');
    playerSelectorEl.classList.remove('hidden');
    playerSelectorListEl.innerHTML = '';

    list.forEach(function (p) {
      const btn = document.createElement('button');
      btn.className = 'player-option';
      btn.textContent = `${p.display_name} (${p.public_code})`;
      btn.addEventListener('click', function () {
        savePlayer(p);
        setStatus(`Jugador activo: ${p.display_name}.`);
        goToGame();
      });
      playerSelectorListEl.appendChild(btn);
    });
  }

  function goToGame() {
    if (!player) return;
    location.href = 'admin/qr_scanner.html';
  }

  async function bootstrap() {
    setStatus('Verificando jugador...');
    const deviceId = getDeviceId();

    try {
      const data = await apiGet('player_me', { device_id: deviceId });
      players = Array.isArray(data.players) ? data.players : [];

      if (data.count === 0) {
        showRegistration();
        setStatus('Ingresá tu nombre para arrancar.');
      } else if (data.count === 1 && players[0]) {
        savePlayer(players[0]);
        showKnownPlayer(players[0], true);
        setStatus('Dispositivo reconocido. Podés continuar o agregar un segundo jugador.');
      } else if (data.count >= 2) {
        showSelector(players);
        setStatus('Elegí quién está jugando para continuar.');
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
      const msg = /DEVICE_FULL/i.test(String(err.message)) ? 'Este celu ya tiene 2 jugadores cargados.' : err.message;
      setStatus(`No se pudo registrar: ${msg}`);
    } finally {
      startBtn.disabled = false;
    }
  });

  continueBtn.addEventListener('click', function () {
    goToGame();
  });

  changeNameBtn.addEventListener('click', function () {
    addSecondWrap.classList.add('hidden');
    renameWrap.classList.toggle('hidden');
  });

  addSecondBtn.addEventListener('click', function () {
    renameWrap.classList.add('hidden');
    addSecondWrap.classList.toggle('hidden');
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
      showKnownPlayer(data.player, true);
      renameWrap.classList.add('hidden');
      setStatus('Nombre actualizado.');
    } catch (err) {
      setStatus(`No se pudo actualizar: ${err.message}`);
    } finally {
      saveNameBtn.disabled = false;
    }
  });

  saveSecondBtn.addEventListener('click', async function () {
    const newName = String(secondNameInput.value || '').trim();
    if (!newName) {
      setStatus('Ingresá un nombre válido para el segundo jugador.');
      return;
    }

    saveSecondBtn.disabled = true;
    setStatus('Registrando segundo jugador...');

    try {
      await apiPost('player_register', {
        display_name: newName,
        device_id: getDeviceId(),
      });
      secondNameInput.value = '';
      await bootstrap();
      if (players.length >= 2) {
        showSelector(players);
        setStatus('Segundo jugador agregado. Elegí quién juega.');
      }
    } catch (err) {
      const msg = /DEVICE_FULL/i.test(String(err.message)) ? 'Este celu ya tiene 2 jugadores cargados.' : err.message;
      setStatus(`No se pudo agregar: ${msg}`);
    } finally {
      saveSecondBtn.disabled = false;
    }
  });

  bootstrap();
})();
