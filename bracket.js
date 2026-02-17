(function () {
  const PLAYERS_API = 'api.php?action=public_players_active';
  const STATE_KEY = 'bracket_state_v1';

  const leftSideEl = document.getElementById('leftSide');
  const rightSideEl = document.getElementById('rightSide');
  const statusMsgEl = document.getElementById('statusMsg');
  const errorMsgEl = document.getElementById('errorMsg');
  const rankingPreviewEl = document.getElementById('rankingPreview');
  const rebuildBtn = document.getElementById('rebuildBtn');
  const applyBtn = document.getElementById('applyBtn');

  const celebrationEl = document.getElementById('celebration');
  const celebrationTopEl = document.getElementById('celebrationTop');
  const celebrationNameEl = document.getElementById('celebrationName');
  const confettiLayer = document.getElementById('confettiLayer');

  let state = null;
  let celebrationTimer = null;

  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function shuffle(players) {
    for (let i = players.length - 1; i > 0; i -= 1) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = players[i];
      players[i] = players[j];
      players[j] = tmp;
    }
    return players;
  }

  function chunkPairs(players) {
    const pairs = [];
    for (let i = 0; i < players.length; i += 2) {
      pairs.push([players[i], players[i + 1]]);
    }
    return pairs;
  }

  function buildTournamentState(playersRows) {
    const players = shuffle(playersRows.slice()).map(function (p) {
      return {
        player_id: Number(p.player_id),
        display_name: String(p.display_name || 'Jugador'),
        total_points: Number(p.total_points || 0),
      };
    });

    const playerMap = {};
    players.forEach(function (p) {
      playerMap[p.player_id] = p;
    });

    let duplicatedPlayerId = null;
    let round1Pairs = [];

    if (players.length % 2 === 0) {
      round1Pairs = chunkPairs(players);
    } else {
      const oddPlayer = players[players.length - 1];
      const basePlayers = players.slice(0, -1);
      round1Pairs = chunkPairs(basePlayers);

      const leftMatchCount = Math.ceil(round1Pairs.length / 2);
      const leftPlayers = [];
      for (let i = 0; i < leftMatchCount; i += 1) {
        leftPlayers.push(round1Pairs[i][0], round1Pairs[i][1]);
      }

      const validCandidates = leftPlayers.filter(function (p) {
        return p && p.player_id !== oddPlayer.player_id;
      });
      if (!validCandidates.length) {
        throw new Error('No se pudo construir llave impar con duplicado válido.');
      }

      const duplicateCandidate = validCandidates[Math.floor(Math.random() * validCandidates.length)];
      duplicatedPlayerId = duplicateCandidate.player_id;
      round1Pairs.push([oddPlayer, duplicateCandidate]);
    }

    const rounds = [];
    const round1Matches = round1Pairs.map(function (pair, idx) {
      return {
        id: `r0m${idx}`,
        round_index: 0,
        match_index: idx,
        player1_id: pair[0] ? pair[0].player_id : null,
        player2_id: pair[1] ? pair[1].player_id : null,
        winner_id: null,
      };
    });
    rounds.push(round1Matches);

    let prevCount = round1Matches.length;
    let roundIndex = 1;
    while (prevCount > 1) {
      const nextCount = Math.ceil(prevCount / 2);
      const matches = [];
      for (let i = 0; i < nextCount; i += 1) {
        matches.push({
          id: `r${roundIndex}m${i}`,
          round_index: roundIndex,
          match_index: i,
          player1_id: null,
          player2_id: null,
          winner_id: null,
        });
      }
      rounds.push(matches);
      prevCount = nextCount;
      roundIndex += 1;
    }

    const bracket = {
      version: 1,
      generated_at: new Date().toISOString(),
      players,
      players_by_id: playerMap,
      duplicated_player_id: duplicatedPlayerId,
      rounds,
      third_place_match: null,
      awards: [],
      bracket_applied_at: null,
    };

    syncRounds(bracket);
    return bracket;
  }

  function syncRounds(currentState) {
    for (let r = 1; r < currentState.rounds.length; r += 1) {
      currentState.rounds[r].forEach(function (m) {
        m.player1_id = null;
        m.player2_id = null;
      });

      currentState.rounds[r - 1].forEach(function (prevMatch, idx) {
        const nextMatch = currentState.rounds[r][Math.floor(idx / 2)];
        if (!nextMatch || !prevMatch.winner_id) return;

        if (idx % 2 === 0) {
          nextMatch.player1_id = prevMatch.winner_id;
        } else {
          nextMatch.player2_id = prevMatch.winner_id;
        }
      });

      currentState.rounds[r].forEach(function (match) {
        if (match.winner_id !== match.player1_id && match.winner_id !== match.player2_id) {
          match.winner_id = null;
        }
      });
    }

    buildThirdPlaceMatch(currentState);
    currentState.awards = computeAwards(currentState);
  }

  function buildThirdPlaceMatch(currentState) {
    const semiRoundIndex = currentState.rounds.length - 2;
    if (semiRoundIndex < 0) {
      currentState.third_place_match = null;
      return;
    }

    const semis = currentState.rounds[semiRoundIndex];
    if (!Array.isArray(semis) || semis.length < 2) {
      currentState.third_place_match = null;
      return;
    }

    const losers = semis.slice(0, 2).map(function (m) {
      if (!m.winner_id || !m.player1_id || !m.player2_id) return null;
      return m.winner_id === m.player1_id ? m.player2_id : m.player1_id;
    });

    if (losers.some(function (v) { return !v; })) {
      currentState.third_place_match = {
        id: 'third_place',
        player1_id: losers[0] || null,
        player2_id: losers[1] || null,
        winner_id: null,
      };
      return;
    }

    const prevWinner = currentState.third_place_match ? currentState.third_place_match.winner_id : null;
    currentState.third_place_match = {
      id: 'third_place',
      player1_id: losers[0],
      player2_id: losers[1],
      winner_id: (prevWinner === losers[0] || prevWinner === losers[1]) ? prevWinner : null,
    };
  }

  function getPlayer(id) {
    if (!id || !state || !state.players_by_id[id]) return null;
    return state.players_by_id[id];
  }

  function matchCard(match, opts) {
    const p1 = getPlayer(match.player1_id);
    const p2 = getPlayer(match.player2_id);
    const canPick = Boolean(p1 && p2);

    const duplicateBadgeP1 = state.duplicated_player_id === match.player1_id ? '<span class="dup-badge">⚠️ juega 2 veces</span>' : '';
    const duplicateBadgeP2 = state.duplicated_player_id === match.player2_id ? '<span class="dup-badge">⚠️ juega 2 veces</span>' : '';

    const editDisabled = opts.disableWinnerEdit ? 'disabled' : '';

    return `
      <div class="match">
        <div class="p">${p1 ? escapeHtml(p1.display_name) : '—'} ${duplicateBadgeP1}</div>
        <div class="vs">vs</div>
        <div class="p">${p2 ? escapeHtml(p2.display_name) : '—'} ${duplicateBadgeP2}</div>
        <select class="winner-select" data-round="${match.round_index}" data-match="${match.match_index}" ${(!canPick || editDisabled) ? 'disabled' : ''}>
          <option value="">Ganador…</option>
          ${p1 ? `<option value="${p1.player_id}" ${match.winner_id === p1.player_id ? 'selected' : ''}>${escapeHtml(p1.display_name)}</option>` : ''}
          ${p2 ? `<option value="${p2.player_id}" ${match.winner_id === p2.player_id ? 'selected' : ''}>${escapeHtml(p2.display_name)}</option>` : ''}
        </select>
      </div>
    `;
  }

  function renderSide(roundIndexes, container, disableEdit) {
    container.innerHTML = roundIndexes.map(function (roundIndex) {
      const round = state.rounds[roundIndex] || [];
      const title = `Round ${roundIndex + 1}`;
      return `
        <section class="round">
          <h3>${title}</h3>
          <div class="matches">
            ${round.map(function (m) { return matchCard(m, { disableWinnerEdit: disableEdit && roundIndex > 0 }); }).join('')}
          </div>
        </section>
      `;
    }).join('');
  }

  function getFinalMatch(currentState) {
    if (!currentState.rounds.length) return null;
    const finalRound = currentState.rounds[currentState.rounds.length - 1];
    return finalRound && finalRound[0] ? finalRound[0] : null;
  }

  function isTournamentClosed(currentState) {
    const finalMatch = getFinalMatch(currentState);
    if (!finalMatch || !finalMatch.winner_id) return false;

    if (!currentState.third_place_match) {
      return false;
    }

    return Boolean(currentState.third_place_match.winner_id);
  }

  function computeFinalRanking(currentState) {
    if (!isTournamentClosed(currentState)) {
      return [];
    }

    const eliminationByPlayer = new Map();

    currentState.rounds.forEach(function (round, roundIndex) {
      round.forEach(function (m) {
        if (!m.player1_id || !m.player2_id || !m.winner_id) return;
        const loserId = (m.winner_id === m.player1_id) ? m.player2_id : m.player1_id;
        const prev = eliminationByPlayer.get(loserId);
        if (typeof prev !== 'number' || roundIndex > prev) {
          eliminationByPlayer.set(loserId, roundIndex);
        }
      });
    });

    const finalMatch = getFinalMatch(currentState);
    const championId = finalMatch.winner_id;
    const runnerUpId = finalMatch.winner_id === finalMatch.player1_id ? finalMatch.player2_id : finalMatch.player1_id;

    const tp = currentState.third_place_match;
    const thirdId = tp.winner_id;
    const fourthId = tp.winner_id === tp.player1_id ? tp.player2_id : tp.player1_id;

    const positions = [];
    const used = new Set();

    function pushPosition(playerId, pos) {
      if (!playerId || used.has(playerId)) return;
      used.add(playerId);
      positions.push({
        player_id: playerId,
        position: pos,
        display_name: currentState.players_by_id[playerId] ? currentState.players_by_id[playerId].display_name : 'Jugador',
        total_points: currentState.players_by_id[playerId] ? currentState.players_by_id[playerId].total_points : 0,
      });
    }

    pushPosition(championId, 1);
    pushPosition(runnerUpId, 2);
    pushPosition(thirdId, 3);
    pushPosition(fourthId, 4);

    const grouped = [];
    Object.keys(currentState.players_by_id).forEach(function (idStr) {
      const id = Number(idStr);
      if (used.has(id)) return;
      const reached = eliminationByPlayer.has(id) ? eliminationByPlayer.get(id) : -1;
      grouped.push({
        player_id: id,
        round_eliminated: reached,
        display_name: currentState.players_by_id[id].display_name,
        total_points: currentState.players_by_id[id].total_points,
      });
    });

    grouped.sort(function (a, b) {
      if (b.round_eliminated !== a.round_eliminated) return b.round_eliminated - a.round_eliminated;
      if (b.total_points !== a.total_points) return b.total_points - a.total_points;
      return a.display_name.localeCompare(b.display_name, 'es');
    });

    let nextPos = 5;
    grouped.forEach(function (g) {
      positions.push({
        player_id: g.player_id,
        position: nextPos,
        display_name: g.display_name,
        total_points: g.total_points,
      });
      nextPos += 1;
    });

    return positions;
  }

  function pointsDeltaByPosition(position) {
    if (position === 1) return 500;
    if (position === 2) return 300;
    if (position === 3) return 100;
    return Math.max(0, 50 - position);
  }

  function computeAwards(currentState) {
    const ranking = computeFinalRanking(currentState);
    if (!ranking.length) return [];

    return ranking.map(function (row) {
      return {
        player_id: row.player_id,
        position: row.position,
        points_delta: pointsDeltaByPosition(row.position),
        note: `Torneo bracket: puesto #${row.position}`,
      };
    });
  }

  function launchConfetti(count) {
    confettiLayer.innerHTML = '';
    const colors = ['#ffd266', '#86ffb5', '#7ab0ff', '#ff7fbf', '#ffeaa6'];
    for (let i = 0; i < count; i += 1) {
      const piece = document.createElement('span');
      piece.className = 'confetti-piece';
      piece.style.left = `${Math.random() * 100}%`;
      piece.style.background = colors[i % colors.length];
      piece.style.animationDuration = `${2.1 + Math.random() * 1.3}s`;
      piece.style.animationDelay = `${Math.random() * 0.6}s`;
      confettiLayer.appendChild(piece);
    }
  }

  function showCelebration(topText, nameText, durationMs, confettiCount) {
    celebrationTopEl.textContent = topText;
    celebrationNameEl.textContent = nameText;
    launchConfetti(confettiCount);
    celebrationEl.classList.add('show');
    clearTimeout(celebrationTimer);
    celebrationTimer = setTimeout(function () {
      celebrationEl.classList.remove('show');
    }, durationMs);
  }

  function saveState() {
    localStorage.setItem(STATE_KEY, JSON.stringify(state));
  }

  function renderRankingPreview() {
    if (!state.awards.length) {
      rankingPreviewEl.textContent = '';
      return;
    }

    const top4 = computeFinalRanking(state).slice(0, 4).map(function (r) {
      return `#${r.position} ${r.display_name}`;
    }).join(' · ');
    rankingPreviewEl.textContent = `Ranking final: ${top4}`;
  }

  function updateApplyButton() {
    if (!isTournamentClosed(state)) {
      applyBtn.hidden = true;
      return;
    }

    applyBtn.hidden = false;
    if (state.bracket_applied_at) {
      applyBtn.disabled = true;
      applyBtn.textContent = 'Puntos ya aplicados';
      return;
    }

    applyBtn.disabled = false;
    applyBtn.textContent = 'Aplicar puntos';
  }

  function renderBracket() {
    const rounds = state.rounds.length;
    const leftIndexes = [];
    const rightIndexes = [];

    for (let i = 0; i < rounds; i += 1) {
      if (i % 2 === 0) {
        leftIndexes.push(i);
      } else {
        rightIndexes.push(i);
      }
    }

    renderSide(leftIndexes, leftSideEl, false);
    renderSide(rightIndexes, rightSideEl, false);

    if (state.third_place_match) {
      const p1 = getPlayer(state.third_place_match.player1_id);
      const p2 = getPlayer(state.third_place_match.player2_id);
      statusMsgEl.innerHTML = `
        3er puesto: ${p1 ? escapeHtml(p1.display_name) : '—'} vs ${p2 ? escapeHtml(p2.display_name) : '—'}
        <select id="thirdWinner" class="winner-select" ${(p1 && p2) ? '' : 'disabled'}>
          <option value="">Ganador 3er puesto…</option>
          ${p1 ? `<option value="${p1.player_id}" ${state.third_place_match.winner_id === p1.player_id ? 'selected' : ''}>${escapeHtml(p1.display_name)}</option>` : ''}
          ${p2 ? `<option value="${p2.player_id}" ${state.third_place_match.winner_id === p2.player_id ? 'selected' : ''}>${escapeHtml(p2.display_name)}</option>` : ''}
        </select>`;
    } else {
      statusMsgEl.textContent = 'Definí ganadores para avanzar en la llave.';
    }

    renderRankingPreview();
    updateApplyButton();
  }

  function handleWinnerChange(roundIndex, matchIndex, winnerId) {
    const match = state.rounds[roundIndex][matchIndex];
    const finalMatch = getFinalMatch(state);
    const prevChampion = finalMatch ? finalMatch.winner_id : null;

    match.winner_id = winnerId || null;
    syncRounds(state);
    saveState();
    renderBracket();

    const currentFinal = getFinalMatch(state);
    if (currentFinal && currentFinal.winner_id && currentFinal.winner_id !== prevChampion) {
      const champ = getPlayer(currentFinal.winner_id);
      if (champ) {
        showCelebration('🏆 CAMPEÓN', champ.display_name, 3000, 85);
      }
    }
  }

  async function applyAwardsToBackend() {
    if (!isTournamentClosed(state)) return;
    const token = localStorage.getItem('admin_token') || '';
    if (!token) {
      errorMsgEl.textContent = 'Falta admin_token (abrí admin.html y guardalo)';
      return;
    }
    if (state.bracket_applied_at) return;

    const awards = state.awards.slice();
    const promises = awards.map(function (award) {
      return fetch('admin/api.php?action=adjust_points', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`,
        },
        body: JSON.stringify({
          player_id: award.player_id,
          points_delta: award.points_delta,
          note: award.note,
        }),
      }).then(async function (res) {
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.ok) {
          throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
      });
    });

    const settled = await Promise.allSettled(promises);
    const errors = settled.filter(function (x) { return x.status === 'rejected'; });
    const okCount = settled.length - errors.length;
    const previewErrors = errors.slice(0, 5).map(function (e, idx) {
      const reason = e.reason && e.reason.message ? e.reason.message : 'Error desconocido';
      return `${idx + 1}) ${reason}`;
    }).join(' | ');

    errorMsgEl.textContent = `Aplicación completada. OK: ${okCount} · ERROR: ${errors.length}${previewErrors ? ` · ${previewErrors}` : ''}`;

    if (errors.length === 0) {
      state.bracket_applied_at = new Date().toISOString();
      saveState();
      updateApplyButton();
      showCelebration('💰 Puntos aplicados', '¡Éxito!', 2000, 40);
    }
  }

  function bindEvents() {
    document.body.addEventListener('change', function (ev) {
      const t = ev.target;
      if (!(t instanceof HTMLSelectElement)) return;

      if (t.id === 'thirdWinner') {
        if (!state.third_place_match) return;
        state.third_place_match.winner_id = t.value ? Number(t.value) : null;
        state.awards = computeAwards(state);
        saveState();
        renderBracket();
        return;
      }

      if (!t.dataset.round || !t.dataset.match) return;
      const roundIndex = Number(t.dataset.round);
      const matchIndex = Number(t.dataset.match);
      const winnerId = t.value ? Number(t.value) : null;
      handleWinnerChange(roundIndex, matchIndex, winnerId);
    });

    rebuildBtn.addEventListener('click', async function () {
      if (!window.confirm('¿Rearmar torneo desde cero?')) return;
      await initializeTournament(true);
    });

    applyBtn.addEventListener('click', function () {
      applyAwardsToBackend();
    });
  }

  async function fetchActivePlayers() {
    const res = await fetch(PLAYERS_API, { cache: 'no-store' });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) {
      throw new Error(data.error || `HTTP ${res.status}`);
    }
    return Array.isArray(data.rows) ? data.rows : [];
  }

  function loadSavedState() {
    try {
      const raw = localStorage.getItem(STATE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!parsed || !Array.isArray(parsed.rounds) || !parsed.players_by_id) return null;
      return parsed;
    } catch (_) {
      return null;
    }
  }

  async function initializeTournament(forceNew) {
    errorMsgEl.textContent = '';
    statusMsgEl.textContent = 'Cargando jugadores activos…';

    if (!forceNew) {
      const saved = loadSavedState();
      if (saved) {
        state = saved;
        syncRounds(state);
        renderBracket();
        return;
      }
    }

    const rows = await fetchActivePlayers();
    if (rows.length < 2) {
      throw new Error('Se requieren al menos 2 jugadores activos para armar la llave.');
    }

    state = buildTournamentState(rows);
    saveState();
    renderBracket();
  }

  bindEvents();
  initializeTournament(false).catch(function (err) {
    errorMsgEl.textContent = `Error: ${err.message || 'No se pudo cargar la llave.'}`;
  });
})();
