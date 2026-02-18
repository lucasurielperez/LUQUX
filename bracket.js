(function () {
  const PLAYERS_API = 'api.php?action=public_players_active';
  const STATE_KEY = 'bracket_state_v1';
  const ADJUST_POINTS_API = './api.php?action=adjust_points';

  const bracketRowEl = document.getElementById('bracketRow');
  const bracketScrollEl = document.getElementById('bracketScroll');
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

  function pickDuplicateCandidate(candidates, excludedPlayerId) {
    const validCandidates = candidates.filter(function (p) {
      return p && p.player_id !== excludedPlayerId;
    });
    if (!validCandidates.length) return null;
    return validCandidates[Math.floor(Math.random() * validCandidates.length)];
  }

  function generateRoundSizes(playerCount) {
    const rounds = [];
    let currentPlayers = playerCount;

    while (currentPlayers > 1) {
      const matches = Math.ceil(currentPlayers / 2);
      rounds.push(matches);
      currentPlayers = matches;
    }

    return rounds;
  }

  function createEmptyThirdPlace() {
    return {
      player1_id: null,
      player2_id: null,
      winner_id: null,
    };
  }

  function ensureStateShape(currentState) {
    if (!currentState.thirdPlace) {
      if (currentState.third_place_match) {
        currentState.thirdPlace = {
          player1_id: currentState.third_place_match.player1_id || null,
          player2_id: currentState.third_place_match.player2_id || null,
          winner_id: currentState.third_place_match.winner_id || null,
        };
      } else {
        currentState.thirdPlace = createEmptyThirdPlace();
      }
    }
    if (!('applied_at' in currentState)) {
      currentState.applied_at = currentState.bracket_applied_at || null;
    }
    currentState.third_place_match = undefined;
    currentState.bracket_applied_at = undefined;
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

      const duplicateCandidate = pickDuplicateCandidate(leftPlayers, oddPlayer.player_id);
      if (!duplicateCandidate) {
        throw new Error('No se pudo construir llave impar con duplicado válido.');
      }
      duplicatedPlayerId = duplicateCandidate.player_id;
      round1Pairs.push([oddPlayer, duplicateCandidate]);
    }

    const roundSizes = generateRoundSizes(players.length);
    const rounds = [];
    const duplicatedByRound = {
      0: duplicatedPlayerId ? [duplicatedPlayerId] : [],
    };

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

    for (let roundIndex = 1; roundIndex < roundSizes.length; roundIndex += 1) {
      const nextCount = roundSizes[roundIndex];
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
    }

    const bracket = {
      version: 2,
      generated_at: new Date().toISOString(),
      players,
      players_by_id: playerMap,
      duplicated_player_id: duplicatedPlayerId,
      duplicated_by_round: duplicatedByRound,
      rounds,
      thirdPlace: createEmptyThirdPlace(),
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

  function getDeterministicPlayerSortValue(currentState, playerId) {
    const player = getPlayerFromState(currentState, playerId);
    return {
      total_points: player ? Number(player.total_points || 0) : 0,
      display_name: player ? String(player.display_name || 'Jugador') : 'Jugador',
    };
  }

  function buildThirdPlaceMatch(currentState) {
    ensureStateShape(currentState);

    const semiRoundIndex = currentState.rounds.length - 2;
    if (semiRoundIndex < 0) {
      currentState.thirdPlace = createEmptyThirdPlace();
      return;
    }

    const semis = Array.isArray(currentState.rounds[semiRoundIndex]) ? currentState.rounds[semiRoundIndex] : [];
    const losers = semis.map(function (m) {
      if (!m || !m.player1_id || !m.player2_id || !m.winner_id) return null;
      if (m.winner_id !== m.player1_id && m.winner_id !== m.player2_id) return null;
      return m.winner_id === m.player1_id ? m.player2_id : m.player1_id;
    }).filter(Boolean);

    const uniqueLosers = [];
    losers.forEach(function (id) {
      if (!uniqueLosers.includes(id)) uniqueLosers.push(id);
    });

    uniqueLosers.sort(function (a, b) {
      const pa = getDeterministicPlayerSortValue(currentState, a);
      const pb = getDeterministicPlayerSortValue(currentState, b);
      if (pb.total_points !== pa.total_points) return pb.total_points - pa.total_points;
      return pa.display_name.localeCompare(pb.display_name, 'es');
    });

    const prev = currentState.thirdPlace || createEmptyThirdPlace();
    if (uniqueLosers.length < 2) {
      currentState.thirdPlace = createEmptyThirdPlace();
      return;
    }

    const nextP1 = uniqueLosers[0];
    const nextP2 = uniqueLosers[1];
    let nextWinner = prev.winner_id;

    if (nextP1 === nextP2) {
      currentState.thirdPlace = createEmptyThirdPlace();
      return;
    }

    if (prev.player1_id !== nextP1 || prev.player2_id !== nextP2) {
      nextWinner = null;
    }
    if (nextWinner !== nextP1 && nextWinner !== nextP2) {
      nextWinner = null;
    }

    currentState.thirdPlace = {
      player1_id: nextP1,
      player2_id: nextP2,
      winner_id: nextWinner,
    };
  }

  function syncRounds(currentState) {
    ensureStateShape(currentState);
    currentState.duplicated_by_round = currentState.duplicated_by_round || {};
    Object.keys(currentState.duplicated_by_round).forEach(function (roundKey) {
      if (Number(roundKey) > 0) {
        currentState.duplicated_by_round[roundKey] = [];
      }
    });

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

      const previousRoundMatches = currentState.rounds[r - 1];
      if (previousRoundMatches.length % 2 !== 0) {
        const carryMatch = currentState.rounds[r][currentState.rounds[r].length - 1];
        if (carryMatch && carryMatch.player1_id && !carryMatch.player2_id) {
          const previousWinners = previousRoundMatches.map(function (m) {
            return m.winner_id ? getPlayerFromState(currentState, m.winner_id) : null;
          }).filter(Boolean);

          const duplicateCandidate = pickDuplicateCandidate(previousWinners, carryMatch.player1_id);
          if (duplicateCandidate) {
            carryMatch.player2_id = duplicateCandidate.player_id;
            currentState.duplicated_by_round[r] = [duplicateCandidate.player_id];
          }
        }
      }

      currentState.rounds[r].forEach(function (match) {
        if (match.player1_id === match.player2_id) {
          match.player2_id = null;
        }
        if (match.winner_id !== match.player1_id && match.winner_id !== match.player2_id) {
          match.winner_id = null;
        }
      });
    }

    buildThirdPlaceMatch(currentState);
    currentState.awards = computeAwards(currentState);
  }

  function getPlayer(id) {
    if (!id || !state || !state.players_by_id[id]) return null;
    return state.players_by_id[id];
  }

  function formatPlayerName(id, fallback) {
    const p = getPlayer(id);
    return p ? escapeHtml(p.display_name) : (fallback || '—');
  }

  function matchCard(match, opts) {
    const p1 = getPlayer(match.player1_id);
    const p2 = getPlayer(match.player2_id);
    const canPick = Boolean(p1 && p2);

    const duplicatedInRound = state.duplicated_by_round && Array.isArray(state.duplicated_by_round[match.round_index])
      ? state.duplicated_by_round[match.round_index]
      : [];
    const duplicateBadgeP1 = duplicatedInRound.includes(match.player1_id) ? '<span class="dup-badge">⚠ juega 2 veces</span>' : '';
    const duplicateBadgeP2 = duplicatedInRound.includes(match.player2_id) ? '<span class="dup-badge">⚠ juega 2 veces</span>' : '';

    const editDisabled = opts.disableWinnerEdit ? 'disabled' : '';

    return `
      <div class="match ${opts.compact ? 'compact' : ''}" style="margin-top:${opts.marginTopPx}px;">
        <div class="p" title="${p1 ? escapeHtml(p1.display_name) : 'Sin definir'}">${p1 ? escapeHtml(p1.display_name) : '—'} ${duplicateBadgeP1}</div>
        <div class="vs">vs</div>
        <div class="p" title="${p2 ? escapeHtml(p2.display_name) : 'Sin definir'}">${p2 ? escapeHtml(p2.display_name) : '—'} ${duplicateBadgeP2}</div>
        <select class="winner-select" data-round="${match.round_index}" data-match="${match.match_index}" ${(!canPick || editDisabled) ? 'disabled' : ''}>
          <option value="">Ganador…</option>
          ${p1 ? `<option value="${p1.player_id}" ${match.winner_id === p1.player_id ? 'selected' : ''}>${escapeHtml(p1.display_name)}</option>` : ''}
          ${p2 ? `<option value="${p2.player_id}" ${match.winner_id === p2.player_id ? 'selected' : ''}>${escapeHtml(p2.display_name)}</option>` : ''}
        </select>
      </div>
    `;
  }

  function getFinalMatch(currentState) {
    const lastRound = currentState.rounds[currentState.rounds.length - 1];
    return lastRound && lastRound[0] ? lastRound[0] : null;
  }

  function getFinalWinnerId(currentState) {
    const fm = getFinalMatch(currentState);
    return fm ? fm.winner_id : null;
  }

  function isTournamentClosed(currentState) {
    return !!getFinalWinnerId(currentState) && !!currentState.thirdPlace?.winner_id;
  }

  function getPodiumData(currentState) {
    const finalMatch = getFinalMatch(currentState);
    if (!finalMatch || !finalMatch.winner_id) return null;

    const championId = finalMatch.winner_id;
    const runnerupId = championId === finalMatch.player1_id ? finalMatch.player2_id : finalMatch.player1_id;
    const thirdId = currentState.thirdPlace ? currentState.thirdPlace.winner_id : null;
    return {
      championId,
      runnerupId,
      thirdId,
    };
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

    const tp = currentState.thirdPlace;
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
    }).filter(function (award) {
      return award.points_delta > 0;
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

  function renderThirdPlaceCard() {
    const third = state.thirdPlace || createEmptyThirdPlace();
    const p1 = getPlayer(third.player1_id);
    const p2 = getPlayer(third.player2_id);
    const canPick = Boolean(p1 && p2 && p1.player_id !== p2.player_id);

    return `
      <div class="special-card">
        <h4>3ER PUESTO</h4>
        <div class="match compact special-match" style="margin-top:0;">
          <div class="p" title="${p1 ? escapeHtml(p1.display_name) : 'Pendiente de semis'}">${p1 ? escapeHtml(p1.display_name) : '—'}</div>
          <div class="vs">vs</div>
          <div class="p" title="${p2 ? escapeHtml(p2.display_name) : 'Pendiente de semis'}">${p2 ? escapeHtml(p2.display_name) : '—'}</div>
          <select id="thirdWinner" class="winner-select" ${canPick ? '' : 'disabled'}>
            <option value="">Ganador 3er puesto…</option>
            ${p1 ? `<option value="${p1.player_id}" ${third.winner_id === p1.player_id ? 'selected' : ''}>${escapeHtml(p1.display_name)}</option>` : ''}
            ${p2 ? `<option value="${p2.player_id}" ${third.winner_id === p2.player_id ? 'selected' : ''}>${escapeHtml(p2.display_name)}</option>` : ''}
          </select>
        </div>
      </div>
    `;
  }

  function renderPodium() {
    const data = getPodiumData(state);
    if (!data) {
      return '';
    }

    const firstName = formatPlayerName(data.championId);
    const secondName = formatPlayerName(data.runnerupId, 'Pendiente');
    const thirdName = data.thirdId ? formatPlayerName(data.thirdId) : 'pendiente…';

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
          disableWinnerEdit: false,
          compact: state.players.length > 24,
          marginTopPx,
        });
      }).join('');

      const finalExtrasMarkup = isFinalColumn
        ? `${renderThirdPlaceCard()}
           <div class="final-trophy"><img class="trophy" alt="Trofeo" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 256 256'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' x2='1' y1='0' y2='1'%3E%3Cstop offset='0' stop-color='%23ffe08c'/%3E%3Cstop offset='1' stop-color='%23d6931c'/%3E%3C/linearGradient%3E%3C/defs%3E%3Cpath fill='url(%23g)' d='M78 26h100v26c0 27-12 48-33 63v27h34v26H77v-26h34v-27C90 100 78 79 78 52V26Zm-37 18h29v20c0 21-11 40-29 49V86c9-6 14-14 14-22V44Zm144 0h30v20c0 8 5 16 14 22v27c-18-9-30-28-30-49V44ZM74 188h108v42H74v-42Z'/%3E%3C/svg%3E" /></div>
           ${renderPodium()}`
        : '';

      return `
        <section class="round-column" data-round-column="${roundIndex}">
          <h3>${title}</h3>
          <div class="matches">
            ${matchMarkup}
          </div>
          ${finalExtrasMarkup}
        </section>
      `;
    }).join('');

    if (!isTournamentClosed(state)) {
      if (!getFinalWinnerId(state)) {
        statusMsgEl.textContent = 'Definí ganador de la final para cerrar el torneo.';
      } else if (!state.thirdPlace.winner_id) {
        statusMsgEl.textContent = 'Falta definir 3er puesto para repartir puntos.';
      }
    } else {
      statusMsgEl.textContent = 'Torneo cerrado. Listo para aplicar puntos reales.';
    }

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

  function handleWinnerChange(roundIndex, matchIndex, winnerId) {
    const match = state.rounds[roundIndex][matchIndex];
    const finalMatch = getFinalMatch(state);
    const prevChampion = finalMatch ? finalMatch.winner_id : null;

    if (winnerId && winnerId !== match.player1_id && winnerId !== match.player2_id) {
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

    const currentFinal = getFinalMatch(state);
    if (currentFinal && currentFinal.winner_id && currentFinal.winner_id !== prevChampion) {
      const champ = getPlayer(currentFinal.winner_id);
      if (champ) {
        showCelebration('🏆 CAMPEÓN', champ.display_name, 3000, 85);
      }
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

    if (!window.confirm('Esto suma puntos reales al ranking. ¿Aplicar?')) {
      return;
    }

    const awards = state.awards.slice().filter(function (award) {
      return award.points_delta > 0;
    });

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
      statusMsgEl.textContent = `Aplicando puntos… ${i + 1}/${awards.length}`;
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
          const err = {
            error: data.error || `HTTP ${res.status}`,
            request_id: data.request_id || 'n/a',
            award,
          };
          errors.push(err);
          console.error('Error adjust_points', err);
        } else {
          okCount += 1;
        }
      } catch (err) {
        const wrapped = {
          error: err && err.message ? err.message : 'Error de red',
          request_id: 'n/a',
          award,
        };
        errors.push(wrapped);
        console.error('Error fetch adjust_points', wrapped);
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
      statusMsgEl.textContent = 'Puntos reales aplicados correctamente.';
    } else {
      updateApplyButton();
      statusMsgEl.textContent = 'Aplicación con errores. Revisá detalles.';
    }
  }

  function bindEvents() {
    document.body.addEventListener('change', function (ev) {
      const t = ev.target;
      if (!(t instanceof HTMLSelectElement)) return;

      if (t.id === 'thirdWinner') {
        const third = state.thirdPlace || createEmptyThirdPlace();
        const selectedWinner = t.value ? Number(t.value) : null;

        if (selectedWinner && selectedWinner !== third.player1_id && selectedWinner !== third.player2_id) {
          errorMsgEl.textContent = 'Ganador inválido para 3er puesto.';
          t.value = '';
          return;
        }

        state.thirdPlace.winner_id = selectedWinner;
        state.awards = computeAwards(state);
        saveState();
        renderBracket();

        if (state.applied_at) {
          errorMsgEl.textContent = 'Ojo: ya aplicaste puntos, cambiar resultados no revierte puntos.';
        }
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
      ensureStateShape(parsed);
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
