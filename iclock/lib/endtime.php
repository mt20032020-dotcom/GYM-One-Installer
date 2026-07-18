<?php
/**
 * Sincroniza el acceso del usuario en el SpeedFace segun su plan vigente.
 * Plan vigente -> autoriza. Sin plan o vencido -> veta.
 * Uso: sincronizar_acceso_speedface($useridGymOne)
 */
function sincronizar_acceso_speedface($userid) {
    $env = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
    $conn = @new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
    if ($conn->connect_error) return false;

    $stmt = $conn->prepare("SELECT cedula FROM users WHERE userid = ?");
    $stmt->bind_param('i', $userid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u || empty($u['cedula'])) { $conn->close(); return false; }
    $pin = preg_replace('/\D/','',$u['cedula']);

    // Tiene pase vigente? (fecha valida Y, si es por ocasiones, que le queden)
    $stmt = $conn->prepare("SELECT COUNT(*) t FROM current_tickets WHERE userid = ? AND expiredate >= CURDATE() AND (opportunities IS NULL OR opportunities > 0)");
    $stmt->bind_param('i', $userid);
    $stmt->execute();
    $vigente = ((int)$stmt->get_result()->fetch_assoc()['t']) > 0;
    $stmt->close();
    // Si esta congelado hoy, vetar aunque tenga plan
    if ($vigente) {
        $todayF = date('Y-m-d');
        $stmtF = $conn->prepare("SELECT COUNT(*) t FROM plan_freezes WHERE userid = ? AND freeze_start <= ? AND freeze_end >= ?");
        $stmtF->bind_param('iss', $userid, $todayF, $todayF);
        $stmtF->execute();
        if (((int)$stmtF->get_result()->fetch_assoc()['t']) > 0) $vigente = false;
        $stmtF->close();
    }
    $conn->close();

    $base = time() % 100000;
    $cmd = $vigente
        ? "C:{$base}7:DATA UPDATE userauthorize Pin={$pin}\tAuthorizeTimezoneId=1\tAuthorizeDoorId=1"
        : "C:{$base}7:DATA DELETE userauthorize Pin={$pin}";

    $fh = fopen('/app/iclock/cmd_queue.txt', 'a');
    if (!$fh) return false;
    flock($fh, LOCK_EX); fwrite($fh, $cmd . "\n"); flock($fh, LOCK_UN); fclose($fh);
    @chmod('/app/iclock/cmd_queue.txt', 0666);
    return $vigente ? 'autorizado' : 'vetado';
}
