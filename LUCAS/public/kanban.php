<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/task_functions.php';
require_once __DIR__ . '/../includes/project_functions.php';
require_login();

$projectId = (int) ($_GET['proyecto_id'] ?? $_POST['proyecto_id'] ?? 0);
$project = fetch_project($projectId);
if (!$project) {
    flash_set('error', 'Proyecto inválido para Kanban.');
    redirect('projects.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task = fetch_task((int)$_POST['task_id']);
    if ($task && (int)$task['proyecto_id'] === $projectId && in_array($_POST['estado'], task_statuses(), true)) {
        update_task((int)$task['id'], array_merge($task, ['estado' => $_POST['estado'], 'proyecto_id' => $projectId]));
        flash_set('success', 'Estado de tarea actualizado.');
    }
    redirect('kanban.php?proyecto_id=' . $projectId);
}

$tasks = fetch_tasks_by_project($projectId);
$columns = ['pendiente' => [], 'en_progreso' => [], 'hecha' => []];
foreach ($tasks as $t) $columns[$t['estado']][] = $t;
$title = 'Kanban';
include __DIR__ . '/../includes/header.php';
?>
<h2>Kanban · <?= e($project['nombre']) ?></h2>
<div class="kanban">
<?php foreach($columns as $status => $items): ?>
<div class="kanban-col card"><h3><?= e($status) ?> (<?= count($items) ?>)</h3>
    <?php foreach($items as $item): ?>
    <div class="kanban-item">
        <strong><?= e($item['titulo']) ?></strong>
        <small><?= e($item['prioridad']) ?></small>
        <form method="post">
            <input type="hidden" name="proyecto_id" value="<?= $projectId ?>">
            <input type="hidden" name="task_id" value="<?= (int)$item['id'] ?>">
            <select name="estado"><?php foreach(task_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $item['estado']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
            <button>Actualizar</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
