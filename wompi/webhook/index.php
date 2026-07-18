<?php
// Leer body
$body = file_get_contents('php://input');
$data = json_decode($body, true);

function read_env($file) {
    $env = [];
    foreach (file($file) as $line) {
        $line = trim($line);
        if (!$line || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v);
        }
    }
    return $env;
}

$env = read_env('../../../.env');

// Verificar firma
$signature = $_SERVER['HTTP_X_WOMPI_SIGNATURE'] ?? '';
$events_secret = $env['WOMPI_EVENTS_SECRET'];
$computed = hash('sha256', $body . $events_secret);

if ($computed !== $signature) {
    http_response_code(401);
    exit('Firma inválida');
}

// Solo procesar transacciones aprobadas
if (($data['event'] ?? '') !== 'transaction.updated') {
    http_response_code(200);
    exit('OK');
}

$transaction = $data['data']['transaction'] ?? [];
$status = $transaction['status'] ?? '';
$reference = $transaction['reference'] ?? '';

if ($status !== 'APPROVED') {
    http_response_code(200);
    exit('OK');
}

// Parsear referencia: GYM-{ticket_id}-{timestamp}-{rand}
$parts = explode('-', $reference);
if (count($parts) < 2) {
    http_response_code(200);
    exit('OK');
}
$ticket_id = intval($parts[1]);
$user_id = intval($transaction['customer_data']['legal_id'] ?? 0);

if (!$ticket_id) {
    http_response_code(200);
    exit('OK');
}

require_once "/app/includes/future_plans.php";
$db = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);

// Buscar ticket
$stmt = $db->prepare("SELECT * FROM tickets WHERE id = ?");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

if (!$ticket) {
    http_response_code(200);
    exit('OK');
}

// Buscar usuario por email
$email = $transaction['customer_email'] ?? '';
$stmt2 = $db->prepare("SELECT userid FROM users WHERE email = ?");
$stmt2->bind_param("s", $email);
$stmt2->execute();
$user = $stmt2->get_result()->fetch_assoc();

if (!$user) {
    http_response_code(200);
    exit('Usuario no encontrado');
}

$userid = $user['userid'];

// Calcular vencimiento
$expire_days = $ticket['expire_days'];
$start_date = date('Y-m-d H:i:s');
$end_date = date('Y-m-d H:i:s', strtotime("+{$expire_days} days"));
$occasions = $ticket['occasions'];

// Asignar plan
$ticketname = $ticket["name"];
// Extraer fecha de inicio de la referencia (5to segmento: YYYYMMDD o T)
$custom_start = null;
if (isset($parts[4]) && $parts[4] !== "T" && strlen($parts[4]) === 8) {
    $custom_start = substr($parts[4], 0, 4) . "-" . substr($parts[4], 4, 2) . "-" . substr($parts[4], 6, 2);
}
$plan_result = add_plan($db, $userid, $ticketname, $expire_days, $occasions, $custom_start);

// Enrolar en SpeedFace si tiene foto y el plan quedo activo
if ($plan_result && $plan_result["type"] === "active" && file_exists("/app/assets/img/profiles/{$userid}.png")) {
    require_once "/app/iclock/lib/enroll.php";
    @enrolar_en_speedface($userid);
}

// Log
$db->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES ($userid, 'Plan asignado via Wompi: {$ticket['name']}', 'success', NOW())");

http_response_code(200);
echo 'OK';
