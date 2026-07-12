<?php
// Endpoint ADMS: /iclock/getrequest
// El equipo consulta si hay comandos pendientes (ENROLL_BIO, DATA UPDATE, etc.)

header('Content-Type: text/plain');

$sn = $_GET['SN'] ?? '';
$logFile = __DIR__ . '/../device_log.txt';

file_put_contents($logFile, date('Y-m-d H:i:s') . " GETREQUEST SN={$sn}\n", FILE_APPEND);

// TODO: aquí consultamos una tabla de "comandos pendientes" en la base de datos
exit;
