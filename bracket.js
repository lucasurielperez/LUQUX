(function () {
  const PLAYERS_API = 'api.php?action=public_players_active';
  const STATE_KEY = 'bracket_state_v2';
  const TOURNAMENT_NAME_KEY = 'bracket_tournament_name_v1';
  const PLAYERS_PER_MATCH_KEY = 'bracket_players_per_match_v1';
  const SELECTED_PLAYERS_KEY = 'bracket_selected_players_v1';
  const ADJUST_POINTS_API = './api.php?action=adjust_points';

  const bracketRowEl = document.getElementById('bracketRow');
  const bracketScrollEl = document.getElementById('bracketScroll');
  const statusMsgEl = document.getElementById('statusMsg');
  const errorMsgEl = document.getElementById('errorMsg');
  const rankingPreviewEl = document.getElementById('rankingPreview');
  const rebuildBtn = document.getElementById('rebuildBtn');
  const applyBtn = document.getElementById('applyBtn');
  const tournamentNameInputEl = document.getElementById('tournamentNameInput');
  const tournamentTitleEl = document.getElementById('tournamentTitle');
  const playersPerMatchSelectEl = document.getElementById('playersPerMatchSelect');
  const playerPickerEl = document.getElementById('playerPicker');
  const playersToggleBtn = document.getElementById('playersToggleBtn');
  const playersPanel = document.getElementById('playersPanel');
  const reshuffleBtn = document.getElementById('reshuffleBtn');

  const celebrationEl = document.getElementById('celebration');
  const celebrationTopEl = document.getElementById('celebrationTop');
  const celebrationNameEl = document.getElementById('celebrationName');
  const confettiLayer = document.getElementById('confettiLayer');

  let state = null;
  let activePlayers = [];
  let selectedPlayerIds = [];
  let playersPerMatch = 2;
  let celebrationTimer = null;
  let tournamentName = '';

  function normalizeTournamentName(name) {
    return String(name || '').trim().replace(/\s+/g, ' ');
  }

  function getTournamentNameForDisplay() {
    return tournamentName || 'SIN NOMBRE';
  }

  function getTournamentLabel() {
    return `TORNEO ${getTournamentNameForDisplay()}`;
  }

  function clampPlayersPerMatch(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 2;
    return Math.max(2, Math.min(6, Math.floor(n)));
  }

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

  function loadTournamentName() {
    tournamentName = normalizeTournamentName(localStorage.getItem(TOURNAMENT_NAME_KEY) || '');
  }

  function saveTournamentName() {
    localStorage.setItem(TOURNAMENT_NAME_KEY, tournamentName);
  }

  function loadPlayersPerMatch() {
    playersPerMatch = clampPlayersPerMatch(localStorage.getItem(PLAYERS_PER_MATCH_KEY) || 2);
  }

  function savePlayersPerMatch() {
    localStorage.setItem(PLAYERS_PER_MATCH_KEY, String(playersPerMatch));
  }

  function syncTournamentNameUI() {
    if (tournamentNameInputEl) tournamentNameInputEl.value = tournamentName;
    if (tournamentTitleEl) tournamentTitleEl.textContent = `🏆 ${getTournamentLabel()}`;
  }

  function syncPlayersPerMatchUI() {
    if (playersPerMatchSelectEl) playersPerMatchSelectEl.value = String(playersPerMatch);
  }

  function saveSelectedPlayers() {
    localStorage.setItem(SELECTED_PLAYERS_KEY, JSON.stringify(selectedPlayerIds));
  }

  function loadSelectedPlayers() {
    try {
      const raw = localStorage.getItem(SELECTED_PLAYERS_KEY);
      const parsed = JSON.parse(raw || '[]');
      if (!Array.isArray(parsed)) return [];
      return parsed.map(function (id) { return Number(id); }).filter(Boolean);
    } catch (_) {
      return [];
    }
  }

  function hydrateSelectedPlayers() {
    const idsFromStorage = loadSelectedPlayers();
    const activeIds = activePlayers.map(function (p) { return Number(p.player_id); });
    const valid = idsFromStorage.filter(function (id) { return activeIds.includes(id); });
    selectedPlayerIds = valid.length ? valid : activeIds.slice();
    saveSelectedPlayers();
  }

  function renderPlayerPicker() {
    if (!playersPanel || !playersToggleBtn) return;
    const checkedCount = selectedPlayerIds.length;
    const totalCount = activePlayers.length;
    playersToggleBtn.textContent = `Jugadores (${checkedCount}/${totalCount})`;

    playersPanel.innerHTML = activePlayers.map(function (p) {
      const id = Number(p.player_id);
      const checked = selectedPlayerIds.includes(id) ? 'checked' : '';
      return `<label class="player-option"><input type="checkbox" data-player-check="${id}" ${checked} /> <span>${escapeHtml(p.display_name || 'Jugador')}</span></label>`;
    }).join('');
  }

  function getSelectedPlayersRows() {
    return activePlayers.filter(function (p) {
      return selectedPlayerIds.includes(Number(p.player_id));
    });
  }

  function normalizeMatch(match) {
    if (Array.isArray(match.participant_ids)) {
      match.participant_ids = match.participant_ids.map(Number).filter(Boolean);
    } else {
      match.participant_ids = [match.player1_id, match.player2_id].map(Number).filter(Boolean);
    }
    if (!match.id) match.id = `r${match.round_index}m${match.match_index}`;
    if (!match.winner_id || !match.participant_ids.includes(match.winner_id)) {
      match.winner_id = null;
    }
  }

  function generateRoundSizes(playerCount, perMatch) {
    const rounds = [];
    let currentPlayers = playerCount;
    while (currentPlayers > 1) {
      const matches = Math.ceil(currentPlayers / perMatch);
      rounds.push(matches);
      currentPlayers = matches;
    }
    return rounds;
  }

  function buildTournamentState(playersRows) {
    const players = shuffle(playersRows.slice()).map(function (p) {
      return {
        player_id: Number(p.player_id),
        display_name: String(p.display_name || 'Jugador'),
        total_points: Number(p.total_points || 0),
      };
    });

    const playersById = {};
    players.forEach(function (p) { playersById[p.player_id] = p; });

    const rounds = [];
    const roundSizes = generateRoundSizes(players.length, playersPerMatch);

    let idx = 0;
    const round1 = [];
    for (let m = 0; m < roundSizes[0]; m += 1) {
      const participantIds = [];
      for (let s = 0; s < playersPerMatch && idx < players.length; s += 1) {
        participantIds.push(players[idx].player_id);
        idx += 1;
      }
      round1.push({
        id: `r0m${m}`,
        round_index: 0,
        match_index: m,
        participant_ids: participantIds,
        winner_id: participantIds.length === 1 ? participantIds[0] : null,
      });
    }
    rounds.push(round1);

    for (let r = 1; r < roundSizes.length; r += 1) {
      const matches = [];
      for (let m = 0; m < roundSizes[r]; m += 1) {
        matches.push({
          id: `r${r}m${m}`,
          round_index: r,
          match_index: m,
          participant_ids: [],
          winner_id: null,
        });
      }
      rounds.push(matches);
    }

    const bracket = {
      version: 3,
      generated_at: new Date().toISOString(),
      players_per_match: playersPerMatch,
      selected_player_ids: selectedPlayerIds.slice(),
      players,
      players_by_id: playersById,
      rounds,
      thirdPlace: { player1_id: null, player2_id: null, winner_id: null },
      awards: [],
      applied_at: null,
    };

    syncRounds(bracket);
    return bracket;
  }

  function getPlayerFromState(currentState, id) {
    if (!id || !currentState || !currentState.players_by_id[id]) return null;
    return currentState.players_by_id[id];
  }

  function getFinalMatch(currentState) {
    const lastRound = currentState.rounds[currentState.rounds.length - 1];
    return lastRound && lastRound[0] ? lastRound[0] : null;
  }

  function getFinalWinnerId(currentState) {
    const fm = getFinalMatch(currentState);
    return fm ? fm.winner_id : null;
  }

  function syncRounds(currentState) {
    currentState.rounds.forEach(function (round) {
      round.forEach(normalizeMatch);
    });

    for (let r = 1; r < currentState.rounds.length; r += 1) {
      const winners = currentState.rounds[r - 1].map(function (m) { return m.winner_id; }).filter(Boolean);

      currentState.rounds[r].forEach(function (match, index) {
        const start = index * currentState.players_per_match;
        const nextParticipants = winners.slice(start, start + currentState.players_per_match);
        match.participant_ids = nextParticipants;
        if (!match.participant_ids.includes(match.winner_id)) {
          match.winner_id = match.participant_ids.length === 1 ? match.participant_ids[0] : null;
        }
      });
    }

    currentState.thirdPlace = { player1_id: null, player2_id: null, winner_id: null };
    currentState.awards = computeAwards(currentState);
  }

  function isTournamentClosed(currentState) {
    return !!getFinalWinnerId(currentState);
  }

  function getDeterministicPlayerSortValue(currentState, playerId) {
    const player = getPlayerFromState(currentState, playerId);
    return {
      total_points: player ? Number(player.total_points || 0) : 0,
      display_name: player ? String(player.display_name || 'Jugador') : 'Jugador',
    };
  }

  function computeFinalRanking(currentState) {
    if (!isTournamentClosed(currentState)) return [];

    const eliminationByPlayer = new Map();

    currentState.rounds.forEach(function (round, roundIndex) {
      round.forEach(function (m) {
        if (!m.winner_id || m.participant_ids.length < 2) return;
        m.participant_ids.forEach(function (participantId) {
          if (participantId === m.winner_id) return;
          const prev = eliminationByPlayer.get(participantId);
          if (typeof prev !== 'number' || roundIndex > prev) {
            eliminationByPlayer.set(participantId, roundIndex);
          }
        });
      });
    });

    const finalMatch = getFinalMatch(currentState);
    const championId = finalMatch ? finalMatch.winner_id : null;
    const finalists = finalMatch ? finalMatch.participant_ids.filter(function (id) { return id !== championId; }) : [];

    const positions = [];
    const used = new Set();

    function pushPosition(playerId, position) {
      if (!playerId || used.has(playerId)) return;
      used.add(playerId);
      const p = currentState.players_by_id[playerId];
      positions.push({
        player_id: playerId,
        position,
        display_name: p ? p.display_name : `Jugador ${playerId}`,
        total_points: p ? p.total_points : 0,
      });
    }

    pushPosition(championId, 1);

    finalists.sort(function (a, b) {
      const pa = getDeterministicPlayerSortValue(currentState, a);
      const pb = getDeterministicPlayerSortValue(currentState, b);
      if (pb.total_points !== pa.total_points) return pb.total_points - pa.total_points;
      return pa.display_name.localeCompare(pb.display_name, 'es');
    });

    finalists.forEach(function (id, idx) {
      pushPosition(id, idx + 2);
    });

    const grouped = [];
    Object.keys(currentState.players_by_id).forEach(function (idStr) {
      const id = Number(idStr);
      if (used.has(id)) return;
      grouped.push({
        player_id: id,
        round_eliminated: eliminationByPlayer.has(id) ? eliminationByPlayer.get(id) : -1,
        display_name: currentState.players_by_id[id].display_name,
        total_points: currentState.players_by_id[id].total_points,
      });
    });

    grouped.sort(function (a, b) {
      if (b.round_eliminated !== a.round_eliminated) return b.round_eliminated - a.round_eliminated;
      if (b.total_points !== a.total_points) return b.total_points - a.total_points;
      return a.display_name.localeCompare(b.display_name, 'es');
    });

    let nextPos = positions.length + 1;
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
    return computeFinalRanking(currentState).map(function (row) {
      return {
        player_id: row.player_id,
        position: row.position,
        points_delta: pointsDeltaByPosition(row.position),
        note: `${getTournamentLabel()}: puesto #${row.position}`,
      };
    }).filter(function (award) {
      return award.points_delta > 0;
    });
  }

  function formatPlayerName(id, fallback) {
    const p = getPlayerFromState(state, id);
    return p ? escapeHtml(p.display_name) : (fallback || '—');
  }

  function matchCard(match, opts) {
    const participants = match.participant_ids.map(function (id) {
      return getPlayerFromState(state, id);
    }).filter(Boolean);
    const canPick = participants.length >= 2;

    const participantMarkup = participants.length
      ? participants.map(function (p, index) {
        const separator = index < participants.length - 1 ? '<div class="vs">vs</div>' : '';
        return `<div class="p" title="${escapeHtml(p.display_name)}">${escapeHtml(p.display_name)}</div>${separator}`;
      }).join('')
      : '<div class="p">—</div>';

    return `
      <div class="match ${opts.compact ? 'compact' : ''}" style="margin-top:${opts.marginTopPx}px;">
        ${participantMarkup}
        <select class="winner-select" data-round="${match.round_index}" data-match="${match.match_index}" ${canPick ? '' : 'disabled'}>
          <option value="">Ganador…</option>
          ${participants.map(function (p) {
            return `<option value="${p.player_id}" ${match.winner_id === p.player_id ? 'selected' : ''}>${escapeHtml(p.display_name)}</option>`;
          }).join('')}
        </select>
      </div>
    `;
  }

  function renderPodium() {
    const ranking = computeFinalRanking(state);
    if (!ranking.length) return '';

    const first = ranking.find(function (r) { return r.position === 1; });
    const second = ranking.find(function (r) { return r.position === 2; });
    const third = ranking.find(function (r) { return r.position === 3; });

    const firstName = first ? formatPlayerName(first.player_id) : 'Pendiente';
    const secondName = second ? formatPlayerName(second.player_id) : 'Pendiente';
    const thirdName = third ? formatPlayerName(third.player_id) : 'Pendiente';

    return `
      <div class="podium-wrap">
        <div class="podium-card p2" title="${escapeHtml(secondName)}"><span>2°</span><strong>${secondName}</strong><em>+300</em></div>
        <div class="podium-card p1" title="${escapeHtml(firstName)}"><span>1°</span><strong>${firstName}</strong><em>+500</em></div>
        <div class="podium-card p3" title="${escapeHtml(String(thirdName))}"><span>3°</span><strong>${thirdName}</strong><em>+100</em></div>
      </div>
    `;
  }

  function updateApplyButton() {
    if (!isTournamentClosed(state)) {
      applyBtn.hidden = true;
      applyBtn.disabled = true;
      return;
    }

    applyBtn.hidden = false;
    if (state.applied_at) {
      applyBtn.disabled = true;
      applyBtn.textContent = 'Ya aplicado';
      return;
    }

    applyBtn.disabled = false;
    applyBtn.textContent = 'Aplicar puntos';
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

  function renderBracket() {
    const baseMatchHeight = state.players.length > 24 ? 90 : 102;
    const baseGap = state.players.length > 24 ? 9 : 12;
    const maxMatches = state.rounds.length ? state.rounds[0].length : 1;

    bracketRowEl.innerHTML = state.rounds.map(function (round, roundIndex) {
      const isFinalColumn = roundIndex === state.rounds.length - 1;
      const title = isFinalColumn ? 'FINAL' : `Round ${roundIndex + 1}`;
      const roundSpacing = Math.min(48, baseGap * Math.pow(2, Math.min(roundIndex, 3)));
      const offsetUnits = Math.max(0, (maxMatches - round.length) / 2);
      const topOffsetPx = Math.floor(offsetUnits * (baseMatchHeight + baseGap));

      const matchMarkup = round.map(function (m, matchIndex) {
        const marginTopPx = matchIndex === 0 ? topOffsetPx : roundSpacing;
        return matchCard(m, {
          compact: state.players.length > 24,
          marginTopPx,
        });
      }).join('');

      const finalExtrasMarkup = isFinalColumn
        ? `<div class="final-trophy"><img class="trophy" alt="Trofeo" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 256 256'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' x2='1' y1='0' y2='1'%3E%3Cstop offset='0' stop-color='%23ffe08c'/%3E%3Cstop offset='1' stop-color='%23d6931c'/%3E%3C/linearGradient%3E%3C/defs%3E%3Cpath fill='url(%23g)' d='M78 26h100v26c0 27-12 48-33 63v27h34v26H77v-26h34v-27C90 100 78 79 78 52V26Zm-37 18h29v20c0 21-11 40-29 49V86c9-6 14-14 14-22V44Zm144 0h30v20c0 8 5 16 14 22v27c-18-9-30-28-30-49V44ZM74 188h108v42H74v-42Z'/%3E%3C/svg%3E" /></div>${renderPodium()}`
        : '';

      return `<section class="round-column" data-round-column="${roundIndex}"><h3>${title}</h3><div class="matches">${matchMarkup}</div>${finalExtrasMarkup}</section>`;
    }).join('');

    statusMsgEl.textContent = isTournamentClosed(state)
      ? 'Torneo cerrado. Listo para aplicar puntos reales.'
      : 'Definí ganador en cada match para avanzar rondas.';

    if (state.applied_at) {
      errorMsgEl.textContent = `Puntos ya aplicados el ${new Date(state.applied_at).toLocaleString('es-AR')}.`;
    }

    renderRankingPreview();
    updateApplyButton();

    const finalMatch = getFinalMatch(state);
    if (finalMatch && finalMatch.winner_id) {
      bracketScrollEl.scrollTo({ left: bracketScrollEl.scrollWidth, behavior: 'smooth' });
    }
  }

  function saveState() {
    localStorage.setItem(STATE_KEY, JSON.stringify(state));
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

  function handleWinnerChange(roundIndex, matchIndex, winnerId) {
    const match = state.rounds[roundIndex][matchIndex];
    const prevChampion = getFinalWinnerId(state);

    if (winnerId && !match.participant_ids.includes(winnerId)) {
      errorMsgEl.textContent = 'Ganador inválido: no pertenece al match.';
      return;
    }

    match.winner_id = winnerId || null;
    syncRounds(state);
    saveState();
    renderBracket();

    if (state.applied_at) {
      errorMsgEl.textContent = 'Ojo: ya aplicaste puntos, cambiar resultados no revierte puntos.';
    }

    const currentChampion = getFinalWinnerId(state);
    if (currentChampion && currentChampion !== prevChampion) {
      const champ = getPlayerFromState(state, currentChampion);
      if (champ) showCelebration('🏆 CAMPEÓN', champ.display_name, 3000, 85);
    }
  }

  async function applyAwardsToBackend() {
    if (!isTournamentClosed(state)) {
      errorMsgEl.textContent = 'El torneo no está cerrado: faltan resultados para aplicar puntos.';
      return;
    }
    const token = localStorage.getItem('admin_token') || '';
    if (!token) {
      errorMsgEl.textContent = 'Falta admin_token (abrí admin.html y guardalo)';
      return;
    }
    if (state.applied_at) {
      errorMsgEl.textContent = 'Ya aplicaste puntos en este torneo.';
      return;
    }
    if (!window.confirm('Esto suma puntos reales al ranking. ¿Aplicar?')) return;

    const awards = state.awards.slice().filter(function (award) { return award.points_delta > 0; });
    if (!awards.length) {
      errorMsgEl.textContent = 'No hay premios positivos para aplicar.';
      return;
    }

    applyBtn.disabled = true;
    errorMsgEl.textContent = '';

    const errors = [];
    let okCount = 0;

    for (let i = 0; i < awards.length; i += 1) {
      const award = awards[i];
      statusMsgEl.textContent = `${getTournamentLabel()} · Aplicando puntos… ${i + 1}/${awards.length}`;
      try {
        const res = await fetch(ADJUST_POINTS_API, {
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
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.ok) {
          errors.push({ error: data.error || `HTTP ${res.status}`, request_id: data.request_id || 'n/a' });
        } else {
          okCount += 1;
        }
      } catch (err) {
        errors.push({ error: err && err.message ? err.message : 'Error de red', request_id: 'n/a' });
      }
    }

    const errorPreview = errors.slice(0, 5).map(function (entry, idx) {
      return `${idx + 1}) ${entry.error} (request_id: ${entry.request_id})`;
    }).join(' | ');

    errorMsgEl.textContent = `Aplicación completada. OK: ${okCount} / ERROR: ${errors.length}${errorPreview ? ` · ${errorPreview}` : ''}`;

    if (errors.length === 0) {
      state.applied_at = new Date().toISOString();
      saveState();
      updateApplyButton();
      showCelebration('💰 Puntos aplicados', '¡Éxito!', 2000, 40);
      statusMsgEl.textContent = `${getTournamentLabel()} · Puntos reales aplicados correctamente.`;
    } else {
      updateApplyButton();
      statusMsgEl.textContent = `${getTournamentLabel()} · Aplicación con errores. Revisá detalles.`;
    }
  }

  async function fetchActivePlayers() {
    const res = await fetch(PLAYERS_API, { cache: 'no-store' });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || !data.ok) throw new Error(data.error || `HTTP ${res.status}`);
    return Array.isArray(data.rows) ? data.rows : [];
  }

  function loadSavedState() {
    try {
      const raw = localStorage.getItem(STATE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!parsed || !Array.isArray(parsed.rounds) || !parsed.players_by_id) return null;
      parsed.players_per_match = clampPlayersPerMatch(parsed.players_per_match || 2);
      parsed.rounds.forEach(function (round) { round.forEach(normalizeMatch); });
      return parsed;
    } catch (_) {
      return null;
    }
  }

  async function initializeTournament(forceNew) {
    errorMsgEl.textContent = '';
    statusMsgEl.textContent = 'Cargando jugadores activos…';

    activePlayers = await fetchActivePlayers();
    hydrateSelectedPlayers();
    renderPlayerPicker();

    const selectedRows = getSelectedPlayersRows();
    if (selectedRows.length < 2) {
      throw new Error('Se requieren al menos 2 jugadores seleccionados para armar la llave.');
    }

    if (!forceNew) {
      const saved = loadSavedState();
      if (saved && saved.players_per_match === playersPerMatch) {
        const savedSelected = Array.isArray(saved.selected_player_ids) ? saved.selected_player_ids.map(Number).sort().join(',') : '';
        const currentSelected = selectedPlayerIds.slice().sort().join(',');
        if (savedSelected === currentSelected) {
          state = saved;
          syncRounds(state);
          renderBracket();
          return;
        }
      }
    }

    state = buildTournamentState(selectedRows);
    saveState();
    renderBracket();
  }

  function bindEvents() {
    document.body.addEventListener('change', function (ev) {
      const t = ev.target;
      if (!(t instanceof HTMLElement)) return;

      if (t instanceof HTMLInputElement && t.dataset.playerCheck) {
        const id = Number(t.dataset.playerCheck);
        if (t.checked) {
          if (!selectedPlayerIds.includes(id)) selectedPlayerIds.push(id);
        } else {
          selectedPlayerIds = selectedPlayerIds.filter(function (x) { return x !== id; });
        }

        if (selectedPlayerIds.length < 2) {
          t.checked = true;
          if (!selectedPlayerIds.includes(id)) selectedPlayerIds.push(id);
          errorMsgEl.textContent = 'Debe haber al menos 2 jugadores seleccionados.';
          return;
        }

        saveSelectedPlayers();
        initializeTournament(true).catch(function (err) {
          errorMsgEl.textContent = `Error: ${err.message || 'No se pudo cargar la llave.'}`;
        });
        return;
      }

      if (!(t instanceof HTMLSelectElement)) return;
      if (!t.dataset.round || !t.dataset.match) return;

      const roundIndex = Number(t.dataset.round);
      const matchIndex = Number(t.dataset.match);
      const winnerId = t.value ? Number(t.value) : null;
      handleWinnerChange(roundIndex, matchIndex, winnerId);
    });

    document.addEventListener('click', function (ev) {
      const target = ev.target;
      if (!(target instanceof Node)) return;
      if (playerPickerEl && !playerPickerEl.contains(target)) {
        playerPickerEl.classList.remove('open');
      }
    });

    playersToggleBtn.addEventListener('click', function () {
      playerPickerEl.classList.toggle('open');
    });

    reshuffleBtn.addEventListener('click', function () {
      initializeTournament(true).catch(function (err) {
        errorMsgEl.textContent = `Error: ${err.message || 'No se pudo cargar la llave.'}`;
      });
    });

    playersPerMatchSelectEl.addEventListener('change', function () {
      playersPerMatch = clampPlayersPerMatch(playersPerMatchSelectEl.value);
      savePlayersPerMatch();
      initializeTournament(true).catch(function (err) {
        errorMsgEl.textContent = `Error: ${err.message || 'No se pudo cargar la llave.'}`;
      });
    });

    rebuildBtn.addEventListener('click', function () {
      if (!window.confirm('¿Rearmar torneo desde cero?')) return;
      initializeTournament(true).catch(function (err) {
        errorMsgEl.textContent = `Error: ${err.message || 'No se pudo cargar la llave.'}`;
      });
    });

    applyBtn.addEventListener('click', function () {
      applyAwardsToBackend();
    });

    tournamentNameInputEl.addEventListener('input', function () {
      tournamentName = normalizeTournamentName(tournamentNameInputEl.value);
      saveTournamentName();
      syncTournamentNameUI();
      if (state) {
        state.awards = computeAwards(state);
        saveState();
        renderRankingPreview();
        updateApplyButton();
      }
    });
  }

  loadTournamentName();
  loadPlayersPerMatch();
  syncTournamentNameUI();
  syncPlayersPerMatchUI();
  bindEvents();

  initializeTournament(false).catch(function (err) {
    errorMsgEl.textContent = `Error: ${err.message || 'No se pudo cargar la llave.'}`;
  });
})();
