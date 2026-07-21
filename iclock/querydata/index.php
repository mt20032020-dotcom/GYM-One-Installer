<?php
$raw = file_get_contents('php://input');
@file_put_contents('/app/iclock/querydata_recibido.txt',
    date('Y-m-d H:i:s') . " QS=" . ($_SERVER['QUERY_STRING'] ?? '') . "\n$raw\n===FIN-PAQUETE===\n",
    FILE_APPEND);
@chmod('/app/iclock/querydata_recibido.txt', 0666);
$body = "OK";
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
