<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/project_functions.php';

function task_statuses(): array
{
    return ['pendiente', 'en_progreso', 'hecha'];
}

function task_priorities(): array
{
    return ['baja', 'media', 'alta'];
}

function fetch_tasks_by_project(int $projectId): array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE proyecto_id=:id ORDER BY orden_manual IS NULL, orden_manual ASC, fecha_creacion DESC');
    $stmt->execute(['id' => $projectId]);
    return $stmt->fetchAll();
}

function fetch_task(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id=:id');
    $stmt->execute(['id' => $id]);
    $task = $stmt->fetch();
    return $task ?: null;
}

function create_task(array $data): array
{
    [$ok, $errors, $clean] = validate_task_data($data);
    if (!$ok) return [false, $errors];

    db()->prepare('INSERT INTO tasks (proyecto_id, titulo, descripcion, estado, prioridad, fecha_limite, orden_manual)
        VALUES (:proyecto_id, :titulo, :descripcion, :estado, :prioridad, :fecha_limite, :orden_manual)')->execute($clean);

    recalculate_project_metrics((int) $clean['proyecto_id']);
    return [true, []];
}

function update_task(int $id, array $data): array
{
    [$ok, $errors, $clean] = validate_task_data($data);
    if (!$ok) return [false, $errors];

    db()->prepare('UPDATE tasks SET titulo=:titulo, descripcion=:descripcion, estado=:estado, prioridad=:prioridad,
        fecha_limite=:fecha_limite, orden_manual=:orden_manual WHERE id=:id')->execute(task_update_payload($id, $clean));

    recalculate_project_metrics((int) $clean['proyecto_id']);
    return [true, []];
}

function delete_task(int $id): void
{
    $task = fetch_task($id);
    if (!$task) return;
    db()->prepare('DELETE FROM tasks WHERE id=:id')->execute(['id' => $id]);
    recalculate_project_metrics((int) $task['proyecto_id']);
}

function validate_task_data(array $data): array
{
    $errors = [];
    $proyectoId = (int) ($data['proyecto_id'] ?? 0);
    $titulo = trim((string) ($data['titulo'] ?? ''));
    $descripcion = trim((string) ($data['descripcion'] ?? ''));
    $estado = (string) ($data['estado'] ?? 'pendiente');
    $prioridad = (string) ($data['prioridad'] ?? 'media');
    $fechaLimite = trim((string) ($data['fecha_limite'] ?? ''));
    $orden = trim((string) ($data['orden_manual'] ?? ''));

    if ($proyectoId <= 0) $errors[] = 'Proyecto inválido.';
    if ($titulo === '') $errors[] = 'El título de la tarea es obligatorio.';
    if (!in_array($estado, task_statuses(), true)) $errors[] = 'Estado de tarea inválido.';
    if (!in_array($prioridad, task_priorities(), true)) $errors[] = 'Prioridad de tarea inválida.';
    if ($fechaLimite !== '' && !DateTimeImmutable::createFromFormat('Y-m-d', $fechaLimite)) $errors[] = 'Fecha límite de tarea inválida.';
    if ($orden !== '' && !ctype_digit($orden)) $errors[] = 'Orden manual inválido.';

    if ($errors) return [false, $errors, []];

    return [true, [], [
        'proyecto_id' => $proyectoId,
        'titulo' => $titulo,
        'descripcion' => $descripcion ?: null,
        'estado' => $estado,
        'prioridad' => $prioridad,
        'fecha_limite' => $fechaLimite ?: null,
        'orden_manual' => $orden === '' ? null : (int) $orden,
    ]];
}

function task_update_payload(int $id, array $clean): array
{
    return [
        'id' => $id,
        'titulo' => $clean['titulo'],
        'descripcion' => $clean['descripcion'],
        'estado' => $clean['estado'],
        'prioridad' => $clean['prioridad'],
        'fecha_limite' => $clean['fecha_limite'],
        'orden_manual' => $clean['orden_manual'],
    ];
}
