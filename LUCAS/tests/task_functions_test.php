<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/task_functions.php';

function assert_true(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, sprintf("%s failed.\n", $label));
        exit(1);
    }
}

[$ok, $errors, $clean] = validate_task_data([
    'proyecto_id' => '4',
    'titulo' => 'Actualizar tablero',
    'descripcion' => 'Probar edición',
    'estado' => 'en_progreso',
    'prioridad' => 'alta',
    'fecha_limite' => '2026-03-25',
    'orden_manual' => '3',
]);

assert_true('task data should validate', $ok === true);
assert_true('task validation should not return errors', $errors === []);

$payload = task_update_payload(9, $clean);

assert_true('payload should include id', $payload['id'] === 9);
assert_true('payload should preserve title', $payload['titulo'] === 'Actualizar tablero');
assert_true('payload should preserve status', $payload['estado'] === 'en_progreso');
assert_true('payload should not include project id', array_key_exists('proyecto_id', $payload) === false);

echo "Task update payload tests passed.\n";
