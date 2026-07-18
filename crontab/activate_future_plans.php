<?php
/**
 * Cron diario: activa planes futuros de usuarios cuyo plan actual venció
 * Ejecutar: una vez al día (ej. 4:00 AM)
 */
require_once '/app/includes/future_plans.php';

$env = [];
foreach (file('/app/.env') as $l) {
    if (strpos($l, '=') !== false) {
        [$k, $v] = explode('=', trim($l), 2);
        $env[$k] = $v;
    }
}

$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) {
    die("Error de conexion: " . $conn->connect_error . "\n");
}

// Usuarios con planes futuros pendientes
$result = $conn->query("SELECT DISTINCT userid FROM future_tickets WHERE activated = 0");
$activados = 0;

while ($row = $result->fetch_assoc()) {
    $activated = activate_next_plan($conn, $row['userid']);
    if ($activated) {
        $activados++;
        echo "Activado: usuario {$row['userid']} - plan {$activated['ticketname']}\n";
        // Sincronizar acceso en el SpeedFace
        require_once '/app/iclock/lib/endtime.php';
        @sincronizar_acceso_speedface($row['userid']);
    }
}

echo "Total planes activados: $activados\n";
$conn->close();
