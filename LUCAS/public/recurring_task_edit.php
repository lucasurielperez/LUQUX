<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/recurring_task_functions.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$rtask = fetch_recurring_task($id);
if (!$rtask) {
    flash_set('error', 'Tarea recurrente no encontrada.');
    redirect('recurring_tasks.php');
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $errors] = update_recurring_task($id, $_POST);
    if ($ok) {
        flash_set('success', 'Tarea recurrente actualizada.');
        redirect('recurring_tasks.php');
    }
    $rtask = array_merge($rtask, $_POST);
}

$title = 'Editar tarea recurrente';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
include __DIR__ . '/../includes/recurring_task_form.php';
include __DIR__ . '/../includes/footer.php';
