<?php
$logFile = __DIR__ . '/../device_log.txt';
$cmdFile = __DIR__ . '/../cmd_queue.txt';
$sn = $_GET['SN'] ?? 'UNKNOWN';

@file_put_contents(__DIR__ . '/../last_poll.txt', date('Y-m-d H:i:s') . " SN=$sn");

// ===== Cron web: barrido diario disparado por el latido del equipo =====
date_default_timezone_set('America/Bogota');
$marker = __DIR__ . '/../barrido_marker.txt';
$hoy = date('Y-m-d');
$GLOBALS['__barrer'] = ((int)date('H') >= 3 && (!file_exists($marker) || trim(@file_get_contents($marker)) !== $hoy));
if ($GLOBALS['__barrer']) {
    @file_put_contents($marker, $hoy);
    @chmod($marker, 0666);
}

$body = "OK";
if (file_exists($cmdFile)) {
    $lineas = file($cmdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($lineas)) {
        $body = array_shift($lineas);           // entrega el primero
        @unlink($cmdFile);
        if (!empty($lineas)) { @file_put_contents($cmdFile, implode("\n", $lineas) . "\n"); @chmod($cmdFile, 0666); }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " GETREQUEST >>> COMANDO: " . substr($body, 0, 80) . "... (quedan " . count($lineas) . ")\n", FILE_APPEND);
    }
}

header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
if (!empty($GLOBALS['__barrer'])) {
    require __DIR__ . '/../lib/barrido_nocturno.php';
    // Activar planes futuros pendientes (barrido diario)
    $envFP = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $envFP[$k]=$v; } }
    $connFP = @new mysqli($envFP['DB_SERVER'],$envFP['DB_USERNAME'],$envFP['DB_PASSWORD'],$envFP['DB_NAME']);
    if (!$connFP->connect_error) {
        require_once '/app/includes/future_plans.php';
        $resFP = @$connFP->query("SELECT DISTINCT userid FROM future_tickets WHERE activated = 0");
        if ($resFP) {
            while ($rowFP = $resFP->fetch_assoc()) {
                $actFP = @activate_next_plan($connFP, $rowFP['userid']);
                if ($actFP) {
                    require_once '/app/iclock/lib/endtime.php';
                    @sincronizar_acceso_speedface($rowFP['userid']);
                }
            }
        }
        $connFP->close();
    }
}
exit;
