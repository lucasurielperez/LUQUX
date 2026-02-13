<?php
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

// Auth simple (Bearer token)
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $auth, $m) || $m[1] !== $config['admin_token']) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

// DB
$db = $config['db'];
$dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function body_json() {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function ok($data = []) { echo json_encode(['ok' => true] + $data); exit; }
function fail($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

// --- Actions ---

// GET ?action=ping
if ($method === 'GET' && $action === 'ping') {
  ok(['msg' => 'pong']);
}

// GET ?action=players
if ($method === 'GET' && $action === 'players') {
  $stmt = $pdo->query("SELECT id, public_code, display_name, is_active, created_at, last_seen_at
                       FROM players ORDER BY created_at DESC");
  ok(['rows' => $stmt->fetchAll()]);
}

// POST ?action=players_delete  body: {id}
if ($method === 'POST' && $action === 'players_delete') {
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  if ($id <= 0) fail('id requerido');

  $stmt = $pdo->prepare("DELETE FROM players WHERE id = ?");
  $stmt->execute([$id]);
  ok(['deleted' => $stmt->rowCount()]);
}

// GET ?action=games
if ($method === 'GET' && $action === 'games') {
  $stmt = $pdo->query("SELECT id, code, name, is_active, base_points
                       FROM games ORDER BY id ASC");
  ok(['rows' => $stmt->fetchAll()]);
}

// POST ?action=games_toggle body: {id, is_active}
if ($method === 'POST' && $action === 'games_toggle') {
  $b = body_json();
  $id = (int)($b['id'] ?? 0);
  $is_active = !empty($b['is_active']) ? 1 : 0;
  if ($id <= 0) fail('id requerido');

  $stmt = $pdo->prepare("UPDATE games SET is_active = ? WHERE id = ?");
  $stmt->execute([$is_active, $id]);
  ok(['updated' => $stmt->rowCount()]);
}

// GET ?action=leaderboard
if ($method === 'GET' && $action === 'leaderboard') {
  $stmt = $pdo->query("SELECT * FROM leaderboard ORDER BY total_points DESC, last_scored_at ASC, display_name ASC");
  ok(['rows' => $stmt->fetchAll()]);
}

// POST ?action=adjust_points body: {player_id, points_delta, note}
if ($method === 'POST' && $action === 'adjust_points') {
  $b = body_json();
  $player_id = (int)($b['player_id'] ?? 0);
  $points_delta = (int)($b['points_delta'] ?? 0);
  $note = trim((string)($b['note'] ?? ''));

  if ($player_id <= 0) fail('player_id requerido');
  if ($points_delta === 0) fail('points_delta no puede ser 0');

  $stmt = $pdo->prepare("INSERT INTO score_events (player_id, event_type, points_delta, note)
                         VALUES (?, 'ADMIN_ADJUST', ?, ?)");
  $stmt->execute([$player_id, $points_delta, $note]);
  ok(['inserted_id' => $pdo->lastInsertId()]);
}

// GET ?action=settings
if ($method === 'GET' && $action === 'settings') {
  $stmt = $pdo->query("SELECT `key`, `value` FROM settings");
  ok(['rows' => $stmt->fetchAll()]);
}

// POST ?action=set_qr_multiplier body: {value}
if ($method === 'POST' && $action === 'set_qr_multiplier') {
  $b = body_json();
  $val = (int)($b['value'] ?? 1);
  if (!in_array($val, [1,2,3], true)) fail('value debe ser 1, 2 o 3');

  $stmt = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('qr_multiplier', ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value)");
  $stmt->execute([(string)$val]);
  ok(['value' => $val]);
}

fail('Not found', 404);

// GET ?action=events_recent  (últimos 50 eventos)
if ($method === 'GET' && $action === 'events_recent') {
  $stmt = $pdo->query("
    SELECT
      se.id, se.created_at, se.event_type, se.points_delta, se.note,
      se.player_id, p.display_name, p.public_code,
      se.game_id, g.code AS game_code,
      se.secret_qr_id, q.code AS qr_code
    FROM score_events se
    JOIN players p ON p.id = se.player_id
    LEFT JOIN games g ON g.id = se.game_id
    LEFT JOIN secret_qrs q ON q.id = se.secret_qr_id
    ORDER BY se.id DESC
    LIMIT 50
  ");
  ok(['rows' => $stmt->fetchAll()]);
}

// POST ?action=reset_player body: {player_id}
if ($method === 'POST' && $action === 'reset_player') {
  $b = body_json();
  $player_id = (int)($b['player_id'] ?? 0);
  if ($player_id <= 0) fail('player_id requerido');

  // borra eventos y claims; el player queda
  $pdo->beginTransaction();
  try {
    $st1 = $pdo->prepare("DELETE FROM score_events WHERE player_id = ?");
    $st1->execute([$player_id]);

    $st2 = $pdo->prepare("DELETE FROM secret_qr_claims WHERE player_id = ?");
    $st2->execute([$player_id]);

    $pdo->commit();
  } catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
  }

  ok(['reset' => true]);
}

// GET ?action=scoring_status
if ($method === 'GET' && $action === 'scoring_status') {
  $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key`='scoring_enabled' LIMIT 1");
  $stmt->execute();
  $val = $stmt->fetchColumn();
  if ($val === false) $val = '1';
  ok(['enabled' => ((string)$val === '1')]);
}

// POST ?action=set_scoring_enabled body: {enabled: true/false}
if ($method === 'POST' && $action === 'set_scoring_enabled') {
  $b = body_json();
  $enabled = !empty($b['enabled']) ? '1' : '0';

  $stmt = $pdo->prepare("
    INSERT INTO settings (`key`,`value`) VALUES ('scoring_enabled', ?)
    ON DUPLICATE KEY UPDATE value = VALUES(value)
  ");
  $stmt->execute([$enabled]);
  ok(['enabled' => ($enabled === '1')]);
}
