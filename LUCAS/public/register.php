<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
$form = ['display_name' => '', 'username' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
    $form['username'] = mb_strtolower(trim((string) ($_POST['username'] ?? '')));

    [$ok, $errors] = register_user($_POST);
    if ($ok) {
        flash_set('success', 'Tu solicitud fue enviada. Cuando lucas la apruebe podrás ingresar.');
        redirect('login.php');
    }
}

$title = 'Crear cuenta';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
?>
<section class="auth-shell">
    <form method="post" class="card auth-card">
        <h1>Crear cuenta</h1>
        <p>Completá tus datos y el superusuario lucas podrá aceptar tu acceso al portal.</p>
        <label>Nombre para mostrar
            <input required name="display_name" maxlength="120" value="<?= e($form['display_name']) ?>" autocomplete="name">
        </label>
        <label>Usuario
            <input required name="username" maxlength="40" value="<?= e($form['username']) ?>" autocomplete="username">
        </label>
        <label>Contraseña
            <input required type="password" name="password" minlength="8" autocomplete="new-password">
        </label>
        <label>Repetir contraseña
            <input required type="password" name="password_confirm" minlength="8" autocomplete="new-password">
        </label>
        <button class="btn-primary" type="submit">Enviar solicitud</button>
        <p class="auth-links">¿Ya tenés acceso? <a href="login.php">Volver al login</a></p>
    </form>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
