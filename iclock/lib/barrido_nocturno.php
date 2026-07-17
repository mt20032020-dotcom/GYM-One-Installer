<?php
// Barrido nocturno: sincroniza el acceso de todos los usuarios con el SpeedFace.
// Ejecutar via cron una vez al dia (madrugada).
require_once __DIR__ . '/endtime.php';

$env = [];
foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
$conn = @new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
if ($conn->connect_error) { die("BD inaccesible\n"); }

$r = $conn->query("SELECT userid FROM users WHERE cedula IS NOT NULL AND cedula != ''");
$total = 0; $autorizados = 0; $vetados = 0;
while ($row = $r->fetch_assoc()) {
    $res = sincronizar_acceso_speedface((int)$row['userid']);
    if ($res === 'autorizado') $autorizados++;
    elseif ($res === 'vetado') $vetados++;
    $total++;
    usleep(100000); // 0.1s entre usuarios para no saturar la cola
}
$conn->close();
$msg = date('Y-m-d H:i:s') . " Barrido: $total usuarios ($autorizados autorizados, $vetados vetados)\n";
file_put_contents('/app/iclock/barrido.log', $msg, FILE_APPEND);
echo $msg;
