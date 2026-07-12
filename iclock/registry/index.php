<?php
// Endpoint ADMS: /iclock/registry
header('Content-Type: text/plain');

$rawData = file_get_contents('php://input');
$logFile = __DIR__ . '/../device_log.txt';

file_put_contents($logFile, date('Y-m-d H:i:s') . " REGISTRY\n" . $rawData . "\n---\n", FILE_APPEND);

echo "OK";
exit;
