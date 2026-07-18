<?php
$tx_id = $_GET['id'] ?? '';
$status = 'pendiente';
$message = 'Verificando tu pago...';
$color = 'info';
$icon = 'bi-hourglass-split';

if ($tx_id) {
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
    $prv = $env['WOMPI_PRIVATE_KEY'];

    $ch = curl_init("https://production.wompi.co/v1/transactions/$tx_id");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $prv"]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $tx_status = $res['data']['status'] ?? '';
    if ($tx_status === 'APPROVED') {
        $status = 'aprobado';
        $message = '¡Pago exitoso! Tu plan ha sido activado.';
        $color = 'success';
        $icon = 'bi-check-circle';
    } elseif ($tx_status === 'DECLINED') {
        $status = 'rechazado';
        $message = 'Tu pago fue rechazado. Intenta de nuevo.';
        $color = 'danger';
        $icon = 'bi-x-circle';
    } elseif ($tx_status === 'VOIDED') {
        $status = 'cancelado';
        $message = 'El pago fue cancelado.';
        $color = 'warning';
        $icon = 'bi-slash-circle';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado del pago</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f5f5f5; }
        .result-card { max-width: 450px; margin: 80px auto; background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 2px 20px rgba(0,0,0,0.1); text-align: center; }
        .icon { font-size: 4em; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="result-card">
    <div class="icon text-<?php echo $color; ?>">
        <i class="bi <?php echo $icon; ?>"></i>
    </div>
    <h3><?php echo $message; ?></h3>
    <?php if ($status === 'aprobado'): ?>
        <p class="text-muted">Inicia sesión para ver tu plan activo.</p>
        <a href="../../login/" class="btn btn-success">Ir al inicio de sesión</a>
    <?php else: ?>
        <a href="../../prices/" class="btn btn-default">Ver planes</a>
    <?php endif; ?>
</div>
</body>
</html>
