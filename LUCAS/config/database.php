<?php

declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'lucas_projects';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    static $bootstrapped = false;

    if ($pdo instanceof PDO) {
        if (!$bootstrapped) {
            ensure_portal_schema($pdo);
            $bootstrapped = true;
        }
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        ensure_portal_schema($pdo);
        $bootstrapped = true;
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Error de conexión a base de datos. Revisá config/database.php y asegurate de importar sql/schema.sql.');
    }

    return $pdo;
}

function ensure_portal_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(40) NOT NULL UNIQUE,
        display_name VARCHAR(120) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        rol ENUM('superusuario','usuario') NOT NULL DEFAULT 'usuario',
        estado ENUM('pendiente','activo') NOT NULL DEFAULT 'pendiente',
        remember_token_hash CHAR(64) NULL,
        remember_token_expires_at DATETIME NULL,
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_users_estado (estado),
        INDEX idx_users_rol (rol)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensure_column($pdo, 'projects', 'user_id', 'BIGINT UNSIGNED NULL AFTER id');
    ensure_column($pdo, 'tasks', 'user_id', 'BIGINT UNSIGNED NULL AFTER proyecto_id');
    ensure_column($pdo, 'recurring_tasks', 'user_id', 'BIGINT UNSIGNED NULL AFTER id');

    ensure_index($pdo, 'projects', 'idx_projects_user', 'CREATE INDEX idx_projects_user ON projects(user_id)');
    ensure_index($pdo, 'tasks', 'idx_tasks_user', 'CREATE INDEX idx_tasks_user ON tasks(user_id)');
    ensure_index($pdo, 'recurring_tasks', 'idx_recurring_tasks_user', 'CREATE INDEX idx_recurring_tasks_user ON recurring_tasks(user_id)');

    $lucasId = ensure_superuser($pdo);

    $pdo->prepare('UPDATE projects SET user_id = :user_id WHERE user_id IS NULL')->execute(['user_id' => $lucasId]);
    $pdo->prepare('UPDATE recurring_tasks SET user_id = :user_id WHERE user_id IS NULL')->execute(['user_id' => $lucasId]);
    $pdo->exec('UPDATE tasks t INNER JOIN projects p ON p.id = t.proyecto_id SET t.user_id = p.user_id WHERE t.user_id IS NULL');
    $pdo->prepare('UPDATE tasks SET user_id = :user_id WHERE user_id IS NULL')->execute(['user_id' => $lucasId]);

    ensure_foreign_key($pdo, 'projects', 'fk_projects_user', 'ALTER TABLE projects ADD CONSTRAINT fk_projects_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    ensure_foreign_key($pdo, 'tasks', 'fk_tasks_user', 'ALTER TABLE tasks ADD CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    ensure_foreign_key($pdo, 'recurring_tasks', 'fk_recurring_tasks_user', 'ALTER TABLE recurring_tasks ADD CONSTRAINT fk_recurring_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
}

function ensure_superuser(PDO $pdo): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => 'lucas']);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE users SET display_name = :display_name, password_hash = :password_hash, rol = :rol, estado = :estado WHERE id = :id')
            ->execute([
                'display_name' => 'Lucas',
                'password_hash' => password_hash('12345678', PASSWORD_DEFAULT),
                'rol' => 'superusuario',
                'estado' => 'activo',
                'id' => $existingId,
            ]);
        return (int) $existingId;
    }

    $pdo->prepare('INSERT INTO users (username, display_name, password_hash, rol, estado) VALUES (:username, :display_name, :password_hash, :rol, :estado)')
        ->execute([
            'username' => 'lucas',
            'display_name' => 'Lucas',
            'password_hash' => password_hash('12345678', PASSWORD_DEFAULT),
            'rol' => 'superusuario',
            'estado' => 'activo',
        ]);

    return (int) $pdo->lastInsertId();
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}

function ensure_index(PDO $pdo, string $table, string $indexName, string $sql): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name');
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $indexName,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}

function ensure_foreign_key(PDO $pdo, string $table, string $constraintName, string $sql): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = :constraint_name');
    $stmt->execute([
        'table_name' => $table,
        'constraint_name' => $constraintName,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec($sql);
    }
}
