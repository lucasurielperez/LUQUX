<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/task_functions.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$task = fetch_task($id);
if ($task) {
    delete_task($id);
    flash_set('success', 'Tarea eliminada.');
    redirect('project_view.php?id=' . (int)$task['proyecto_id']);
}
redirect('projects.php');
