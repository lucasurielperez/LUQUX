<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}

$errors = [];
$form = ['username' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['username'] = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $rememberMe = isset($_POST['remember_me']);

    [$ok, $error] = login_user($form['username'], $password, $rememberMe);
    if ($ok) {
        flash_set('success', 'Bienvenido a LUCAS.');
        redirect('dashboard.php');
    }

    if ($error) {
        $errors[] = $error;
    }
}

$title = 'Iniciar sesión';
include __DIR__ . '/../includes/header.php';
if ($errors) echo '<div class="alert alert-error">' . e(implode(' ', $errors)) . '</div>';
?>
<section class="auth-shell">
    <form method="post" class="card auth-card">
        <h1>Ingresar a LUCAS</h1>
        <p>Accedé con tu usuario del portal. Si todavía no tenés acceso, registrate y lucas aprobará la cuenta.</p>
        <label>Usuario
            <input required name="username" maxlength="40" value="<?= e($form['username']) ?>" autocomplete="username">
        </label>
        <label>Contraseña
            <input required type="password" name="password" minlength="8" autocomplete="current-password">
        </label>
        <label class="check-row">
            <input type="checkbox" name="remember_me" value="1">
            <span>¡Recordarme!</span>
        </label>
        <button class="btn-primary" type="submit">Entrar</button>
        <p class="muted-text">Superusuario inicial: <strong>lucas</strong> / <strong>12345678</strong>.</p>
        <p class="auth-links">¿No tenés usuario? <a href="register.php">Crear cuenta</a></p>
    </form>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
