<?php
// error_logger.php (mínimo y seguro)
// Importante: NO usar ob_clean/ob_end_clean, NO hacer exit/die,
// NO tocar el output buffer. Solo registrar.

declare(strict_types=1);

$GLOBALS['__errlog_ctx'] = [
  'context' => 'unknown',
  'request_id' => null,
  'base_dir' => __DIR__,
];

$GLOBALS['__errlog_pdo'] = null;

function init_error_logging(array $ctx = []): void {
  $GLOBALS['__errlog_ctx'] = array_merge($GLOBALS['__errlog_ctx'] ?? [], $ctx);

  // Reportar errores al log del servidor (no al output)
  error_reporting(E_ALL);
  ini_set('display_errors', '0');

  set_error_handler(function($errno, $errstr, $errfile, $errline) {
    log_event('PHP_WARNING', $errstr, [
      'errno' => $errno,
      'file' => $errfile,
      'line' => $errline,
    ]);
    // Dejar que PHP siga su curso normal
    return false;
  });

  set_exception_handler(function(Throwable $e) {
    // Loguear, pero NO borrar output ni hacer exit acá.
    log_event('UNCAUGHT_EXCEPTION', $e->getMessage(), [
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'trace' => $e->getTraceAsString(),
    ]);
  });
}

function error_logger_set_pdo(PDO $pdo): void {
  $GLOBALS['__errlog_pdo'] = $pdo;
}

function error_logger_file_path(string $baseDir): string {
  // Log a archivo JSONL dentro del proyecto
  $dir = rtrim($baseDir, '/');
  return $dir . '/error_log.jsonl';
}

function log_event(string $level, string $message, array $extra = []): void {
  $ctx = $GLOBALS['__errlog_ctx'] ?? [];
  $row = [
    'timestamp'  => gmdate('c'),
    'level'      => $level,
    'message'    => $message,
    'request_id' => $ctx['request_id'] ?? null,
    'context'    => $ctx['context'] ?? null,
    'url'        => $_SERVER['REQUEST_URI'] ?? null,
    'method'     => $_SERVER['REQUEST_METHOD'] ?? null,
    'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
    'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'extra'      => $extra,
  ];

  // 1) Siempre al error_log del servidor
  error_log('[API] ' . json_encode($row, JSON_UNESCAPED_UNICODE));

  // 2) Además, a archivo JSONL
  $baseDir = (string)($ctx['base_dir'] ?? __DIR__);
  $file = error_logger_file_path($baseDir);
  @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}
