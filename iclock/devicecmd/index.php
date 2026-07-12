<?php
// Endpoint ADMS: /iclock/devicecmd
header('Content-Type: text/plain');

$rawData = file_get_contents('php://input');
$logFile = __DIR__ . '/../device_log.txt';

file_put_contents($logFile, date('Y-m-d H:i:s') . " DEVICECMD\n" . $rawData . "\n---\n", FILE_APPEND);

echo "OK";
exit;
