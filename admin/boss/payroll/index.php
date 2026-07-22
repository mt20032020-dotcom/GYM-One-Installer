<?php
session_start();
if (!isset($_SESSION['adminuser'])) {
    header("Location: /admin/");
    exit();
}
$userid = $_SESSION['adminuser'];

function read_env_file($file_path) {
    $env_data = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) @file_get_contents($file_path)) as $line) {
        if (trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $env_data[trim($parts[0])] = trim($parts[1]);
    }
    return $env_data;
}
$env_data = read_env_file('/app/.env');
$conn = new mysqli($env_data['DB_SERVER'] ?? '', $env_data['DB_USERNAME'] ?? '', $env_data['DB_PASSWORD'] ?? '', $env_data['DB_NAME'] ?? '');
if ($conn->connect_error) { die("Error de conexion: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');
$currency = $env_data['CURRENCY'] ?? 'COP';
$business_name = $env_data['BUSINESS_NAME'] ?? '';
$version = $env_data['APP_VERSION'] ?? '';

$is_boss = null;
$stmtB = $conn->prepare("SELECT is_boss FROM workers WHERE userid = ?");
$stmtB->bind_param("i", $userid);
$stmtB->execute();
$stmtB->bind_result($is_boss);
$stmtB->fetch();
$stmtB->close();

$conn->query("CREATE TABLE IF NOT EXISTS payroll_expense_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    month INT NOT NULL,
    quincena TINYINT NOT NULL,
    expense_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    created_by BIGINT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_period (year, month, quincena)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$chkPM = $conn->query("SHOW COLUMNS FROM expenses LIKE 'payment_method'");
if ($chkPM && $chkPM->num_rows == 0) { $conn->query("ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'efectivo'"); }

function quincena_range($year, $month, $quincena) {
    $year = (int)$year; $month = (int)$month;
    if ($quincena == 1) {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = sprintf('%04d-%02d-15', $year, $month);
    } else {
        $lastDay = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        $start = sprintf('%04d-%02d-16', $year, $month);
        $end = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
    }
    return [$start, $end];
}

$diasSemana = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miercoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sabado'];
$mesesNombres = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$today = new DateTime();
$sel_year = (int) ($_GET['year'] ?? $_POST['year'] ?? $today->format('Y'));
$sel_month = (int) ($_GET['month'] ?? $_POST['month'] ?? $today->format('n'));
$sel_quincena = (int) ($_GET['quincena'] ?? $_POST['quincena'] ?? ($today->format('j') <= 15 ? 1 : 2));
$sel_trainer = (int) ($_GET['trainer_id'] ?? $_POST['trainer_id'] ?? 0);

$saveMsg = '';
$expenseMsg = '';
$expenseMsgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar']) && $sel_trainer > 0) {
    [$startDate, $endDate] = quincena_range($sel_year, $sel_month, $sel_quincena);
    $cursor = new DateTime($startDate);
    $endDt = new DateTime($endDate);
    $now = date('Y-m-d H:i:s');
    $turnosGuardados = 0;

    while ($cursor <= $endDt) {
        $d = $cursor->format('Y-m-d');
        $insArr = $_POST['time_in'][$d] ?? [];
        $outArr = $_POST['time_out'][$d] ?? [];

        $del = $conn->prepare("DELETE FROM payroll_hours WHERE trainer_id = ? AND work_date = ?");
        $del->bind_param("is", $sel_trainer, $d);
        $del->execute();
        $del->close();

        $n = max(count($insArr), count($outArr));
        for ($i = 0; $i < $n; $i++) {
            $tin = trim($insArr[$i] ?? '');
            $tout = trim($outArr[$i] ?? '');
            if ($tin === '' || $tout === '') continue;

            $inMin = (int) substr($tin,0,2)*60 + (int) substr($tin,3,2);
            $outMin = (int) substr($tout,0,2)*60 + (int) substr($tout,3,2);
            if ($outMin <= $inMin) $outMin += 24*60;
            $horas = round(($outMin - $inMin) / 60, 2);

            $stmt = $conn->prepare("INSERT INTO payroll_hours (trainer_id, work_date, time_in, time_out, hours_worked, created_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssdis", $sel_trainer, $d, $tin, $tout, $horas, $userid, $now);
            $stmt->execute();
            $stmt->close();
            $turnosGuardados++;
        }
        $cursor->modify('+1 day');
    }
    $saveMsg = "Guardado: $turnosGuardados turno(s) en total.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emitir_gasto']) && $is_boss == 1) {
    $chk = $conn->prepare("SELECT expense_id, amount, created_at FROM payroll_expense_log WHERE year=? AND month=? AND quincena=?");
    $chk->bind_param("iii", $sel_year, $sel_month, $sel_quincena);
    $chk->execute();
    $yaEmitido = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($yaEmitido) {
        $expenseMsg = "Este periodo ya tiene un gasto de nomina emitido (" . number_format($yaEmitido['amount'],0,',','.') . " $currency, el " . $yaEmitido['created_at'] . "). No se volvio a emitir.";
        $expenseMsgType = 'warning';
    } else {
        [$eStart, $eEnd] = quincena_range($sel_year, $sel_month, $sel_quincena);
        $rSum = $conn->prepare("SELECT COALESCE(SUM(ph.hours_worked * t.hourly_wage),0) as total
            FROM payroll_hours ph JOIN trainers t ON t.id = ph.trainer_id
            WHERE ph.work_date BETWEEN ? AND ? AND t.hourly_wage IS NOT NULL");
        $rSum->bind_param("ss", $eStart, $eEnd);
        $rSum->execute();
        $totalToExpense = (float) $rSum->get_result()->fetch_assoc()['total'];
        $rSum->close();

        if ($totalToExpense <= 0) {
            $expenseMsg = "No hay horas con tarifa asignada en este periodo para emitir un gasto.";
            $expenseMsgType = 'warning';
        } else {
            $metodoPago = $_POST['metodo_pago'] ?? 'efectivo';
            if (!in_array($metodoPago, ['efectivo','transferencia','mixto'], true)) { $metodoPago = 'efectivo'; }

            $periodLabel = ($sel_quincena == 1 ? '1-15' : '16-fin') . ' de ' . $mesesNombres[$sel_month] . ' ' . $sel_year;
            $descBase = "Nomina profesores - Quincena $periodLabel";
            $cat = 'Nómina';
            $now = date('Y-m-d H:i:s');
            $lastExpenseId = null;
            $huboError = false;

            if ($metodoPago === 'mixto') {
                $montoEfectivo = round((float)($_POST['monto_efectivo'] ?? 0), 2);
                $montoTransferencia = round((float)($_POST['monto_transferencia'] ?? 0), 2);

                if (abs(($montoEfectivo + $montoTransferencia) - $totalToExpense) > 1) {
                    $expenseMsg = "Los montos de efectivo (" . number_format($montoEfectivo,0,',','.') . ") y transferencia (" . number_format($montoTransferencia,0,',','.') . ") no suman el total (" . number_format($totalToExpense,0,',','.') . "). No se emitio el gasto.";
                    $expenseMsgType = 'warning';
                    $huboError = true;
                } else {
                    if ($montoEfectivo > 0) {
                        $descEf = $descBase . " (Efectivo)";
                        $mEf = 'efectivo';
                        $st1 = $conn->prepare("INSERT INTO expenses (description, category, amount, expense_date, created_by, payment_method) VALUES (?, ?, ?, ?, ?, ?)");
                        $st1->bind_param("ssdsis", $descEf, $cat, $montoEfectivo, $eEnd, $userid, $mEf);
                        $st1->execute();
                        $lastExpenseId = $st1->insert_id;
                        $st1->close();
                    }
                    if ($montoTransferencia > 0) {
                        $descTr = $descBase . " (Transferencia)";
                        $mTr = 'transferencia';
                        $st2 = $conn->prepare("INSERT INTO expenses (description, category, amount, expense_date, created_by, payment_method) VALUES (?, ?, ?, ?, ?, ?)");
                        $st2->bind_param("ssdsis", $descTr, $cat, $montoTransferencia, $eEnd, $userid, $mTr);
                        $st2->execute();
                        $lastExpenseId = $st2->insert_id;
                        $st2->close();
                    }
                    $expenseMsg = "Gasto emitido (mixto): " . number_format($montoEfectivo,0,',','.') . " efectivo + " . number_format($montoTransferencia,0,',','.') . " transferencia = " . number_format($totalToExpense,0,',','.') . " $currency, registrado en Gastos (categoria Nomina).";
                    $expenseMsgType = 'success';
                }
            } else {
                $stmtE = $conn->prepare("INSERT INTO expenses (description, category, amount, expense_date, created_by, payment_method) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtE->bind_param("ssdsis", $descBase, $cat, $totalToExpense, $eEnd, $userid, $metodoPago);
                $stmtE->execute();
                $lastExpenseId = $stmtE->insert_id;
                $stmtE->close();

                $expenseMsg = "Gasto emitido: " . number_format($totalToExpense,0,',','.') . " $currency (" . $metodoPago . ") registrado en Gastos (categoria Nomina).";
                $expenseMsgType = 'success';
            }

            if (!$huboError) {
                $stmtLog = $conn->prepare("INSERT INTO payroll_expense_log (year, month, quincena, expense_id, amount, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->bind_param("iiiidis", $sel_year, $sel_month, $sel_quincena, $lastExpenseId, $totalToExpense, $userid, $now);
                $stmtLog->execute();
                $stmtLog->close();
            }
        }
    }
}

$trainers = [];
$rt = $conn->query("SELECT id, name, hourly_wage FROM trainers ORDER BY name ASC");
if ($rt) { while ($row = $rt->fetch_assoc()) { $trainers[] = $row; } }

$trainerInfo = null;
$existingShifts = [];
$totalHoras = 0;
$totalPagar = 0;
$dayRows = [];

if ($sel_trainer > 0) {
    $rti = $conn->query("SELECT id, name, hourly_wage FROM trainers WHERE id = " . (int)$sel_trainer);
    $trainerInfo = $rti ? $rti->fetch_assoc() : null;

    [$startDate, $endDate] = quincena_range($sel_year, $sel_month, $sel_quincena);
    $rh = $conn->prepare("SELECT work_date, time_in, time_out, hours_worked FROM payroll_hours WHERE trainer_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date ASC, time_in ASC");
    $rh->bind_param("iss", $sel_trainer, $startDate, $endDate);
    $rh->execute();
    $res = $rh->get_result();
    while ($row = $res->fetch_assoc()) {
        $existingShifts[$row['work_date']][] = ['time_in' => $row['time_in'], 'time_out' => $row['time_out']];
        $totalHoras += (float) $row['hours_worked'];
    }
    $rh->close();

    if ($trainerInfo && $trainerInfo['hourly_wage'] !== null) {
        $totalPagar = $totalHoras * (float) $trainerInfo['hourly_wage'];
    }

    $cursor = new DateTime($startDate);
    $endDt = new DateTime($endDate);
    while ($cursor <= $endDt) {
        $d = $cursor->format('Y-m-d');
        $dayRows[] = [
            'date' => $d,
            'label' => $diasSemana[$cursor->format('l')] . ' ' . $cursor->format('d'),
            'shifts' => $existingShifts[$d] ?? [],
        ];
        $cursor->modify('+1 day');
    }
}

$yaExisten = $totalHoras > 0;

[$rStart, $rEnd] = quincena_range($sel_year, $sel_month, $sel_quincena);
$resumen = [];
$rr = $conn->prepare("SELECT t.id, t.name, t.hourly_wage, COALESCE(SUM(ph.hours_worked),0) as total_horas
    FROM trainers t
    LEFT JOIN payroll_hours ph ON ph.trainer_id = t.id AND ph.work_date BETWEEN ? AND ?
    GROUP BY t.id, t.name, t.hourly_wage
    HAVING total_horas > 0
    ORDER BY t.name ASC");
$rr->bind_param("ss", $rStart, $rEnd);
$rr->execute();
$rres = $rr->get_result();
$grandTotal = 0;
while ($row = $rres->fetch_assoc()) {
    $row['total_pagar'] = $row['hourly_wage'] !== null ? $row['total_horas'] * $row['hourly_wage'] : null;
    if ($row['total_pagar'] !== null) $grandTotal += $row['total_pagar'];
    $resumen[] = $row;
}
$rr->close();

$chkEmitido = $conn->prepare("SELECT amount, created_at FROM payroll_expense_log WHERE year=? AND month=? AND quincena=?");
$chkEmitido->bind_param("iii", $sel_year, $sel_month, $sel_quincena);
$chkEmitido->execute();
$emitidoInfo = $chkEmitido->get_result()->fetch_assoc();
$chkEmitido->close();

$periodLabelDisplay = ($sel_quincena == 1 ? '1 - 15' : '16 - fin') . ' de ' . $mesesNombres[$sel_month] . ' ' . $sel_year;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nomina de profesores</title>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../../../assets/css/dashboard.css">
<link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
<style>
  .horas-cell { font-weight: 700; text-align: center; }
  .sched-btn { padding: 2px 7px; font-size: 14px; line-height: 1; }
  .shifts-wrap { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 4px; }
  .shift-row { display: flex; align-items: center; gap: 6px; margin-bottom: 0; }
  .shift-row .time-in, .shift-row .time-out { width: 110px; }
  .btn-remove-shift { padding: 2px 8px; }
  .btn-add-shift { padding-left: 0; font-size: 13px; }
  #clipIndicator {
    display: none; align-items: center; justify-content: space-between; gap: 10px;
    background: #fff3cd; border: 1px solid #ffe69c; border-radius: 10px;
    padding: 10px 14px; margin-bottom: 14px; font-size: 14px; font-weight: 600; color: #664d03;
  }
  .resumen-box { border: 2px solid #e53935; border-radius: 14px; padding: 18px 20px; margin-top: 20px; background: #fffaf9; }
  .resumen-box h3 { margin-top: 0; }
  .emitido-banner { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px 16px; border-radius: 10px; margin-top: 14px; }
  .metodo-pago label.radio-inline { font-weight: normal; margin-right: 18px; }
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
                <li><a href="../../dashboard"><i class="bi bi-speedometer"></i> Inicio</a></li>
                <li><a href="../../users"><i class="bi bi-people"></i> Miembros</a></li>
                <li><a href="../../statistics"><i class="bi bi-bar-chart"></i> Estadisticas</a></li>
                <li><a href="../../boss/sell"><i class="bi bi-shop"></i> Venta</a></li>
                <li><a href="../../invoices"><i class="bi bi-receipt"></i> Facturas</a></li>
                <li><a href="../finance"><i class="bi bi-cash-stack"></i> Reportes financieros</a></li>
                <li><a href="../expenses"><i class="bi bi-wallet2"></i> Gastos</a></li>
                <li class="active"><a href="#"><i class="bi bi-clipboard-data"></i> Nomina</a></li>
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
                    <a class="sidebar-link" href="../../dashboard/"><i class="bi bi-speedometer"></i> Inicio</a>
                </li>
                <li class="sidebar-item">
                    <a class="sidebar-link" href="../../users"><i class="bi bi-people"></i> Miembros</a>
                </li>
                <li class="sidebar-item">
                    <a class="sidebar-link" href="../../statistics"><i class="bi bi-bar-chart"></i> Estadisticas</a>
                </li>
                <li class="sidebar-item">
                    <a class="sidebar-link" href="../../boss/sell"><i class="bi bi-shop"></i> Venta</a>
                </li>
                <li class="sidebar-item">
                    <a href="../../invoices/" class="sidebar-link"><i class="bi bi-receipt"></i> Facturas</a>
                </li>
                <li class="sidebar-header">Finanzas</li>
                <li class="sidebar-item">
                    <a class="sidebar-link" href="../finance"><i class="bi bi-cash-stack"></i> <span>Reportes financieros</span></a>
                </li>
                <li class="sidebar-item">
                    <a class="sidebar-link" href="../expenses"><i class="bi bi-wallet2"></i> <span>Gastos</span></a>
                </li>
                <li class="sidebar-item active">
                    <a class="sidebar-link" href="#"><i class="bi bi-clipboard-data"></i> <span>Nomina</span></a>
                </li>
            </ul><br>
        </div>
        <br>
        <div class="col-sm-10">
            <h3 class="mb-3"><i class="bi bi-clipboard-data"></i> Nomina de profesores</h3>

  <form method="GET" id="filtroForm" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;">
    <div>
      <label>Profesor (para cargar horas)</label>
      <select name="trainer_id" class="form-control" onchange="document.getElementById('filtroForm').submit()">
        <option value="0">-- Selecciona --</option>
        <?php foreach ($trainers as $t): ?>
          <option value="<?php echo (int)$t['id']; ?>" <?php echo $sel_trainer == $t['id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($t['name']); ?><?php echo $t['hourly_wage'] === null ? ' (sin tarifa)' : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Ano</label>
      <input type="number" name="year" class="form-control" value="<?php echo $sel_year; ?>" style="width:100px;" onchange="document.getElementById('filtroForm').submit()">
    </div>
    <div>
      <label>Mes</label>
      <select name="month" class="form-control" onchange="document.getElementById('filtroForm').submit()">
        <?php foreach ($mesesNombres as $i => $m): ?>
          <option value="<?php echo $i; ?>" <?php echo $sel_month == $i ? 'selected' : ''; ?>><?php echo $m; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Quincena</label>
      <select name="quincena" class="form-control" onchange="document.getElementById('filtroForm').submit()">
        <option value="1" <?php echo $sel_quincena == 1 ? 'selected' : ''; ?>>1 - 15</option>
        <option value="2" <?php echo $sel_quincena == 2 ? 'selected' : ''; ?>>16 - fin de mes</option>
      </select>
    </div>
  </form>

  <?php if ($sel_trainer > 0 && $trainerInfo): ?>
  <div id="clipIndicator">
    <span class="clip-text"></span>
    <button type="button" class="btn btn-xs btn-default" id="clipCancelBtn">Cancelar</button>
  </div>

  <form method="POST">
    <input type="hidden" name="trainer_id" value="<?php echo $sel_trainer; ?>">
    <input type="hidden" name="year" value="<?php echo $sel_year; ?>">
    <input type="hidden" name="month" value="<?php echo $sel_month; ?>">
    <input type="hidden" name="quincena" value="<?php echo $sel_quincena; ?>">

    <h4><?php echo htmlspecialchars($trainerInfo['name']); ?> &mdash; Tarifa: <?php echo $trainerInfo['hourly_wage'] !== null ? number_format($trainerInfo['hourly_wage'],0,',','.') . ' ' . $currency . '/hora' : 'SIN TARIFA ASIGNADA'; ?></h4>

    <div class="table-responsive">
    <table class="table table-bordered" id="tablaHoras">
      <thead><tr><th style="width:110px;">Fecha</th><th>Horario(s)</th><th style="width:90px;">Horas</th><th style="width:110px;">Copiar/Pegar</th></tr></thead>
      <tbody>
        <?php foreach ($dayRows as $row): ?>
        <tr data-date="<?php echo $row['date']; ?>">
          <td><?php echo $row['label']; ?></td>
          <td>
            <div class="shifts-wrap" data-date="<?php echo $row['date']; ?>">
              <?php if (empty($row['shifts'])): ?>
              <div class="shift-row">
                <input type="time" class="form-control time-in" name="time_in[<?php echo $row['date']; ?>][]" value="">
                <span>&ndash;</span>
                <input type="time" class="form-control time-out" name="time_out[<?php echo $row['date']; ?>][]" value="">
                <button type="button" class="btn btn-xs btn-default btn-remove-shift">&times;</button>
              </div>
              <?php else: foreach ($row['shifts'] as $shift): ?>
              <div class="shift-row">
                <input type="time" class="form-control time-in" name="time_in[<?php echo $row['date']; ?>][]" value="<?php echo htmlspecialchars($shift['time_in']); ?>">
                <span>&ndash;</span>
                <input type="time" class="form-control time-out" name="time_out[<?php echo $row['date']; ?>][]" value="<?php echo htmlspecialchars($shift['time_out']); ?>">
                <button type="button" class="btn btn-xs btn-default btn-remove-shift">&times;</button>
              </div>
              <?php endforeach; endif; ?>
            </div>
            <button type="button" class="btn btn-xs btn-link btn-add-shift">+ Agregar turno (partido)</button>
          </td>
          <td class="horas-cell">&mdash;</td>
          <td style="white-space:nowrap;">
            <button type="button" class="btn btn-xs btn-default sched-btn btn-copy-sched" title="Copiar este dia completo">&#128203; Copiar</button>
            <button type="button" class="btn btn-xs btn-default sched-btn btn-paste-sched" title="Pegar en este dia">&#128204; Pegar</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="2" style="text-align:right;">Total horas:</th><th id="totalHorasCell"><?php echo number_format($totalHoras,2); ?></th><th></th></tr>
        <tr><th colspan="2" style="text-align:right;">Total a pagar:</th><th id="totalPagarCell"><?php echo number_format($totalPagar,0,',','.'); ?> <?php echo $currency; ?></th><th></th></tr>
      </tfoot>
    </table>
    </div>

    <button type="submit" name="guardar" class="btn btn-danger"><?php echo $yaExisten ? 'Modificar horas' : 'Guardar horas'; ?></button>
    <?php if ($yaExisten): ?>
      <button type="button" class="btn btn-default" data-toggle="modal" data-target="#verHorasModal"><i class="bi bi-eye"></i> Ver</button>
    <?php endif; ?>
    <?php if ($saveMsg): ?><span class="alert alert-success" style="margin-left:10px;padding:6px 12px;"><?php echo $saveMsg; ?></span><?php endif; ?>
  </form>
  <?php endif; ?>

  <div class="modal fade" id="verHorasModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal">&times;</button>
          <h4 class="modal-title">Horas registradas <?php echo isset($trainerInfo['name']) ? '&mdash; ' . htmlspecialchars($trainerInfo['name']) : ''; ?></h4>
          <small><?php echo $periodLabelDisplay; ?></small>
        </div>
        <div class="modal-body">
          <table class="table table-condensed">
            <thead><tr><th>Fecha</th><th>Turnos</th><th>Horas</th></tr></thead>
            <tbody>
              <?php foreach ($dayRows as $row): if (empty($row['shifts'])) continue; ?>
                <tr>
                  <td><?php echo $row['label']; ?></td>
                  <td><?php
                    $partes = [];
                    foreach ($row['shifts'] as $s) { $partes[] = substr($s['time_in'],0,5) . '-' . substr($s['time_out'],0,5); }
                    echo htmlspecialchars(implode(', ', $partes));
                  ?></td>
                  <td>
                    <?php
                    $hd = 0;
                    foreach ($row['shifts'] as $s) {
                        $inMin = (int)substr($s['time_in'],0,2)*60 + (int)substr($s['time_in'],3,2);
                        $outMin = (int)substr($s['time_out'],0,2)*60 + (int)substr($s['time_out'],3,2);
                        if ($outMin <= $inMin) $outMin += 24*60;
                        $hd += ($outMin - $inMin) / 60;
                    }
                    echo number_format($hd, 2);
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr><th colspan="2" style="text-align:right;">Total horas:</th><th><?php echo number_format($totalHoras,2); ?></th></tr>
              <tr><th colspan="2" style="text-align:right;">Total a pagar:</th><th><?php echo number_format($totalPagar,0,',','.'); ?> <?php echo $currency; ?></th></tr>
            </tfoot>
          </table>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <?php if ($sel_trainer > 0 && $trainerInfo): ?>
  <script>
  (function(){
    var wage = <?php echo json_encode($trainerInfo['hourly_wage'] !== null ? (float)$trainerInfo['hourly_wage'] : 0); ?>;
    var table = document.getElementById('tablaHoras');
    var clipboard = null;
    var clipIndicator = document.getElementById('clipIndicator');
    var clipText = clipIndicator.querySelector('.clip-text');

    function shiftHours(tin, tout) {
      if (!tin || !tout) return 0;
      var inMin = parseInt(tin.split(':')[0],10)*60 + parseInt(tin.split(':')[1],10);
      var outMin = parseInt(tout.split(':')[0],10)*60 + parseInt(tout.split(':')[1],10);
      if (outMin <= inMin) outMin += 24*60;
      return (outMin - inMin) / 60;
    }

    function calcDay(tr) {
      var wrap = tr.querySelector('.shifts-wrap');
      var total = 0;
      wrap.querySelectorAll('.shift-row').forEach(function(sr){
        total += shiftHours(sr.querySelector('.time-in').value, sr.querySelector('.time-out').value);
      });
      tr.querySelector('.horas-cell').textContent = total > 0 ? total.toFixed(2) : '\u2014';
      return total;
    }

    function recalcTotal() {
      var total = 0;
      table.querySelectorAll('tbody tr').forEach(function(tr){ total += calcDay(tr); });
      document.getElementById('totalHorasCell').textContent = total.toFixed(2);
      document.getElementById('totalPagarCell').textContent = new Intl.NumberFormat('es-CO').format(Math.round(total * wage)) + ' <?php echo $currency; ?>';
    }

    function addShiftRow(wrap, inVal, outVal) {
      var div = document.createElement('div');
      div.className = 'shift-row';
      div.innerHTML =
        '<input type="time" class="form-control time-in" name="time_in[' + wrap.dataset.date + '][]" value="' + (inVal||'') + '">' +
        '<span>&ndash;</span>' +
        '<input type="time" class="form-control time-out" name="time_out[' + wrap.dataset.date + '][]" value="' + (outVal||'') + '">' +
        '<button type="button" class="btn btn-xs btn-default btn-remove-shift">&times;</button>';
      wrap.appendChild(div);
    }

    function updateIndicator() {
      if (clipboard && clipboard.length) {
        clipIndicator.style.display = 'flex';
        var partes = clipboard.map(function(s){ return s.in + '-' + s.out; }).join(', ');
        clipText.textContent = 'Copiado: ' + partes + '  (clic en "Pegar" en los dias donde se repite)';
      } else {
        clipIndicator.style.display = 'none';
      }
    }

    table.addEventListener('click', function(e){
      var tr = e.target.closest('tr');
      if (!tr) return;
      var wrap = tr.querySelector('.shifts-wrap');

      if (e.target.closest('.btn-add-shift')) {
        addShiftRow(wrap, '', '');
        calcDay(tr); recalcTotal();
      } else if (e.target.closest('.btn-remove-shift')) {
        e.target.closest('.shift-row').remove();
        calcDay(tr); recalcTotal();
      } else if (e.target.closest('.btn-copy-sched')) {
        clipboard = [];
        wrap.querySelectorAll('.shift-row').forEach(function(sr){
          var tin = sr.querySelector('.time-in').value;
          var tout = sr.querySelector('.time-out').value;
          if (tin && tout) clipboard.push({in: tin, out: tout});
        });
        table.querySelectorAll('tbody tr').forEach(function(r){ r.style.background = ''; });
        tr.style.background = '#fff3cd';
        updateIndicator();
      } else if (e.target.closest('.btn-paste-sched')) {
        if (!clipboard || !clipboard.length) return;
        wrap.innerHTML = '';
        clipboard.forEach(function(s){ addShiftRow(wrap, s.in, s.out); });
        calcDay(tr); recalcTotal();
        tr.style.background = '#d4edda';
        setTimeout(function(){ tr.style.background = ''; }, 500);
      }
    });

    table.addEventListener('change', function(e){
      if (e.target.classList.contains('time-in') || e.target.classList.contains('time-out')) {
        calcDay(e.target.closest('tr')); recalcTotal();
      }
    });

    document.getElementById('clipCancelBtn').addEventListener('click', function(){
      clipboard = null;
      table.querySelectorAll('tbody tr').forEach(function(r){ r.style.background = ''; });
      updateIndicator();
    });

    recalcTotal();
  })();
  </script>
  <?php elseif ($sel_trainer > 0): ?>
    <div class="alert alert-warning">No se encontro ese profesor.</div>
  <?php else: ?>
    <div class="alert alert-info">Selecciona un profesor arriba para cargar sus horas de esta quincena.</div>
  <?php endif; ?>

  <div class="resumen-box">
    <h3><i class="bi bi-clipboard-data"></i> Nomina unificada &mdash; Quincena <?php echo $periodLabelDisplay; ?></h3>
    <?php if (empty($resumen)): ?>
      <p style="color:#888;">Sin horas registradas todavia para este periodo.</p>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr><th>Profesor</th><th>Horas</th><th>Tarifa</th><th>Total a pagar</th></tr></thead>
        <tbody>
          <?php foreach ($resumen as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><?php echo number_format($r['total_horas'],2); ?></td>
            <td><?php echo $r['hourly_wage'] !== null ? number_format($r['hourly_wage'],0,',','.') : '&mdash;'; ?></td>
            <td><?php echo $r['total_pagar'] !== null ? number_format($r['total_pagar'],0,',','.') . ' ' . $currency : 'SIN TARIFA'; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="3" style="text-align:right;">Total nomina quincena:</th><th><?php echo number_format($grandTotal,0,',','.'); ?> <?php echo $currency; ?></th></tr>
        </tfoot>
      </table>
      </div>

      <?php if ($emitidoInfo): ?>
        <div class="emitido-banner">
          <i class="bi bi-check-circle-fill"></i> Gasto de nomina ya emitido para este periodo: <strong><?php echo number_format($emitidoInfo['amount'],0,',','.'); ?> <?php echo $currency; ?></strong>
          (el <?php echo htmlspecialchars($emitidoInfo['created_at']); ?>). Revisalo en tu pantalla de Gastos, categoria "Nomina".
        </div>
      <?php elseif ($is_boss == 1): ?>
        <form method="POST" id="formEmitirGasto" style="margin-top:14px;">
          <input type="hidden" name="year" value="<?php echo $sel_year; ?>">
          <input type="hidden" name="month" value="<?php echo $sel_month; ?>">
          <input type="hidden" name="quincena" value="<?php echo $sel_quincena; ?>">
          <input type="hidden" name="trainer_id" value="<?php echo $sel_trainer; ?>">

          <div class="metodo-pago" style="margin-bottom:10px;">
            <label style="display:block;margin-bottom:6px;">Metodo de pago</label>
            <label class="radio-inline"><input type="radio" name="metodo_pago" value="efectivo" checked> Efectivo</label>
            <label class="radio-inline"><input type="radio" name="metodo_pago" value="transferencia"> Transferencia</label>
            <label class="radio-inline"><input type="radio" name="metodo_pago" value="mixto"> Mixto</label>
          </div>

          <div id="mixtoFields" style="display:none;margin-bottom:12px;">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
              <div>
                <label>Efectivo</label>
                <input type="number" step="1" min="0" name="monto_efectivo" id="montoEfectivo" class="form-control" style="width:160px;" placeholder="0">
              </div>
              <div>
                <label>Transferencia</label>
                <input type="number" step="1" min="0" name="monto_transferencia" id="montoTransferencia" class="form-control" style="width:160px;" placeholder="0">
              </div>
              <div style="align-self:center;color:#888;font-size:13px;">
                Debe sumar: <strong><?php echo number_format($grandTotal,0,',','.'); ?></strong> <?php echo $currency; ?>
              </div>
            </div>
          </div>

          <button type="submit" name="emitir_gasto" class="btn btn-warning" id="btnEmitirGasto"><i class="bi bi-cash-coin"></i> Emitir gasto (<?php echo number_format($grandTotal,0,',','.'); ?> <?php echo $currency; ?>)</button>
        </form>
        <script>
        (function(){
          var total = <?php echo (float) $grandTotal; ?>;
          var radios = document.querySelectorAll('input[name="metodo_pago"]');
          var mixtoDiv = document.getElementById('mixtoFields');
          function toggleMixto() {
            var sel = document.querySelector('input[name="metodo_pago"]:checked');
            mixtoDiv.style.display = (sel && sel.value === 'mixto') ? 'block' : 'none';
          }
          radios.forEach(function(r){ r.addEventListener('change', toggleMixto); });
          toggleMixto();

          document.getElementById('formEmitirGasto').addEventListener('submit', function(e){
            var sel = document.querySelector('input[name="metodo_pago"]:checked');
            if (sel && sel.value === 'mixto') {
              var ef = parseFloat(document.getElementById('montoEfectivo').value) || 0;
              var tr = parseFloat(document.getElementById('montoTransferencia').value) || 0;
              if (Math.abs((ef + tr) - total) > 1) {
                alert('El efectivo y la transferencia deben sumar exactamente el total: ' + new Intl.NumberFormat('es-CO').format(total) + ' <?php echo $currency; ?>');
                e.preventDefault();
                return false;
              }
            }
            if (!confirm('Se registrara un gasto de ' + new Intl.NumberFormat('es-CO').format(total) + ' <?php echo $currency; ?> en la categoria Nomina. Confirmas?')) {
              e.preventDefault();
              return false;
            }
          });
        })();
        </script>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($expenseMsg): ?>
      <div class="alert alert-<?php echo $expenseMsgType; ?>" style="margin-top:12px;"><?php echo htmlspecialchars($expenseMsg); ?></div>
    <?php endif; ?>
  </div>

        </div>
    </div>
</div>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>
</html>
