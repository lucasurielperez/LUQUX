<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_functions.php';
require_once __DIR__ . '/../includes/task_functions.php';

$id = (int) ($_GET['id'] ?? 0);
recalculate_project_metrics($id);
$project = fetch_project($id);
if (!$project) {
    flash_set('error', 'Proyecto no encontrado.');
    redirect('projects.php');
}
$tasks = fetch_tasks_by_project($id);

if (isset($_POST['quick_estado']) && in_array($_POST['quick_estado'], project_statuses(), true)) {
    update_project($id, array_merge($project, ['estado' => $_POST['quick_estado']]));
    flash_set('success', 'Estado actualizado.');
    redirect('project_view.php?id=' . $id);
}

$title = 'Detalle proyecto';
include __DIR__ . '/../includes/header.php';
?>
<article class="card">
    <div class="card-head"><h2><?= e($project['nombre']) ?></h2>
    <div class="badges"><span class="badge state-<?= e($project['estado']) ?>"><?= e($project['estado']) ?></span><span class="badge health-<?= e($project['salud']) ?>"><?= e($project['salud']) ?></span></div></div>
    <p><?= nl2br(e($project['descripcion'])) ?></p>
    <p>Prioridad personal <?= (int)$project['prioridad_personal'] ?> · Prioridad real <?= (int)$project['prioridad_real'] ?></p>
    <div class="progress big"><div style="width: <?= (int)$project['porcentaje_avance'] ?>%"></div></div>
    <p><?= (int)$project['porcentaje_avance'] ?>% completado</p>
    <p>Fecha límite: <?= e(format_partial_deadline($project['fecha_limite_precision'], (int)$project['fecha_limite_anio'], (int)$project['fecha_limite_mes'], (int)$project['fecha_limite_dia'])) ?></p>

    <form method="post" class="inline-form">
        <label>Cambio rápido estado <select name="quick_estado"><?php foreach(project_statuses() as $s): ?><option <?= $project['estado']===$s?'selected':'' ?> value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?></select></label>
        <button class="btn-primary">Guardar</button>
    </form>
    <div class="actions"><a href="project_edit.php?id=<?= $id ?>">Editar</a><a onclick="return confirm('¿Eliminar proyecto?')" href="project_delete.php?id=<?= $id ?>">Eliminar</a><a href="task_create.php?proyecto_id=<?= $id ?>">+ Tarea</a><a href="kanban.php?proyecto_id=<?= $id ?>">Vista Kanban</a></div>
</article>

<section class="card">
    <h3>Tareas</h3>
    <?php if(!$tasks): ?><p>Sin tareas todavía.</p><?php endif; ?>
    <?php foreach($tasks as $t): ?>
    <div class="task-item">
        <div><strong><?= e($t['titulo']) ?></strong><small><?= e((string)$t['descripcion']) ?></small></div>
        <div class="badges"><span class="badge"><?= e($t['estado']) ?></span><span class="badge"><?= e($t['prioridad']) ?></span></div>
        <small><?= e((string)$t['fecha_limite']) ?></small>
        <div class="actions"><a href="task_edit.php?id=<?= (int)$t['id'] ?>">Editar</a><a onclick="return confirm('¿Eliminar tarea?')" href="task_delete.php?id=<?= (int)$t['id'] ?>">Eliminar</a></div>
    </div>
    <?php endforeach; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
