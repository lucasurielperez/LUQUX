<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

$user = current_user();
$isAuthPage = in_array(basename($_SERVER['PHP_SELF'] ?? ''), ['login.php', 'register.php'], true);
?>
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
    <a class="brand" href="<?= $user ? 'dashboard.php' : 'login.php' ?>">LUCAS</a>
    <nav>
        <?php if ($user): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="projects.php">Proyectos</a>
            <a href="recurring_tasks.php">Rutinas</a>
            <?php if (is_superuser()): ?>
                <a href="user_admin.php">Usuarios</a>
            <?php endif; ?>
            <a href="project_create.php" class="btn-primary">+ Nuevo</a>
            <span class="nav-user">Hola, <?= e($user['display_name']) ?></span>
            <a href="logout.php">Salir</a>
        <?php elseif ($isAuthPage): ?>
            <a href="login.php">Ingresar</a>
            <a href="register.php">Crear cuenta</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?php if ($flash = flash_get()): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
