<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/error_logger.php';

$requestId = bin2hex(random_bytes(6));
init_error_logging([
  'context' => 'api',
  'request_id' => $requestId,
  'base_dir' => __DIR__,
]);

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
  global $requestId;
  echo json_encode(['ok' => true, 'request_id' => $requestId] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $status = 400, ?string $errorCode = null, array $extra = []): void {
  global $requestId;
  http_response_code($status);
  $payload = ['ok' => false, 'error' => $msg, 'request_id' => $requestId];
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
  $playerId = (int) ($payload['player_id'] ?? 0);
  if ($playerId > 0) {
    if (!player_exists($pdo, $playerId)) {
      fail('player_id requerido o token inválido', 401, 'PLAYER_REQUIRED');
    }

    return $playerId;
  }

  $token = trim((string) ($payload['player_token'] ?? ''));
  if ($token !== '') {
    if (strlen($token) < 24) {
      fail('player_id requerido o token inválido', 401, 'PLAYER_REQUIRED');
    }

    $player = find_player_by_token($pdo, $token);
    if (!$player) {
      fail('player_id requerido o token inválido', 401, 'PLAYER_REQUIRED');
    }

    return (int) $player['id'];
  }

  fail('player_id requerido o token inválido', 401, 'PLAYER_REQUIRED');
}

function resolve_player_id_from_body(PDO $pdo, array $payload): int {
  return resolve_player_id($pdo, $payload);
}

function resolve_game_mode(array $payload): string {
  $modeRaw = strtolower(trim((string) ($payload['mode'] ?? 'real')));
  if ($modeRaw === '') {
    $modeRaw = 'real';
  }

  if ($modeRaw !== 'practice' && $modeRaw !== 'real') {
    fail('mode inválido', 400, 'BAD_MODE');
  }

  return $modeRaw;
}

function has_real_play(PDO $pdo, int $playerId, int $gameId): bool {
  $stmt = $pdo->prepare('SELECT id FROM game_plays WHERE player_id = ? AND game_id = ? AND is_practice = 0 LIMIT 1');
  $stmt->execute([$playerId, $gameId]);
  return (bool) $stmt->fetchColumn();
}

function practice_play_count(PDO $pdo, int $playerId, int $gameId): int {
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM game_plays WHERE player_id = ? AND game_id = ? AND is_practice = 1');
  $stmt->execute([$playerId, $gameId]);
  return (int) $stmt->fetchColumn();
}

function can_play(PDO $pdo, int $playerId, int $gameId, string $mode): bool {
  if ($mode === 'practice') {
    return true;
  }

  return !has_real_play($pdo, $playerId, $gameId);
}

function is_debug_mode(array $config): bool {
  if (array_key_exists('debug', $config)) {
    return (bool) $config['debug'];
  }

  $envDebug = getenv('DEBUG');
  if ($envDebug !== false) {
    return in_array(strtolower((string) $envDebug), ['1', 'true', 'yes', 'on'], true);
  }

  return false;
}

function start_play(PDO $pdo, int $playerId, int $gameId, string $mode): int {
  $isPractice = ($mode === 'practice') ? 1 : 0;
  $stmt = $pdo->prepare('INSERT INTO game_plays (player_id, game_id, is_practice) VALUES (?, ?, ?)');
  $stmt->execute([$playerId, $gameId, $isPractice]);
  return (int) $pdo->lastInsertId();
}

