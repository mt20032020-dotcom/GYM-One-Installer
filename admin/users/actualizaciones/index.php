<?php
date_default_timezone_set('America/Bogota');
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

require_once '/app/includes/roles.php';
if (!gymone_can($conn, $userid, ['boss','finance'])) { header("Location: ../../dashboard/"); exit(); }

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
<?php
/* ===== PANEL ACTUALIZACION DE DATOS ===== */
/* expenses cierra $conn antes del HTML, asi que abrimos una propia */
$connP = new mysqli($db_host, $db_username, $db_password, $db_name);
$connP->set_charset('utf8mb4');
$conn = $connP;
$msgPanel = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['panel_uid'])) {
    $puid = (int)$_POST['panel_uid'];
    if ($puid && !empty($_POST['nuevo_cel'])) {
        $celP = preg_replace('/\D/','', $_POST['nuevo_cel']);
        if (strlen($celP) === 10 && $celP[0] === '3') {
            $sP = $conn->prepare("UPDATE users SET celular=? WHERE userid=?");
            $sP->bind_param('si', $celP, $puid); $sP->execute(); $sP->close();
            $msgPanel = ['ok', "Celular actualizado a $celP"];
        } else { $msgPanel = ['err', 'Numero invalido: debe tener 10 digitos y empezar por 3']; }
    }
    if ($puid && isset($_POST['resetear'])) {
        $conn->query("UPDATE users SET datos_actualizados = NULL WHERE userid = $puid");
        $msgPanel = ['ok', 'Ese socio ya puede volver a actualizar sus datos'];
    }
}
$fP = $_GET['f'] ?? 'todos';
$qP = trim($_GET['q'] ?? '');
$wP = ["u.userid <> 222222222222"];
if ($fP === 'pendientes')   $wP[] = "u.datos_actualizados IS NULL";
if ($fP === 'actualizados') $wP[] = "u.datos_actualizados IS NOT NULL";
if ($fP === 'celmal')       $wP[] = "u.celular NOT REGEXP '^3[0-9]{9}$'";
if ($qP !== '') { $bP = $conn->real_escape_string($qP);
    $wP[] = "(u.cedula LIKE '%$bP%' OR u.firstname LIKE '%$bP%' OR u.lastname LIKE '%$bP%')"; }
