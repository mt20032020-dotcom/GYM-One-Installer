<?php
ini_set('display_errors', '0');
error_reporting(0);
session_start();
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json');
if (!isset($_SESSION['adminuser'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'No autorizado']); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Solo POST']); exit(); }

$cmdFile = '/app/iclock/cmd_queue.txt';
$id = (string) random_int(100000, 999999);

$fh = @fopen($cmdFile, 'a');
if (!$fh) { @unlink($cmdFile); $fh = @fopen($cmdFile, 'a'); }
if (!$fh) { echo json_encode(['ok'=>false,'error'=>'Cola inaccesible']); exit(); }
flock($fh, LOCK_EX);
fwrite($fh, "C:{$id}:CONTROL DEVICE 01010105\n");
flock($fh, LOCK_UN);
fclose($fh);
@chmod($cmdFile, 0666);

@file_put_contents('/app/iclock/puerta_log.txt', date('Y-m-d H:i:s') . " apertura manual admin=" . $_SESSION['adminuser'] . "\n", FILE_APPEND);
@chmod('/app/iclock/puerta_log.txt', 0666);

echo json_encode(['ok' => true]);
