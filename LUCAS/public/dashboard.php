<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_functions.php';
require_once __DIR__ . '/../includes/task_functions.php';
require_once __DIR__ . '/../includes/recurring_task_functions.php';

refresh_all_projects();
$projects = fetch_projects();
$total = count($projects);
$byEstado = array_fill_keys(project_statuses(), 0);
$attention = [];
$good = [];
$totalPendingTasks = 0;
$globalProgress = 0;

$recurringTasks = fetch_recurring_tasks(true);

foreach ($projects as $p) {
    $byEstado[$p['estado']]++;
    $totalPendingTasks += (int) ($p['tareas_pendientes'] ?? 0);
    $globalProgress += (int) $p['porcentaje_avance'];
    if (in_array($p['salud'], ['atrasado', 'en_riesgo', 'trabado'], true)) $attention[] = $p;
    if ($p['salud'] === 'saludable') $good[] = $p;
}
$globalProgress = $total ? (int) round($globalProgress / $total) : 0;
$title = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>
<section class="card recurring-highlight">
    <div class="card-head">
        <h3>Recurrentes prioritarias</h3>
        <a class="btn-primary" href="recurring_tasks.php">Administrar</a>
    </div>
    <?php if (!$recurringTasks): ?>
        <p>No hay rutinas activas en este momento.</p>
    <?php else: ?>
        <?php foreach (array_slice($recurringTasks, 0, 4) as $r): ?>
            <a class="list-item recurring-item recurring-<?= e($r['prioridad']) ?>" href="recurring_tasks.php">
                <strong><?= e($r['titulo']) ?></strong>
                <span class="badge"><?= e($r['frecuencia']) ?></span>
                <span class="badge"><?= e($r['estado']) ?></span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<section class="kpis">
    <article class="card"><h3>Total proyectos</h3><p><?= $total ?></p></article>
    <article class="card"><h3>Tareas pendientes</h3><p><?= $totalPendingTasks ?></p></article>
    <article class="card"><h3>Progreso global</h3><p><?= $globalProgress ?>%</p></article>
    <article class="card"><h3>En riesgo/atrasados</h3><p><?= count($attention) ?></p></article>
</section>

<?php if (!$projects): ?>
<div class="empty-state card">
    <h2>Arrancá tu primer proyecto</h2>
    <p>Centralizá tus ideas, prioridades y tareas en una sola vista clara.</p>
    <a class="btn-primary" href="project_create.php">Crear proyecto</a>
</div>
<?php else: ?>
<section class="grid-2">
    <article class="card"><h3>Distribución por estado</h3><canvas id="stateChart"></canvas></article>
    <article class="card"><h3>Prioridades (real)</h3><canvas id="prioChart"></canvas></article>
</section>

<section class="grid-2">
    <article class="card"><h3>Requieren atención</h3>
        <?php foreach (array_slice($attention, 0, 5) as $p): ?>
            <a class="list-item" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?> <span class="badge health-<?= e($p['salud']) ?>"><?= e($p['salud']) ?></span></a>
        <?php endforeach; ?>
    </article>
    <article class="card"><h3>Avanzando bien</h3>
        <?php foreach (array_slice($good, 0, 5) as $p): ?>
            <a class="list-item" href="project_view.php?id=<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?> <strong><?= (int)$p['porcentaje_avance'] ?>%</strong></a>
        <?php endforeach; ?>
    </article>
</section>
<script>
window.dashboardData = {
    estados: <?= json_encode(array_values($byEstado)) ?>,
    estadoLabels: <?= json_encode(array_keys($byEstado)) ?>,
    prioridades: <?= json_encode(array_count_values(array_map(fn($p)=>(int)$p['prioridad_real'],$projects))) ?>
};
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
