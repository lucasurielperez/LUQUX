<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function project_statuses(): array
{
    return ['idea', 'pendiente', 'en_progreso', 'pausado', 'completado', 'cancelado'];
}

function health_statuses(): array
{
    return ['saludable', 'en_riesgo', 'atrasado', 'trabado'];
}

function fetch_projects(array $filters = []): array
{
    $pdo = db();
    $where = [];
    $params = [];

    if (!empty($filters['q'])) {
        $where[] = '(p.nombre LIKE :q OR p.descripcion LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }

    foreach (['estado', 'salud', 'prioridad_personal', 'prioridad_real'] as $key) {
        if (!empty($filters[$key])) {
            $where[] = "p.$key = :$key";
            $params[$key] = $filters[$key];
        }
    }

    if (($filters['vencimiento'] ?? '') === 'proximo') {
        $where[] = "p.fecha_limite_precision IS NOT NULL";
    }

    $orderMap = [
        'prioridad_real' => 'p.prioridad_real DESC',
        'prioridad_personal' => 'p.prioridad_personal DESC',
        'fecha_limite' => 'p.fecha_limite_anio IS NULL, p.fecha_limite_anio ASC, p.fecha_limite_mes ASC, p.fecha_limite_dia ASC',
        'avance' => 'p.porcentaje_avance DESC',
        'actualizacion' => 'p.fecha_actualizacion DESC',
        'nombre' => 'p.nombre ASC',
    ];

    $order = $orderMap[$filters['orden'] ?? 'actualizacion'] ?? $orderMap['actualizacion'];

    $sql = "SELECT p.*,
        COUNT(t.id) AS total_tareas,
        SUM(CASE WHEN t.estado='hecha' THEN 1 ELSE 0 END) AS tareas_hechas,
        SUM(CASE WHEN t.estado!='hecha' THEN 1 ELSE 0 END) AS tareas_pendientes
        FROM projects p
        LEFT JOIN tasks t ON t.proyecto_id = p.id";

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY p.id ORDER BY ' . $order;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_project(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function create_project(array $data): array
{
    [$ok, $errors, $clean] = validate_project_data($data);
    if (!$ok) {
        return [false, $errors];
    }

    $sql = 'INSERT INTO projects
            (nombre, descripcion, prioridad_personal, prioridad_real, estado, salud, porcentaje_avance, fecha_limite_precision, fecha_limite_anio, fecha_limite_mes, fecha_limite_dia)
            VALUES (:nombre, :descripcion, :prioridad_personal, :prioridad_real, :estado, :salud, 0, :precision, :anio, :mes, :dia)';
    $stmt = db()->prepare($sql);
    $stmt->execute($clean);

    return [true, []];
}

function update_project(int $id, array $data): array
{
    [$ok, $errors, $clean] = validate_project_data($data);
    if (!$ok) {
        return [false, $errors];
    }

    $clean['id'] = $id;
    $sql = 'UPDATE projects SET
            nombre=:nombre, descripcion=:descripcion, prioridad_personal=:prioridad_personal, prioridad_real=:prioridad_real,
            estado=:estado, salud=:salud, fecha_limite_precision=:precision, fecha_limite_anio=:anio, fecha_limite_mes=:mes, fecha_limite_dia=:dia
            WHERE id=:id';
    db()->prepare($sql)->execute($clean);
    recalculate_project_metrics($id);

    return [true, []];
}

function delete_project(int $id): void
{
    db()->prepare('DELETE FROM projects WHERE id=:id')->execute(['id' => $id]);
}

function validate_project_data(array $data): array
{
    $errors = [];
    $nombre = trim((string) ($data['nombre'] ?? ''));
    $descripcion = trim((string) ($data['descripcion'] ?? ''));
    $pp = (int) ($data['prioridad_personal'] ?? 0);
    $pr = (int) ($data['prioridad_real'] ?? 0);
    $estado = (string) ($data['estado'] ?? 'idea');

    if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
    if ($descripcion === '') $errors[] = 'La descripción es obligatoria.';
    if ($pp < 1 || $pp > 5) $errors[] = 'Prioridad personal inválida.';
    if ($pr < 1 || $pr > 5) $errors[] = 'Prioridad real inválida.';
    if (!in_array($estado, project_statuses(), true)) $errors[] = 'Estado inválido.';

    [$dateOk, $dateError, $year, $month, $day] = validate_partial_deadline(
        $data['fecha_limite_precision'] ?: null,
        ($data['fecha_limite_anio'] !== '' ? (int) $data['fecha_limite_anio'] : null),
        ($data['fecha_limite_mes'] !== '' ? (int) $data['fecha_limite_mes'] : null),
        ($data['fecha_limite_dia'] !== '' ? (int) $data['fecha_limite_dia'] : null)
    );
    if (!$dateOk) $errors[] = $dateError;

    $salud = (string) ($data['salud'] ?? 'saludable');
    if (!in_array($salud, health_statuses(), true)) $salud = 'saludable';

    if ($errors) return [false, $errors, []];

    return [true, [], [
        'nombre' => $nombre,
        'descripcion' => $descripcion,
        'prioridad_personal' => $pp,
        'prioridad_real' => $pr,
        'estado' => $estado,
        'salud' => $salud,
        'precision' => $data['fecha_limite_precision'] ?: null,
        'anio' => $year,
        'mes' => $month,
        'dia' => $day,
    ]];
}

function recalculate_project_metrics(int $projectId): void
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN estado='hecha' THEN 1 ELSE 0 END) hechas,
            SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) pendientes,
            MAX(fecha_actualizacion) last_task_update
        FROM tasks WHERE proyecto_id=:id");
    $stmt->execute(['id' => $projectId]);
    $t = $stmt->fetch() ?: ['total' => 0, 'hechas' => 0, 'pendientes' => 0, 'last_task_update' => null];

    $project = fetch_project($projectId);
    if (!$project) return;

    $total = (int) $t['total'];
    $hechas = (int) ($t['hechas'] ?? 0);
    $avance = $total > 0 ? (int) round(($hechas / $total) * 100) : 0;

    $salud = calculate_health($project, $total, (int) ($t['pendientes'] ?? 0), $avance, $t['last_task_update']);

    $pdo->prepare('UPDATE projects SET porcentaje_avance=:avance, salud=:salud WHERE id=:id')
        ->execute(['avance' => $avance, 'salud' => $salud, 'id' => $projectId]);
}

function calculate_health(array $project, int $totalTasks, int $pendingTasks, int $avance, ?string $lastTaskUpdate): string
{
    if ($project['estado'] === 'completado') return 'saludable';

    $deadline = deadline_to_date($project['fecha_limite_precision'], (int) $project['fecha_limite_anio'],
        (int) $project['fecha_limite_mes'], (int) $project['fecha_limite_dia']);
    $today = today();

    if ($deadline && $deadline < $today && $project['estado'] !== 'completado') return 'atrasado';

    if ($deadline) {
        $days = (int) $today->diff($deadline)->format('%r%a');
        if ($days >= 0 && $days <= 14 && $avance < 60) return 'en_riesgo';
    }

    if ($totalTasks >= 4 && $pendingTasks >= 3 && $avance <= 35) {
        if (!$lastTaskUpdate || (new DateTimeImmutable($lastTaskUpdate)) < $today->modify('-10 days')) {
            return 'trabado';
        }
    }

    return 'saludable';
}

function refresh_all_projects(): void
{
    $ids = db()->query('SELECT id FROM projects')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        recalculate_project_metrics((int) $id);
    }
}
