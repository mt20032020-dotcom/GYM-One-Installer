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

$cedula = preg_replace('/\D/', '', $_GET['cedula'] ?? '');
if ($cedula === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta cedula']);
    exit();
}

$stmt = $conn->prepare("SELECT userid, firstname, lastname FROM users WHERE cedula = ?");
$stmt->bind_param("s", $cedula);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['ok' => true, 'found' => false]);
    exit();
}

$uid = (int) $user['userid'];

$stmt2 = $conn->prepare("SELECT id, ticketname, buydate, expiredate, opportunities FROM current_tickets WHERE userid = ? ORDER BY id DESC LIMIT 1");
$stmt2->bind_param("i", $uid);
$stmt2->execute();
$plan = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

$congelado = false;
$congelado_hasta = null;
if ($plan && file_exists('/app/includes/freezes.php')) {
    require_once '/app/includes/freezes.php';
    if (function_exists('is_frozen_today')) {
        $frz = @is_frozen_today($conn, $uid);
        if ($frz) { $congelado = true; $congelado_hasta = $frz['freeze_end'] ?? null; }
    }
}

$hoy = date('Y-m-d');
$vencido = $plan ? ($plan['expiredate'] < $hoy) : null;

$estado = 'sin_plan';
if ($plan) {
    $estado = $congelado ? 'congelado' : ($vencido ? 'vencido' : 'activo');
}

echo json_encode([
    'ok' => true,
    'found' => true,
    'userid' => $uid,
    'nombre' => trim($user['firstname'] . ' ' . $user['lastname']),
    'cedula' => $cedula,
    'plan' => $plan ? [
        'ticket_id' => (int)$plan['id'],
        'nombre' => $plan['ticketname'],
        'estado' => $estado,
        'fecha_inicio' => $plan['buydate'],
        'fecha_vencimiento' => $plan['expiredate'],
        'congelado_hasta' => $congelado_hasta,
        'tickets_restantes' => $plan['opportunities'],
    ] : null,
], JSON_UNESCAPED_UNICODE);
