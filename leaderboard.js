(function () {
  const API_URL = 'api.php?action=public_leaderboard_top';
  const REFRESH_MS = 60000;

  const lbBody = document.getElementById('lbBody');
  const cycleTimeEl = document.getElementById('cycleTime');
  const updatingEl = document.getElementById('updating');
  const errorMsgEl = document.getElementById('errorMsg');
  const lastOkEl = document.getElementById('lastOk');
  const celebrationEl = document.getElementById('celebration');
  const celebrationTopEl = document.getElementById('celebrationTop');
  const celebrationNameEl = document.getElementById('celebrationName');
  const celebrationPointsEl = document.getElementById('celebrationPoints');
  const confettiLayer = document.getElementById('confettiLayer');

  let prevRanksByPlayerId = new Map();
  let refreshTimer = null;
  let celebrationTimer = null;

  const gameNames = {
    sumador: 'Sumador',
    virus: 'Virus',
  };

  function formatClock(date) {
    return date.toLocaleTimeString('es-AR', { hour12: false });
  }

  function formatPointsDelta(delta) {
    if (typeof delta !== 'number' || Number.isNaN(delta)) return '';
    return `${delta >= 0 ? '+' : ''}${delta}`;
  }

  function humanLastEvent(lastEvent) {
    if (!lastEvent) return '—';

    const gameCode = String(lastEvent.game_code || '').trim().toLowerCase();
    const gameName = gameNames[gameCode] || (gameCode ? gameCode.charAt(0).toUpperCase() + gameCode.slice(1) : 'Acción');
    const deltaText = formatPointsDelta(Number(lastEvent.points_delta));
    const note = String(lastEvent.note || '').trim();

    if (!deltaText && !note) {
      return gameName;
    }

    if (!note) {
      return `${gameName} ${deltaText}`.trim();
    }

    return `${gameName} ${deltaText}`.trim() + ` <span class="note">(${escapeHtml(note)})</span>`;
  }

  function escapeHtml(str) {
    return str
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function arrowForPlayer(playerId, position) {
    if (!prevRanksByPlayerId.size) return '🔵 =';

    const prevPos = prevRanksByPlayerId.get(playerId);
    if (typeof prevPos !== 'number') return '🔵 =';

    if (position < prevPos) return '🟢 ↑';
    if (position > prevPos) return '🔴 ↓';
    return '🔵 =';
  }

  function buildRows(rows) {
    lbBody.innerHTML = rows.map(function (row, idx) {
      const pos = idx + 1;
      const playerId = Number(row.player_id);
      const rankClass = pos === 1 ? 'rank-1' : (pos === 2 || pos === 3 ? 'rank-2' : 'rank-other');
      const arrow = arrowForPlayer(playerId, pos);
      const lastAction = humanLastEvent(row.last_event);

      return `
        <tr class="${rankClass}">
          <td class="col-arrow">${arrow}</td>
          <td class="col-pos">#${pos}</td>
          <td class="col-name">${escapeHtml(String(row.display_name || '—'))}</td>
          <td class="col-points">${Number(row.total_points || 0)}</td>
          <td class="col-last">${lastAction}</td>
        </tr>
      `;
    }).join('');
  }

  function updateSubtitle(serverGeneratedAt) {
    const now = serverGeneratedAt ? new Date(String(serverGeneratedAt).replace(' ', 'T')) : new Date();
    cycleTimeEl.textContent = formatClock(now);
  }

  function detectCelebration(rows) {
    if (!prevRanksByPlayerId.size || !rows.length) return null;

    let candidate = null;
    rows.slice(0, 15).forEach(function (row, idx) {
      const newPos = idx + 1;
      if (newPos > 3) return;

      const playerId = Number(row.player_id);
      const prevPos = prevRanksByPlayerId.get(playerId);
      if (typeof prevPos !== 'number') return;

      if (newPos < prevPos) {
        if (!candidate || newPos < candidate.newPos || prevPos > 3) {
          candidate = {
            display_name: String(row.display_name || 'Jugador'),
            total_points: Number(row.total_points || 0),
            newPos,
          };
        }
      }
    });

    return candidate;
  }

  function launchConfetti() {
    confettiLayer.innerHTML = '';
    const colors = ['#ffd266', '#86ffb5', '#7ab0ff', '#ff7fbf', '#ffeaa6'];

    for (let i = 0; i < 85; i += 1) {
      const piece = document.createElement('span');
      piece.className = 'confetti-piece';
      piece.style.left = `${Math.random() * 100}%`;
      piece.style.background = colors[i % colors.length];
      piece.style.animationDuration = `${2.1 + Math.random() * 1.3}s`;
      piece.style.animationDelay = `${Math.random() * 0.6}s`;
      confettiLayer.appendChild(piece);
    }
  }

  function showCelebration(candidate) {
    if (!candidate) return;

    celebrationTopEl.textContent = `🔥 SUBIÓ AL #${candidate.newPos}`;
    celebrationNameEl.textContent = candidate.display_name;
    celebrationPointsEl.textContent = `Puntaje: ${candidate.total_points}`;
    launchConfetti();

    celebrationEl.classList.add('show');
    clearTimeout(celebrationTimer);
    celebrationTimer = setTimeout(function () {
      celebrationEl.classList.remove('show');
    }, 3000);
  }

  function snapshotRanks(rows) {
    const next = new Map();
    rows.forEach(function (row, idx) {
      next.set(Number(row.player_id), idx + 1);
    });
    prevRanksByPlayerId = next;
  }

  async function refreshLeaderboard() {
    updatingEl.textContent = ' · Actualizando…';

    try {
      const res = await fetch(API_URL, { cache: 'no-store' });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `HTTP ${res.status}`);
      }

      const rows = Array.isArray(data.rows) ? data.rows.slice(0, 15) : [];
      const celebrationCandidate = detectCelebration(rows);

      buildRows(rows);
      snapshotRanks(rows);
      showCelebration(celebrationCandidate);

      const now = new Date();
      lastOkEl.textContent = `Última actualización: ${formatClock(now)}`;
      errorMsgEl.textContent = '';
      errorMsgEl.classList.remove('error');
      updateSubtitle(data.generated_at || null);
    } catch (err) {
      errorMsgEl.textContent = 'Sin conexión / Error actualizando';
      errorMsgEl.classList.add('error');
    } finally {
      updatingEl.textContent = '';
    }
  }

  function startAutoRefresh() {
    refreshLeaderboard();
    refreshTimer = setInterval(refreshLeaderboard, REFRESH_MS);
  }

  document.addEventListener('dblclick', function (ev) {
    ev.preventDefault();
  }, { passive: false });
  document.addEventListener('gesturestart', function (ev) {
    ev.preventDefault();
  }, { passive: false });
  document.addEventListener('touchmove', function (ev) {
    if (ev.scale && ev.scale !== 1) {
      ev.preventDefault();
    }
  }, { passive: false });

  window.addEventListener('beforeunload', function () {
    clearInterval(refreshTimer);
    clearTimeout(celebrationTimer);
  });

  startAutoRefresh();
})();
