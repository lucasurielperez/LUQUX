<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recurring_task_functions.php';
require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $errors] = create_recurring_task($_POST);
    if ($ok) {
        flash_set('success', 'Tarea recurrente creada.');
        redirect('recurring_tasks.php');
    }
    $rtask = $_POST;
}

$title = 'Crear tarea recurrente';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
include __DIR__ . '/../includes/recurring_task_form.php';
include __DIR__ . '/../includes/footer.php';
