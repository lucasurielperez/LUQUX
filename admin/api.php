<?php
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/virus_lib.php';

function body_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') {
    return [];
  }

  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function ok(array $data = []): void {
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $status = 400, ?string $errorCode = null, array $extra = []): void {
  http_response_code($status);
  $payload = ['ok' => false, 'error' => $msg];
  if ($errorCode !== null && $errorCode !== '') {
    $payload['code'] = $errorCode;
  }
  if (!empty($extra)) {
    $payload = array_merge($payload, $extra);
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function auth_bearer_token(): string {
  $header = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';

  if (preg_match('/Bearer\s+(.+)$/i', $header, $m)) {
    return trim($m[1]);
  }

  return '';
}

function setting_get(PDO $pdo, string $key, string $default): string {
  $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
  $stmt->execute([$key]);
  $val = $stmt->fetchColumn();

  if ($val === false || $val === null) {
    $ins = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $ins->execute([$key, $default]);
    return $default;
  }

  return (string) $val;
}

function setting_set(PDO $pdo, string $key, string $value): void {
  $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
  $stmt->execute([$key, $value]);
}

function player_exists(PDO $pdo, int $playerId): bool {
  $stmt = $pdo->prepare('SELECT id FROM players WHERE id = ? LIMIT 1');
  $stmt->execute([$playerId]);
  return (bool) $stmt->fetchColumn();
}

function find_player_by_token(PDO $pdo, string $playerToken): ?array {
  $stmt = $pdo->prepare('SELECT id, public_code, display_name FROM players WHERE player_token = ? LIMIT 1');
  $stmt->execute([$playerToken]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function resolve_player_id(PDO $pdo, array $payload): int {
  $token = trim((string) ($payload['player_token'] ?? ''));
  if ($token !== '') {
    if (strlen($token) < 24) {
      fail('player_token inválido', 422, 'INVALID_PLAYER_TOKEN');
    }

    $player = find_player_by_token($pdo, $token);
    if (!$player) {
      fail('Jugador inválido', 404, 'PLAYER_NOT_FOUND');
    }

    return (int) $player['id'];
  }

  $playerId = (int) ($payload['player_id'] ?? 0);
  if ($playerId <= 0) {
    fail('player_token o player_id requerido', 422, 'PLAYER_REQUIRED');
  }

  if (!player_exists($pdo, $playerId)) {
    fail('Jugador inválido', 404, 'PLAYER_NOT_FOUND');
  }

  return $playerId;
}

function resolve_player_id_from_body(PDO $pdo, array $payload): int {
  return resolve_player_id($pdo, $payload);
}

function get_players_by_device(PDO $pdo, string $deviceId): array {
  $stmt = $pdo->prepare('SELECT id, display_name, public_code, player_token, device_slot FROM players WHERE device_fingerprint = ? ORDER BY device_slot ASC, id ASC');
  $stmt->execute([$deviceId]);
  $rows = $stmt->fetchAll();

  return array_map(static function (array $row): array {
    return [
      'id' => (int) $row['id'],
      'display_name' => (string) $row['display_name'],
      'public_code' => (string) $row['public_code'],
      'player_token' => (string) $row['player_token'],
      'device_slot' => (int) $row['device_slot'],
    ];
  }, $rows);
}

function get_or_create_sumador_game(PDO $pdo): array {
  $stmt = $pdo->prepare('SELECT id, is_active FROM games WHERE code = ? LIMIT 1');
  $stmt->execute(['sumador']);
  $row = $stmt->fetch();

  if ($row) {
    return [
      'id' => (int) $row['id'],
      'is_active' => (int) $row['is_active'],
    ];
  }

  $ins = $pdo->prepare('INSERT INTO games (code, name, is_active, base_points) VALUES (?, ?, 1, 0)');
  $ins->execute(['sumador', 'Sumador']);

  return [
    'id' => (int) $pdo->lastInsertId(),
    'is_active' => 1,
  ];
}

function get_or_create_virus_game(PDO $pdo): array {
  $stmt = $pdo->prepare('SELECT id, is_active FROM games WHERE code = ? LIMIT 1');
  $stmt->execute(['virus']);
  $row = $stmt->fetch();

  if ($row) {
    return [
      'id' => (int) $row['id'],
      'is_active' => (int) $row['is_active'],
    ];
  }

  $ins = $pdo->prepare('INSERT INTO games (code, name, is_active, base_points) VALUES (?, ?, 0, 0)');
  $ins->execute(['virus', 'Virus']);

  return [
    'id' => (int) $pdo->lastInsertId(),
    'is_active' => 0,
  ];
}

function virus_secret(array $config): string {
  $secret = (string) ($config['virus_qr_secret'] ?? $config['admin_token'] ?? 'virus-secret');
  if (strlen($secret) < 16) {
    $secret .= '-virus-fallback-secret';
  }
  return $secret;
}

function virus_get_active_session(PDO $pdo): ?array {
  $stmt = $pdo->query('SELECT id, is_active, started_at, ended_at, leaderboard_snapshot_json FROM virus_sessions WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
  $row = $stmt->fetch();
  return $row ?: null;
}

function virus_get_last_session(PDO $pdo): ?array {
  $stmt = $pdo->query('SELECT id, is_active, started_at, ended_at, leaderboard_snapshot_json FROM virus_sessions ORDER BY id DESC LIMIT 1');
  $row = $stmt->fetch();
  return $row ?: null;
}

function virus_compute_leaderboard(PDO $pdo, int $sessionId): array {
  $stmt = $pdo->prepare(
    'SELECT vps.player_id, p.display_name, p.public_code, vps.role, vps.power, vps.updated_at,
            COALESCE(stats.matches, 0) AS matches
     FROM virus_player_state vps
     INNER JOIN players p ON p.id = vps.player_id
     LEFT JOIN (
       SELECT player_id, COUNT(*) AS matches
       FROM (
         SELECT player_a AS player_id FROM virus_interactions WHERE session_id = ?
         UNION ALL
         SELECT player_b AS player_id FROM virus_interactions WHERE session_id = ?
       ) z
       GROUP BY player_id
     ) stats ON stats.player_id = vps.player_id
     WHERE vps.session_id = ?
     ORDER BY vps.power DESC, vps.updated_at ASC, vps.player_id ASC'
  );
  $stmt->execute([$sessionId, $sessionId, $sessionId]);
  return $stmt->fetchAll();
}

function virus_close_active_session(PDO $pdo): ?int {
  $active = virus_get_active_session($pdo);
  if (!$active) {
    return null;
  }

  $sessionId = (int) $active['id'];
  $snapshot = virus_compute_leaderboard($pdo, $sessionId);
  $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

  $pdo->beginTransaction();
  $up = $pdo->prepare('UPDATE virus_sessions SET is_active = 0, ended_at = NOW(), leaderboard_snapshot_json = ? WHERE id = ?');
  $up->execute([$snapshotJson, $sessionId]);
  $pdo->commit();

  return $sessionId;
}

function virus_set_enabled(PDO $pdo, bool $enabled): array {
  $virusGame = get_or_create_virus_game($pdo);

  if ($enabled) {
    $active = virus_get_active_session($pdo);
    if (!$active) {
      $started = virus_start_session($pdo);
      $sessionId = (int) $started['session_id'];
    } else {
      $sessionId = (int) $active['id'];
    }

    $up = $pdo->prepare('UPDATE games SET is_active = 1 WHERE id = ?');
    $up->execute([$virusGame['id']]);

    return ['enabled' => true, 'session_id' => $sessionId];
  }

  $sessionId = virus_close_active_session($pdo);
  $up = $pdo->prepare('UPDATE games SET is_active = 0 WHERE id = ?');
  $up->execute([$virusGame['id']]);

  return ['enabled' => false, 'session_id' => $sessionId];
}

function virus_start_session(PDO $pdo): array {
  $playersStmt = $pdo->query('SELECT id FROM players WHERE is_active = 1 ORDER BY id ASC');
  $playerIds = array_map('intval', array_column($playersStmt->fetchAll(), 'id'));

  if (count($playerIds) < 2) {
    fail('Se necesitan al menos 2 jugadores activos para iniciar Virus', 422);
  }

  $pdo->beginTransaction();
  $close = $pdo->prepare('UPDATE virus_sessions SET is_active = 0, ended_at = NOW() WHERE is_active = 1');
  $close->execute();

  $create = $pdo->prepare('INSERT INTO virus_sessions (is_active, started_at) VALUES (1, NOW())');
  $create->execute();
  $sessionId = (int) $pdo->lastInsertId();

  $roles = virus_assign_roles($playerIds);
  $ins = $pdo->prepare('INSERT INTO virus_player_state (session_id, player_id, role, power) VALUES (?, ?, ?, 1)');
  foreach ($playerIds as $playerId) {
    $ins->execute([$sessionId, $playerId, $roles[$playerId]]);
  }

  $pdo->commit();
  return [
    'session_id' => $sessionId,
    'players_count' => count($playerIds),
  ];
}

function validate_display_name(string $displayName): string {
  $name = trim($displayName);
  $len = mb_strlen($name, 'UTF-8');
  if ($len < 1 || $len > 80) {
    fail('display_name inválido (1..80)', 422);
  }
  return $name;
}

function validate_device_id(string $deviceId): string {
  $id = trim($deviceId);
  if (strlen($id) < 12 || strlen($id) > 255) {
    fail('device_id inválido', 422);
  }
  return $id;
}

function generate_public_code(PDO $pdo, int $length = 8): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $max = strlen($alphabet) - 1;

  for ($attempt = 0; $attempt < 20; $attempt++) {
    $code = '';
    for ($i = 0; $i < $length; $i++) {
      $code .= $alphabet[random_int(0, $max)];
    }

    $stmt = $pdo->prepare('SELECT id FROM players WHERE public_code = ? LIMIT 1');
    $stmt->execute([$code]);
    if (!$stmt->fetchColumn()) {
      return $code;
    }
  }

  fail('No se pudo generar public_code', 500);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$publicActions = [
  'sumador_start',
  'sumador_finish',
  'resolve_player',
  'player_info',
  'player_register',
  'player_me',
  'device_players',
  'player_rename',
  'virus_status',
  'virus_my_qr',
  'virus_scan',
];

if (!in_array($action, $publicActions, true)) {
  $token = auth_bearer_token();
  if ($token === '' || !hash_equals((string) $config['admin_token'], $token)) {
    fail('Unauthorized', 401);
  }
}

$db = $config['db'];
$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

try {
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  fail('Database connection error', 500);
}

get_or_create_virus_game($pdo);

try {
  if ($method === 'GET' && $action === 'ping') {
    ok(['msg' => 'pong']);
  }

  if ($method === 'GET' && $action === 'games') {
    $stmt = $pdo->query('SELECT id, code, name, is_active, base_points FROM games ORDER BY id ASC');
    ok(['rows' => $stmt->fetchAll()]);
  }

  if ($method === 'GET' && $action === 'players') {
    $stmt = $pdo->query('SELECT id, public_code, display_name, player_token, is_active, created_at, last_seen_at FROM players ORDER BY created_at DESC');
    ok(['rows' => $stmt->fetchAll()]);
  }

  if (($method === 'GET' || $method === 'POST') && $action === 'resolve_player') {
    $payload = ($method === 'POST') ? body_json() : $_GET;
    $token = trim((string) ($payload['player_token'] ?? ''));

    if ($token === '') {
      fail('player_token requerido');
    }

    if (strlen($token) < 24) {
      fail('player_token inválido');
    }

    $player = find_player_by_token($pdo, $token);
    if (!$player) {
      fail('Jugador inválido', 404);
    }

    ok([
      'player_id' => (int) $player['id'],
      'public_code' => (string) $player['public_code'],
      'display_name' => (string) $player['display_name'],
    ]);
  }

  if (($method === 'GET' || $method === 'POST') && $action === 'player_info') {
    $payload = ($method === 'POST') ? body_json() : $_GET;
    $playerId = resolve_player_id($pdo, $payload);

    $playerStmt = $pdo->prepare('SELECT display_name FROM players WHERE id = ? LIMIT 1');
    $playerStmt->execute([$playerId]);
    $player = $playerStmt->fetch();
    if (!$player) {
      fail('Jugador inválido', 404);
    }

    $game = get_or_create_sumador_game($pdo);

    $playedStmt = $pdo->prepare('SELECT id FROM game_plays WHERE player_id = ? AND game_id = ? LIMIT 1');
    $playedStmt->execute([$playerId, $game['id']]);
    $alreadyPlayed = (bool) $playedStmt->fetchColumn();

    ok([
      'player_id' => $playerId,
      'display_name' => (string) $player['display_name'],
      'sumador_played' => $alreadyPlayed,
      'status' => $alreadyPlayed ? 'Ya jugaste' : 'Disponible',
    ]);
  }

  if ($method === 'POST' && $action === 'player_register') {
    $b = body_json();
    $displayName = validate_display_name((string) ($b['display_name'] ?? ''));
    $deviceId = validate_device_id((string) ($b['device_id'] ?? ''));

    $requestedSlot = isset($b['device_slot']) ? (int) $b['device_slot'] : 0;
    if ($requestedSlot !== 0 && $requestedSlot !== 1 && $requestedSlot !== 2) {
      fail('device_slot inválido', 422, 'INVALID_DEVICE_SLOT');
    }

    $existingPlayers = get_players_by_device($pdo, $deviceId);
    $count = count($existingPlayers);

    if ($count >= 2) {
      fail('Este celu ya tiene 2 jugadores cargados', 409, 'DEVICE_FULL');
    }

    $occupiedSlots = array_column($existingPlayers, 'device_slot');
    if ($requestedSlot > 0) {
      if (in_array($requestedSlot, $occupiedSlots, true)) {
        fail('Ese lugar ya está ocupado en este dispositivo', 409, 'SLOT_TAKEN');
      }
      $deviceSlot = $requestedSlot;
    } else {
      $deviceSlot = in_array(1, $occupiedSlots, true) ? 2 : 1;
    }

    $created = null;
    for ($attempt = 0; $attempt < 10; $attempt++) {
      $publicCode = generate_public_code($pdo, 8);
      $playerToken = strtolower(hash('sha256', random_bytes(32)));
      $ins = $pdo->prepare('INSERT INTO players (display_name, device_fingerprint, device_slot, public_code, player_token, last_seen_at) VALUES (?, ?, ?, ?, ?, NOW())');

      try {
        $ins->execute([$displayName, $deviceId, $deviceSlot, $publicCode, $playerToken]);
        $created = [
          'id' => (int) $pdo->lastInsertId(),
          'display_name' => $displayName,
          'public_code' => $publicCode,
          'player_token' => $playerToken,
          'device_slot' => $deviceSlot,
        ];
        break;
      } catch (PDOException $e) {
        if ((string) $e->getCode() !== '23000') {
          throw $e;
        }

        if (str_contains((string) $e->getMessage(), 'uq_players_device_slot')) {
          fail('Ese lugar ya está ocupado en este dispositivo', 409, 'SLOT_TAKEN');
        }
      }
    }

    if (!$created) {
      fail('No se pudo registrar al jugador', 409);
    }

    ok(['player' => $created]);
  }

  if ($method === 'GET' && $action === 'player_me') {
    $deviceId = validate_device_id((string) ($_GET['device_id'] ?? ''));

    $players = get_players_by_device($pdo, $deviceId);
    $count = count($players);

    if ($count > 0) {
      $touch = $pdo->prepare('UPDATE players SET last_seen_at = NOW() WHERE device_fingerprint = ?');
      $touch->execute([$deviceId]);
    }

    $response = [
      'players' => $players,
      'count' => $count,
      'player' => $count === 1 ? $players[0] : null,
    ];

    ok($response);
  }

  if ($method === 'GET' && $action === 'device_players') {
    $deviceId = validate_device_id((string) ($_GET['device_id'] ?? ''));
    $players = get_players_by_device($pdo, $deviceId);

    ok([
      'players' => $players,
      'count' => count($players),
      'player' => count($players) === 1 ? $players[0] : null,
    ]);
  }

  if ($method === 'POST' && $action === 'player_rename') {
    $b = body_json();
    $playerId = (int) ($b['player_id'] ?? 0);
    $deviceId = validate_device_id((string) ($b['device_id'] ?? ''));
    $displayName = validate_display_name((string) ($b['display_name'] ?? ''));

    if ($playerId <= 0) {
      fail('player_id requerido', 422);
    }

    $stmt = $pdo->prepare('SELECT id, public_code, player_token, device_slot FROM players WHERE id = ? AND device_fingerprint = ? LIMIT 1');
    $stmt->execute([$playerId, $deviceId]);
    $player = $stmt->fetch();

    if (!$player) {
      fail('Jugador inválido', 403);
    }

    $up = $pdo->prepare('UPDATE players SET display_name = ?, last_seen_at = NOW() WHERE id = ?');
    $up->execute([$displayName, $playerId]);

    ok([
      'player' => [
        'id' => $playerId,
        'display_name' => $displayName,
        'public_code' => (string) $player['public_code'],
        'player_token' => (string) $player['player_token'],
        'device_slot' => (int) $player['device_slot'],
      ],
    ]);
  }

  if ($method === 'GET' && $action === 'leaderboard') {
    $stmt = $pdo->query('SELECT * FROM leaderboard ORDER BY total_points DESC, last_scored_at ASC, display_name ASC');
    ok(['rows' => $stmt->fetchAll()]);
  }

  if ($method === 'GET' && $action === 'events_recent') {
    $stmt = $pdo->query(
      'SELECT se.id, se.created_at, se.event_type, se.points_delta, se.note,
              se.player_id, p.display_name, p.public_code,
              se.game_id, g.code AS game_code,
              se.secret_qr_id, q.code AS qr_code
       FROM score_events se
       INNER JOIN players p ON p.id = se.player_id
       LEFT JOIN games g ON g.id = se.game_id
       LEFT JOIN secret_qrs q ON q.id = se.secret_qr_id
       ORDER BY se.id DESC
       LIMIT 50'
    );
    ok(['rows' => $stmt->fetchAll()]);
  }

  if ($method === 'GET' && $action === 'scoring_status') {
    $enabled = setting_get($pdo, 'scoring_enabled', '1');
    $multiplier = setting_get($pdo, 'qr_multiplier', '1');
    ok([
      'enabled' => ($enabled === '1'),
      'qr_multiplier' => (int) $multiplier,
    ]);
  }

  if ($method === 'POST' && $action === 'set_scoring_enabled') {
    $b = body_json();
    $enabled = !empty($b['enabled']) ? '1' : '0';
    setting_set($pdo, 'scoring_enabled', $enabled);
    ok(['enabled' => ($enabled === '1')]);
  }

  if ($method === 'POST' && $action === 'set_qr_multiplier') {
    $b = body_json();
    $value = (int) ($b['value'] ?? 0);

    if (!in_array($value, [1, 2, 3], true)) {
      fail('value debe ser 1, 2 o 3');
    }

    setting_set($pdo, 'qr_multiplier', (string) $value);
    ok(['value' => $value]);
  }

  if ($method === 'POST' && $action === 'reset_player') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);

    $pdo->beginTransaction();
    $st1 = $pdo->prepare('DELETE FROM score_events WHERE player_id = ?');
    $st1->execute([$playerId]);
    $deletedScoreEvents = $st1->rowCount();

    $st2 = $pdo->prepare('DELETE FROM secret_qr_claims WHERE player_id = ?');
    $st2->execute([$playerId]);
    $deletedQrClaims = $st2->rowCount();

    $st3 = $pdo->prepare('DELETE FROM game_plays WHERE player_id = ?');
    $st3->execute([$playerId]);
    $deletedGamePlays = $st3->rowCount();

    $st4 = $pdo->prepare('DELETE FROM virus_player_state WHERE player_id = ?');
    $st4->execute([$playerId]);
    $deletedVirusStates = $st4->rowCount();

    $st5 = $pdo->prepare('DELETE FROM virus_interactions WHERE player_a = ? OR player_b = ?');
    $st5->execute([$playerId, $playerId]);
    $deletedVirusInteractions = $st5->rowCount();

    $pdo->commit();

    ok([
      'deleted_score_events' => $deletedScoreEvents,
      'deleted_qr_claims' => $deletedQrClaims,
      'deleted_game_plays' => $deletedGamePlays,
      'deleted_virus_states' => $deletedVirusStates,
      'deleted_virus_interactions' => $deletedVirusInteractions,
    ]);
  }

  if ($method === 'POST' && $action === 'adjust_points') {
    $b = body_json();
    $playerId = (int) ($b['player_id'] ?? 0);
    $pointsDelta = (int) ($b['points_delta'] ?? 0);
    $note = trim((string) ($b['note'] ?? ''));

    if ($playerId <= 0) {
      fail('player_id requerido');
    }

    if ($pointsDelta === 0) {
      fail('points_delta no puede ser 0');
    }

    $stmt = $pdo->prepare("INSERT INTO score_events (player_id, event_type, points_delta, note) VALUES (?, 'ADMIN_ADJUST', ?, ?)");
    $stmt->execute([$playerId, $pointsDelta, $note]);
    ok(['inserted_id' => (int) $pdo->lastInsertId()]);
  }

  if ($method === 'POST' && $action === 'games_toggle') {
    $b = body_json();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
      fail('id requerido');
    }

    $gameStmt = $pdo->prepare('SELECT id, code FROM games WHERE id = ? LIMIT 1');
    $gameStmt->execute([$id]);
    $game = $gameStmt->fetch();
    if (!$game) {
      fail('Juego no encontrado', 404);
    }

    $isActive = !empty($b['is_active']);
    if ((string) $game['code'] === 'virus') {
      ok(virus_set_enabled($pdo, $isActive));
    }

    $stmt = $pdo->prepare('UPDATE games SET is_active = ? WHERE id = ?');
    $stmt->execute([$isActive ? 1 : 0, $id]);
    ok(['updated' => $stmt->rowCount()]);
  }

  if ($method === 'POST' && $action === 'players_delete') {
    $b = body_json();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
      fail('id requerido');
    }

    $stmt = $pdo->prepare('DELETE FROM players WHERE id = ?');
    $stmt->execute([$id]);
    ok(['deleted' => $stmt->rowCount()]);
  }

  if ($method === 'POST' && $action === 'sumador_start') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);

    $enabled = setting_get($pdo, 'scoring_enabled', '1');
    if ($enabled !== '1') {
      fail('El puntaje está pausado', 403);
    }

    $game = get_or_create_sumador_game($pdo);
    if ($game['is_active'] !== 1) {
      fail('Juego no disponible', 403);
    }

    $stmt = $pdo->prepare('INSERT INTO game_plays (player_id, game_id) VALUES (?, ?)');
    try {
      $stmt->execute([$playerId, $game['id']]);
    } catch (PDOException $e) {
      if ((string) $e->getCode() === '23000') {
        fail('Ya jugaste este juego', 409);
      }
      throw $e;
    }

    ok([
      'duration_ms' => 20000,
      'game_id' => $game['id'],
    ]);
  }

  if ($method === 'POST' && $action === 'sumador_finish') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);
    $score = (int) ($b['score'] ?? 0);
    $clicks = (int) ($b['clicks'] ?? 0);
    $durationMs = (int) ($b['duration_ms'] ?? 0);

    if ($durationMs < 18000 || $durationMs > 22000) {
      fail('duration_ms inválido');
    }
    if ($clicks < 0 || $clicks > 2000) {
      fail('clicks inválido');
    }
    if ($score < -4000 || $score > 4000) {
      fail('score inválido');
    }

    $game = get_or_create_sumador_game($pdo);

    $stmt = $pdo->prepare(
      'SELECT id
       FROM game_plays
       WHERE player_id = ? AND game_id = ? AND finished_at IS NULL
       LIMIT 1'
    );
    $stmt->execute([$playerId, $game['id']]);
    $play = $stmt->fetch();

    if (!$play) {
      fail('Partida no iniciada o ya finalizada', 409);
    }

    $pdo->beginTransaction();

    $up = $pdo->prepare('UPDATE game_plays SET finished_at = NOW(), duration_ms = ?, attempts = ?, score = ? WHERE id = ?');
    $up->execute([$durationMs, $clicks, $score, (int) $play['id']]);

    $note = sprintf('Sumador (clicks=%d)', $clicks);
    $ins = $pdo->prepare(
      "INSERT INTO score_events (player_id, event_type, game_id, attempts, duration_ms, points_delta, note)
       VALUES (?, 'GAME_RESULT', ?, ?, ?, ?, ?)"
    );
    $ins->execute([$playerId, $game['id'], $clicks, $durationMs, $score, $note]);

    $pdo->commit();

    ok([
      'score' => $score,
      'clicks' => $clicks,
      'duration_ms' => $durationMs,
    ]);
  }


  if (($method === 'GET' || $method === 'POST') && $action === 'virus_status') {
    $payload = ($method === 'POST') ? body_json() : $_GET;
    $playerId = resolve_player_id($pdo, $payload);
    $virusGame = get_or_create_virus_game($pdo);

    if ((int) $virusGame['is_active'] !== 1) {
      $last = virus_get_last_session($pdo);
      ok([
        'is_active' => false,
        'session_id' => $last ? (int) $last['id'] : null,
        'my_power' => null,
        'my_role' => null,
        'opponents_pending' => [],
        'interacted_count' => 0,
        'total_opponents' => 0,
      ]);
    }

    $session = virus_get_active_session($pdo);

    if (!$session) {
      $last = virus_get_last_session($pdo);
      ok([
        'is_active' => true,
        'session_id' => $last ? (int) $last['id'] : null,
        'my_power' => null,
        'my_role' => null,
        'opponents_pending' => [],
        'interacted_count' => 0,
        'total_opponents' => 0,
      ]);
    }

    $sessionId = (int) $session['id'];
    $meStmt = $pdo->prepare('SELECT player_id, role, power FROM virus_player_state WHERE session_id = ? AND player_id = ? LIMIT 1');
    $meStmt->execute([$sessionId, $playerId]);
    $me = $meStmt->fetch();

    if (!$me) {
      ok([
        'is_active' => true,
        'session_id' => $sessionId,
        'my_power' => null,
        'my_role' => null,
        'opponents_pending' => [],
        'interacted_count' => 0,
        'total_opponents' => 0,
      ]);
    }

    $oppStmt = $pdo->prepare(
      'SELECT p.id, p.display_name
       FROM virus_player_state vps
       INNER JOIN players p ON p.id = vps.player_id
       LEFT JOIN virus_interactions vi
         ON vi.session_id = vps.session_id
        AND ((vi.player_a = ? AND vi.player_b = vps.player_id)
          OR (vi.player_b = ? AND vi.player_a = vps.player_id))
       WHERE vps.session_id = ?
         AND vps.player_id <> ?
         AND vi.id IS NULL
       ORDER BY p.display_name ASC, p.id ASC'
    );
    $oppStmt->execute([$playerId, $playerId, $sessionId, $playerId]);
    $pending = $oppStmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM virus_interactions WHERE session_id = ? AND (player_a = ? OR player_b = ?)');
    $countStmt->execute([$sessionId, $playerId, $playerId]);
    $interacted = (int) $countStmt->fetchColumn();

    $totalStmt = $pdo->prepare('SELECT GREATEST(COUNT(*) - 1, 0) FROM virus_player_state WHERE session_id = ?');
    $totalStmt->execute([$sessionId]);
    $totalOpp = (int) $totalStmt->fetchColumn();

    ok([
      'is_active' => true,
      'session_id' => $sessionId,
      'my_power' => (int) $me['power'],
      'my_role' => null,
      'opponents_pending' => array_map(fn($r) => [
        'id' => (int) $r['id'],
        'display_name' => (string) $r['display_name'],
      ], $pending),
      'interacted_count' => $interacted,
      'total_opponents' => $totalOpp,
    ]);
  }

  if (($method === 'GET' || $method === 'POST') && $action === 'virus_my_qr') {
    $payload = ($method === 'POST') ? body_json() : $_GET;
    $playerId = resolve_player_id($pdo, $payload);
    $virusGame = get_or_create_virus_game($pdo);

    if ((int) $virusGame['is_active'] !== 1) {
      fail('Juego Virus inactivo', 403, 'GAME_INACTIVE');
    }

    $session = virus_get_active_session($pdo);

    if (!$session) {
      fail('Juego Virus inactivo', 403, 'GAME_INACTIVE');
    }

    $sessionId = (int) $session['id'];
    $stateStmt = $pdo->prepare('SELECT player_id FROM virus_player_state WHERE session_id = ? AND player_id = ? LIMIT 1');
    $stateStmt->execute([$sessionId, $playerId]);
    if (!$stateStmt->fetch()) {
      fail('Jugador no participa de la sesión activa', 403);
    }

    $tokenPayload = [
      'session_id' => $sessionId,
      'player_id' => $playerId,
      'nonce' => bin2hex(random_bytes(8)),
      'exp' => time() + 86400,
    ];

    $qrPayload = virus_sign_payload($tokenPayload, virus_secret($config));
    ok(['session_id' => $sessionId, 'qr_payload' => $qrPayload]);
  }

  if ($method === 'POST' && $action === 'virus_scan') {
    $b = body_json();
    $playerId = resolve_player_id($pdo, $b);
    $qrPayload = trim((string) ($b['qr_payload_string'] ?? ''));
    if ($qrPayload === '') {
      fail('qr_payload_string requerido', 422);
    }

    $virusGame = get_or_create_virus_game($pdo);
    if ((int) $virusGame['is_active'] !== 1) {
      fail('Juego Virus inactivo', 403, 'GAME_INACTIVE');
    }

    $session = virus_get_active_session($pdo);
    if (!$session) {
      fail('Juego Virus inactivo', 403, 'GAME_INACTIVE');
    }

    $sessionId = (int) $session['id'];
    try {
      $parsed = virus_verify_payload_string($qrPayload, virus_secret($config));
    } catch (InvalidArgumentException $e) {
      $msg = $e->getMessage();
      $code = ($msg === 'QR expirado') ? 'QR_EXPIRED' : 'INVALID_QR';
      fail($msg, 422, $code);
    }

    if ((int) $parsed['session_id'] !== $sessionId) {
      fail('QR de otra sesión', 409, 'INVALID_QR');
    }

    $otherId = (int) $parsed['player_id'];
    if ($otherId === $playerId) {
      fail('No podés enfrentarte a vos mismo', 422, 'SELF_SCAN');
    }

    [$a, $bId] = virus_pair_ids($playerId, $otherId);

    $pdo->beginTransaction();

    $insInt = $pdo->prepare('INSERT INTO virus_interactions (session_id, player_a, player_b) VALUES (?, ?, ?)');
    try {
      $insInt->execute([$sessionId, $a, $bId]);
    } catch (PDOException $e) {
      if ((string) $e->getCode() === '23000') {
        $pdo->rollBack();
        $playersStmt = $pdo->prepare('SELECT id, display_name FROM players WHERE id IN (?, ?) ORDER BY id ASC');
        $playersStmt->execute([$playerId, $otherId]);
        $players = array_map(fn($row) => [
          'id' => (int) $row['id'],
          'handle' => (string) $row['display_name'],
        ], $playersStmt->fetchAll());
        fail(
          'Ya interactuaste con este jugador en esta sesión',
          409,
          'ALREADY_INTERACTED',
          virus_build_already_interacted_error($sessionId, $players)
        );
      }
      throw $e;
    }

    $stateStmt = $pdo->prepare(
      'SELECT vps.player_id, p.display_name AS handle, vps.role, vps.power
       FROM virus_player_state vps
       INNER JOIN players p ON p.id = vps.player_id
       WHERE vps.session_id = ? AND vps.player_id IN (?, ?)
       ORDER BY vps.player_id ASC
       FOR UPDATE'
    );
    $stateStmt->execute([$sessionId, $playerId, $otherId]);
    $states = $stateStmt->fetchAll();

    if (count($states) !== 2) {
      $pdo->rollBack();
      fail('Estados de jugadores no disponibles', 409);
    }

    $byPlayer = [];
    foreach ($states as $row) {
      $byPlayer[(int) $row['player_id']] = [
        'player_id' => (int) $row['player_id'],
        'handle' => (string) $row['handle'],
        'role' => (string) $row['role'],
        'power' => (int) $row['power'],
      ];
    }

    $combat = virus_resolve_combat($byPlayer[$playerId], $byPlayer[$otherId]);

    $up = $pdo->prepare('UPDATE virus_player_state SET power = ?, updated_at = NOW() WHERE session_id = ? AND player_id = ?');
    $up->execute([(int) $combat['post_state']['me']['power'], $sessionId, $playerId]);
    $up->execute([(int) $combat['post_state']['other']['power'], $sessionId, $otherId]);

    $pdo->commit();

    ok([
      'session_id' => $sessionId,
      'pre_state' => $combat['pre_state'],
      'post_state' => $combat['post_state'],
      'message' => $combat['message'],
      'winner_player_id' => $combat['winner_player_id'],
      'loser_player_id' => $combat['loser_player_id'],
      'draw' => $combat['draw'],
      'view_for_player_id' => $playerId,
      'view_result' => virus_player_view_result($combat, $playerId),
      'outcome_for_viewer' => virus_player_outcome_code($combat, $playerId),
      'matchup_type' => $combat['matchup_type'],
    ]);
  }

  if ($method === 'POST' && $action === 'admin_virus_toggle') {
    $b = body_json();
    $enabled = !empty($b['enabled']);

    ok(virus_set_enabled($pdo, $enabled));
  }

  if ($method === 'POST' && $action === 'admin_virus_reset_session') {
    $started = virus_start_session($pdo);
    $virusGame = get_or_create_virus_game($pdo);
    $up = $pdo->prepare('UPDATE games SET is_active = 1 WHERE id = ?');
    $up->execute([$virusGame['id']]);
    ok($started + ['enabled' => true]);
  }

  if ($method === 'GET' && $action === 'admin_virus_leaderboard') {
    $virusGame = get_or_create_virus_game($pdo);
    $active = virus_get_active_session($pdo);

    if ((int) $virusGame['is_active'] === 1 && $active) {
      $rows = virus_compute_leaderboard($pdo, (int) $active['id']);
      ok(['is_active' => true, 'session_id' => (int) $active['id'], 'rows' => $rows]);
    }

    $last = virus_get_last_session($pdo);
    if (!$last) {
      ok(['is_active' => (int) $virusGame['is_active'] === 1, 'session_id' => null, 'rows' => []]);
    }

    $snapshot = json_decode((string) ($last['leaderboard_snapshot_json'] ?? '[]'), true);
    if (!is_array($snapshot)) {
      $snapshot = [];
    }

    ok(['is_active' => (int) $virusGame['is_active'] === 1, 'session_id' => (int) $last['id'], 'rows' => $snapshot]);
  }

  fail('Not found', 404);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fail('Database error', 500);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  fail('Server error', 500);
}
