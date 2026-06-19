<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../../");
    exit();
}

$userid = $_SESSION['adminuser'];

function read_env_file($file_path)
{
    if (!file_exists($file_path)) {
        die("No se encontró el archivo .env: $file_path");
    }
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];

    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line, 2);
        if (count($line_parts) == 2) {
            $key = trim($line_parts[0]);
            $value = trim($line_parts[1]);
            $env_data[$key] = $value;
        }
    }

    return $env_data;
}

$env_data = read_env_file('../../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$currency = $env_data["CURRENCY"] ?? '';

$lang = $lang_code;
$langDir = __DIR__ . "/../../../assets/lang/";
$langFile = $langDir . "$lang.json";

$translations = [];
if (file_exists($langFile)) {
    $translations = json_decode(file_get_contents($langFile), true);
}

function t($translations, $key, $fallback)
{
    return $translations[$key] ?? $fallback;
}

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$sql = "SELECT is_boss FROM workers WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$stmt->store_result();

$is_boss = null;
if ($stmt->num_rows > 0) {
    $stmt->bind_result($is_boss);
    $stmt->fetch();
}
$stmt->close();

if ($is_boss != 1) {
    header("Location: ../../dashboard/");
    exit();
}

// ----- Make sure the expenses table exists -----
$conn->query("
    CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        category VARCHAR(100) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        expense_date DATE NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// ----- Date range -----
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');
$start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : $firstOfMonth;
$end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : $today;

// ----- CSV export -----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $conn->prepare("SELECT name, price, status, created_at FROM invoices WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at ASC");
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_financiero_' . $start_date . '_a_' . $end_date . '.csv"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tipo', 'Cliente/Descripción', 'Categoría/Estado', 'Monto', 'Fecha']);
    while ($row = $res->fetch_assoc()) {
        fputcsv($out, ['Ingreso', $row['name'], $row['status'], $row['price'], $row['created_at']]);
    }
    $stmt->close();

    $stmt2 = $conn->prepare("SELECT description, category, amount, expense_date FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date ASC");
    $stmt2->bind_param("ss", $start_date, $end_date);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($row = $res2->fetch_assoc()) {
        fputcsv($out, ['Gasto', $row['description'], $row['category'], $row['amount'], $row['expense_date']]);
    }
    $stmt2->close();

    fclose($out);
    $conn->close();
    exit();
}

// ----- Summary for selected range (from invoices) -----
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN price ELSE 0 END), 0) AS paid_total,
        COALESCE(SUM(CASE WHEN status = 'unpaid' THEN price ELSE 0 END), 0) AS unpaid_total,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) AS paid_count,
        COUNT(CASE WHEN status = 'unpaid' THEN 1 END) AS unpaid_count
    FROM invoices
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ----- Cash vs card breakdown for selected range (from revenu_stats) -----
$total_cash = 0;
$total_card = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(cash),0) AS total_cash, COALESCE(SUM(bank_card),0) AS total_card FROM revenu_stats WHERE `date` BETWEEN ? AND ?");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$pm = $stmt->get_result()->fetch_assoc();
if ($pm) {
    $total_cash = (float) $pm['total_cash'];
    $total_card = (float) $pm['total_card'];
}
$stmt->close();

// ----- Expenses for selected range -----
$total_expenses = 0;
$expenses_by_category = [];
$stmt = $conn->prepare("SELECT category, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $expenses_by_category[] = $row;
    $total_expenses += (float) $row['total'];
}
$stmt->close();

$net_profit = $summary['paid_total'] - $total_expenses;

// ----- Monthly trend, last 12 months (income) -----
$monthly = [];
$res = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS ym,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN price ELSE 0 END), 0) AS paid_total,
        COALESCE(SUM(CASE WHEN status = 'unpaid' THEN price ELSE 0 END), 0) AS unpaid_total,
        COUNT(*) AS invoice_count
    FROM invoices
    GROUP BY ym
    ORDER BY ym DESC
    LIMIT 12
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $monthly[$row['ym']] = $row;
    }
}

// ----- Monthly expenses, last 12 months -----
$monthly_expenses = [];
$res = $conn->query("
    SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
    FROM expenses
    GROUP BY ym
    ORDER BY ym DESC
    LIMIT 12
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $monthly_expenses[$row['ym']] = (float) $row['total'];
    }
}

// Merge months (union of both sets), sorted descending
$all_months = array_unique(array_merge(array_keys($monthly), array_keys($monthly_expenses)));
rsort($all_months);
$all_months = array_slice($all_months, 0, 12);

