<?php

function virus_base64url_encode(string $input): string {
  return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

function virus_base64url_decode(string $input): string|false {
  $padding = strlen($input) % 4;
  if ($padding > 0) {
    $input .= str_repeat('=', 4 - $padding);
  }
  return base64_decode(strtr($input, '-_', '+/'), true);
}

function virus_assign_roles(array $playerIds): array {
  $ids = array_values(array_map('intval', $playerIds));
  shuffle($ids);

  $total = count($ids);
  $virusCount = (int) floor($total / 2);
  $antidoteCount = $total - $virusCount;

  if (random_int(0, 1) === 1) {
    [$virusCount, $antidoteCount] = [$antidoteCount, $virusCount];
  }

  $roles = [];
  foreach ($ids as $idx => $playerId) {
    $roles[$playerId] = ($idx < $virusCount) ? 'virus' : 'antidote';
  }

  return $roles;
}

function virus_sign_payload(array $payload, string $secret): string {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('No se pudo codificar payload');
  }

  $payloadEncoded = virus_base64url_encode($json);
  $signature = hash_hmac('sha256', $payloadEncoded, $secret, true);
  return $payloadEncoded . '.' . virus_base64url_encode($signature);
}

function virus_verify_payload_string(string $token, string $secret): array {
  $parts = explode('.', trim($token));
  if (count($parts) !== 2) {
    throw new InvalidArgumentException('QR inválido');
  }

  [$payloadEncoded, $sigEncoded] = $parts;
  if ($payloadEncoded === '' || $sigEncoded === '') {
    throw new InvalidArgumentException('QR inválido');
  }

  $expected = hash_hmac('sha256', $payloadEncoded, $secret, true);
  $actual = virus_base64url_decode($sigEncoded);
  if ($actual === false || !hash_equals($expected, $actual)) {
    throw new InvalidArgumentException('Firma QR inválida');
  }

  $json = virus_base64url_decode($payloadEncoded);
  if ($json === false) {
    throw new InvalidArgumentException('QR inválido');
  }

  $payload = json_decode($json, true);
  if (!is_array($payload)) {
    throw new InvalidArgumentException('QR inválido');
  }

  $sessionId = (int) ($payload['session_id'] ?? 0);
  $playerId = (int) ($payload['player_id'] ?? 0);
  $exp = (int) ($payload['exp'] ?? 0);
  $nonce = (string) ($payload['nonce'] ?? '');

  if ($sessionId <= 0 || $playerId <= 0 || $exp <= 0 || $nonce === '') {
    throw new InvalidArgumentException('QR inválido');
  }

  if ($exp < time()) {
    throw new InvalidArgumentException('QR expirado');
  }

  return [
    'session_id' => $sessionId,
    'player_id' => $playerId,
    'nonce' => $nonce,
    'exp' => $exp,
  ];
}

function virus_pair_ids(int $playerOne, int $playerTwo): array {
  if ($playerOne === $playerTwo) {
    throw new InvalidArgumentException('Los jugadores deben ser distintos');
  }

  return [min($playerOne, $playerTwo), max($playerOne, $playerTwo)];
}

function virus_matchup_type(array $preState): string {
  $myRole = (string) ($preState['me']['role'] ?? '');
  $otherRole = (string) ($preState['other']['role'] ?? '');

  if ($myRole === 'virus' && $otherRole === 'virus') {
    return 'VV';
  }

  if ($myRole === 'antidote' && $otherRole === 'antidote') {
    return 'AA';
  }

  return 'VA';
}

function virus_player_view_result(array $combat, int $viewForPlayerId): string {
  if (!empty($combat['draw'])) {
    return 'EMPATE';
  }

  return ((int) ($combat['winner_player_id'] ?? 0) === $viewForPlayerId) ? 'GANASTE' : 'PERDISTE';
}

function virus_player_outcome_code(array $combat, int $viewForPlayerId): string {
  if (!empty($combat['draw'])) {
    return 'DRAW';
  }

  return ((int) ($combat['winner_player_id'] ?? 0) === $viewForPlayerId) ? 'WIN' : 'LOSE';
}

function virus_build_already_interacted_error(int $sessionId, array $players): array {
  return [
    'code' => 'ALREADY_INTERACTED',
    'message' => 'Already interacted',
    'players' => array_map(fn($p) => [
      'id' => (int) ($p['id'] ?? 0),
      'handle' => (string) ($p['handle'] ?? ''),
    ], $players),
    'session_id' => $sessionId,
  ];
}

function virus_resolve_combat(array $me, array $other): array {
  $a = [
    'player_id' => (int) $me['player_id'],
    'handle' => (string) ($me['handle'] ?? ''),
    'role' => (string) $me['role'],
    'power' => (int) $me['power'],
  ];

  $b = [
    'player_id' => (int) $other['player_id'],
    'handle' => (string) ($other['handle'] ?? ''),
    'role' => (string) $other['role'],
    'power' => (int) $other['power'],
  ];

  $aPost = $a;
  $bPost = $b;
  $result = 'Empate: sin cambios.';
  $winnerPlayerId = null;
  $loserPlayerId = null;
  $draw = false;

  if ($a['role'] === $b['role']) {
    $aPost['power'] = $a['power'] + 1;
    $bPost['power'] = $b['power'] + 1;
    $result = 'Mismo rol: ambos ganan +1 power.';
    $draw = true;
  } elseif ($a['power'] === $b['power']) {
    $result = 'Virus vs Antídoto empatados: no hay cambios.';
    $draw = true;
  } else {
    $aWins = $a['power'] > $b['power'];
    if ($aWins) {
      $aPost['power'] = max(1, $a['power'] - 1);
      $bPost['power'] = 1;
      $winnerPlayerId = $a['player_id'];
      $loserPlayerId = $b['player_id'];
      $result = 'Ganó ' . $a['role'] . ' (player_id=' . $a['player_id'] . ').';
    } else {
      $bPost['power'] = max(1, $b['power'] - 1);
      $aPost['power'] = 1;
      $winnerPlayerId = $b['player_id'];
      $loserPlayerId = $a['player_id'];
      $result = 'Ganó ' . $b['role'] . ' (player_id=' . $b['player_id'] . ').';
    }
  }

  return [
    'pre_state' => ['me' => $a, 'other' => $b],
    'post_state' => ['me' => $aPost, 'other' => $bPost],
    'message' => $result,
    'winner_player_id' => $winnerPlayerId,
    'loser_player_id' => $loserPlayerId,
    'draw' => $draw,
    'matchup_type' => virus_matchup_type(['me' => $a, 'other' => $b]),
  ];
}
