<?php
$logFile = __DIR__ . '/../device_log.txt';
$sn = $_GET['SN'] ?? 'UNKNOWN';
$raw = file_get_contents('php://input');
@file_put_contents($logFile, date('Y-m-d H:i:s') . " PUSH SN=$sn METHOD=" . $_SERVER['REQUEST_METHOD'] . "\n$raw\n---\n", FILE_APPEND);

$session = strtoupper(substr(md5($sn . date('Ymd')), 0, 10));
$r  = "ServerVersion=2.4.1\r\n";
$r .= "ServerName=GYMONE\r\n";
$r .= "PushVersion=2.4.1\r\n";
$r .= "ErrorDelay=30\r\n";
$r .= "RequestDelay=6\r\n";
$r .= "TransTimes=00:00;14:05\r\n";
$r .= "TransInterval=1\r\n";
$r .= "TransTables=User Transaction Facev7 templatev10 biophoto biodata errorlog\r\n";
$r .= "Realtime=1\r\n";
$r .= "SessionID=$session\r\n";
$r .= "TimeoutSec=10\r\n";
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($r));
echo $r;