// ----- Detailed invoice list within the selected range -----
$detail_rows = [];
$stmt = $conn->prepare("SELECT userid, name, price, status, created_at, route FROM invoices WHERE DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 200");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $detail_rows[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_code); ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo t($translations, "dashboard", "Panel"); ?> - Reportes financieros</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
    <style>
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            color: #fff;
            margin-bottom: 15px;
        }
        .stat-card h4 { margin: 0 0 5px 0; font-size: 14px; opacity: .85; }
        .stat-card .value { font-size: 24px; font-weight: bold; }
        .bg-paid { background: linear-gradient(135deg,#16a34a,#15803d); }
        .bg-unpaid { background: linear-gradient(135deg,#f59e0b,#d97706); }
        .bg-cash { background: linear-gradient(135deg,#2563eb,#1d4ed8); }
        .bg-card { background: linear-gradient(135deg,#7c3aed,#6d28d9); }
        .bg-expense { background: linear-gradient(135deg,#dc2626,#b91c1c); }
        .bg-profit { background: linear-gradient(135deg,#0d9488,#0f766e); }
    </style>
</head>

<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#"><img src="../../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../../dashboard"><i class="bi bi-speedometer"></i> <?php echo t($translations, "mainpage", "Inicio"); ?></a></li>
                    <li><a href="../../users"><i class="bi bi-people"></i> <?php echo t($translations, "users", "Miembros"); ?></a></li>
                    <li><a href="../../statistics"><i class="bi bi-bar-chart"></i> <?php echo t($translations, "statspage", "Estadísticas"); ?></a></li>
                    <li><a href="../../boss/sell"><i class="bi bi-shop"></i> <?php echo t($translations, "sellpage", "Venta"); ?></a></li>
                    <li><a href="../../invoices"><i class="bi bi-receipt"></i> <?php echo t($translations, "invoicepage", "Facturas"); ?></a></li>
                    <li class="active"><a href="#"><i class="bi bi-cash-stack"></i> Reportes financieros</a></li>
                    <li><a href="../expenses"><i class="bi bi-wallet2"></i> Gastos</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../../assets/img/logo.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo htmlspecialchars($business_name); ?> - <?php echo htmlspecialchars($version); ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../dashboard/">
                            <i class="bi bi-speedometer"></i> <?php echo t($translations, "mainpage", "Inicio"); ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../users">
                            <i class="bi bi-people"></i> <?php echo t($translations, "users", "Miembros"); ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../statistics">
                            <i class="bi bi-bar-chart"></i> <?php echo t($translations, "statspage", "Estadísticas"); ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/sell">
                            <i class="bi bi-shop"></i> <?php echo t($translations, "sellpage", "Venta"); ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="../../invoices/" class="sidebar-link">
                            <i class="bi bi-receipt"></i> <?php echo t($translations, "invoicepage", "Facturas"); ?>
                        </a>
                    </li>
                    <li class="sidebar-header">Finanzas</li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-cash-stack"></i>
                            <span>Reportes financieros</span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../expenses">
                            <i class="bi bi-wallet2"></i>
                            <span>Gastos</span>
                        </a>
                    </li>
                </ul><br>
            </div>
            <br>
            <div class="col-sm-10">
                <div class="row">
                    <div class="col-sm-12">

                        <h3 class="mb-3"><i class="bi bi-cash-stack"></i> Reportes financieros</h3>

                        <!-- Filter -->
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <form method="get" class="form-inline">
                                    <div class="form-group" style="margin-right:15px;">
                                        <label style="margin-right:5px;">Desde</label>
                                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                                    </div>
                                    <div class="form-group" style="margin-right:15px;">
                                        <label style="margin-right:5px;">Hasta</label>
                                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
                                    <a href="?start_date=<?php echo htmlspecialchars($start_date); ?>&end_date=<?php echo htmlspecialchars($end_date); ?>&export=csv"
                                       class="btn btn-success" style="margin-left:10px;">
                                        <i class="bi bi-file-earmark-spreadsheet"></i> Exportar CSV
                                    </a>
                                </form>
                            </div>
                        </div>

                        <!-- Summary cards -->
                        <div class="row">
                            <div class="col-sm-2">
                                <div class="stat-card bg-paid">
                                    <h4>Ingresos (pagado)</h4>
                                    <div class="value"><?php echo number_format($summary['paid_total'], 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                    <small><?php echo $summary['paid_count']; ?> facturas</small>
                                </div>
                            </div>
                            <div class="col-sm-2">
                                <div class="stat-card bg-unpaid">
                                    <h4>Pendiente</h4>
                                    <div class="value"><?php echo number_format($summary['unpaid_total'], 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                    <small><?php echo $summary['unpaid_count']; ?> facturas</small>
                                </div>
                            </div>
                            <div class="col-sm-2">
                                <div class="stat-card bg-expense">
                                    <h4>Gastos</h4>
                                    <div class="value"><?php echo number_format($total_expenses, 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                </div>
                            </div>
                            <div class="col-sm-2">
                                <div class="stat-card bg-profit">
                                    <h4>Ganancia neta</h4>
                                    <div class="value"><?php echo number_format($net_profit, 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                    <small>Ingresos - Gastos</small>
                                </div>
                            </div>
                            <div class="col-sm-2">
                                <div class="stat-card bg-cash">
                                    <h4>Efectivo</h4>
                                    <div class="value"><?php echo number_format($total_cash, 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                </div>
                            </div>
                            <div class="col-sm-2">
                                <div class="stat-card bg-card">
                                    <h4>Tarjeta</h4>
                                    <div class="value"><?php echo number_format($total_card, 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Monthly trend -->
                            <div class="col-sm-8">
                                <div class="card shadow mb-4">
                                    <div class="card-body">
                                        <h4 class="mb-3">Tendencia mensual (últimos 12 meses)</h4>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped text-center">
                                                <thead>
                                                    <tr>
                                                        <th>Mes</th>
                                                        <th>Ingresos</th>
                                                        <th>Gastos</th>
                                                        <th>Ganancia neta</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($all_months) > 0): ?>
                                                        <?php foreach ($all_months as $ym): ?>
                                                            <?php
                                                            $income = isset($monthly[$ym]) ? (float) $monthly[$ym]['paid_total'] : 0;
                                                            $exp = $monthly_expenses[$ym] ?? 0;
                                                            $profit = $income - $exp;
                                                            ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($ym); ?></td>
                                                                <td><?php echo number_format($income, 2); ?> <?php echo htmlspecialchars($currency); ?></td>
                                                                <td><?php echo number_format($exp, 2); ?> <?php echo htmlspecialchars($currency); ?></td>
                                                                <td style="color: <?php echo $profit >= 0 ? '#16a34a' : '#dc2626'; ?>; font-weight:bold;">
                                                                    <?php echo number_format($profit, 2); ?> <?php echo htmlspecialchars($currency); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr><td colspan="4">Todavía no hay datos suficientes.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Expenses by category -->
                            <div class="col-sm-4">
                                <div class="card shadow mb-4">
                                    <div class="card-body">
                                        <h4 class="mb-3">Gastos por categoría</h4>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped text-center">
                                                <thead>
                                                    <tr><th>Categoría</th><th>Total</th></tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (count($expenses_by_category) > 0): ?>
                                                        <?php foreach ($expenses_by_category as $cat): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($cat['category']); ?></td>
                                                                <td><?php echo number_format($cat['total'], 2); ?> <?php echo htmlspecialchars($currency); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <tr><td colspan="2">Sin gastos en este periodo.</td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <a href="../expenses" class="btn btn-danger btn-block"><i class="bi bi-plus-circle"></i> Agregar gasto</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Detail table -->
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <h4 class="mb-3">Detalle de facturas del periodo (<?php echo htmlspecialchars($start_date); ?> a <?php echo htmlspecialchars($end_date); ?>)</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped text-center">
                                        <thead>
                                            <tr>
                                                <th>Cliente</th>
                                                <th>Precio</th>
                                                <th>Fecha</th>
                                                <th>Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($detail_rows) > 0): ?>
                                                <?php foreach ($detail_rows as $row): ?>
                                                    <tr>
                                                        <td><a class="linkhref" href="../../users/edit/?user=<?php echo (int) $row['userid']; ?>"><?php echo htmlspecialchars($row['name']); ?></a></td>
                                                        <td><?php echo htmlspecialchars($row['price']); ?> <?php echo htmlspecialchars($currency); ?></td>
                                                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                                        <td>
                                                            <span class="<?php echo $row['status'] === 'unpaid' ? 'badge bg-label-danger' : 'badge bg-label-success'; ?>">
                                                                <?php echo $row['status'] === 'unpaid' ? 'No pagado' : 'Pagado'; ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="4">No hay facturas en este rango de fechas.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                    <?php if (count($detail_rows) >= 200): ?>
                                        <p class="text-muted">Mostrando las primeras 200 facturas del rango. Usa "Exportar CSV" para ver todas.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>
