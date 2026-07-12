<?php
// Endpoint ADMS: /iclock/cdata
// Maneja el handshake inicial (GET) y la subida de datos del dispositivo (POST)

header('Content-Type: text/plain');

$sn = $_GET['SN'] ?? '';
$logFile = __DIR__ . '/../device_log.txt';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // El equipo pide opciones de configuración (handshake)
    $response = "GET OPTION FROM: {$sn}\r\n";
    $response .= "Stamp=9999\r\n";
    $response .= "OpStamp=9999\r\n";
    $response .= "ErrorDelay=30\r\n";
    $response .= "Delay=30\r\n";
    $response .= "TransTimes=00:00;14:05\r\n";
    $response .= "TransInterval=1\r\n";
    $response .= "TransFlag=TransData AttLog OpLog AttPhoto EnrollFP EnrollUser ChgUser EnrollFace ChgFP\r\n";
    $response .= "Realtime=1\r\n";
    $response .= "Encrypt=0\r\n";
    $response .= "SessionID=" . strtoupper(substr(md5($sn . 'ADRENALINE'), 0, 10)) . "\r\n";

    echo $response;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " GET SN={$sn}\n" . $response . "\n---\n", FILE_APPEND);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // El equipo envía datos (marcaciones, logs, fotos, etc.)
    $rawData = file_get_contents('php://input');
    file_put_contents($logFile, date('Y-m-d H:i:s') . " POST SN={$sn}\n" . $rawData . "\n---\n", FILE_APPEND);

    // TODO: aquí procesamos e insertamos en la base de datos real
    echo "OK";
    exit;
}
