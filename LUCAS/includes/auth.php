<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

const REMEMBER_ME_COOKIE = 'lucas_remember';
const REMEMBER_ME_DAYS = 30;

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function current_user(): ?array
{
    static $resolved = false;
    static $user = null;

    start_secure_session();

    if ($resolved) {
        return $user;
    }

    $resolved = true;

    if (isset($_SESSION['user_id'])) {
        $user = find_user_by_id((int) $_SESSION['user_id']);
        if ($user && $user['estado'] === 'activo') {
            return $user;
        }
        logout_user();
        return null;
    }

    $token = $_COOKIE[REMEMBER_ME_COOKIE] ?? '';
    if ($token !== '') {
        $candidate = find_user_by_remember_token($token);
        if ($candidate && $candidate['estado'] === 'activo') {
            complete_login($candidate, true);
            $user = $candidate;
            return $user;
        }
        clear_remember_me_cookie();
    }

    return null;
}

function current_user_id(): int
{
    $user = current_user();
    return $user ? (int) $user['id'] : 0;
}

function is_superuser(): bool
{
    $user = current_user();
    return $user && $user['rol'] === 'superusuario';
}

function require_login(): void
{
    if (current_user()) {
        return;
    }

    flash_set('error', 'Iniciá sesión para continuar.');
    redirect('login.php');
}

function require_superuser(): void
{
    require_login();
    if (!is_superuser()) {
        flash_set('error', 'Solo lucas puede administrar usuarios.');
        redirect('dashboard.php');
    }
}

function find_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->execute(['username' => mb_strtolower(trim($username))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_user_by_remember_token(string $token): ?array
{
    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT * FROM users WHERE remember_token_hash = :hash AND remember_token_expires_at > NOW()');
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function login_user(string $username, string $password, bool $rememberMe = false): array
{
    $user = find_user_by_username($username);

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        return [false, 'Usuario o contraseña incorrectos.'];
    }

    if ($user['estado'] !== 'activo') {
        return [false, 'Tu usuario todavía no fue aprobado por lucas.'];
    }

    complete_login($user, $rememberMe);
    return [true, null];
}

function complete_login(array $user, bool $rememberMe): void
{
    start_secure_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    if ($rememberMe) {
        remember_user((int) $user['id']);
        return;
    }

    forget_user_remember_token((int) $user['id']);
    clear_remember_me_cookie();
}

function logout_user(): void
{
    start_secure_session();
    if (isset($_SESSION['user_id'])) {
        forget_user_remember_token((int) $_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }
    session_destroy();
    clear_remember_me_cookie();
}

function remember_user(int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('now'))->modify('+' . REMEMBER_ME_DAYS . ' days');

    db()->prepare('UPDATE users SET remember_token_hash = :hash, remember_token_expires_at = :expires WHERE id = :id')
        ->execute([
            'hash' => hash('sha256', $token),
            'expires' => $expires->format('Y-m-d H:i:s'),
            'id' => $userId,
        ]);

    setcookie(REMEMBER_ME_COOKIE, $token, [
        'expires' => $expires->getTimestamp(),
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_me_cookie(): void
{
    setcookie(REMEMBER_ME_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function forget_user_remember_token(int $userId): void
{
    db()->prepare('UPDATE users SET remember_token_hash = NULL, remember_token_expires_at = NULL WHERE id = :id')
        ->execute(['id' => $userId]);
}

function validate_registration_data(array $data): array
{
    $errors = [];
    $username = mb_strtolower(trim((string) ($data['username'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $passwordConfirm = (string) ($data['password_confirm'] ?? '');
    $displayName = trim((string) ($data['display_name'] ?? ''));

    if ($displayName === '') {
        $errors[] = 'El nombre para mostrar es obligatorio.';
    }
    if ($username === '' || !preg_match('/^[a-z0-9_.-]{3,40}$/', $username)) {
        $errors[] = 'El usuario debe tener entre 3 y 40 caracteres y usar solo letras minúsculas, números, punto, guion o guion bajo.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Las contraseñas no coinciden.';
    }
    if (find_user_by_username($username)) {
        $errors[] = 'Ese usuario ya existe.';
    }

    if ($errors) {
        return [false, $errors, []];
    }

    return [true, [], [
        'username' => $username,
        'display_name' => $displayName,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]];
}

function register_user(array $data): array
{
    [$ok, $errors, $clean] = validate_registration_data($data);
    if (!$ok) {
        return [false, $errors];
    }

    db()->prepare('INSERT INTO users (username, display_name, password_hash, rol, estado) VALUES (:username, :display_name, :password_hash, :rol, :estado)')
        ->execute([
            'username' => $clean['username'],
            'display_name' => $clean['display_name'],
            'password_hash' => $clean['password_hash'],
            'rol' => 'usuario',
            'estado' => 'pendiente',
        ]);

    return [true, []];
}

function fetch_all_users(): array
{
    return db()->query('SELECT * FROM users ORDER BY FIELD(rol, "superusuario", "usuario"), username ASC')->fetchAll();
}

function approve_user(int $id): bool
{
    if ($id === 0) {
        return false;
    }

    $stmt = db()->prepare('UPDATE users SET estado = :estado WHERE id = :id AND rol != :rol');
    $stmt->execute([
        'estado' => 'activo',
        'id' => $id,
        'rol' => 'superusuario',
    ]);
    return $stmt->rowCount() > 0;
}

function delete_user_account(int $id): bool
{
    $user = find_user_by_id($id);
    if (!$user || $user['rol'] === 'superusuario') {
        return false;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM tasks WHERE user_id = :user_id')->execute(['user_id' => $id]);
        $pdo->prepare('DELETE FROM recurring_tasks WHERE user_id = :user_id')->execute(['user_id' => $id]);
        $pdo->prepare('DELETE FROM projects WHERE user_id = :user_id')->execute(['user_id' => $id]);
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function reset_user_password(int $id): ?string
{
    $user = find_user_by_id($id);
    if (!$user || $user['rol'] === 'superusuario') {
        return null;
    }

    $tempPassword = 'tmp-' . substr(bin2hex(random_bytes(6)), 0, 10);
    db()->prepare('UPDATE users SET password_hash = :password_hash, estado = :estado, remember_token_hash = NULL, remember_token_expires_at = NULL WHERE id = :id')
        ->execute([
            'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
            'estado' => 'activo',
            'id' => $id,
        ]);

    return $tempPassword;
}
