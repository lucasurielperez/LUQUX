<?php
header('Content-Type: application/json; charset=utf-8');

function ok(array $data = []): void {
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}

function fail(string $msg, int $status = 400): void {
  http_response_code($status);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$action = (string) ($_GET['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

$config = require __DIR__ . '/admin/config.php';
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

try {
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

  fail('Action not found', 404);
} catch (Throwable $e) {
  fail('Server error', 500);
}
