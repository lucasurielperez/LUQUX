<?php $rtask = $rtask ?? ['titulo'=>'','descripcion'=>'','frecuencia'=>'diaria','estado'=>'pendiente','prioridad'=>'alta']; ?>
<form method="post" class="card form-grid">
<label>Título <input required name="titulo" value="<?= e((string)$rtask['titulo']) ?>"></label>
<label>Descripción <textarea name="descripcion"><?= e((string)$rtask['descripcion']) ?></textarea></label>
<label>Frecuencia
    <select name="frecuencia"><?php foreach(recurring_task_frequencies() as $f): ?><option value="<?= e($f) ?>" <?= $rtask['frecuencia']===$f?'selected':'' ?>><?= e($f) ?></option><?php endforeach; ?></select>
</label>
<label>Estado
    <select name="estado"><?php foreach(recurring_task_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $rtask['estado']===$s?'selected':'' ?>><?= e($s) ?></option><?php endforeach; ?></select>
</label>
<label>Prioridad
    <select name="prioridad"><?php foreach(recurring_task_priorities() as $p): ?><option value="<?= e($p) ?>" <?= $rtask['prioridad']===$p?'selected':'' ?>><?= e($p) ?></option><?php endforeach; ?></select>
</label>
<button class="btn-primary" type="submit">Guardar tarea recurrente</button>
</form>
