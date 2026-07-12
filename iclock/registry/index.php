<?php
$logFile = __DIR__ . '/../device_log.txt';
$sn = $_GET['SN'] ?? 'UNKNOWN';
$raw = file_get_contents('php://input');
@file_put_contents($logFile, date('Y-m-d H:i:s') . " REGISTRY SN=$sn\n$raw\n---\n", FILE_APPEND);

$code = strtoupper(substr(md5('adrenaline-' . $sn), 0, 16));
$body = "RegistryCode=$code";
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
