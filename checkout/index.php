<?php
session_start();

// Manejar registro desde checkout
$reg_error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "register") {
    $fn = trim($_POST["firstname"] ?? "");
    $ln = trim($_POST["lastname"] ?? "");
    $ced = trim($_POST["cedula"] ?? "");
    $cel = trim($_POST["celular"] ?? "");
    $em = trim($_POST["email"] ?? "");
    $pw = $_POST["password"] ?? "";
    $pw2 = $_POST["confirm_password"] ?? "";
    $tid = intval($_POST["ticket_id"] ?? 0);

    if ($pw !== $pw2) {
        $reg_error = "Las contraseñas no coinciden.";
    } elseif (strlen($pw) < 6) {
        $reg_error = "La contraseña debe tener al menos 6 caracteres.";
    } elseif (empty($fn) || empty($ln) || empty($ced) || empty($em)) {
        $reg_error = "Completa todos los campos obligatorios.";
    } else {
        // Leer env para conectar
        $env_tmp = [];
        foreach (file("/app/.env") as $line) {
            $line = trim($line);
            if (strpos($line, "=") !== false) {
                [$k, $v] = explode("=", $line, 2);
                $env_tmp[trim($k)] = trim($v);
            }
        }
        $db_tmp = new mysqli($env_tmp["DB_SERVER"], $env_tmp["DB_USERNAME"], $env_tmp["DB_PASSWORD"], $env_tmp["DB_NAME"]);

        // Verificar cédula duplicada
        $chk = $db_tmp->prepare("SELECT userid FROM users WHERE cedula = ? OR email = ?");
        $chk->bind_param("ss", $ced, $em);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $reg_error = "Ya existe una cuenta con esa cédula o correo.";
        } else {
            $new_userid = rand(pow(10, 9), pow(10, 10) - 1);
            $hashed = password_hash($pw, PASSWORD_DEFAULT);
            $now = date("Y-m-d H:i:s");
            $stmt_ins = $db_tmp->prepare("INSERT INTO users (userid, cedula, firstname, lastname, email, password, gender, birthdate, celular, registration_date, confirmed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $gender = "Male";
            $birth = "1990-01-01";
            $confirmed = "Yes";
            $stmt_ins->bind_param("issssssssss", $new_userid, $ced, $fn, $ln, $em, $hashed, $gender, $birth, $cel, $now, $confirmed);
            if ($stmt_ins->execute()) {
                $_SESSION["userid"] = $new_userid;
                header("Location: /checkout/?ticket=" . $tid);
                exit();
            } else {
                $reg_error = "Error al crear la cuenta. Intenta de nuevo.";
            }
        }
    }
}

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
if (isset($_SESSION['userid'])) {
    $stmt2 = $db->prepare("SELECT * FROM users WHERE userid = ?");
    $stmt2->bind_param("i", $_SESSION['userid']);
    $stmt2->execute();
    $user = $stmt2->get_result()->fetch_assoc();

    // Buscar último plan del usuario
    $stmt3 = $db->prepare("SELECT * FROM current_tickets WHERE userid = ? ORDER BY id DESC LIMIT 1");
    $stmt3->bind_param("i", $_SESSION["userid"]);
    $stmt3->execute();
    $last_plan = $stmt3->get_result()->fetch_assoc();
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
        * { box-sizing: border-box; }
        body { background: #f0f0f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .checkout-card { max-width: 480px; width: 100%; margin: 30px auto; background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); border-top: 4px solid #e53935; }
        .checkout-card h4 { color: #333; margin: 0; font-size: 0.95em; letter-spacing: 1px; text-transform: uppercase; }
        .plan-name { font-size: 1.6em; font-weight: bold; color: #222; }
        .plan-price { font-size: 2.2em; font-weight: bold; color: #e53935; margin: 8px 0; }
        .plan-detail { color: #666; margin-bottom: 20px; font-size: 0.95em; }
        .divider { border-top: 1px solid #eee; margin: 20px 0; }
        .user-info { background: #f9f9f9; border-radius: 10px; padding: 14px; margin-bottom: 16px; color: #444; border: 1px solid #eee; }
        .user-info strong { color: #222; }
        small { color: #999; }
        .alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; border-radius: 10px; }
        .alert-info a { color: #2563eb; }
    </style>
</head>
<body>
<div class="checkout-card">
    <div class="text-center mb-3">
        <img src="../../assets/img/logo.png" height="70" alt="Logo" style="background:#111;padding:10px;border-radius:10px;" onerror="this.style.display='none'">
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
        <i class="bi bi-person-check" style="color:#22c55e;font-size:1.2em;"></i>
        <strong> ¡Hola, <?php echo htmlspecialchars($user['firstname']); ?>!</strong><br>
        <small style="color:#888;"><?php echo htmlspecialchars($user['email']); ?></small>
    </div>
    <?php if ($last_plan): ?>
    <div style="background:#052e16;border:1px solid #166534;border-radius:8px;padding:12px;margin-bottom:15px;font-size:0.9em;color:#86efac;">
        <i class="bi bi-clock-history" style="color:#4ade80;"></i>
        <strong>Tu último plan:</strong> <?php echo htmlspecialchars($last_plan['ticketname']); ?><br>
        <?php
        $today = date('Y-m-d');
        $expire = $last_plan['expiredate'];
        if ($expire >= $today):
            $days_left = (strtotime($expire) - strtotime($today)) / 86400;
        ?>
        <span style="color:#4ade80;"><i class="bi bi-check-circle"></i> Activo — vence el <?php echo date('d/m/Y', strtotime($expire)); ?> (<?php echo intval($days_left); ?> días)</span>
        <?php else: ?>
        <span style="color:#dc2626;"><i class="bi bi-x-circle"></i> Venció el <?php echo date('d/m/Y', strtotime($expire)); ?></span>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px;margin-bottom:15px;font-size:0.9em;">
        <i class="bi bi-star" style="color:#ea580c;"></i>
        <strong style="color:#ea580c;">Bienvenido a Adrenaline Gym!</strong><br>
        <span style="color:#9a3412;">Este sera tu primer plan con nosotros.</span>
    </div>
    <?php endif; ?>
    <div style="background:#1e1b4b;border:1px solid #3730a3;border-radius:8px;padding:12px;margin-bottom:15px;font-size:0.9em;color:#a5b4fc;">
        <i class="bi bi-calendar-plus" style="color:#818cf8;"></i>
        <strong>Si pagas hoy:</strong><br>
        Tu plan <strong><?php echo htmlspecialchars($ticket['name']); ?></strong> iniciará hoy
        y vencerá el <strong><?php echo date('d/m/Y', strtotime('+' . $ticket['expire_days'] . ' days')); ?></strong>.
    </div>
    <?php else: ?>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:15px;">
        <div style="font-weight:bold;margin-bottom:10px;color:#222;">
            <i class="bi bi-person-plus"></i> Crea tu cuenta para continuar
            <span style="font-weight:normal;font-size:0.85em;color:#666;"> — ¿Ya tienes? <a href="../login/?redirect=/checkout/%3Fticket=<?php echo $ticket_id; ?>">Inicia sesión</a></span>
        </div>
        <?php if (!empty($reg_error)): ?>
        <div class="alert alert-danger" style="padding:8px;font-size:0.9em;"><?php echo $reg_error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                <input type="text" name="firstname" class="form-control" placeholder="Nombre" required style="font-size:0.9em;">
                <input type="text" name="lastname" class="form-control" placeholder="Apellido" required style="font-size:0.9em;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                <input type="text" name="cedula" class="form-control" placeholder="Cédula" required style="font-size:0.9em;">
                <input type="tel" name="celular" class="form-control" placeholder="Celular" style="font-size:0.9em;">
            </div>
            <input type="email" name="email" class="form-control" placeholder="Correo electrónico" required style="font-size:0.9em;margin-bottom:8px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                <input type="password" name="password" class="form-control" placeholder="Contraseña" required style="font-size:0.9em;">
                <input type="password" name="confirm_password" class="form-control" placeholder="Confirmar contraseña" required style="font-size:0.9em;">
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-size:0.85em;color:#555;margin-bottom:3px;">Fecha de nacimiento</label>
                <input type="date" name="birthdate" class="form-control" required style="font-size:0.9em;">
            </div>
            <div style="margin-bottom:10px;font-size:0.85em;">
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="accept_policies" required style="margin-top:3px;">
                    <span>Acepto las <a href="/policies/" target="_blank">políticas y términos</a> de Adrenaline Gym</span>
                </label>
            </div>
            <button type="submit" class="btn btn-danger" style="width:100%;">
                <i class="bi bi-person-check"></i> Registrarme y continuar al pago
            </button>
        </form>
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
            data-customer-data:full-name="<?php echo htmlspecialchars($user['lastname'] . ' ' . $user['firstname']); ?>"
            <?php endif; ?>
        ></script>
    </form>
</div>
</body>
</html>
