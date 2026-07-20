<?php
session_start();
if (!isset($_SESSION['adminuser'])) { header("Location: ../../"); exit(); }
$userid = $_SESSION['adminuser'];

$env = [];
foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) die("Error de conexión");

// Verificar que sea worker o boss
$stmt = $conn->prepare("SELECT is_boss FROM workers WHERE userid = ?");
$stmt->bind_param("i", $userid);
$stmt->execute();
$worker = $stmt->get_result()->fetch_assoc();
if (!$worker) { header("Location: ../../dashboard/"); exit(); }

$business_name = $env['BUSINESS_NAME'] ?? 'Adrenaline Gym';
$hoy = date('Y-m-d');
$BASE = 80000;
$msg = "";

// ===== Registrar gasto de caja =====
if (isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    $desc = trim($_POST['exp_desc'] ?? '');
    $amt = floatval($_POST['exp_amount'] ?? 0);
    if ($desc && $amt > 0) {
        $stmt = $conn->prepare("INSERT INTO cash_expenses (expense_date, description, amount, created_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssdi", $hoy, $desc, $amt, $userid);
        $stmt->execute();
        $msg = "Gasto registrado.";
    }
}

// ===== Eliminar gasto (solo del dia, antes del cierre) =====
if (isset($_GET['del_expense']) && is_numeric($_GET['del_expense'])) {
    $conn->query("DELETE FROM cash_expenses WHERE id = " . intval($_GET['del_expense']) . " AND expense_date = '$hoy'");
    header("Location: ./"); exit();
}

// ===== Datos del dia =====
$rev = $conn->query("SELECT COALESCE(cash,0) cash, COALESCE(bank_card,0) card, COALESCE(transfer,0) transfer, COALESCE(web,0) web FROM revenu_stats WHERE date = '$hoy'")->fetch_assoc();
if (!$rev) $rev = ['cash'=>0, 'card'=>0, 'transfer'=>0, 'web'=>0];

$gastos = $conn->query("SELECT ce.*, u.firstname, u.lastname FROM cash_expenses ce LEFT JOIN users u ON u.userid = ce.created_by WHERE ce.expense_date = '$hoy' ORDER BY ce.id DESC")->fetch_all(MYSQLI_ASSOC);
$total_gastos = array_sum(array_column($gastos, 'amount'));

$esperado = $BASE + $rev['cash'] - $total_gastos;

// Ya cerrado hoy?
$cierre_hoy = $conn->query("SELECT cc.*, u.firstname, u.lastname FROM cash_closures cc LEFT JOIN users u ON u.userid = cc.closed_by WHERE closure_date = '$hoy'")->fetch_assoc();

