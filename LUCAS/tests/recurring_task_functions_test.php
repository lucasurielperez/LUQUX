<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/recurring_task_functions.php';

function assert_same(string $label, string $expected, string $actual): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("%s failed. Expected %s, got %s\n", $label, $expected, $actual));
        exit(1);
    }
}

$base = new DateTimeImmutable('2026-03-18 15:42:10');

assert_same('daily recurrence', '2026-03-19 00:00:00', calculate_next_recurring_date('diaria', $base)->format('Y-m-d H:i:s'));
assert_same('weekly recurrence', '2026-03-25 00:00:00', calculate_next_recurring_date('semanal', $base)->format('Y-m-d H:i:s'));
assert_same('monthly recurrence', '2026-04-18 00:00:00', calculate_next_recurring_date('mensual', $base)->format('Y-m-d H:i:s'));
assert_same('yearly recurrence', '2027-03-18 00:00:00', calculate_next_recurring_date('anual', $base)->format('Y-m-d H:i:s'));

echo "Recurring task scheduling tests passed.\n";
