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
$chk = $conn->query("SHOW COLUMNS FROM expenses LIKE 'payment_method'");
if ($chk && $chk->num_rows == 0) { $conn->query("ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'efectivo'"); }
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

$categories = ['Renta', 'Nómina', 'Servicios', 'Mantenimiento', 'Insumos', 'Marketing', 'Impuestos', 'Otro'];

$alert = '';

// ----- Add expense -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'Otro');
    $amount = (float) ($_POST['amount'] ?? 0);
    $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
    $payment_method = in_array($_POST['payment_method'] ?? '', ['efectivo','transferencia','tarjeta']) ? $_POST['payment_method'] : 'efectivo';

    if ($description !== '' && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO expenses (description, category, amount, expense_date, created_by, payment_method) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdsis", $description, $category, $amount, $expense_date, $userid, $payment_method);
        $stmt->execute();
        $stmt->close();
        $alert = 'added';
    } else {
        $alert = 'error';
    }

    header("Location: " . $_SERVER['PHP_SELF'] . "?alert=$alert");
    exit();
}

// ----- Delete expense -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense'])) {
    $delete_id = (int) $_POST['delete_id'];
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF'] . "?alert=deleted");
    exit();
}

$alert = $_GET['alert'] ?? '';

// ----- Date range -----
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');
$start_date = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : $firstOfMonth;
$end_date = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : $today;

// ----- List expenses in range -----
$expenses = [];
$total_expenses = 0;
$met_tot = ["efectivo"=>0,"transferencia"=>0,"tarjeta"=>0];
$stmtM = $conn->prepare("SELECT payment_method, SUM(amount) t FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY payment_method");
if ($stmtM) { $stmtM->bind_param("ss", $start_date, $end_date); $stmtM->execute(); $rM = $stmtM->get_result(); while($x=$rM->fetch_assoc()){ $k=$x["payment_method"] ?: "efectivo"; if(isset($met_tot[$k])) $met_tot[$k]=(float)$x["t"]; } $stmtM->close(); }

$stmt = $conn->prepare("SELECT id, description, category, amount, expense_date, payment_method FROM expenses WHERE expense_date BETWEEN ? AND ? ORDER BY expense_date DESC, id DESC");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $expenses[] = $row;
    $total_expenses += (float) $row['amount'];
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_code); ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo t($translations, "dashboard", "Panel"); ?> - Gastos</title>
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
        .stat-card .value { font-size: 26px; font-weight: bold; }
        .bg-expense { background: linear-gradient(135deg,#dc2626,#b91c1c); }
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
                    <li><a href="../finance"><i class="bi bi-cash-stack"></i> Reportes financieros</a></li>
                    <li class="active"><a href="#"><i class="bi bi-wallet2"></i> Gastos</a></li>
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
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../finance">
                            <i class="bi bi-cash-stack"></i>
                            <span>Reportes financieros</span>
                        </a>
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
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

                        <h3 class="mb-3"><i class="bi bi-wallet2"></i> Gastos del negocio</h3>

                        <?php if ($alert === 'added'): ?>
                            <div class="alert alert-success">Gasto agregado correctamente.</div>
                        <?php elseif ($alert === 'deleted'): ?>
                            <div class="alert alert-success">Gasto eliminado.</div>
                        <?php elseif ($alert === 'error'): ?>
                            <div class="alert alert-danger">Revisa los datos: la descripción y el monto son obligatorios.</div>
                        <?php endif; ?>

                        <!-- Add expense form -->
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <h4 class="mb-3">Agregar gasto</h4>
                                <form method="post" class="form-inline">
                                    <input type="hidden" name="add_expense" value="1">
                                    <div class="form-group" style="margin-right:10px;">
                                        <label style="margin-right:5px;">Descripción</label>
                                        <input type="text" name="description" class="form-control" placeholder="Ej. Pago de luz" required>
                                    </div>
                                    <div class="form-group" style="margin-right:10px;">
                                        <label style="margin-right:5px;">Categoría</label>
                                        <select name="category" class="form-control">
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-right:10px;">
                                        <label style="margin-right:5px;">M&eacute;todo</label>
                                        <select name="payment_method" class="form-control">
                                            <option value="efectivo">Efectivo</option>
                                            <option value="transferencia">Transferencia</option>
                                            <option value="tarjeta">Tarjeta</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-right:10px;">
                                        <label style="margin-right:5px;">Monto</label>
                                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div class="form-group" style="margin-right:10px;">
                                        <label style="margin-right:5px;">Fecha</label>
                                        <input type="date" name="expense_date" class="form-control" value="<?php echo $today; ?>" required>
                                    </div>
                                    <button type="submit" class="btn btn-danger"><i class="bi bi-plus-circle"></i> Agregar</button>
                                </form>
                            </div>
                        </div>

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
                                </form>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-sm-3">
                                <div class="stat-card" style="background:#141418;border:1px solid #232329;color:#fff;min-width:220px;margin-right:12px;">
                            <h4 style="margin:0 0 8px;">Por m&eacute;todo</h4>
                            <div style="font-size:.95rem;">Efectivo: <strong><?php echo number_format($met_tot["efectivo"], 2); ?></strong></div>
                            <div style="font-size:.95rem;">Transferencia: <strong><?php echo number_format($met_tot["transferencia"], 2); ?></strong></div>
                            <div style="font-size:.95rem;">Tarjeta: <strong><?php echo number_format($met_tot["tarjeta"], 2); ?></strong></div>
                        </div>
                        <div class="stat-card bg-expense">
                                    <h4>Total gastos del periodo</h4>
                                    <div class="value"><?php echo number_format($total_expenses, 2); ?> <?php echo htmlspecialchars($currency); ?></div>
                                    <small><?php echo count($expenses); ?> gastos</small>
                                </div>
                            </div>
                        </div>

                        <!-- Expense list -->
                        <div class="card shadow mb-4">
                            <div class="card-body">
                                <h4 class="mb-3">Detalle (<?php echo htmlspecialchars($start_date); ?> a <?php echo htmlspecialchars($end_date); ?>)</h4>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped text-center">
                                        <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Descripción</th>
                                                <th>Categoría</th>
                                                <th>M&eacute;todo</th>
                                                <th>Monto</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($expenses) > 0): ?>
                                                <?php foreach ($expenses as $exp): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($exp['expense_date']); ?></td>
                                                        <td><?php echo htmlspecialchars($exp['description']); ?></td>
                                                        <td><?php echo htmlspecialchars($exp['category']); ?></td>
                                                        <td><?php echo ucfirst(htmlspecialchars($exp['payment_method'] ?? 'efectivo')); ?></td>
                                                        <td><?php echo number_format($exp['amount'], 2); ?> <?php echo htmlspecialchars($currency); ?></td>
                                                        <td>
                                                            <form method="post" onsubmit="return confirm('¿Eliminar este gasto?');" style="display:inline;">
                                                                <input type="hidden" name="delete_expense" value="1">
                                                                <input type="hidden" name="delete_id" value="<?php echo (int) $exp['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="6">No hay gastos registrados en este rango de fechas.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
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