// ===== Ejecutar cierre =====
if (isset($_POST['action']) && $_POST['action'] === 'close' && !$cierre_hoy) {
    $contado = floatval($_POST['counted'] ?? 0);
    $obs = trim($_POST['observations'] ?? '');
    $dif = $contado - $esperado;
    $stmt = $conn->prepare("INSERT INTO cash_closures (closure_date, base_amount, cash_sales, card_sales, transfer_sales, web_sales, cash_expenses, expected_cash, counted_cash, difference, observations, closed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sdddddddddsi", $hoy, $BASE, $rev['cash'], $rev['card'], $rev['transfer'], $rev['web'], $total_gastos, $esperado, $contado, $dif, $obs, $userid);
    if ($stmt->execute()) {
        // Correo al dueño
        require_once '/app/includes/mailer.php';
        require_once '/app/includes/email_templates.php';
        $uC = $conn->query("SELECT firstname, lastname FROM users WHERE userid = $userid")->fetch_assoc();
        $dif_txt = $dif == 0 ? "✓ Cuadró exacto" : ($dif > 0 ? "Sobrante: $" . number_format($dif,0,',','.') : "FALTANTE: $" . number_format(abs($dif),0,',','.'));
        $body = adrenaline_email(
            "💰 CIERRE DE CAJA",
            "Cierre del " . date('d/m/Y'),
            "Cerrado por " . htmlspecialchars(($uC['firstname'] ?? '') . ' ' . ($uC['lastname'] ?? '')) . ". Resultado: <strong>" . $dif_txt . "</strong>",
            [
                "Base de caja" => "$" . number_format($BASE,0,',','.'),
                "Ventas efectivo" => "$" . number_format($rev['cash'],0,',','.'),
                "Gastos de caja" => "-$" . number_format($total_gastos,0,',','.'),
                "Efectivo esperado" => "$" . number_format($esperado,0,',','.'),
                "Efectivo contado" => "$" . number_format($contado,0,',','.'),
                "Diferencia" => $dif_txt,
                "Tarjeta" => "$" . number_format($rev['card'],0,',','.'),
                "Transferencia" => "$" . number_format($rev['transfer'],0,',','.'),
                "Pagos Web" => "$" . number_format($rev['web'],0,',','.'),
            ],
            $obs ? '<p style="color:#6B7280;font-size:14px;"><strong>Observaciones:</strong> ' . htmlspecialchars($obs) . '</p>' : ''
        );
        @send_mail($env, $env['MAIL_USERNAME'], "Cierre de caja " . date('d/m/Y') . " — " . $dif_txt, $body, $business_name, true);
        header("Location: ./"); exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de Caja - <?php echo htmlspecialchars($business_name); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f4f5f7; font-family: 'Segoe UI', Arial, sans-serif; }
        .topbar { background: #111; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; }
        .topbar img { height: 44px; }
        .topbar a { color: #fff; text-decoration: none; font-size: 0.9em; }
        .wrap { max-width: 820px; margin: 26px auto; padding: 0 16px; }
        .card-x { background: #fff; border-radius: 14px; padding: 22px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); margin-bottom: 18px; }
        .money-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .money-row .v { font-weight: bold; }
        .big-expected { background: #111; color: #fff; border-radius: 12px; padding: 18px; text-align: center; margin: 14px 0; }
        .big-expected .amount { font-size: 2em; font-weight: 800; color: #4ade80; }
        .btn-adr { background: #e53935; color: #fff; border: none; }
        .btn-adr:hover { background: #c62828; color: #fff; }
        .dif-ok { color: #16a34a; } .dif-bad { color: #dc2626; }
    </style>
</head>
<body>
<div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <img src="../../assets/img/logo.png" alt="Logo" onerror="this.style.display='none'">
        <span style="color:#fff;font-weight:bold;letter-spacing:0.5px;">CIERRE DE CAJA — <?php echo date('d/m/Y'); ?></span>
    </div>
    <a href="../dashboard/"><i class="bi bi-arrow-left"></i> Volver al panel</a>
</div>

<div class="wrap">

<?php if ($cierre_hoy): ?>
    <div class="card-x" style="text-align:center;">
        <i class="bi bi-check-circle" style="font-size:3em;color:#16a34a;"></i>
        <h3>Caja cerrada hoy</h3>
        <p style="color:#777;">Cerrada por <strong><?php echo htmlspecialchars(($cierre_hoy['firstname'] ?? '') . ' ' . ($cierre_hoy['lastname'] ?? '')); ?></strong> a las <?php echo date('h:i A', strtotime($cierre_hoy['closed_at'])); ?></p>
        <div class="money-row"><span>Efectivo esperado</span><span class="v">$<?php echo number_format($cierre_hoy['expected_cash'],0,',','.'); ?></span></div>
        <div class="money-row"><span>Efectivo contado</span><span class="v">$<?php echo number_format($cierre_hoy['counted_cash'],0,',','.'); ?></span></div>
        <div class="money-row"><span>Diferencia</span>
            <span class="v <?php echo $cierre_hoy['difference'] == 0 ? 'dif-ok' : 'dif-bad'; ?>">
                <?php echo $cierre_hoy['difference'] == 0 ? 'Cuadró ✓' : ($cierre_hoy['difference'] > 0 ? '+' : '') . '$' . number_format($cierre_hoy['difference'],0,',','.'); ?>
            </span>
        </div>
        <?php if ($cierre_hoy['observations']): ?>
        <p style="color:#888;margin-top:10px;"><i class="bi bi-chat-left-text"></i> <?php echo htmlspecialchars($cierre_hoy['observations']); ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card-x">
        <h4 style="margin-top:0;"><i class="bi bi-graph-up"></i> Ventas de hoy</h4>
        <div class="money-row"><span><i class="bi bi-cash-coin"></i> Efectivo</span><span class="v">$<?php echo number_format($rev['cash'],0,',','.'); ?></span></div>
        <div class="money-row"><span><i class="bi bi-credit-card"></i> Tarjeta</span><span class="v">$<?php echo number_format($rev['card'],0,',','.'); ?></span></div>
        <div class="money-row"><span><i class="bi bi-bank"></i> Transferencia</span><span class="v">$<?php echo number_format($rev['transfer'],0,',','.'); ?></span></div>
        <div class="money-row"><span><i class="bi bi-globe"></i> Pagos Web</span><span class="v">$<?php echo number_format($rev['web'],0,',','.'); ?></span></div>
    </div>

    <div class="card-x">
        <h4 style="margin-top:0;"><i class="bi bi-cart-dash"></i> Gastos de caja de hoy</h4>
        <?php if (!empty($msg)): ?><div class="alert alert-success" style="padding:8px;"><?php echo $msg; ?></div><?php endif; ?>
        <?php foreach ($gastos as $g): ?>
        <div class="money-row">
            <span><?php echo htmlspecialchars($g['description']); ?> <small style="color:#aaa;">(<?php echo htmlspecialchars($g['firstname'] ?? ''); ?>)</small></span>
            <span class="v">-$<?php echo number_format($g['amount'],0,',','.'); ?>
                <a href="?del_expense=<?php echo $g['id']; ?>" onclick="return confirm('¿Eliminar este gasto?');" style="color:#dc2626;margin-left:8px;"><i class="bi bi-trash"></i></a>
            </span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($gastos)): ?><p style="color:#aaa;">Sin gastos registrados hoy.</p><?php endif; ?>
        <form method="POST" style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="add_expense">
            <input type="text" name="exp_desc" class="form-control" placeholder="Descripción (ej: agua, aseo...)" required style="flex:2;min-width:180px;">
            <input type="number" name="exp_amount" class="form-control" placeholder="Valor" required min="1" style="flex:1;min-width:110px;">
            <button type="submit" class="btn btn-default"><i class="bi bi-plus-lg"></i> Registrar gasto</button>
        </form>
    </div>

    <div class="card-x">
        <h4 style="margin-top:0;"><i class="bi bi-safe"></i> Cierre</h4>
        <div class="money-row"><span>Base de caja</span><span class="v">$<?php echo number_format($BASE,0,',','.'); ?></span></div>
        <div class="money-row"><span>+ Ventas en efectivo</span><span class="v">$<?php echo number_format($rev['cash'],0,',','.'); ?></span></div>
        <div class="money-row"><span>− Gastos de caja</span><span class="v">$<?php echo number_format($total_gastos,0,',','.'); ?></span></div>
        <div class="big-expected">
            <div>Efectivo que debe haber en el cajón</div>
            <div class="amount">$<?php echo number_format($esperado,0,',','.'); ?></div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="close">
            <label>¿Cuánto efectivo contaste físicamente?</label>
            <input type="number" name="counted" class="form-control" required min="0" step="50" style="font-size:1.3em;font-weight:bold;margin-bottom:10px;" placeholder="0">
            <label>Observaciones (opcional)</label>
            <textarea name="observations" class="form-control" rows="2" style="margin-bottom:14px;" placeholder="Ej: se dio mal un vuelto..."></textarea>
            <button type="submit" class="btn btn-adr btn-lg" style="width:100%;" onclick="return confirm('¿Confirmar el cierre de caja? Esta acción no se puede deshacer hoy.');">
                <i class="bi bi-lock"></i> Cerrar caja del día
            </button>
        </form>
    </div>

<?php endif; ?>
</div>
</body>
</html>
