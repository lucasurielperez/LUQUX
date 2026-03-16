<?php $task = $task ?? ['titulo'=>'','descripcion'=>'','estado'=>'pendiente','prioridad'=>'media','fecha_limite'=>'','orden_manual'=>'']; ?>
<form method="post" class="card form-grid">
<input type="hidden" name="proyecto_id" value="<?= (int)$proyectoId ?>">
<label>Título <input required name="titulo" value="<?= e((string)$task['titulo']) ?>"></label>
<label>Descripción <textarea name="descripcion"><?= e((string)$task['descripcion']) ?></textarea></label>
<label>Estado <select name="estado"><?php foreach(task_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $task['estado']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
<label>Prioridad <select name="prioridad"><?php foreach(task_priorities() as $s): ?><option value="<?= e($s) ?>" <?= $task['prioridad']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
<label>Fecha límite <input type="date" name="fecha_limite" value="<?= e((string)$task['fecha_limite']) ?>"></label>
<label>Orden manual <input type="number" min="1" name="orden_manual" value="<?= e((string)$task['orden_manual']) ?>"></label>
<button class="btn-primary" type="submit">Guardar tarea</button>
</form>
