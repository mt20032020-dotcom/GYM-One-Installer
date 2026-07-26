<?php
header('Content-Type: application/json; charset=utf-8');

$API_KEY = '0e231ea5656de9582899896e9d97384875b194c41e1ef3750349a6e0db874fbb';
$providedKey = $_SERVER['HTTP_X_BOT_KEY'] ?? '';
if (!hash_equals($API_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit();
}

function read_env_file($file_path) {
    $env_data = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) @file_get_contents($file_path)) as $line) {
        if (trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $env_data[trim($parts[0])] = trim($parts[1]);
    }
    return $env_data;
}
$env = read_env_file('/app/.env');
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexion']);
    exit();
}
$conn->set_charset('utf8mb4');

$info = [];

// ===== 1. Categorias de texto libre (panel admin/boss/bot_info) =====
$result = $conn->query("SELECT categoria, contenido FROM bot_info ORDER BY orden ASC");
while ($row = $result->fetch_assoc()) {
    if (trim($row['contenido']) !== '') {
        $info[] = ['categoria' => $row['categoria'], 'contenido' => $row['contenido']];
    }
}

// ===== 2. Horarios de atencion (tabla opening_hours, en vivo) =====
$diasNombre = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];
$rHoras = $conn->query("SELECT day, open_time, close_time FROM opening_hours ORDER BY day ASC");
$lineasHoras = [];
while ($h = $rHoras->fetch_assoc()) {
    $nombreDia = $diasNombre[(int)$h['day']] ?? $h['day'];
    if (empty($h['open_time']) || empty($h['close_time'])) {
        $lineasHoras[] = "$nombreDia: Cerrado";
    } else {
        $lineasHoras[] = "$nombreDia: " . substr($h['open_time'],0,5) . " a " . substr($h['close_time'],0,5);
    }
}
if (!empty($lineasHoras)) {
    $info[] = ['categoria' => 'Horarios de atencion', 'contenido' => implode("\n", $lineasHoras)];
}

// ===== 3. Planes y tiqueteras (tabla tickets, en vivo) =====
$rTickets = $conn->query("SELECT id, name, expire_days, price, occasions FROM tickets WHERE visible = 1 ORDER BY price ASC");
$lineasPlanes = [];
$lineasTiqueteras = [];
while ($t = $rTickets->fetch_assoc()) {
    $precioFmt = number_format((float)$t['price'], 0, ',', '.');
    $linkPago = "https://gympasto.com/checkout/?ticket=" . (int)$t['id'];
    if ($t['occasions'] === null) {
        $lineasPlanes[] = $t['name'] . ": $" . $precioFmt . " COP (" . (int)$t['expire_days'] . " dias) - Pagar: " . $linkPago;
    } else {
        $lineasTiqueteras[] = $t['name'] . ": $" . $precioFmt . " COP (" . (int)$t['occasions'] . " ingresos, vigencia " . (int)$t['expire_days'] . " dias) - Pagar: " . $linkPago;
    }
}
if (!empty($lineasPlanes)) {
    $info[] = ['categoria' => 'Membresias (acceso ilimitado)', 'contenido' => implode("\n", $lineasPlanes)];
}
if (!empty($lineasTiqueteras)) {
    $info[] = ['categoria' => 'Tiqueteras (por numero de ingresos)', 'contenido' => implode("\n", $lineasTiqueteras)];
}

// ===== 4. Clases grupales (tabla timetable, en vivo) =====
$rClases = $conn->query("SELECT event_name, day_of_week, start_time, end_time FROM timetable ORDER BY day_of_week, start_time ASC");
$lineasClases = [];
while ($cl = $rClases->fetch_assoc()) {
    $lineasClases[] = $cl['day_of_week'] . ": " . $cl['event_name'] . " (" . substr($cl['start_time'],0,5) . " a " . substr($cl['end_time'],0,5) . ")";
}
if (!empty($lineasClases)) {
    $info[] = ['categoria' => 'Clases grupales', 'contenido' => implode("\n", $lineasClases)];
} else {
    $info[] = ['categoria' => 'Clases grupales', 'contenido' => 'Por el momento no hay clases grupales programadas.'];
}

echo json_encode(['ok' => true, 'info' => $info], JSON_UNESCAPED_UNICODE);
