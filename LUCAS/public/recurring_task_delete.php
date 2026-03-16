<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recurring_task_functions.php';

$id = (int) ($_GET['id'] ?? 0);
delete_recurring_task($id);
flash_set('success', 'Tarea recurrente eliminada.');
redirect('recurring_tasks.php');
