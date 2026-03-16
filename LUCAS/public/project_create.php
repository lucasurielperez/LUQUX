<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_functions.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $errors] = create_project($_POST);
    if ($ok) {
        flash_set('success', 'Proyecto creado correctamente.');
        redirect('projects.php');
    }
    $project = $_POST;
}
$title = 'Crear proyecto';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
include __DIR__ . '/../includes/project_form.php';
include __DIR__ . '/../includes/footer.php';
