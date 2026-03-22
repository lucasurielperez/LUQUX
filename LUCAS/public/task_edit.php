<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/task_functions.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$task = fetch_task($id);
if (!$task) {
    flash_set('error', 'Tarea no encontrada.');
    redirect('projects.php');
}
$proyectoId = (int) $task['proyecto_id'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['proyecto_id'] = $proyectoId;
    [$ok, $errors] = update_task($id, $_POST);
    if ($ok) {
        flash_set('success', 'Tarea actualizada.');
        redirect('project_view.php?id=' . $proyectoId);
    }
    $task = array_merge($task, $_POST);
}
$title = 'Editar tarea';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
include __DIR__ . '/../includes/task_form.php';
include __DIR__ . '/../includes/footer.php';
