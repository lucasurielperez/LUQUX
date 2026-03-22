<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/task_functions.php';
require_once __DIR__ . '/../includes/project_functions.php';
require_login();

$proyectoId = (int) ($_GET['proyecto_id'] ?? $_POST['proyecto_id'] ?? 0);
if (!fetch_project($proyectoId)) {
    flash_set('error', 'Proyecto inválido.');
    redirect('projects.php');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $errors] = create_task($_POST);
    if ($ok) {
        flash_set('success', 'Tarea creada.');
        redirect('project_view.php?id=' . $proyectoId);
    }
    $task = $_POST;
}
$title = 'Crear tarea';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
include __DIR__ . '/../includes/task_form.php';
include __DIR__ . '/../includes/footer.php';
