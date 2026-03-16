<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/project_functions.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    delete_project($id);
    flash_set('success', 'Proyecto eliminado.');
}
redirect('projects.php');
