<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recurring_task_functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task_id'], $_POST['estado'])) {
    $ok = set_recurring_task_state((int) $_POST['task_id'], (string) $_POST['estado']);
    flash_set($ok ? 'success' : 'error', $ok ? 'Estado actualizado.' : 'No se pudo actualizar el estado.');
    redirect('recurring_tasks.php');
}

$activeTasks = fetch_recurring_tasks(true);
$allTasks = fetch_recurring_tasks(false);
$title = 'Tareas recurrentes';
include __DIR__ . '/../includes/header.php';
?>
<section class="card recurring-highlight">
    <div class="card-head">
        <h2>Tareas recurrentes prioritarias</h2>
        <a class="btn-primary" href="recurring_task_create.php">+ Nueva recurrente</a>
    </div>
    <?php if (!$activeTasks): ?>
        <p>No hay tareas recurrentes activas por ahora.</p>
    <?php else: ?>
        <?php foreach ($activeTasks as $task): ?>
        <div class="task-item recurring-item recurring-<?= e($task['prioridad']) ?>">
            <div>
                <strong><?= e($task['titulo']) ?></strong>
                <small><?= e((string) $task['descripcion']) ?></small>
            </div>
            <div class="badges">
                <span class="badge"><?= e($task['frecuencia']) ?></span>
                <span class="badge"><?= e($task['estado']) ?></span>
            </div>
            <form method="post" class="inline-form">
                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                <select name="estado">
                    <?php foreach(recurring_task_statuses() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $task['estado']===$s?'selected':'' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Guardar</button>
            </form>
            <div class="actions">
                <a href="recurring_task_edit.php?id=<?= (int) $task['id'] ?>">Editar</a>
                <a onclick="return confirm('¿Eliminar tarea recurrente?')" href="recurring_task_delete.php?id=<?= (int) $task['id'] ?>">Eliminar</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Administración (todas)</h3>
    <?php if (!$allTasks): ?>
        <p>Sin tareas recurrentes.</p>
    <?php else: ?>
        <?php foreach ($allTasks as $task): ?>
            <div class="list-item">
                <span><?= e($task['titulo']) ?> · <?= e($task['frecuencia']) ?></span>
                <span class="badge"><?= e($task['estado']) ?></span>
                <small>Próxima aparición: <?= e((string) $task['proxima_aparicion']) ?: 'inmediata' ?></small>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
