<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_functions.php';

$id = (int) ($_GET['id'] ?? 0);
$project = fetch_project($id);
if (!$project) {
    flash_set('error', 'Proyecto no encontrado.');
    redirect('projects.php');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $errors] = update_project($id, $_POST);
    if ($ok) {
        flash_set('success', 'Proyecto actualizado.');
        redirect('project_view.php?id=' . $id);
    }
    $project = array_merge($project, $_POST);
}
$title = 'Editar proyecto';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
include __DIR__ . '/../includes/project_form.php';
include __DIR__ . '/../includes/footer.php';
