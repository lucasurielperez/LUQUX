<?php
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

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

function fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
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

$token = auth_bearer_token();
if ($token === '' || !hash_equals((string) $config['admin_token'], $token)) {
  fail('Unauthorized', 401);
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

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
  if ($method === 'GET' && $action === 'ping') {
    ok(['msg' => 'pong']);
  }

  if ($method === 'GET' && $action === 'games') {
    $stmt = $pdo->query('SELECT id, code, name, is_active, base_points FROM games ORDER BY id ASC');
    ok(['rows' => $stmt->fetchAll()]);
  }

  if ($method === 'GET' && $action === 'players') {
    $stmt = $pdo->query('SELECT id, public_code, display_name, is_active, created_at, last_seen_at FROM players ORDER BY created_at DESC');
    ok(['rows' => $stmt->fetchAll()]);
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
    $playerId = (int) ($b['player_id'] ?? 0);
    if ($playerId <= 0) {
      fail('player_id requerido');
    }

    $pdo->beginTransaction();
    $st1 = $pdo->prepare('DELETE FROM score_events WHERE player_id = ?');
    $st1->execute([$playerId]);

    $st2 = $pdo->prepare('DELETE FROM secret_qr_claims WHERE player_id = ?');
    $st2->execute([$playerId]);
    $pdo->commit();

    ok(['reset' => true]);
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

    $isActive = !empty($b['is_active']) ? 1 : 0;
    $stmt = $pdo->prepare('UPDATE games SET is_active = ? WHERE id = ?');
    $stmt->execute([$isActive, $id]);
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
