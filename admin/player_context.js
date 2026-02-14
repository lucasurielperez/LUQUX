(function (window) {
  const API = 'api.php';
  const STORAGE = {
    deviceId: 'device_id',
    playerId: 'player_id',
    playerToken: 'player_token',
    displayName: 'display_name',
    publicCode: 'public_code',
  };

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

  function getOrCreateDeviceId() {
    let deviceId = localStorage.getItem(STORAGE.deviceId);
    if (!deviceId) {
      deviceId = uuidV4();
      localStorage.setItem(STORAGE.deviceId, deviceId);
    }
    return deviceId;
  }

  function saveActivePlayer(player) {
    localStorage.setItem(STORAGE.playerId, String(player.id));
    localStorage.setItem(STORAGE.playerToken, String(player.player_token || ''));
    localStorage.setItem(STORAGE.displayName, String(player.display_name || ''));
    localStorage.setItem(STORAGE.publicCode, String(player.public_code || ''));
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

  function createSelectorModal(players) {
    return new Promise(function (resolve) {
      const overlay = document.createElement('div');
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.background = 'rgba(17, 24, 39, 0.96)';
      overlay.style.display = 'flex';
      overlay.style.alignItems = 'center';
      overlay.style.justifyContent = 'center';
      overlay.style.zIndex = '99999';
      overlay.innerHTML = '<div style="width:min(540px,92vw);background:#1f2937;border:1px solid #374151;border-radius:16px;padding:20px;color:#fff;"><h2 style="margin:0 0 16px;">¿Quién está jugando?</h2><div id="pcPlayerButtons" style="display:grid;gap:10px;"></div></div>';
      document.body.appendChild(overlay);

      const wrap = overlay.querySelector('#pcPlayerButtons');
      players.forEach(function (player) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.style.padding = '16px';
        btn.style.fontSize = '20px';
        btn.style.fontWeight = '700';
        btn.style.border = '0';
        btn.style.borderRadius = '12px';
        btn.style.background = '#22c55e';
        btn.style.color = '#052e16';
        btn.textContent = `${player.display_name} (${player.public_code})`;
        btn.addEventListener('click', function () {
          saveActivePlayer(player);
          overlay.remove();
          resolve(player);
        });
        wrap.appendChild(btn);
      });
    });
  }

  async function ensureActivePlayerForThisDevice() {
    const deviceId = getOrCreateDeviceId();
    const data = await apiGet('device_players', { device_id: deviceId });
    const players = Array.isArray(data.players) ? data.players : [];

    if (players.length === 0) {
      throw new Error('No hay jugadores registrados en este dispositivo.');
    }

    if (players.length === 1) {
      saveActivePlayer(players[0]);
      return players[0];
    }

    return createSelectorModal(players);
  }

  window.PlayerContext = {
    ensureActivePlayerForThisDevice,
    getOrCreateDeviceId,
  };
})(window);
