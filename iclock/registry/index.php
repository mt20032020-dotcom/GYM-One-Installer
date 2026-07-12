<?php
// Endpoint ADMS: /iclock/registry
header('Content-Type: text/plain');

$sn = $_GET['SN'] ?? '';
$rawData = file_get_contents('php://input');
$logFile = __DIR__ . '/../device_log.txt';

file_put_contents($logFile, date('Y-m-d H:i:s') . " REGISTRY\n" . $rawData . "\n---\n", FILE_APPEND);

$registryCode = strtoupper(substr(md5($sn . 'ADRENALINE'), 0, 10));
echo "RegistryCode=$registryCode\r\n";
exit;