$rowsP = $conn->query("SELECT u.userid,u.cedula,u.firstname,u.lastname,u.celular,u.email,u.datos_actualizados
    FROM users u WHERE ".implode(' AND ', $wP)." ORDER BY u.datos_actualizados IS NULL DESC, u.firstname LIMIT 400");
$totP = $conn->query("SELECT COUNT(*) t, SUM(datos_actualizados IS NOT NULL) ok,
    SUM(datos_actualizados IS NULL) pend, SUM(celular NOT REGEXP '^3[0-9]{9}$') mal
    FROM users WHERE userid <> 222222222222")->fetch_assoc();
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
                    <li><a href="../../boss/finance"><i class="bi bi-cash-stack"></i> Reportes financieros</a></li>
                    <li class="active"><a href="../../boss/expenses"><i class="bi bi-wallet2"></i> Gastos</a></li>
                    <li><a href="../../boss/payroll"><i class="bi bi-clipboard-data"></i> Nomina</a></li>
                    <li><a href="../../boss/bot_info"><i class="bi bi-robot"></i> Info del Bot</a></li>
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
                        <a class="sidebar-link" href="../../boss/finance">
                            <i class="bi bi-cash-stack"></i>
                            <span>Reportes financieros</span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/expenses">
                            <i class="bi bi-wallet2"></i>
                            <span>Gastos</span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/payroll">
                            <i class="bi bi-clipboard-data"></i>
                            <span>Nomina</span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/bot_info">
                            <i class="bi bi-robot"></i>
                            <span>Info del Bot</span>
                        </a>
                    </li>
                </ul><br>
            </div>
            <br>
            <div class="col-sm-10">
                <div class="row"><div class="col-sm-12">
                    <h3 class="mb-3"><i class="bi bi-person-check"></i> Actualizacion de datos</h3>
                    <p class="text-muted">Quien ya confirmo sus datos y quien falta. Aqui puedes corregir celulares y permitir que alguien vuelva a actualizar.</p>
                    <?php if ($msgPanel): ?>
                      <div class="alert alert-<?php echo $msgPanel[0]==='ok'?'success':'danger'; ?>"><?php echo htmlspecialchars($msgPanel[1]); ?></div>
                    <?php endif; ?>
                    <div class="row mb-4">
                      <?php
                      $tarjetas = [['Socios',(int)$totP['t'],'#374151'],['Actualizados',(int)$totP['ok'],'#16a34a'],
                                   ['Pendientes',(int)$totP['pend'],'#d97706'],['Celular invalido',(int)$totP['mal'],'#dc2626']];
                      foreach ($tarjetas as $t): ?>
                        <div class="col-sm-3"><div class="card shadow"><div class="card-body" style="padding:16px">
                          <div style="color:#6B7280;font-size:13px"><?php echo $t[0]; ?></div>
                          <div style="font-size:28px;font-weight:700;color:<?php echo $t[2]; ?>"><?php echo $t[1]; ?></div>
                        </div></div></div>
                      <?php endforeach; ?>
                    </div>
                    <div style="margin-bottom:14px">
                      <?php foreach ([['todos','Todos'],['pendientes','Pendientes'],['actualizados','Actualizados'],['celmal','Celular invalido']] as $ff): ?>
                        <a href="?f=<?php echo $ff[0]; ?>" class="btn btn-<?php echo $fP===$ff[0]?'danger':'default'; ?> btn-sm"><?php echo $ff[1]; ?></a>
                      <?php endforeach; ?>
                      <form method="get" class="form-inline pull-right">
                        <input type="hidden" name="f" value="<?php echo htmlspecialchars($fP); ?>">
                        <input type="text" name="q" class="form-control input-sm" placeholder="Buscar cedula o nombre" value="<?php echo htmlspecialchars($qP); ?>">
                        <button class="btn btn-primary btn-sm">Buscar</button>
                      </form>
                    </div>
                    <div class="card shadow"><div class="card-body" style="padding:0">
                    <table class="table table-hover" style="margin:0">
                      <tr><th>Cedula</th><th>Nombre</th><th>Celular</th><th>Email</th><th>Estado</th><th>Acciones</th></tr>
                      <?php while ($uP = $rowsP->fetch_assoc()):
                        $celOkP = preg_match('/^3\d{9}$/', $uP['celular']);
                        $actP = !empty($uP['datos_actualizados']); ?>
                      <tr>
                        <td><?php echo htmlspecialchars($uP['cedula']); ?></td>
                        <td><?php echo htmlspecialchars(trim($uP['lastname'].' '.$uP['firstname'])); ?></td>
                        <td <?php if(!$celOkP) echo 'style="color:#dc2626;font-weight:600"'; ?>><?php echo htmlspecialchars($uP['celular']); ?></td>
                        <td style="font-size:12px;color:#6B7280"><?php echo htmlspecialchars($uP['email']); ?></td>
                        <td><?php if($actP): ?><span class="label label-success">OK <?php echo date('d/m', strtotime($uP['datos_actualizados'])); ?></span>
                            <?php else: ?><span class="label label-warning">Pendiente</span><?php endif; ?></td>
                        <td>
                          <form method="post" style="display:inline-flex;gap:4px">
                            <input type="hidden" name="panel_uid" value="<?php echo $uP['userid']; ?>">
                            <input type="text" name="nuevo_cel" class="form-control input-sm" style="width:110px" placeholder="Nuevo cel" maxlength="10">
                            <button class="btn btn-primary btn-sm">Guardar</button>
                          </form>
                          <?php if($actP): ?>
                          <form method="post" style="display:inline" onsubmit="return confirm('Permitir que actualice de nuevo?')">
                            <input type="hidden" name="panel_uid" value="<?php echo $uP['userid']; ?>">
                            <input type="hidden" name="resetear" value="1">
                            <button class="btn btn-default btn-sm">Reset</button>
                          </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php endwhile; ?>
                    </table>
                    </div></div>
                </div></div>
            </div>
        </div>
    </div>    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>
