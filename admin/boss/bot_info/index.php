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
$business_name = $env_data['BUSINESS_NAME'] ?? '';
$version = $env_data['APP_VERSION'] ?? '';

mysqli_report(MYSQLI_REPORT_OFF);
$saveMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_categoria'])) {
    $id = (int) $_POST['id'];
    $contenido = trim($_POST['contenido'] ?? '');
    $stmt = $conn->prepare("UPDATE bot_info SET contenido = ? WHERE id = ?");
    $stmt->bind_param("si", $contenido, $id);
    $stmt->execute();
    $stmt->close();
    $saveMsg = 'Categoria actualizada.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_categoria'])) {
    $nombreNueva = trim($_POST['nombre_categoria'] ?? '');
    if ($nombreNueva !== '') {
        $maxOrden = $conn->query("SELECT COALESCE(MAX(orden),0)+1 AS n FROM bot_info")->fetch_assoc()['n'];
        $stmt = $conn->prepare("INSERT INTO bot_info (categoria, contenido, orden) VALUES (?, '', ?)");
        $stmt->bind_param("si", $nombreNueva, $maxOrden);
        $stmt->execute();
        $stmt->close();
        $saveMsg = 'Categoria "' . htmlspecialchars($nombreNueva) . '" agregada.';
    }
}

if (isset($_GET['borrar']) && is_numeric($_GET['borrar'])) {
    $idBorrar = (int) $_GET['borrar'];
    $conn->query("DELETE FROM bot_info WHERE id = $idBorrar");
    header('Location: ./');
    exit();
}

$categorias = [];
$r = $conn->query("SELECT id, categoria, contenido FROM bot_info ORDER BY orden ASC");
while ($row = $r->fetch_assoc()) { $categorias[] = $row; }
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Informacion del Bot</title>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../../../assets/css/dashboard.css">
<link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
<style>
  .categoria-card { border: 2px solid #e5e7eb; border-radius: 14px; padding: 18px 20px; margin-bottom: 18px; background: #fff; }
  .categoria-card h4 { margin-top: 0; color: #e53935; }
  .categoria-card textarea { width: 100%; min-height: 110px; border-radius: 8px; border: 1px solid #d4d4d8; padding: 10px; font-size: 14px; }
  .btn-borrar-cat { float: right; color: #b91c1c; font-size: 13px; }
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
                <li class="active"><a href="#"><i class="bi bi-robot"></i> Info del Bot</a></li>
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
                <li class="sidebar-item">
                    <a class="sidebar-link" href="../payroll"><i class="bi bi-clipboard-data"></i> <span>Nomina</span></a>
                </li>
                <li class="sidebar-item active">
                    <a class="sidebar-link" href="#"><i class="bi bi-robot"></i> <span>Info del Bot</span></a>
                </li>
            </ul><br>
        </div>
        <br>
        <div class="col-sm-10">
            <h3 class="mb-3"><i class="bi bi-robot"></i> Informacion del Bot (AdrenaBot)</h3>
            <p style="color:#666;">Esta informacion es la que AdrenaBot usa para responder preguntas generales de los clientes (horarios, contacto, pagos, clases). Edita cada categoria y dale Guardar.</p>

            <?php if ($saveMsg): ?>
                <div class="alert alert-success"><?php echo $saveMsg; ?></div>
            <?php endif; ?>

            <?php foreach ($categorias as $cat): ?>
                <div class="categoria-card">
                    <a href="?borrar=<?php echo (int)$cat['id']; ?>" class="btn-borrar-cat" onclick="return confirm('Borrar la categoria &quot;<?php echo htmlspecialchars($cat['categoria']); ?>&quot;? Esto no se puede deshacer.');"><i class="bi bi-trash"></i> Borrar categoria</a>
                    <h4><?php echo htmlspecialchars($cat['categoria']); ?></h4>
                    <form method="POST">
                        <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                        <textarea name="contenido" placeholder="Escribe aqui la informacion de esta categoria..."><?php echo htmlspecialchars($cat['contenido']); ?></textarea>
                        <button type="submit" name="guardar_categoria" class="btn btn-primary" style="margin-top:10px;"><i class="bi bi-save"></i> Guardar</button>
                    </form>
                </div>
            <?php endforeach; ?>

            <div class="categoria-card" style="border-style:dashed;">
                <h4><i class="bi bi-plus-circle"></i> Agregar nueva categoria</h4>
                <form method="POST" style="display:flex;gap:10px;align-items:center;">
                    <input type="text" name="nombre_categoria" class="form-control" placeholder="Ej: Preguntas frecuentes" style="max-width:300px;" required>
                    <button type="submit" name="agregar_categoria" class="btn btn-success"><i class="bi bi-plus"></i> Agregar</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>
</html>
