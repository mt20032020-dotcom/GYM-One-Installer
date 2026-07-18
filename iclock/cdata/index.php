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
    // ===== Oyente de entradas reales (rtlog del SpeedFace) =====
    if (strpos($_SERVER['QUERY_STRING'] ?? '', 'table=rtlog') !== false && preg_match('/pin=(\d+).*?event=(\d+)/s', $raw, $m)) {
        $pinEvt = $m[1]; $evt = (int)$m[2];
        if ($evt === 3 || $evt === 0) { // acceso concedido
            $env = [];
            foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
            $conn = @new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
            if (!$conn->connect_error) {
                $stmt = $conn->prepare("SELECT userid, firstname, lastname FROM users WHERE cedula = ?");
                $stmt->bind_param('s', $pinEvt);
                $stmt->execute();
                $u = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($u) {
                    $uid = (int)$u['userid'];
                    $nombre = trim($u['lastname'] . ' ' . $u['firstname']);
                    // Anti-doble-conteo: ignorar si ya registro entrada en los ultimos 3 minutos
                    $rDup = $conn->query("SELECT COUNT(*) t FROM access_log WHERE userid = $uid AND entry_time >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)");
                    $dup = $rDup ? ((int)$rDup->fetch_assoc()['t']) > 0 : false;
                    if (!$dup) {
                        // Bitacora
                        $stmt = $conn->prepare("INSERT INTO access_log (userid, display_name, is_companion) VALUES (?, ?, 0)");
                        $stmt->bind_param('is', $uid, $nombre);
                        $stmt->execute(); $stmt->close();
                        // Activar plan futuro si el actual ya murio
                        require_once '/app/includes/future_plans.php';
                        @activate_next_plan($conn, $uid);
                        // Descontar ocasion si su pase vigente es por ocasiones
                        $stmt = $conn->prepare("SELECT id, opportunities FROM current_tickets WHERE userid = ? AND expiredate >= CURDATE() AND (opportunities IS NULL OR opportunities > 0) ORDER BY expiredate ASC LIMIT 1");
                        $stmt->bind_param('i', $uid);
                        $stmt->execute();
                        $tk = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($tk && $tk['opportunities'] !== null) {
                            $nuevo = max(0, (int)$tk['opportunities'] - 1);
                            $conn->query("UPDATE current_tickets SET opportunities = $nuevo WHERE id = " . (int)$tk['id']);
                            if ($nuevo <= 0) {
                                require_once __DIR__ . '/../lib/endtime.php';
                                @sincronizar_acceso_speedface($uid);
                                // Tiquetera agotada: activar siguiente plan de la cola
                                if (@activate_next_plan($conn, $uid)) {
                                    @sincronizar_acceso_speedface($uid);
                                }
                            }
                        }
                    }
                }
                $conn->close();
            }
        }
    }
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
