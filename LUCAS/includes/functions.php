<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function today(): DateTimeImmutable
{
    return new DateTimeImmutable('today');
}

function format_partial_deadline(?string $precision, ?int $year, ?int $month, ?int $day): string
{
    if (!$precision || !$year) {
        return 'Sin fecha límite';
    }

    return match ($precision) {
        'year' => (string) $year,
        'month' => sprintf('%02d/%04d', (int) $month, $year),
        'day' => sprintf('%02d/%02d/%04d', (int) $day, (int) $month, $year),
        default => 'Sin fecha límite',
    };
}

function deadline_to_date(?string $precision, ?int $year, ?int $month, ?int $day): ?DateTimeImmutable
{
    if (!$precision || !$year) {
        return null;
    }

    if ($precision === 'year') {
        return DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-12-31', $year)) ?: null;
    }

    if ($precision === 'month' && $month) {
        $lastDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        return DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $lastDay)) ?: null;
    }

    if ($precision === 'day' && $month && $day) {
        return DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: null;
    }

    return null;
}

function validate_partial_deadline(?string $precision, ?int $year, ?int $month, ?int $day): array
{
    if (!$precision) {
        return [true, null, null, null, null];
    }

    if (!in_array($precision, ['year', 'month', 'day'], true) || !$year || $year < 2000 || $year > 2100) {
        return [false, 'Fecha límite inválida.', null, null, null];
    }

    if ($precision === 'year') {
        return [true, null, $year, null, null];
    }

    if (!$month || $month < 1 || $month > 12) {
        return [false, 'Mes inválido para la fecha límite.', null, null, null];
    }

    if ($precision === 'month') {
        return [true, null, $year, $month, null];
    }

    if (!$day || !checkdate($month, $day, $year)) {
        return [false, 'Día inválido para la fecha límite.', null, null, null];
    }

    return [true, null, $year, $month, $day];
}
