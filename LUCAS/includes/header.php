<?php if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'LUCAS Projects') ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="dashboard.php">LUCAS</a>
    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="projects.php">Proyectos</a>
        <a href="recurring_tasks.php">Rutinas</a>
        <a href="project_create.php" class="btn-primary">+ Nuevo</a>
    </nav>
</header>
<main class="container">
<?php if ($flash = flash_get()): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
