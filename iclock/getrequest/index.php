<?php
$logFile = __DIR__ . '/../device_log.txt';
$cmdFile = __DIR__ . '/../cmd_queue.txt';
$sn = $_GET['SN'] ?? 'UNKNOWN';

// Latido: guarda la última visita sin engordar el log
@file_put_contents(__DIR__ . '/../last_poll.txt', date('Y-m-d H:i:s') . " SN=$sn");

$body = "OK";
if (file_exists($cmdFile)) {
    $cmd = trim(file_get_contents($cmdFile));
    if ($cmd !== '') {
        $body = $cmd;
        @unlink($cmdFile); // se entrega UNA sola vez
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " GETREQUEST >>> COMANDO ENVIADO: $cmd\n", FILE_APPEND);
    }
}

header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
