<?php
$logFile = __DIR__ . '/../device_log.txt';
$sn = $_GET['SN'] ?? 'UNKNOWN';
$raw = file_get_contents('php://input');
@file_put_contents($logFile, date('Y-m-d H:i:s') . " DEVICECMD SN=$sn\n$raw\n---\n", FILE_APPEND);
$body = "OK";
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
