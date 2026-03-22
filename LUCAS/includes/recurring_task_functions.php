<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

function recurring_task_statuses(): array
{
    return ['pendiente', 'en_progreso', 'completada'];
}

function recurring_task_frequencies(): array
{
    return ['diaria', 'semanal', 'mensual', 'anual'];
}

function recurring_task_priorities(): array
{
    return ['alta', 'media', 'baja'];
}

function refresh_recurring_tasks(): void
{
    $stmt = db()->prepare("UPDATE recurring_tasks
        SET estado='pendiente'
        WHERE estado='completada' AND proxima_aparicion IS NOT NULL AND proxima_aparicion <= NOW() AND user_id = :user_id");
    $stmt->execute(['user_id' => current_user_id()]);
}

function fetch_recurring_tasks(bool $onlyActive = false): array
{
    refresh_recurring_tasks();
    $sql = 'SELECT * FROM recurring_tasks WHERE user_id = :user_id';
    if ($onlyActive) {
        $sql .= " AND estado != 'completada'";
    }
    $sql .= " ORDER BY prioridad='alta' DESC, prioridad='media' DESC, fecha_actualizacion DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => current_user_id()]);
    return $stmt->fetchAll();
}

function fetch_recurring_task(int $id): ?array
{
    refresh_recurring_tasks();
    $stmt = db()->prepare('SELECT * FROM recurring_tasks WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $id, 'user_id' => current_user_id()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_recurring_task(array $data): array
{
    [$ok, $errors, $clean] = validate_recurring_task_data($data);
    if (!$ok) return [false, $errors];

    db()->prepare('INSERT INTO recurring_tasks (user_id, titulo, descripcion, frecuencia, estado, prioridad)
        VALUES (:user_id, :titulo, :descripcion, :frecuencia, :estado, :prioridad)')->execute($clean + ['user_id' => current_user_id()]);

    return [true, []];
}

function update_recurring_task(int $id, array $data): array
{
    [$ok, $errors, $clean] = validate_recurring_task_data($data);
    if (!$ok) return [false, $errors];

    $clean['id'] = $id;
    $clean['user_id'] = current_user_id();
    db()->prepare('UPDATE recurring_tasks
        SET titulo=:titulo, descripcion=:descripcion, frecuencia=:frecuencia, estado=:estado, prioridad=:prioridad
        WHERE id=:id AND user_id=:user_id')->execute($clean);

    if ($clean['estado'] === 'completada') {
        complete_recurring_task($id);
    }

    return [true, []];
}

function set_recurring_task_state(int $id, string $status): bool
{
    if (!in_array($status, recurring_task_statuses(), true)) {
        return false;
    }
    if ($status === 'completada') {
        complete_recurring_task($id);
        return true;
    }

    $stmt = db()->prepare('UPDATE recurring_tasks SET estado=:estado, proxima_aparicion=NULL WHERE id=:id AND user_id=:user_id');
    $stmt->execute(['estado' => $status, 'id' => $id, 'user_id' => current_user_id()]);
    return $stmt->rowCount() > 0;
}

function complete_recurring_task(int $id): void
{
    $task = fetch_recurring_task($id);
    if (!$task) return;

    $now = new DateTimeImmutable('now');
    $next = calculate_next_recurring_date((string) $task['frecuencia'], $now);
    db()->prepare("UPDATE recurring_tasks
        SET estado='completada', ultima_completada=:completedAt, proxima_aparicion=:nextDate
        WHERE id=:id AND user_id=:user_id")
        ->execute([
            'completedAt' => $now->format('Y-m-d H:i:s'),
            'nextDate' => $next->format('Y-m-d H:i:s'),
            'id' => $id,
            'user_id' => current_user_id(),
        ]);
}

function calculate_next_recurring_date(string $frequency, ?DateTimeImmutable $base = null): DateTimeImmutable
{
    $base ??= new DateTimeImmutable('now');
    $startOfDay = $base->setTime(0, 0, 0);

    return match ($frequency) {
        'diaria' => $startOfDay->modify('+1 day'),
        'semanal' => $startOfDay->modify('+1 week'),
        'mensual' => $startOfDay->modify('+1 month'),
        'anual' => $startOfDay->modify('+1 year'),
        default => $startOfDay->modify('+1 day'),
    };
}

function delete_recurring_task(int $id): void
{
    db()->prepare('DELETE FROM recurring_tasks WHERE id=:id AND user_id=:user_id')->execute(['id' => $id, 'user_id' => current_user_id()]);
}

function validate_recurring_task_data(array $data): array
{
    $errors = [];
    $titulo = trim((string) ($data['titulo'] ?? ''));
    $descripcion = trim((string) ($data['descripcion'] ?? ''));
    $frecuencia = (string) ($data['frecuencia'] ?? 'diaria');
    $estado = (string) ($data['estado'] ?? 'pendiente');
    $prioridad = (string) ($data['prioridad'] ?? 'alta');

    if ($titulo === '') $errors[] = 'El título de la tarea recurrente es obligatorio.';
    if (!in_array($frecuencia, recurring_task_frequencies(), true)) $errors[] = 'Frecuencia inválida.';
    if (!in_array($estado, recurring_task_statuses(), true)) $errors[] = 'Estado inválido.';
    if (!in_array($prioridad, recurring_task_priorities(), true)) $errors[] = 'Prioridad inválida.';

    if ($errors) return [false, $errors, []];

    return [true, [], [
        'titulo' => $titulo,
        'descripcion' => $descripcion ?: null,
        'frecuencia' => $frecuencia,
        'estado' => $estado,
        'prioridad' => $prioridad,
    ]];
}
