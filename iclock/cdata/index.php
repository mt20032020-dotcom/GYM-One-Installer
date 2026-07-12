<?php
$logFile = __DIR__ . '/../device_log.txt';
$sn = $_GET['SN'] ?? 'UNKNOWN';

function responder($body) {
    header('Content-Type: text/plain');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " CDATA-POST SN=$sn QS=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n$raw\n---\n", FILE_APPEND);
    responder("OK");
}

@file_put_contents($logFile, date('Y-m-d H:i:s') . " CDATA-GET SN=$sn QS=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n", FILE_APPEND);

if (($_GET['options'] ?? '') === 'all') {
    $r  = "GET OPTION FROM: $sn\r\n";
    $r .= "ATTLOGStamp=None\r\n";
    $r .= "OPERLOGStamp=None\r\n";
    $r .= "ATTPHOTOStamp=None\r\n";
    $r .= "ErrorDelay=30\r\n";
    $r .= "Delay=10\r\n";
    $r .= "TransTimes=00:00;14:05\r\n";
    $r .= "TransInterval=1\r\n";
    $r .= "TransFlag=TransData AttLog OpLog AttPhoto EnrollUser ChgUser EnrollFP ChgFP FACE UserPic\r\n";
    $r .= "TimeZone=-5\r\n";
    $r .= "Realtime=1\r\n";
    $r .= "Encrypt=None\r\n";
    responder($r);
}
responder("OK");
