<?php
$project = $project ?? [
 'nombre'=>'','descripcion'=>'','prioridad_personal'=>3,'prioridad_real'=>3,'estado'=>'idea','salud'=>'saludable',
 'fecha_limite_precision'=>'','fecha_limite_anio'=>'','fecha_limite_mes'=>'','fecha_limite_dia'=>''
];
?>
<form method="post" class="card form-grid">
<label>Nombre <input required name="nombre" maxlength="150" value="<?= e((string)$project['nombre']) ?>"></label>
<label>Descripción <textarea required name="descripcion"><?= e((string)$project['descripcion']) ?></textarea></label>
<label>Prioridad personal <input type="number" min="1" max="5" name="prioridad_personal" value="<?= e((string)$project['prioridad_personal']) ?>"></label>
<label>Prioridad real <input type="number" min="1" max="5" name="prioridad_real" value="<?= e((string)$project['prioridad_real']) ?>"></label>
<label>Estado <select name="estado"><?php foreach(project_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $project['estado']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>

<div class="deadline-block">
<h4>Fecha límite (opcional)</h4>
<label>Precisión <select name="fecha_limite_precision" id="deadline_precision">
<option value="" <?= $project['fecha_limite_precision']===''?'selected':'' ?>>Sin fecha</option>
<option value="year" <?= $project['fecha_limite_precision']==='year'?'selected':'' ?>>Solo año</option>
<option value="month" <?= $project['fecha_limite_precision']==='month'?'selected':'' ?>>Año y mes</option>
<option value="day" <?= $project['fecha_limite_precision']==='day'?'selected':'' ?>>Fecha completa</option>
</select></label>
<div class="deadline-fields">
<input type="number" name="fecha_limite_anio" placeholder="Año" min="2000" max="2100" value="<?= e((string)$project['fecha_limite_anio']) ?>">
<input type="number" name="fecha_limite_mes" placeholder="Mes" min="1" max="12" value="<?= e((string)$project['fecha_limite_mes']) ?>">
<input type="number" name="fecha_limite_dia" placeholder="Día" min="1" max="31" value="<?= e((string)$project['fecha_limite_dia']) ?>">
</div>
</div>
<button class="btn-primary" type="submit">Guardar proyecto</button>
</form>
