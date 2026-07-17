<?php
session_start();

// Leer .env
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

$db = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($db->connect_error) die("Error de conexión");

// Obtener ticket
$ticket_id = intval($_GET['ticket'] ?? 0);
if (!$ticket_id) { header("Location: ../prices/"); exit(); }

$stmt = $db->prepare("SELECT * FROM tickets WHERE id = ? AND visible = 1");
$stmt->bind_param("i", $ticket_id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
if (!$ticket) { header("Location: ../prices/"); exit(); }

// Usuario logueado?
$user = null;
if (isset($_SESSION['user'])) {
    $stmt2 = $db->prepare("SELECT * FROM users WHERE userid = ?");
    $stmt2->bind_param("i", $_SESSION['user']);
    $stmt2->execute();
    $user = $stmt2->get_result()->fetch_assoc();
}

$business_name = $env['BUSINESS_NAME'] ?? 'Adrenaline Gym';
$wompi_pub = $env['WOMPI_PUBLIC_KEY'];
$integrity_secret = $env['WOMPI_INTEGRITY_SECRET'];

// Generar referencia única
$reference = 'GYM-' . $ticket_id . '-' . time() . '-' . rand(100, 999);
$amount_cents = intval($ticket['price'] * 100);
$currency = 'COP';

// Firma de integridad
$integrity_string = $reference . $amount_cents . $currency . $integrity_secret;
$integrity_hash = hash('sha256', $integrity_string);

$redirect_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/wompi/redirect/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo htmlspecialchars($business_name); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f5f5f5; }
        .checkout-card { max-width: 500px; margin: 60px auto; background: #fff; border-radius: 12px; padding: 30px; box-shadow: 0 2px 20px rgba(0,0,0,0.1); }
        .plan-name { font-size: 1.5em; font-weight: bold; color: #222; }
        .plan-price { font-size: 2em; font-weight: bold; color: #e53935; margin: 10px 0; }
        .plan-detail { color: #666; margin-bottom: 20px; }
        .btn-wompi { background: #7c3aed; color: #fff; width: 100%; padding: 14px; border: none; border-radius: 8px; font-size: 1.1em; cursor: pointer; }
        .btn-wompi:hover { background: #6d28d9; }
        .divider { border-top: 1px solid #eee; margin: 20px 0; }
        .user-info { background: #f9f9f9; border-radius: 8px; padding: 12px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="checkout-card">
    <div class="text-center mb-3">
        <img src="../../assets/img/logo.png" height="60" alt="Logo" onerror="this.style.display='none'">
        <h4><?php echo htmlspecialchars($business_name); ?></h4>
    </div>
    <div class="divider"></div>
    <div class="plan-name"><?php echo htmlspecialchars($ticket['name']); ?></div>
    <div class="plan-price">$<?php echo number_format($ticket['price'], 0, ',', '.'); ?> COP</div>
    <div class="plan-detail">
        <i class="bi bi-calendar-check"></i> <?php echo $ticket['expire_days']; ?> días de vigencia &nbsp;
        <?php if ($ticket['occasions']): ?>
            <i class="bi bi-lightning"></i> <?php echo $ticket['occasions']; ?> ingresos
        <?php else: ?>
            <i class="bi bi-infinity"></i> Acceso ilimitado
        <?php endif; ?>
    </div>

    <?php if ($user): ?>
    <div class="user-info">
        <i class="bi bi-person-check"></i> <strong><?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?></strong><br>
        <small><?php echo htmlspecialchars($user['email']); ?></small>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> ¿Ya tienes cuenta? <a href="../login/?redirect=../checkout/?ticket=<?php echo $ticket_id; ?>">Inicia sesión</a> o continúa para registrarte y pagar.
    </div>
    <?php endif; ?>

    <div class="divider"></div>
    <p class="text-muted text-center"><small>Pago seguro procesado por Wompi</small></p>

    <form>
        <script
            src="https://checkout.wompi.co/widget.js"
            data-render="button"
            data-public-key="<?php echo $wompi_pub; ?>"
            data-currency="COP"
            data-amount-in-cents="<?php echo $amount_cents; ?>"
            data-reference="<?php echo $reference; ?>"
            data-signature:integrity="<?php echo $integrity_hash; ?>"
            data-redirect-url="<?php echo $redirect_url; ?>"
            <?php if ($user): ?>
            data-customer-data:email="<?php echo htmlspecialchars($user['email']); ?>"
            data-customer-data:full-name="<?php echo htmlspecialchars($user['firstname'] . ' ' . $user['lastname']); ?>"
            <?php endif; ?>
        ></script>
    </form>
</div>
</body>
</html>
