<?php
// Leer body
$body = file_get_contents('php://input');
@file_put_contents('/app/wompi/webhook_log.txt', date('Y-m-d H:i:s') . " RECIBIDO: " . substr($body, 0, 500) . "\n", FILE_APPEND);
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

$env = read_env('/app/.env');

// Verificar firma (esquema oficial Wompi: X-Event-Checksum)
$signature = $_SERVER['HTTP_X_EVENT_CHECKSUM'] ?? '';
$events_secret = $env['WOMPI_EVENTS_SECRET'];
$props = $data['signature']['properties'] ?? [];
$timestamp = $data['timestamp'] ?? '';
$concat = '';
foreach ($props as $prop) {
    $keys = explode('.', $prop);
    $val = $data['data'];
    foreach ($keys as $k) { $val = $val[$k] ?? ''; }
    $concat .= $val;
}
$computed = hash('sha256', $concat . $timestamp . $events_secret);

if (empty($signature) || !hash_equals($computed, $signature)) {
    @file_put_contents('/app/wompi/webhook_log.txt', date('Y-m-d H:i:s') . " FIRMA INVALIDA: esperada=$computed recibida=$signature\n", FILE_APPEND);
    http_response_code(401);
    exit(json_encode(["error" => "Firma inválida"]));
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
$db->query("CREATE TABLE IF NOT EXISTS wompi_processed (reference VARCHAR(120) PRIMARY KEY, processed_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
// Idempotencia: si la referencia ya fue procesada, responder OK sin duplicar
$stmtIdem = $db->prepare("SELECT 1 FROM wompi_processed WHERE reference = ?");
$stmtIdem->bind_param("s", $reference);
$stmtIdem->execute();
if ($stmtIdem->get_result()->fetch_row()) {
    @file_put_contents("/app/wompi/webhook_log.txt", date("Y-m-d H:i:s") . " DUPLICADO ignorado: $reference\n", FILE_APPEND);
    http_response_code(200);
    exit(json_encode(["ok" => true, "duplicate" => true]));
}
$db->query("INSERT IGNORE INTO wompi_processed (reference) VALUES ('" . $db->real_escape_string($reference) . "')");

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

// ===== REGISTRO CONTABLE =====
$monto_pesos = $transaction["amount_in_cents"] / 100;
$hoy_rev = date("Y-m-d");

// 1. Ingresos del dia (columna web)
$rRev = $db->query("SELECT id FROM revenu_stats WHERE date = '$hoy_rev'");
if ($rRev && $rRev->num_rows > 0) {
    $revId = $rRev->fetch_assoc()["id"];
    $db->query("UPDATE revenu_stats SET web = web + $monto_pesos WHERE id = " . (int)$revId);
} else {
    $db->query("INSERT INTO revenu_stats (date, bank_card, cash, transfer, web) VALUES ('$hoy_rev', 0, 0, 0, $monto_pesos)");
}

// 2. Factura en invoices con numeracion consecutiva
$seqW = $db->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM invoices")->fetch_assoc();
$invNumW = "ADR-" . date("Y") . "-" . str_pad($seqW["n"], 5, "0", STR_PAD_LEFT);
$uNameRow = $db->query("SELECT firstname, lastname FROM users WHERE userid = " . (int)$userid)->fetch_assoc();
$fullNameW = trim(($uNameRow["firstname"] ?? "") . " " . ($uNameRow["lastname"] ?? ""));
$statusW = "Pagado";
$routeW = ""; // pago web: sin PDF generado por ahora
$stmtInv = $db->prepare("INSERT INTO invoices (userid, name, price, status, route, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
$stmtInv->bind_param("isdss", $userid, $fullNameW, $monto_pesos, $statusW, $routeW);
$stmtInv->execute();

// Correo de confirmacion al cliente
$stmtM = $db->prepare("SELECT firstname FROM users WHERE userid = ?");
$stmtM->bind_param("i", $userid);
$stmtM->execute();
$uM = $stmtM->get_result()->fetch_assoc();
if ($uM && !empty($email) && strpos($email, "@") !== false) {
    if (!empty($env["MAIL_HOST"])) {
        require_once "/app/includes/mailer.php";
        require_once "/app/includes/email_templates.php";
        $monto = number_format($transaction["amount_in_cents"] / 100, 0, ",", ".");
        $filasM = [
            "Plan" => htmlspecialchars($ticket["name"]),
            "Valor pagado" => "$" . $monto . " COP",
            "Referencia" => htmlspecialchars($reference),
        ];
        if ($plan_result && $plan_result["type"] === "active") {
            $filasM["Inicia"] = date("d/m/Y", strtotime($plan_result["start_date"]));
            $filasM["Vence"] = date("d/m/Y", strtotime($plan_result["end_date"]));
            $subM = "Tu pago fue confirmado y tu plan ya está activo. ¡Nos vemos en el gym!";
        } else {
            $filasM["Inicia aprox."] = $plan_result && $plan_result["start_date"] ? date("d/m/Y", strtotime($plan_result["start_date"])) : "Al vencer tu plan actual";
            $subM = "Tu pago fue confirmado. Como ya tienes un plan activo, este quedó en cola y se activará automáticamente.";
        }
        $bodyM = adrenaline_email(
            "✓ PAGO EXITOSO",
            "¡Gracias, " . htmlspecialchars($uM["firstname"]) . "!",
            $subM,
            $filasM
        );
        @send_mail($env, $email, "Pago confirmado — Adrenaline Gym", $bodyM, $env["BUSINESS_NAME"] ?? "Adrenaline Gym", true);
    }
}

http_response_code(200);
echo 'OK';