function find_open_play(PDO $pdo, int $playerId, int $gameId, string $mode): ?array {
  $isPractice = ($mode === 'practice') ? 1 : 0;
  $stmt = $pdo->prepare(
    'SELECT id
     FROM game_plays
     WHERE player_id = ? AND game_id = ? AND is_practice = ? AND finished_at IS NULL
     ORDER BY id DESC
     LIMIT 1'
  );
  $stmt->execute([$playerId, $gameId, $isPractice]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function apply_points_if_real(PDO $pdo, int $playerId, int $gameId, string $mode, int $clicks, int $durationMs, int $score): void {
  if ($mode !== 'real') {
    return;
  }

  $note = sprintf('Sumador (clicks=%d)', $clicks);
  $ins = $pdo->prepare(
    "INSERT INTO score_events (player_id, event_type, game_id, attempts, duration_ms, points_delta, note)
     VALUES (?, 'GAME_RESULT', ?, ?, ?, ?, ?)"
  );
  $ins->execute([$playerId, $gameId, $clicks, $durationMs, $score, $note]);
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

function get_or_create_qr_scanner_game(PDO $pdo): array {
  $stmt = $pdo->prepare('SELECT id, is_active FROM games WHERE code = ? LIMIT 1');
  $stmt->execute(['qr_scanner']);
  $row = $stmt->fetch();

  if ($row) {
    return [
      'id' => (int) $row['id'],
      'is_active' => (int) $row['is_active'],
    ];
  }

  $ins = $pdo->prepare('INSERT INTO games (code, name, is_active, base_points) VALUES (?, ?, 1, 0)');
  $ins->execute(['qr_scanner', 'Escáner QR']);

  return [
    'id' => (int) $pdo->lastInsertId(),
    'is_active' => 1,
  ];
}

function get_or_create_luzverde_game(PDO $pdo): array {
  $stmt = $pdo->prepare('SELECT id, is_active FROM games WHERE code = ? LIMIT 1');
  $stmt->execute(['luzverde']);
  $row = $stmt->fetch();

  if ($row) {
    return [
      'id' => (int) $row['id'],
      'is_active' => (int) $row['is_active'],
    ];
  }

  $ins = $pdo->prepare('INSERT INTO games (code, name, is_active, base_points) VALUES (?, ?, 1, 10)');
  $ins->execute(['luzverde', 'Muévete Luz Verde']);

  return [
    'id' => (int) $pdo->lastInsertId(),
    'is_active' => 1,
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


function luzverde_threshold_for_level(int $level): float {
  $level = max(1, min(40, $level));
  $maxThreshold = 8.0;
  $minThreshold = 0.3;
  return $maxThreshold - (($level - 1) * (($maxThreshold - $minThreshold) / 39));
}

function luzverde_offline_timeout_seconds(): float {
  return 2.5;
}

function luzverde_get_alive_players(PDO $pdo, int $sessionId): array {
  $stmt = $pdo->prepare(
    'SELECT lp.player_id, p.display_name, p.public_code
     FROM luzverde_participants lp
     INNER JOIN players p ON p.id = lp.player_id
     WHERE lp.session_id = ? AND lp.armed = 1 AND lp.eliminated_at IS NULL
     ORDER BY lp.id ASC'
  );
  $stmt->execute([$sessionId]);
  return $stmt->fetchAll() ?: [];
}

function luzverde_eliminate_participant(PDO $pdo, int $sessionId, int $participantId, int $roundNo, string $reason, ?float $motionScore = null): void {
  $maxOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(eliminated_order), 0) FROM luzverde_participants WHERE session_id = ? FOR UPDATE');
  $maxOrderStmt->execute([$sessionId]);
  $nextOrder = (int) $maxOrderStmt->fetchColumn() + 1;

  $setMotionSql = $motionScore !== null ? ', last_motion_score = ?' : '';
  $sql =
    'UPDATE luzverde_participants
     SET eliminated_at = NOW(), eliminated_order = ?, eliminated_round = ?, eliminated_reason = ?' . $setMotionSql . '
     WHERE id = ?';
  $params = [$nextOrder, $roundNo, $reason];
  if ($motionScore !== null) {
    $params[] = $motionScore;
  }
  $params[] = $participantId;

  $elimStmt = $pdo->prepare($sql);
  $elimStmt->execute($params);
}

function luzverde_apply_round_threshold(PDO $pdo, array $sessionLocked): bool {
  $roundEliminated = (int) $sessionLocked['round_eliminated_count'] + 1;
  $aliveStart = (int) $sessionLocked['round_alive_start'];
  $target = $aliveStart >= 2 ? max(1, (int) floor($aliveStart / 2)) : 0;
  $shouldRest = $target > 0 && $roundEliminated >= $target;

  $updateSessionSql = 'UPDATE luzverde_sessions SET round_eliminated_count = ?, updated_at = NOW()';
  $params = [$roundEliminated];
  if ($shouldRest) {
    $updateSessionSql .= ', state = ?, rest_ends_at = DATE_ADD(NOW(), INTERVAL rest_seconds SECOND)';
    $params[] = 'REST';
  }
  $updateSessionSql .= ' WHERE id = ?';
  $params[] = (int) $sessionLocked['id'];

  $updSession = $pdo->prepare($updateSessionSql);
  $updSession->execute($params);

  return $shouldRest;
}

function luzverde_prune_offline(PDO $pdo, int $sessionId): int {
  $sessionLock = $pdo->prepare('SELECT * FROM luzverde_sessions WHERE id = ? LIMIT 1 FOR UPDATE');
  $sessionLock->execute([$sessionId]);
  $session = $sessionLock->fetch();
  if (!$session || (string) $session['state'] !== 'ACTIVE') {
    return 0;
  }

  $offlineStmt = $pdo->prepare(
    'SELECT id
     FROM luzverde_participants
     WHERE session_id = ?
       AND armed = 1
       AND eliminated_at IS NULL
       AND last_seen_at IS NOT NULL
       AND TIMESTAMPDIFF(MICROSECOND, last_seen_at, NOW()) > ?
     ORDER BY id ASC
     FOR UPDATE'
  );
  $offlineMicros = (int) round(luzverde_offline_timeout_seconds() * 1000000);
  $offlineStmt->execute([$sessionId, $offlineMicros]);
  $offlineRows = $offlineStmt->fetchAll() ?: [];

  $eliminated = 0;
  foreach ($offlineRows as $row) {
    luzverde_eliminate_participant($pdo, $sessionId, (int) $row['id'], (int) $session['round_no'], 'SENSOR_OFFLINE');
    $shouldRest = luzverde_apply_round_threshold($pdo, $session);
    $session['round_eliminated_count'] = (int) $session['round_eliminated_count'] + 1;
    $eliminated++;
    if ($shouldRest) {
      break;
    }
  }

  if ($eliminated > 0) {
    $aliveStmt = $pdo->prepare(
      'SELECT lp.player_id, p.display_name, p.public_code
       FROM luzverde_participants lp
       INNER JOIN players p ON p.id = lp.player_id
       WHERE lp.session_id = ? AND lp.armed = 1 AND lp.eliminated_at IS NULL
       ORDER BY lp.id ASC
       FOR UPDATE'
    );
    $aliveStmt->execute([$sessionId]);
    $alivePlayers = $aliveStmt->fetchAll() ?: [];
    if (count($alivePlayers) === 1) {
      luzverde_finish_session_with_winner($pdo, $session, $alivePlayers[0]);
    }
  }

  return $eliminated;
}

function luzverde_get_winner_name(PDO $pdo, int $sessionId): ?string {
  $alive = luzverde_get_alive_players($pdo, $sessionId);
  if (count($alive) === 1) {
    return (string) $alive[0]['display_name'];
  }
  return null;
}

function luzverde_finish_session_with_winner(PDO $pdo, array $session, array $winner): void {
  $sessionId = (int) $session['id'];
  $winnerPlayerId = (int) $winner['player_id'];
  $winnerName = (string) $winner['display_name'];
  $basePoints = (int) $session['base_points'];

  $update = $pdo->prepare(
    "UPDATE luzverde_sessions
     SET state = 'FINISHED', round_eliminated_count = 0, rest_ends_at = NULL, updated_at = NOW()
     WHERE id = ?"
  );
  $update->execute([$sessionId]);

  $game = get_or_create_luzverde_game($pdo);
  $note = sprintf('Muévete Luz Verde: Ganador automático (%s)', $winnerName);
  $scoreIns = $pdo->prepare(
    "INSERT INTO score_events (player_id, event_type, game_id, points_delta, note)
     VALUES (?, 'GAME_RESULT', ?, ?, ?)"
  );
  $scoreIns->execute([$winnerPlayerId, (int) $game['id'], $basePoints, $note]);
}

function luzverde_get_active_session(PDO $pdo): ?array {
  $stmt = $pdo->query('SELECT * FROM luzverde_sessions WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
  $row = $stmt->fetch();
  return $row ?: null;
}

function luzverde_get_session_with_counts(PDO $pdo, int $sessionId): array {
  $sessionStmt = $pdo->prepare('SELECT * FROM luzverde_sessions WHERE id = ? LIMIT 1');
  $sessionStmt->execute([$sessionId]);
  $session = $sessionStmt->fetch();
  if (!$session) {
    fail('Sesión no encontrada', 404, 'SESSION_NOT_FOUND');
  }

  $countsStmt = $pdo->prepare(
    'SELECT SUM(CASE WHEN armed = 1 THEN 1 ELSE 0 END) AS total,
            SUM(CASE WHEN armed = 1 AND eliminated_at IS NULL THEN 1 ELSE 0 END) AS alive,
            SUM(CASE WHEN armed = 1 AND eliminated_at IS NOT NULL THEN 1 ELSE 0 END) AS eliminated,
            SUM(CASE WHEN armed = 0 THEN 1 ELSE 0 END) AS not_ready
     FROM luzverde_participants
     WHERE session_id = ?'
  );
  $countsStmt->execute([$sessionId]);
  $counts = $countsStmt->fetch() ?: ['total' => 0, 'alive' => 0, 'eliminated' => 0];

  return [
    'session' => $session,
    'counts' => [
      'total' => (int) ($counts['total'] ?? 0),
      'alive' => (int) ($counts['alive'] ?? 0),
      'eliminated' => (int) ($counts['eliminated'] ?? 0),
      'not_ready' => (int) ($counts['not_ready'] ?? 0),
    ],
  ];
}

function luzverde_build_status_message(?array $session, ?array $me): string {
  if (!$session) {
    return 'Esperando que el host cree una sesión.';
  }

  $state = (string) ($session['state'] ?? 'WAITING');
  if ($state === 'WAITING') {
    return 'Esperando que arranque la ronda...';
  }
  if ($state === 'ACTIVE') {
    if ($me && $me['eliminated_at'] !== null) {
      return 'Eliminado. Esperá la próxima ronda.';
    }
    if ($me && (int) ($me['armed'] ?? 0) !== 1) {
      return 'NO LISTO: habilitá sensores para participar.';
    }
    return 'EN JUEGO – QUEDATE QUIETO';
  }
  if ($state === 'REST') {
    return 'Descanso entre rondas.';
  }
  if ($state === 'FINISHED') {
    return 'Juego terminado.';
  }

  return 'Estado actualizado.';
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

function build_qr_url(string $code): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = (string) ($_SERVER['HTTP_HOST'] ?? 'pcn.com.ar');
  $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
  $scriptDir = rtrim($scriptDir, '/');
  if (str_ends_with($scriptDir, '/admin')) {
    $scriptDir = substr($scriptDir, 0, -6);
  }
  $basePath = ($scriptDir === '' || $scriptDir === '.') ? '' : $scriptDir;
  return sprintf('%s://%s%s/qr.html?code=%s', $scheme, $host, $basePath, rawurlencode($code));
}

function generate_qr_code_value(PDO $pdo, string $prefix = '', int $length = 8): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $max = strlen($alphabet) - 1;
  $cleanPrefix = strtoupper(preg_replace('/[^A-Z2-9]/', '', $prefix) ?? '');

  for ($attempt = 0; $attempt < 50; $attempt++) {
    $code = $cleanPrefix;
    for ($i = 0; $i < $length; $i++) {
      $code .= $alphabet[random_int(0, $max)];
    }

    $stmt = $pdo->prepare('SELECT id FROM qr_codes WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    if (!$stmt->fetchColumn()) {
      return $code;
    }
  }

  fail('No se pudo generar QR único', 500);
}

function is_unique_violation(Throwable $e): bool {
  if (!$e instanceof PDOException) {
    return false;
  }

  $sqlState = (string) ($e->errorInfo[0] ?? '');
  $mysqlCode = (int) ($e->errorInfo[1] ?? 0);
  return $sqlState === '23000' || $mysqlCode === 1062;
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
  if ($dbName === '') {
    $cache[$key] = false;
    return false;
  }

  $stmt = $pdo->prepare(
    'SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
     LIMIT 1'
  );
  $stmt->execute([$dbName, $table, $column]);
  $cache[$key] = (bool) $stmt->fetchColumn();

  return $cache[$key];
}

function db_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }

  $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
  if ($dbName === '') {
    $cache[$table] = false;
    return false;
  }

  $stmt = $pdo->prepare(
    'SELECT 1
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
     LIMIT 1'
  );
  $stmt->execute([$dbName, $table]);
  $cache[$table] = (bool) $stmt->fetchColumn();

  return $cache[$table];
}

function admin_is_authorized(array $config): bool {
  $token = auth_bearer_token();
  return $token !== '' && hash_equals((string) $config['admin_token'], $token);
}

function error_log_file_path(): string {
  return error_logger_file_path(__DIR__);
}

function read_log_tail(string $file, int $lines = 200): array {
  if (!is_file($file)) {
    return [];
  }

  $lines = max(1, min(1000, $lines));
  $fp = fopen($file, 'rb');
  if ($fp === false) {
    return [];
  }

  $buffer = '';
  $chunkSize = 8192;
  fseek($fp, 0, SEEK_END);
  $position = ftell($fp);
  $lineCount = 0;

  while ($position > 0 && $lineCount <= $lines) {
    $readSize = min($chunkSize, $position);
    $position -= $readSize;
    fseek($fp, $position);
    $chunk = fread($fp, $readSize);
    if ($chunk === false) {
      break;
    }
    $buffer = $chunk . $buffer;
    $lineCount = substr_count($buffer, "\n");
  }

  fclose($fp);

  $rawLines = preg_split('/\r\n|\n|\r/', trim($buffer));
  if (!is_array($rawLines)) {
    return [];
  }

  $tail = array_slice(array_values(array_filter($rawLines, static fn($v) => $v !== '')), -$lines);
  $rows = [];
  foreach ($tail as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
      $rows[] = $decoded;
    }
  }
  return $rows;
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
  'qr_claim',
  'public_leaderboard_top',
  'public_players_active',
  'luzverde_join',
  'luzverde_status',
  'luzverde_motion',
  'luzverde_heartbeat',
];

if (!in_array($action, $publicActions, true) && !admin_is_authorized($config)) {
  fail('Unauthorized', 401);
}

$db = $config['db'];
$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

try {
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  error_logger_set_pdo($pdo);
} catch (PDOException $e) {
  fail('Database connection error', 500);
}

get_or_create_virus_game($pdo);
get_or_create_qr_scanner_game($pdo);
get_or_create_luzverde_game($pdo);

try {
  if ($method === 'GET' && $action === 'ping') {
    ok(['msg' => 'pong']);
  }

  if ($method === 'GET' && $action === 'public_leaderboard_top') {
    $stmt = $pdo->query(
      "SELECT p.id AS player_id,
              p.display_name,
              p.public_code,
              COALESCE(tp.total_points, 0) AS total_points,
              le.created_at AS last_event_created_at,
              le.game_code AS last_event_game_code,
              le.event_type AS last_event_event_type,
              le.points_delta AS last_event_points_delta,
              le.note AS last_event_note
       FROM players p
       LEFT JOIN (
         SELECT se.player_id, SUM(se.points_delta) AS total_points
         FROM score_events se
         GROUP BY se.player_id
       ) tp ON tp.player_id = p.id
       LEFT JOIN (
         SELECT se1.player_id,
                se1.created_at,
                g.code AS game_code,
                se1.event_type,
                se1.points_delta,
                se1.note
         FROM score_events se1
         INNER JOIN (
           SELECT player_id, MAX(id) AS max_id
           FROM score_events
           GROUP BY player_id
         ) last_se ON last_se.player_id = se1.player_id AND last_se.max_id = se1.id
         LEFT JOIN games g ON g.id = se1.game_id
       ) le ON le.player_id = p.id
       ORDER BY total_points DESC, le.created_at DESC, p.id ASC
       LIMIT 15"
    );

    $rows = array_map(static function (array $row): array {
      $hasLastEvent = $row['last_event_created_at'] !== null;

      return [
        'player_id' => (int) $row['player_id'],
        'display_name' => (string) $row['display_name'],
        'public_code' => (string) $row['public_code'],
        'total_points' => (int) $row['total_points'],
        'last_event' => $hasLastEvent ? [
          'created_at' => (string) $row['last_event_created_at'],
          'game_code' => $row['last_event_game_code'] !== null ? (string) $row['last_event_game_code'] : null,
          'event_type' => $row['last_event_event_type'] !== null ? (string) $row['last_event_event_type'] : null,
          'points_delta' => $row['last_event_points_delta'] !== null ? (int) $row['last_event_points_delta'] : null,
          'note' => $row['last_event_note'] !== null ? (string) $row['last_event_note'] : null,
        ] : null,
      ];
    }, $stmt->fetchAll());

    ok([
      'generated_at' => date('Y-m-d H:i:s'),
      'rows' => $rows,
    ]);
  }

  if ($method === 'GET' && $action === 'public_players_active') {
    $windowHours = isset($_GET['hours']) ? (int) $_GET['hours'] : 6;
    if ($windowHours <= 0) {
      $windowHours = 6;
    }
    if ($windowHours > 168) {
      $windowHours = 168;
    }

    $cutoff = (new DateTimeImmutable('now'))
      ->modify('-' . $windowHours . ' hours')
      ->format('Y-m-d H:i:s');

    $hasGamePlays = db_table_exists($pdo, 'game_plays');
    $hasPlayersIsActive = db_has_column($pdo, 'players', 'is_active');
    $scoreEventsTimeColumn = db_has_column($pdo, 'score_events', 'created_at') ? 'created_at' : null;
    $gamePlaysTimeColumn = null;
    if ($hasGamePlays) {
      if (db_has_column($pdo, 'game_plays', 'created_at')) {
        $gamePlaysTimeColumn = 'created_at';
      } elseif (db_has_column($pdo, 'game_plays', 'started_at')) {
        $gamePlaysTimeColumn = 'started_at';
      }
    }

    $activeIds = [];
    $activityParts = [];
    $activityParams = [];
    if ($scoreEventsTimeColumn !== null) {
      $activityParts[] = "SELECT se.player_id FROM score_events se WHERE se.$scoreEventsTimeColumn >= ?";
      $activityParams[] = $cutoff;
    }
    if ($hasGamePlays && $gamePlaysTimeColumn !== null) {
      $activityParts[] = "SELECT gp.player_id FROM game_plays gp WHERE gp.$gamePlaysTimeColumn >= ?";
      $activityParams[] = $cutoff;
    }

    if (!empty($activityParts)) {
      $activitySql = "SELECT DISTINCT player_id FROM (" . implode(' UNION ', $activityParts) . ') active_ids';
      $activityStmt = $pdo->prepare($activitySql);
      $activityStmt->execute($activityParams);
      $activeIds = array_map('intval', $activityStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $activeRows = [];
    $playersIsActiveFilter = $hasPlayersIsActive ? 'p.is_active = 1' : '1=1';

    if (!empty($activeIds)) {
      $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
      $activePlayersStmt = $pdo->prepare(
        "SELECT p.id AS player_id,
                p.display_name,
                COALESCE(tp.total_points, 0) AS total_points
         FROM players p
         LEFT JOIN (
           SELECT se.player_id, SUM(se.points_delta) AS total_points
           FROM score_events se
           GROUP BY se.player_id
         ) tp ON tp.player_id = p.id
         WHERE p.id IN ($placeholders)
           AND $playersIsActiveFilter
         ORDER BY p.id ASC"
      );
      $activePlayersStmt->execute($activeIds);
      $activeRows = $activePlayersStmt->fetchAll();
    }

    if (empty($activeRows)) {
      $fallbackStmt = $pdo->query(
        "SELECT p.id AS player_id,
                p.display_name,
                COALESCE(tp.total_points, 0) AS total_points
         FROM players p
         LEFT JOIN (
           SELECT se.player_id, SUM(se.points_delta) AS total_points
           FROM score_events se
           GROUP BY se.player_id
         ) tp ON tp.player_id = p.id
         WHERE $playersIsActiveFilter
         ORDER BY p.id ASC"
      );
      $activeRows = $fallbackStmt->fetchAll();
    }

    $rows = array_map(static function (array $row): array {
      return [
        'player_id' => (int) $row['player_id'],
        'display_name' => (string) $row['display_name'],
        'total_points' => (int) $row['total_points'],
      ];
    }, $activeRows);

    ok(['rows' => $rows]);
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

    $alreadyPlayed = has_real_play($pdo, $playerId, $game['id']);
    $practiceCount = practice_play_count($pdo, $playerId, $game['id']);

    ok([
      'player_id' => $playerId,
      'display_name' => (string) $player['display_name'],
      'sumador_played_real' => $alreadyPlayed,
      'sumador_practice_count' => $practiceCount,
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
       LEFT JOIN qr_codes q ON q.id = se.secret_qr_id
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

    $st2 = $pdo->prepare('DELETE FROM qr_claims WHERE player_id = ?');
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

  if (($method === 'GET' || $method === 'POST') && $action === 'qr_claim') {
    $payload = ($method === 'POST') ? body_json() : $_GET;
    $playerId = resolve_player_id($pdo, $payload);

    $enabled = setting_get($pdo, 'scoring_enabled', '1');
    if ($enabled !== '1') {
      fail('El puntaje está pausado', 403, 'SCORING_DISABLED');
    }

    $code = strtoupper(trim((string) ($payload['code'] ?? '')));
    if ($code === '') {
      fail('code requerido', 422, 'CODE_REQUIRED');
    }

    $qrStmt = $pdo->prepare('SELECT id, code, qr_type, game_code, points_delta, is_active FROM qr_codes WHERE code = ? LIMIT 1');
    $qrStmt->execute([$code]);
    $qr = $qrStmt->fetch();
    if (!$qr) {
      fail('QR no encontrado', 404, 'QR_NOT_FOUND');
    }
    if ((int) $qr['is_active'] !== 1) {
      fail('QR inactivo', 403, 'QR_INACTIVE');
    }

    $qrId = (int) $qr['id'];

    $pdo->beginTransaction();
    try {
      $qrLockStmt = $pdo->prepare('SELECT id, code, qr_type, game_code, points_delta, is_active FROM qr_codes WHERE id = ? LIMIT 1 FOR UPDATE');
      $qrLockStmt->execute([$qrId]);
      $lockedQr = $qrLockStmt->fetch();

      if (!$lockedQr) {
        throw new RuntimeException('QR no encontrado durante lock');
      }
      if ((int) $lockedQr['is_active'] !== 1) {
        $pdo->rollBack();
        fail('QR inactivo', 403, 'QR_INACTIVE');
      }

      $claimStmt = $pdo->prepare('INSERT INTO qr_claims (qr_id, player_id) VALUES (?, ?)');
      try {
        $claimStmt->execute([$qrId, $playerId]);
      } catch (Throwable $e) {
        if (is_unique_violation($e)) {
          $pdo->rollBack();
          fail('Ya canjeaste este QR.', 409, 'ALREADY_CLAIMED');
        }
        throw $e;
      }

      $qrType = (string) ($lockedQr['qr_type'] ?? '');
      if ($qrType === 'secret') {
        $multiplier = (int) setting_get($pdo, 'qr_multiplier', '1');
        if ($multiplier < 1) {
          $multiplier = 1;
        }

        $appliedPoints = (int) $lockedQr['points_delta'] * $multiplier;
        $idempotencyKey = 'qr:' . $qrId . ':' . $playerId;
        $note = 'QR secreto (code=' . (string) $lockedQr['code'] . ')';

        $scoreStmt = $pdo->prepare("INSERT INTO score_events (player_id, event_type, points_delta, secret_qr_id, note, idempotency_key) VALUES (?, 'QR_SECRET', ?, ?, ?, ?)");
        try {
          $scoreStmt->execute([$playerId, $appliedPoints, $qrId, $note, $idempotencyKey]);
        } catch (Throwable $e) {
          if (is_unique_violation($e)) {
            $pdo->rollBack();
            fail('Ya canjeaste este QR.', 409, 'ALREADY_CLAIMED');
          }
          throw $e;
        }

        $pdo->commit();
        ok([
          'qr_type' => 'secret',
          'applied_points' => $appliedPoints,
        ]);
      }

      if ($qrType === 'game') {
        $gameCode = (string) ($lockedQr['game_code'] ?? '');
        if ($gameCode === '') {
          $pdo->rollBack();
          fail('Juego no encontrado', 404, 'GAME_NOT_FOUND');
        }

        $gameStmt = $pdo->prepare('SELECT id, is_active FROM games WHERE code = ? LIMIT 1');
        $gameStmt->execute([$gameCode]);
        $game = $gameStmt->fetch();
        if (!$game) {
          $pdo->rollBack();
          fail('Juego no encontrado', 404, 'GAME_NOT_FOUND');
        }
        if ((int) $game['is_active'] !== 1) {
          $pdo->rollBack();
          fail('Ese juego está apagado', 403, 'GAME_INACTIVE');
        }

        $redirectUrl = '/123/admin/' . rawurlencode($gameCode) . '.html';

        $pdo->commit();
        ok([
          'qr_type' => 'game',
          'redirect_url' => $redirectUrl,
        ]);
      }

      $pdo->rollBack();
      fail('Tipo de QR inválido', 422, 'INVALID_QR_TYPE');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  if ($method === 'POST' && $action === 'admin_qr_create') {
    $b = body_json();
    $qrType = (string) ($b['qr_type'] ?? '');
    if ($qrType !== 'secret' && $qrType !== 'game') {
      fail('qr_type inválido', 422);
    }

    $count = (int) ($b['count'] ?? 1);
    if ($count < 1 || $count > 50) {
      fail('count debe ser 1..50', 422);
    }

    $pointsDelta = (int) ($b['points_delta'] ?? 0);
    $gameCode = trim((string) ($b['game_code'] ?? ''));
    if ($qrType === 'game' && $gameCode === '') {
      fail('game_code requerido', 422);
    }

    if ($qrType === 'game') {
      $gameCheck = $pdo->prepare('SELECT id FROM games WHERE code = ? LIMIT 1');
      $gameCheck->execute([$gameCode]);
      if (!$gameCheck->fetchColumn()) {
        fail('game_code inexistente', 404);
      }
    }

    $prefix = trim((string) ($b['prefix'] ?? ''));
    $ins = $pdo->prepare('INSERT INTO qr_codes (code, qr_type, game_code, points_delta, is_active) VALUES (?, ?, ?, ?, 1)');
    $created = [];

    for ($i = 0; $i < $count; $i++) {
      $code = generate_qr_code_value($pdo, $prefix);
      $ins->execute([$code, $qrType, $gameCode !== '' ? $gameCode : null, $pointsDelta]);
      $created[] = [
        'id' => (int) $pdo->lastInsertId(),
        'code' => $code,
        'url' => build_qr_url($code),
        'qr_type' => $qrType,
        'game_code' => $gameCode !== '' ? $gameCode : null,
        'points_delta' => $pointsDelta,
      ];
    }

    ok(['rows' => $created]);
  }

  if ($method === 'GET' && $action === 'admin_qr_list') {
    $limit = (int) ($_GET['limit'] ?? 100);
    if ($limit < 1 || $limit > 300) {
      $limit = 100;
    }
    $stmt = $pdo->prepare('SELECT id, code, qr_type, game_code, points_delta, is_active, created_at FROM qr_codes ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
      $row['url'] = build_qr_url((string) $row['code']);
    }
    ok(['rows' => $rows]);
  }

  if ($method === 'POST' && $action === 'admin_qr_toggle') {
    $b = body_json();
    $id = (int) ($b['id'] ?? 0);
    $isActive = !empty($b['is_active']) ? 1 : 0;
    if ($id <= 0) {
      fail('id requerido', 422);
    }
    $stmt = $pdo->prepare('UPDATE qr_codes SET is_active = ? WHERE id = ?');
    $stmt->execute([$isActive, $id]);
    ok(['updated' => $stmt->rowCount()]);
  }

  if ($method === 'POST' && $action === 'admin_qr_claims_cleanup') {
    $b = body_json();
    $minutes = (int) ($b['older_than_minutes'] ?? 10);
    if ($minutes < 1) {
      $minutes = 1;
    }

    $mode = strtolower(trim((string) ($b['mode'] ?? 'mark_failed')));
    if ($mode !== 'delete' && $mode !== 'mark_failed') {
      fail('mode inválido', 422, 'INVALID_MODE');
    }

    if (!db_has_column($pdo, 'qr_claims', 'status')) {
      fail('La tabla qr_claims no tiene columna status. Ejecutá migración primero.', 422, 'MIGRATION_REQUIRED');
    }

    $hasError = db_has_column($pdo, 'qr_claims', 'error');
    $hasAppliedAt = db_has_column($pdo, 'qr_claims', 'applied_at');
    $thresholdExpr = 'DATE_SUB(NOW(), INTERVAL ? MINUTE)';

    if ($mode === 'delete') {
      $stmt = $pdo->prepare('DELETE FROM qr_claims WHERE status = ? AND applied_points = 0 AND claimed_at < ' . $thresholdExpr);
      $stmt->execute(['pending', $minutes]);
      ok(['mode' => $mode, 'older_than_minutes' => $minutes, 'affected' => $stmt->rowCount()]);
    }

    $updates = ['status = ?'];
    $params = ['failed'];
    if ($hasError) {
      $updates[] = '`error` = ?';
      $params[] = 'Auto cleanup: pending expirado';
    }
    if ($hasAppliedAt) {
      $updates[] = 'applied_at = NULL';
    }
    $params[] = 'pending';
    $params[] = $minutes;

    $stmt = $pdo->prepare('UPDATE qr_claims SET ' . implode(', ', $updates) . ' WHERE status = ? AND applied_points = 0 AND claimed_at < ' . $thresholdExpr);
    $stmt->execute($params);
    ok(['mode' => $mode, 'older_than_minutes' => $minutes, 'affected' => $stmt->rowCount()]);
  }


  if ($method === 'POST' && $action === 'luzverde_join') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);
    $active = luzverde_get_active_session($pdo);

    if (!$active || (string) $active['state'] === 'FINISHED') {
      fail('No hay sesión activa de Luz Verde', 409, 'NO_ACTIVE_SESSION');
    }

    $ins = $pdo->prepare(
      'INSERT INTO luzverde_participants (session_id, player_id, joined_at)
       VALUES (?, ?, NOW())
       ON DUPLICATE KEY UPDATE joined_at = joined_at'
    );
    $ins->execute([(int) $active['id'], $playerId]);

    $meStmt = $pdo->prepare('SELECT * FROM luzverde_participants WHERE session_id = ? AND player_id = ? LIMIT 1');
    $meStmt->execute([(int) $active['id'], $playerId]);
    $me = $meStmt->fetch();

    ok([
      'session' => [
        'id' => (int) $active['id'],
        'state' => (string) $active['state'],
        'round_no' => (int) $active['round_no'],
        'rest_ends_at' => $active['rest_ends_at'],
        'sensitivity_level' => (int) $active['sensitivity_level'],
        'base_points' => (int) $active['base_points'],
      ],
      'me' => [
        'status' => ($me && $me['eliminated_at'] === null) ? 'alive' : 'eliminated',
        'armed' => $me ? ((int) ($me['armed'] ?? 0) === 1) : false,
        'eliminated_order' => $me ? ($me['eliminated_order'] !== null ? (int) $me['eliminated_order'] : null) : null,
        'eliminated_round' => $me ? ($me['eliminated_round'] !== null ? (int) $me['eliminated_round'] : null) : null,
        'eliminated_reason' => $me['eliminated_reason'] ?? null,
      ],
      'message' => luzverde_build_status_message($active, $me ?: null),
    ]);
  }

  if (($method === 'GET' || $method === 'POST') && $action === 'luzverde_status') {
    $payload = ($method === 'POST') ? body_json() : $_GET;
    $playerId = resolve_player_id($pdo, $payload);
    $active = luzverde_get_active_session($pdo);

    if (!$active) {
      ok([
        'session' => null,
        'me' => ['status' => 'alive', 'armed' => false, 'eliminated_order' => null, 'eliminated_round' => null, 'eliminated_reason' => null],
        'message' => luzverde_build_status_message(null, null),
      ]);
    }

    $meStmt = $pdo->prepare('SELECT * FROM luzverde_participants WHERE session_id = ? AND player_id = ? LIMIT 1');
    $meStmt->execute([(int) $active['id'], $playerId]);
    $me = $meStmt->fetch();

    $winnerName = luzverde_get_winner_name($pdo, (int) $active['id']);

    ok([
      'session' => [
        'id' => (int) $active['id'],
        'state' => (string) $active['state'],
        'round_no' => (int) $active['round_no'],
        'rest_ends_at' => $active['rest_ends_at'],
        'sensitivity_level' => (int) $active['sensitivity_level'],
        'base_points' => (int) $active['base_points'],
        'winner_name' => $winnerName,
      ],
      'me' => [
        'status' => ($me && $me['eliminated_at'] === null) ? 'alive' : 'eliminated',
        'armed' => $me ? ((int) ($me['armed'] ?? 0) === 1) : false,
        'eliminated_order' => $me && $me['eliminated_order'] !== null ? (int) $me['eliminated_order'] : null,
        'eliminated_round' => $me && $me['eliminated_round'] !== null ? (int) $me['eliminated_round'] : null,
        'eliminated_reason' => $me['eliminated_reason'] ?? null,
      ],
      'message' => luzverde_build_status_message($active, $me ?: null),
    ]);
  }

  if ($method === 'POST' && $action === 'luzverde_heartbeat') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);
    $sensorOk = !array_key_exists('sensor_ok', $b) || (bool) $b['sensor_ok'];

    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      ok(['ignored' => true, 'reason' => 'NO_ACTIVE_SESSION']);
    }

    $sessionId = (int) $active['id'];
    $participantStmt = $pdo->prepare('SELECT * FROM luzverde_participants WHERE session_id = ? AND player_id = ? LIMIT 1');
    $participantStmt->execute([$sessionId, $playerId]);
    $participant = $participantStmt->fetch();
    if (!$participant) {
      ok(['ignored' => true, 'reason' => 'NOT_JOINED']);
    }

    $setArmedSql = '';
    if ((int) ($participant['armed'] ?? 0) !== 1 && $sensorOk) {
      $setArmedSql = ', armed = 1, armed_at = NOW()';
    }
    $hbUpdate = $pdo->prepare('UPDATE luzverde_participants SET last_seen_at = NOW()' . $setArmedSql . ' WHERE id = ?');
    $hbUpdate->execute([(int) $participant['id']]);

    $offlineEliminated = 0;
    $pdo->beginTransaction();
    $offlineEliminated = luzverde_prune_offline($pdo, $sessionId);
    $pdo->commit();

    $meStmt = $pdo->prepare('SELECT * FROM luzverde_participants WHERE session_id = ? AND player_id = ? LIMIT 1');
    $meStmt->execute([$sessionId, $playerId]);
    $me = $meStmt->fetch();

    ok([
      'armed' => $me ? ((int) ($me['armed'] ?? 0) === 1) : false,
      'session_state' => (string) $active['state'],
      'me_status' => ($me && $me['eliminated_at'] === null) ? 'alive' : 'eliminated',
      'offline_eliminated' => $offlineEliminated,
    ]);
  }

  if ($method === 'POST' && $action === 'luzverde_motion') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);
    $motionScore = (float) ($b['motion_score'] ?? 0);

    $active = luzverde_get_active_session($pdo);
    if (!$active || (string) $active['state'] !== 'ACTIVE') {
      ok(['ignored' => true, 'reason' => 'NOT_ACTIVE']);
    }

    $sessionId = (int) $active['id'];
    $participantStmt = $pdo->prepare('SELECT * FROM luzverde_participants WHERE session_id = ? AND player_id = ? LIMIT 1');
    $participantStmt->execute([$sessionId, $playerId]);
    $participant = $participantStmt->fetch();
    if (!$participant) {
      ok(['ignored' => true, 'reason' => 'NOT_JOINED']);
    }

    $setArmedSql = ((int) ($participant['armed'] ?? 0) !== 1) ? ', armed = 1, armed_at = NOW()' : '';
    $upMotion = $pdo->prepare('UPDATE luzverde_participants SET last_motion_score = ?, last_seen_at = NOW()' . $setArmedSql . ' WHERE id = ?');
    $upMotion->execute([$motionScore, (int) $participant['id']]);

    if ($participant['eliminated_at'] !== null) {
      $pdo->beginTransaction();
      $offlineEliminated = luzverde_prune_offline($pdo, $sessionId);
      $pdo->commit();
      ok(['ignored' => true, 'reason' => 'ALREADY_ELIMINATED', 'offline_eliminated' => $offlineEliminated]);
    }

    $threshold = luzverde_threshold_for_level((int) $active['sensitivity_level']);
    if ($motionScore <= $threshold) {
      $pdo->beginTransaction();
      $offlineEliminated = luzverde_prune_offline($pdo, $sessionId);
      $pdo->commit();
      ok(['ignored' => true, 'threshold' => $threshold, 'offline_eliminated' => $offlineEliminated]);
    }

    $pdo->beginTransaction();

    $sessionLock = $pdo->prepare('SELECT * FROM luzverde_sessions WHERE id = ? LIMIT 1 FOR UPDATE');
    $sessionLock->execute([$sessionId]);
    $sessionLocked = $sessionLock->fetch();
    if (!$sessionLocked || (string) $sessionLocked['state'] !== 'ACTIVE') {
      $pdo->rollBack();
      ok(['ignored' => true, 'reason' => 'ROUND_FINISHED']);
    }

    $partLock = $pdo->prepare('SELECT * FROM luzverde_participants WHERE session_id = ? AND player_id = ? LIMIT 1 FOR UPDATE');
    $partLock->execute([$sessionId, $playerId]);
    $participantLocked = $partLock->fetch();
    if (!$participantLocked || $participantLocked['eliminated_at'] !== null) {
      $pdo->rollBack();
      ok(['ignored' => true, 'reason' => 'ALREADY_ELIMINATED']);
    }

    luzverde_eliminate_participant($pdo, $sessionId, (int) $participantLocked['id'], (int) $sessionLocked['round_no'], 'MOTION', $motionScore);

    $aliveStmt = $pdo->prepare(
      'SELECT lp.player_id, p.display_name, p.public_code
       FROM luzverde_participants lp
       INNER JOIN players p ON p.id = lp.player_id
       WHERE lp.session_id = ? AND lp.armed = 1 AND lp.eliminated_at IS NULL
       ORDER BY lp.id ASC
       FOR UPDATE'
    );
    $aliveStmt->execute([$sessionId]);
    $alivePlayers = $aliveStmt->fetchAll() ?: [];

    if (count($alivePlayers) === 1) {
      luzverde_finish_session_with_winner($pdo, $sessionLocked, $alivePlayers[0]);
      $pdo->commit();
      ok([
        'eliminated' => true,
        'threshold' => $threshold,
        'auto_finished' => true,
        'winner_name' => (string) $alivePlayers[0]['display_name'],
      ]);
    }

    $shouldRest = luzverde_apply_round_threshold($pdo, $sessionLocked);
    $offlineEliminated = luzverde_prune_offline($pdo, $sessionId);

    $pdo->commit();

    ok([
      'eliminated' => true,
      'threshold' => $threshold,
      'auto_rest' => $shouldRest,
      'auto_finished' => false,
      'offline_eliminated' => $offlineEliminated,
    ]);
  }

  if ($method === 'GET' && $action === 'admin_luzverde_state') {
    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      ok(['session' => null, 'participants' => [], 'totals' => ['total' => 0, 'alive' => 0, 'eliminated' => 0, 'not_ready' => 0]]);
    }

    $sessionId = (int) $active['id'];
    $pdo->beginTransaction();
    $offlineEliminated = luzverde_prune_offline($pdo, $sessionId);
    $pdo->commit();

    $active = luzverde_get_active_session($pdo);
    $participantsStmt = $pdo->prepare(
      'SELECT lp.player_id, p.display_name, p.public_code, lp.armed, lp.last_seen_at, lp.eliminated_at, lp.eliminated_reason, lp.eliminated_order, lp.eliminated_round, lp.last_motion_score
       FROM luzverde_participants lp
       INNER JOIN players p ON p.id = lp.player_id
       WHERE lp.session_id = ?
       ORDER BY (lp.armed = 1 AND lp.eliminated_at IS NULL) DESC, lp.armed DESC, lp.eliminated_order ASC, p.display_name ASC'
    );
    $participantsStmt->execute([$sessionId]);
    $participants = $participantsStmt->fetchAll();

    $counts = luzverde_get_session_with_counts($pdo, $sessionId)['counts'];

    $eliminatedThisRoundStmt = $pdo->prepare(
      'SELECT p.display_name, p.public_code
       FROM luzverde_participants lp
       INNER JOIN players p ON p.id = lp.player_id
       WHERE lp.session_id = ? AND lp.eliminated_round = ?
       ORDER BY lp.eliminated_order ASC'
    );
    $eliminatedThisRoundStmt->execute([$sessionId, (int) $active['round_no']]);

    $survivorsStmt = $pdo->prepare(
      'SELECT p.display_name, p.public_code
       FROM luzverde_participants lp
       INNER JOIN players p ON p.id = lp.player_id
       WHERE lp.session_id = ? AND lp.armed = 1 AND lp.eliminated_at IS NULL
       ORDER BY p.display_name ASC'
    );
    $survivorsStmt->execute([$sessionId]);

    ok([
      'session' => [
        'id' => $sessionId,
        'state' => (string) $active['state'],
        'round_no' => (int) $active['round_no'],
        'sensitivity_level' => (int) $active['sensitivity_level'],
        'base_points' => (int) $active['base_points'],
        'rest_seconds' => (int) $active['rest_seconds'],
        'rest_ends_at' => $active['rest_ends_at'],
        'round_alive_start' => (int) $active['round_alive_start'],
        'round_eliminated_count' => (int) $active['round_eliminated_count'],
      ],
      'participants' => $participants,
      'totals' => $counts,
      'offline_eliminated' => $offlineEliminated,
      'eliminated_this_round' => $eliminatedThisRoundStmt->fetchAll(),
      'survivors' => $survivorsStmt->fetchAll(),
    ]);
  }

  if ($method === 'POST' && $action === 'admin_luzverde_update_config') {
    $b = body_json();
    $sensitivity = max(1, min(40, (int) ($b['sensitivity_level'] ?? 15)));
    $restSeconds = max(5, min(600, (int) ($b['rest_seconds'] ?? 60)));
    $basePoints = max(1, min(10000, (int) ($b['base_points'] ?? 10)));

    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      $ins = $pdo->prepare(
        "INSERT INTO luzverde_sessions (is_active, state, round_no, sensitivity_level, base_points, rest_seconds, created_at, updated_at)
         VALUES (1, 'WAITING', 0, ?, ?, ?, NOW(), NOW())"
      );
      $ins->execute([$sensitivity, $basePoints, $restSeconds]);
      $active = luzverde_get_active_session($pdo);
    } else {
      $up = $pdo->prepare('UPDATE luzverde_sessions SET sensitivity_level = ?, rest_seconds = ?, base_points = ?, updated_at = NOW() WHERE id = ?');
      $up->execute([$sensitivity, $restSeconds, $basePoints, (int) $active['id']]);
      $active = luzverde_get_active_session($pdo);
    }

    ok(['session' => $active]);
  }

  if ($method === 'POST' && $action === 'admin_luzverde_reset_session') {
    $b = body_json();
    $sensitivity = max(1, min(40, (int) ($b['sensitivity_level'] ?? 15)));
    $restSeconds = max(5, min(600, (int) ($b['rest_seconds'] ?? 60)));
    $basePoints = max(1, min(10000, (int) ($b['base_points'] ?? 10)));

    $pdo->beginTransaction();
    $pdo->exec('UPDATE luzverde_sessions SET is_active = 0, updated_at = NOW() WHERE is_active = 1');

    $ins = $pdo->prepare(
      "INSERT INTO luzverde_sessions
      (is_active, state, round_no, sensitivity_level, base_points, rest_seconds, rest_ends_at, round_alive_start, round_eliminated_count, created_at, updated_at)
      VALUES (1, 'WAITING', 0, ?, ?, ?, NULL, 0, 0, NOW(), NOW())"
    );
    $ins->execute([$sensitivity, $basePoints, $restSeconds]);
    $sessionId = (int) $pdo->lastInsertId();
    $pdo->commit();

    ok(['session_id' => $sessionId]);
  }

  if ($method === 'POST' && $action === 'admin_luzverde_start_round') {
    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      fail('No hay sesión activa', 409, 'NO_ACTIVE_SESSION');
    }

    $state = (string) $active['state'];
    if ($state !== 'WAITING' && $state !== 'REST') {
      fail('La ronda sólo puede iniciar desde WAITING o REST', 409, 'INVALID_STATE');
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM luzverde_participants WHERE session_id = ? AND armed = 1 AND eliminated_at IS NULL');
    $countStmt->execute([(int) $active['id']]);
    $aliveCount = (int) $countStmt->fetchColumn();
    if ($aliveCount < 2) {
      fail('Se necesitan al menos 2 jugadores vivos para iniciar ronda', 409, 'NOT_ENOUGH_PLAYERS');
    }

    $up = $pdo->prepare(
      "UPDATE luzverde_sessions
       SET state = 'ACTIVE', round_no = round_no + 1, rest_ends_at = NULL,
           round_alive_start = ?, round_eliminated_count = 0, updated_at = NOW()
       WHERE id = ?"
    );
    $up->execute([$aliveCount, (int) $active['id']]);

    ok(['round_started' => true, 'alive_start' => $aliveCount]);
  }

  if ($method === 'POST' && $action === 'admin_luzverde_end_round') {
    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      fail('No hay sesión activa', 409, 'NO_ACTIVE_SESSION');
    }

    $up = $pdo->prepare(
      "UPDATE luzverde_sessions
       SET state = 'REST', rest_ends_at = DATE_ADD(NOW(), INTERVAL rest_seconds SECOND), updated_at = NOW()
       WHERE id = ?"
    );
    $up->execute([(int) $active['id']]);

    ok(['ended' => true]);
  }

  if ($method === 'POST' && $action === 'admin_luzverde_finish_game') {
    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      fail('No hay sesión activa', 409, 'NO_ACTIVE_SESSION');
    }

    $sessionId = (int) $active['id'];
    $participantsStmt = $pdo->prepare(
      'SELECT lp.*, p.display_name, p.public_code
       FROM luzverde_participants lp
       INNER JOIN players p ON p.id = lp.player_id
       WHERE lp.session_id = ? AND lp.armed = 1
       ORDER BY lp.id ASC'
    );
    $participantsStmt->execute([$sessionId]);
    $participants = $participantsStmt->fetchAll();
    $total = count($participants);

    if ($total < 2) {
      fail('Se necesitan al menos 2 participantes para finalizar', 409, 'NOT_ENOUGH_PARTICIPANTS');
    }

    $alive = array_values(array_filter($participants, static fn(array $r): bool => $r['eliminated_at'] === null));
    if (count($alive) !== 1) {
      fail('Debe quedar exactamente 1 ganador vivo para finalizar', 409, 'INVALID_WINNER_COUNT');
    }

    $game = get_or_create_luzverde_game($pdo);
    $scoreIns = $pdo->prepare(
      "INSERT INTO score_events (player_id, event_type, game_id, points_delta, note)
       VALUES (?, 'GAME_RESULT', ?, ?, ?)"
    );

    $summary = [];
    foreach ($participants as $row) {
      $isWinner = $row['eliminated_at'] === null;
      $position = $isWinner ? $total : max(1, (int) $row['eliminated_order']);
      $points = (int) $active['base_points'] * ($position - 1);
      $note = sprintf('Muévete Luz Verde: Puesto %d de %d%s', $position, $total, $isWinner ? ' (Ganador)' : '');
      if (!$isWinner && $row['eliminated_round'] !== null) {
        $note .= ' - Ronda ' . (int) $row['eliminated_round'];
      }
      $scoreIns->execute([(int) $row['player_id'], (int) $game['id'], $points, $note]);

      $summary[] = [
        'player_id' => (int) $row['player_id'],
        'display_name' => (string) $row['display_name'],
        'public_code' => (string) $row['public_code'],
        'points' => $points,
        'position' => $position,
        'winner' => $isWinner,
      ];
    }

    $up = $pdo->prepare("UPDATE luzverde_sessions SET state = 'FINISHED', is_active = 0, updated_at = NOW() WHERE id = ?");
    $up->execute([$sessionId]);

    ok([
      'winner' => [
        'player_id' => (int) $alive[0]['player_id'],
        'display_name' => (string) $alive[0]['display_name'],
        'public_code' => (string) $alive[0]['public_code'],
      ],
      'results' => $summary,
    ]);
  }

  if ($method === 'POST' && $action === 'admin_luzverde_remove_participant') {
    $active = luzverde_get_active_session($pdo);
    if (!$active) {
      fail('No hay sesión activa', 409, 'NO_ACTIVE_SESSION');
    }

    $b = body_json();
    $playerId = (int) ($b['player_id'] ?? 0);
    if ($playerId <= 0) {
      fail('player_id inválido');
    }

    $sessionId = (int) $active['id'];
    $del = $pdo->prepare('DELETE FROM luzverde_participants WHERE session_id = ? AND player_id = ?');
    $del->execute([$sessionId, $playerId]);
    if ($del->rowCount() <= 0) {
      fail('Participante no encontrado', 404);
    }

    $alivePlayers = luzverde_get_alive_players($pdo, $sessionId);
    if (count($alivePlayers) === 1 && $active['state'] !== 'FINISHED') {
      luzverde_finish_session_with_winner($pdo, $active, $alivePlayers[0]);
    }

    ok(['removed' => true, 'player_id' => $playerId]);
  }

  if ($method === 'POST' && $action === 'sumador_start') {
    $b = body_json();
    $mode = resolve_game_mode($b);
    $playerId = resolve_player_id_from_body($pdo, $b);

    if ($mode === 'real') {
      $enabled = setting_get($pdo, 'scoring_enabled', '1');
      if ($enabled !== '1') {
        fail('El puntaje está pausado', 403);
      }
    }

    $game = get_or_create_sumador_game($pdo);
    if ($game['is_active'] !== 1) {
      fail('Juego no disponible', 403);
    }

    if (!can_play($pdo, $playerId, $game['id'], $mode)) {
      fail('Ya jugaste en modo real', 409, 'ALREADY_PLAYED_REAL');
    }

    start_play($pdo, $playerId, $game['id'], $mode);

    ok([
      'duration_ms' => 20000,
      'game_id' => $game['id'],
      'mode' => $mode,
    ]);
  }

  if ($method === 'POST' && $action === 'sumador_finish') {
    $b = body_json();
    $playerId = resolve_player_id_from_body($pdo, $b);
    $mode = resolve_game_mode($b);
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

    $play = find_open_play($pdo, $playerId, $game['id'], $mode);

    if (!$play) {
      fail('Partida no iniciada o ya finalizada', 409);
    }

    $pdo->beginTransaction();

    $up = $pdo->prepare('UPDATE game_plays SET finished_at = NOW(), duration_ms = ?, attempts = ?, score = ? WHERE id = ?');
    $up->execute([$durationMs, $clicks, $score, (int) $play['id']]);

    apply_points_if_real($pdo, $playerId, $game['id'], $mode, $clicks, $durationMs, $score);

    $pdo->commit();

    ok([
      'score' => $score,
      'clicks' => $clicks,
      'duration_ms' => $durationMs,
      'mode' => $mode,
      'message' => $mode === 'practice' ? 'guardado (práctica)' : 'guardado (real)',
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


  if ($method === 'GET' && $action === 'admin_error_logs') {
    $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 200)));
    $level = strtoupper(trim((string) ($_GET['level'] ?? '')));
    $since = trim((string) ($_GET['since'] ?? ''));
    $source = strtolower(trim((string) ($_GET['source'] ?? '')));

    $hasDb = db_table_exists($pdo, 'error_logs');
    if ($source === '') {
      $source = $hasDb ? 'db' : 'file';
    }

    $rows = [];

    if ($source === 'db' && $hasDb) {
      $sql = 'SELECT id, created_at, level, message, file, line, request_id, url, method, user_agent, ip, context, extra_json FROM error_logs';
      $where = [];
      $params = [];

      if ($level !== '') {
        $where[] = 'level = ?';
        $params[] = $level;
      }

      if ($since !== '') {
        if (ctype_digit($since)) {
          $where[] = 'id > ?';
          $params[] = (int) $since;
        } else {
          $where[] = 'created_at >= ?';
          $params[] = $since;
        }
      }

      if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
      }

      $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $rows = $stmt->fetchAll();
    } else {
      $rows = array_reverse(read_log_tail(error_log_file_path(), $limit));
      if ($level !== '') {
        $rows = array_values(array_filter($rows, static fn(array $r): bool => strtoupper((string) ($r['level'] ?? '')) === $level));
      }
      if ($since !== '') {
        $rows = array_values(array_filter($rows, static function (array $r) use ($since): bool {
          $ts = (string) ($r['timestamp'] ?? '');
          if ($ts === '') {
            return false;
          }
          return strtotime($ts) >= strtotime($since);
        }));
      }
      $rows = array_slice($rows, -$limit);
    }

    ok(['source' => $source, 'rows' => $rows]);
  }

  if ($method === 'GET' && $action === 'admin_error_log_tail') {
    $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 200)));
    $rows = read_log_tail(error_log_file_path(), $limit);
    ok(['rows' => $rows]);
  }

  if ($method === 'POST' && $action === 'admin_error_log_clear') {
    $hasDb = db_table_exists($pdo, 'error_logs');
    $clearedDb = false;

    if ($hasDb) {
      $pdo->exec('DELETE FROM error_logs');
      $clearedDb = true;
    }

    $file = error_log_file_path();
    if (is_file($file)) {
      file_put_contents($file, '');
    }

    ok(['cleared_db' => $clearedDb, 'cleared_file' => $file]);
  }

  fail('Not found', 404);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode() ?? 'UNKNOWN');
  log_event('ERROR', $e->getMessage(), [
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'sql_state' => $sqlState,
    'trace' => $e->getTraceAsString(),
    'action' => (string) $action,
  ]);

  $extra = [];
  if (is_debug_mode($config)) {
    $extra['debug_sqlstate'] = $sqlState;
  }

  fail('Database error', 500, 'DB_ERROR', $extra);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  log_event('ERROR', $e->getMessage(), [
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'trace' => $e->getTraceAsString(),
    'action' => (string) $action,
  ]);

  $isDebugView = isset($_GET['debug_view']) && (string) $_GET['debug_view'] === '1' && admin_is_authorized($config);
  if ($isDebugView) {
    fail('EXCEPTION', 500, 'EXCEPTION', [
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'trace' => $e->getTraceAsString(),
    ]);
  }

  fail('EXCEPTION', 500, 'EXCEPTION', ['message' => $e->getMessage()]);
}
