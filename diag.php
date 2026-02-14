<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "DIAG OK\n";
echo "DIR: " . __DIR__ . "\n";
echo "PHP: " . PHP_VERSION . "\n";

$files = [
  'config.php',
  'error_logger.php',
  'virus_lib.php',
  'api.php',
  'admin/config.php',
  'admin/virus_lib.php',
  'admin/api.php',
];

foreach ($files as $f) {
  $p = __DIR__ . '/' . $f;
  echo $f . ": " . (file_exists($p) ? "EXISTS" : "MISSING") . "\n";
}

echo "\nTrying include api.php...\n";
require __DIR__ . '/api.php';
echo "api.php included OK\n";
