<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/project_functions.php';
require_login();

refresh_all_projects();
$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'estado' => $_GET['estado'] ?? '',
    'salud' => $_GET['salud'] ?? '',
    'prioridad_personal' => $_GET['prioridad_personal'] ?? '',
    'prioridad_real' => $_GET['prioridad_real'] ?? '',
    'vencimiento' => $_GET['vencimiento'] ?? '',
    'orden' => $_GET['orden'] ?? 'actualizacion',
];
$projects = fetch_projects($filters);
$title = 'Proyectos';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
<form class="filters" method="get">
    <input type="text" name="q" placeholder="Buscar por nombre o descripción" value="<?= e($filters['q']) ?>">
    <select name="estado"><option value="">Estado</option><?php foreach(project_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $filters['estado']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
    <select name="salud"><option value="">Salud</option><?php foreach(health_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $filters['salud']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
    <select name="prioridad_personal"><option value="">Prioridad personal</option><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>" <?= (string)$i===$filters['prioridad_personal']?'selected':'' ?>><?= $i ?></option><?php endfor; ?></select>
    <select name="prioridad_real"><option value="">Prioridad real</option><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>" <?= (string)$i===$filters['prioridad_real']?'selected':'' ?>><?= $i ?></option><?php endfor; ?></select>
    <select name="orden">
        <option value="actualizacion">Orden: actualización</option><option value="prioridad_real" <?= $filters['orden']==='prioridad_real'?'selected':'' ?>>Prioridad real</option>
        <option value="prioridad_personal" <?= $filters['orden']==='prioridad_personal'?'selected':'' ?>>Prioridad personal</option>
        <option value="fecha_limite" <?= $filters['orden']==='fecha_limite'?'selected':'' ?>>Fecha límite</option>
        <option value="avance" <?= $filters['orden']==='avance'?'selected':'' ?>>Avance</option>
        <option value="nombre" <?= $filters['orden']==='nombre'?'selected':'' ?>>Nombre</option>
    </select>
    <button class="btn-primary" type="submit">Aplicar</button>
    <a href="projects.php">Limpiar</a>
</form>
</section>

<section class="cards-grid">
<?php foreach($projects as $p): $misalign = abs((int)$p['prioridad_personal']-(int)$p['prioridad_real']); ?>
<article class="card project-card">
    <div class="card-head"><h3><?= e($p['nombre']) ?></h3><span class="badge state-<?= e($p['estado']) ?>"><?= e($p['estado']) ?></span></div>
    <p><?= e(mb_strimwidth($p['descripcion'], 0, 130, '...')) ?></p>
    <div class="badges"><span class="badge health-<?= e($p['salud']) ?>">Salud: <?= e($p['salud']) ?></span>
        <span class="badge">P. personal <?= (int)$p['prioridad_personal'] ?></span><span class="badge">P. real <?= (int)$p['prioridad_real'] ?></span>
        <?php if($misalign>=2): ?><span class="badge warn">Desalineado</span><?php endif; ?>
    </div>
    <div class="progress"><div style="width: <?= (int)$p['porcentaje_avance'] ?>%"></div></div>
    <small><?= (int)$p['porcentaje_avance'] ?>% · <?= (int)$p['tareas_hechas'] ?>/<?= (int)$p['total_tareas'] ?> tareas</small>
    <small>Fecha límite: <?= e(format_partial_deadline($p['fecha_limite_precision'], (int)$p['fecha_limite_anio'], (int)$p['fecha_limite_mes'], (int)$p['fecha_limite_dia'])) ?></small>
    <small>Actualizado: <?= e($p['fecha_actualizacion']) ?></small>
    <div class="actions"><a href="project_view.php?id=<?= (int)$p['id'] ?>">Ver detalle</a> <a href="project_edit.php?id=<?= (int)$p['id'] ?>">Editar</a></div>
</article>
<?php endforeach; ?>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
