<?php

const ERROR_LOGGER_DEFAULT_CONTEXT = 'app';

$GLOBALS['ERROR_LOGGER_STATE'] = [
  'initialized' => false,
  'context' => ERROR_LOGGER_DEFAULT_CONTEXT,
  'request_id' => null,
  'log_file' => null,
  'base_dir' => __DIR__,
  'pdo' => null,
];

function error_logger_level_from_errno(int $errno): string {
  if (in_array($errno, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
    return 'ERROR';
  }
  if (in_array($errno, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true)) {
    return 'WARN';
  }
  if (in_array($errno, [E_NOTICE, E_USER_NOTICE, E_STRICT, E_DEPRECATED, E_USER_DEPRECATED], true)) {
    return 'INFO';
  }
  return 'DEBUG';
}

function error_logger_is_fatal_type(int $type): bool {
  return in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
}

function error_logger_request_meta(): array {
  return [
    'url' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
  ];
}

function error_logger_resolve_player_id(): ?int {
  if (!empty($_REQUEST['player_id'])) {
    return (int) $_REQUEST['player_id'];
  }

  $raw = file_get_contents('php://input');
  if ($raw === false || $raw === '') {
    return null;
  }

  $json = json_decode($raw, true);
  if (is_array($json) && !empty($json['player_id'])) {
    return (int) $json['player_id'];
  }

  return null;
}

function error_logger_set_pdo(?PDO $pdo): void {
  $GLOBALS['ERROR_LOGGER_STATE']['pdo'] = $pdo;
}

function error_logger_file_path(?string $baseDir = null): string {
  $dir = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR);
  $projectBase = $baseDir ?? ($GLOBALS['ERROR_LOGGER_STATE']['base_dir'] ?? __DIR__);
  $hash = substr(md5((string) $projectBase), 0, 6);
  return $dir . DIRECTORY_SEPARATOR . 'pcn_' . $hash . '_errors.log';
}

function init_error_logging(array $opts = []): void {
  $context = (string) ($opts['context'] ?? ERROR_LOGGER_DEFAULT_CONTEXT);
  $requestId = (string) ($opts['request_id'] ?? '');
  if ($requestId === '') {
    $requestId = bin2hex(random_bytes(6));
  }

  $baseDir = (string) ($opts['base_dir'] ?? __DIR__);
  $logFile = (string) ($opts['log_file'] ?? error_logger_file_path($baseDir));

  $GLOBALS['ERROR_LOGGER_STATE'] = array_merge($GLOBALS['ERROR_LOGGER_STATE'], [
    'initialized' => true,
    'context' => $context,
    'request_id' => $requestId,
    'log_file' => $logFile,
    'base_dir' => $baseDir,
  ]);

  error_reporting(E_ALL);
  ini_set('display_errors', '0');
  ini_set('log_errors', '1');
  ini_set('error_log', $logFile);

  set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
      return false;
    }

    log_event(error_logger_level_from_errno($errno), $errstr, [
      'file' => $errfile,
      'line' => $errline,
      'errno' => $errno,
    ]);

    return false;
  });

  set_exception_handler(static function (Throwable $e): void {
    log_event('ERROR', $e->getMessage(), [
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'trace' => $e->getTraceAsString(),
      'exception' => get_class($e),
    ]);
  });

  register_shutdown_function(static function (): void {
    $last = error_get_last();
    if (!is_array($last)) {
      return;
    }

    if (!error_logger_is_fatal_type((int) ($last['type'] ?? 0))) {
      return;
    }

    log_event('FATAL', (string) ($last['message'] ?? 'Fatal error'), [
      'file' => (string) ($last['file'] ?? ''),
      'line' => (int) ($last['line'] ?? 0),
      'errno' => (int) ($last['type'] ?? 0),
      'fatal' => true,
    ]);
  });
}

function log_event(string $level, string $message, array $data = []): void {
  $state = $GLOBALS['ERROR_LOGGER_STATE'] ?? [];
  $meta = error_logger_request_meta();

  $record = [
    'timestamp' => date(DATE_ATOM),
    'level' => strtoupper($level),
    'message' => $message,
    'file' => $data['file'] ?? null,
    'line' => isset($data['line']) ? (int) $data['line'] : null,
    'request_id' => $state['request_id'] ?? null,
    'context' => $state['context'] ?? ERROR_LOGGER_DEFAULT_CONTEXT,
    'url' => $meta['url'],
    'method' => $meta['method'],
    'user_agent' => $meta['user_agent'],
    'ip' => $meta['ip'],
    'player_id' => error_logger_resolve_player_id(),
    'extra' => $data,
  ];

  $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json !== false) {
    $logFile = (string) ($state['log_file'] ?? error_logger_file_path());
    @file_put_contents($logFile, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
  }

  $pdo = $state['pdo'] ?? null;
  if ($pdo instanceof PDO) {
    try {
      $stmt = $pdo->prepare(
        'INSERT INTO error_logs (level, message, file, line, request_id, url, method, user_agent, ip, context, extra_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
      );
      $stmt->execute([
        $record['level'],
        $record['message'],
        $record['file'],
        $record['line'],
        (string) ($record['request_id'] ?? ''),
        $record['url'] ?: null,
        $record['method'] ?: null,
        $record['user_agent'] ?: null,
        $record['ip'] ?: null,
        $record['context'] ?: null,
        json_encode($record['extra'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ]);
    } catch (Throwable $e) {
      // Silent fallback to file-only logging.
    }
  }
}
