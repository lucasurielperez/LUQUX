<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_superuser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'approve') {
        $ok = approve_user($userId);
        flash_set($ok ? 'success' : 'error', $ok ? 'Usuario aprobado.' : 'No se pudo aprobar el usuario.');
    } elseif ($action === 'delete') {
        $ok = delete_user_account($userId);
        flash_set($ok ? 'success' : 'error', $ok ? 'Usuario eliminado junto con sus datos.' : 'No se pudo eliminar el usuario.');
    } elseif ($action === 'reset_password') {
        $tempPassword = reset_user_password($userId);
        flash_set($tempPassword ? 'success' : 'error', $tempPassword ? 'Contraseña reseteada. Temporal: ' . $tempPassword : 'No se pudo resetear la contraseña.');
    }

    redirect('user_admin.php');
}

$users = fetch_all_users();
$title = 'Administrar usuarios';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <div class="card-head">
        <div>
            <h2>Administración de usuarios</h2>
            <p class="muted-text">Solo lucas puede aceptar altas, borrar cuentas y resetear contraseñas.</p>
        </div>
    </div>

    <div class="user-admin-table">
        <div class="user-admin-row user-admin-head">
            <strong>Usuario</strong>
            <strong>Nombre</strong>
            <strong>Rol</strong>
            <strong>Estado</strong>
            <strong>Acciones</strong>
        </div>
        <?php foreach ($users as $user): ?>
            <div class="user-admin-row">
                <span><?= e($user['username']) ?></span>
                <span><?= e($user['display_name']) ?></span>
                <span><span class="badge"><?= e($user['rol']) ?></span></span>
                <span><span class="badge <?= $user['estado'] === 'activo' ? 'state-en_progreso' : 'warn' ?>"><?= e($user['estado']) ?></span></span>
                <div class="actions compact-actions">
                    <?php if ($user['rol'] !== 'superusuario'): ?>
                        <?php if ($user['estado'] !== 'activo'): ?>
                            <form method="post">
                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit">Aceptar</button>
                            </form>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input type="hidden" name="action" value="reset_password">
                            <button type="submit">Reset contraseña</button>
                        </form>
                        <form method="post" onsubmit="return confirm('¿Eliminar usuario y todos sus datos?')">
                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit">Borrar</button>
                        </form>
                    <?php else: ?>
                        <span class="muted-text">Cuenta protegida</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
