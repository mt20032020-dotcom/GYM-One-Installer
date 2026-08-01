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
        /* LOTE_COMANDOS: entrega hasta 10 por poll para acelerar cargas masivas.
           Los comandos de biofoto son grandes, asi que se limita por tamano tambien. */
        $lote = []; $bytes = 0;
        while (!empty($lineas) && count($lote) < 10 && $bytes < 1500000) {
            $linea = array_shift($lineas);
            $lote[] = $linea; $bytes += strlen($linea);
        }
        $body = implode("\n", $lote);
        @unlink($cmdFile);
        if (!empty($lineas)) { @file_put_contents($cmdFile, implode("\n", $lineas) . "\n"); @chmod($cmdFile, 0666); }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " GETREQUEST >>> COMANDO: " . substr($body, 0, 80) . "... (quedan " . count($lineas) . ")\n", FILE_APPEND);
    }
}

header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
echo $body;
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
/* AUTOSALIDA: cierra sesiones de mas de 90 minutos */
$envAS = [];
foreach (file('/app/.env') as $lAS) { if (strpos($lAS,'=')!==false) { [$kAS,$vAS]=explode('=',trim($lAS),2); $envAS[$kAS]=$vAS; } }
$cAS = @new mysqli($envAS['DB_SERVER'],$envAS['DB_USERNAME'],$envAS['DB_PASSWORD'],$envAS['DB_NAME']);
if ($cAS && !$cAS->connect_error) {
    $cAS->query("DELETE FROM temp_loggeduser WHERE login_date < (NOW() - INTERVAL 90 MINUTE)");
    if ($cAS->affected_rows > 0) {
        @file_put_contents(__DIR__.'/../device_log.txt', date('Y-m-d H:i:s')." AUTOSALIDA: ".$cAS->affected_rows." sesion(es) cerrada(s)\n", FILE_APPEND);
    }
    $cAS->close();
}
if (!empty($GLOBALS['__barrer'])) {
    require __DIR__ . '/../lib/barrido_nocturno.php';
    // Recordatorios de vencimiento (planes que vencen manana)
    @include '/app/iclock/lib/recordatorios_vencimiento.php';
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
