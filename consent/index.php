<?php
function bc_read_env($p) {
    $d = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($p)) as $l) {
        if (trim($l) === '' || strpos(ltrim($l), '#') === 0) continue;
        $parts = explode('=', $l, 2);
        if (count($parts) === 2) $d[trim($parts[0])] = trim($parts[1]);
    }
    return $d;
}
$env = bc_read_env(__DIR__ . '/../.env');
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) { die("Error de conexion"); }
$conn->set_charset('utf8mb4');

$business_name = $env['BUSINESS_NAME'] ?? 'el gimnasio';
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$state = 'invalid';
$clientName = '';

if ($token !== '') {
    $stmt = $conn->prepare("SELECT bc.userid, bc.confirmed_at, u.firstname, u.lastname FROM biometric_consents bc JOIN users u ON u.userid = bc.userid WHERE bc.token = ? ORDER BY bc.id DESC LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $clientName = trim($row['firstname'] . ' ' . $row['lastname']);
        $state = !empty($row['confirmed_at']) ? 'already' : 'pending';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $state === 'pending' && isset($_POST['confirmar'])) {
            $upd = $conn->prepare("UPDATE biometric_consents SET confirmed_at = NOW() WHERE token = ?");
            $upd->bind_param("s", $token);
            $upd->execute();
            $upd->close();

            require_once __DIR__ . '/../iclock/lib/enroll.php';
            $enrollResult = @enrolar_en_speedface((int)$row['userid']);
            @file_put_contents(__DIR__ . '/../iclock/enroll_consent.log',
                date('Y-m-d H:i:s') . " userid={$row['userid']} ok=" . (!empty($enrollResult['ok']) ? '1' : '0') . ' ' . json_encode($enrollResult, JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND);

            $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES (" . (int)$row['userid'] . ", 'Autorizacion de datos biometricos confirmada por el titular', 'success', NOW())");
            $state = 'done';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Autorizacion de acceso - <?php echo htmlspecialchars($business_name); ?></title>
<style>
  body { font-family: 'Segoe UI', Tahoma, sans-serif; background:#f4f6fb; margin:0; padding:24px; }
  .card { max-width:480px; margin:40px auto; background:#fff; border-radius:18px; box-shadow:0 8px 30px rgba(15,23,42,.08); padding:32px 28px; text-align:center; }
  .card img { max-width:130px; margin-bottom:16px; }
  h1 { font-size:1.35rem; color:#0f172a; margin-bottom:12px; }
  p { color:#475569; font-size:.96rem; line-height:1.5; }
  .btn { display:inline-block; background:#e53935; color:#fff; text-decoration:none; padding:14px 30px; border-radius:10px; font-weight:700; border:none; font-size:1rem; cursor:pointer; margin-top:18px; }
  .ok { color:#15803d; font-weight:700; }
  .box { background:#f8f9fa; border-radius:10px; padding:16px; margin:18px 0; font-size:.88rem; color:#555; text-align:left; }
</style>
</head>
<body>
  <div class="card">
    <img src="../assets/img/brand/logo.png" alt="">
    <?php if ($state === 'invalid'): ?>
      <h1>Enlace no valido</h1>
      <p>Este enlace de autorizacion no es valido. Comunicate con el gimnasio para que te generen uno nuevo.</p>
    <?php elseif ($state === 'already'): ?>
      <h1 class="ok">Ya habias autorizado esto</h1>
      <p>Gracias <?php echo htmlspecialchars($clientName); ?>, tu autorizacion ya estaba confirmada.</p>
    <?php elseif ($state === 'done'): ?>
      <h1 class="ok">Listo, gracias</h1>
      <p>Tu autorizacion quedo registrada. Tu acceso facial ya deberia estar activo en el torniquete.</p>
    <?php else: ?>
      <h1>Hola <?php echo htmlspecialchars($clientName); ?></h1>
      <p>Para usar el reconocimiento facial en la entrada de <?php echo htmlspecialchars($business_name); ?>, necesitamos tu autorizacion.</p>
      <div class="box">
        Autorizo el tratamiento de mi fotografia con fines de verificacion biometrica de acceso (reconocimiento facial en el torniquete), conforme a la Politica de Tratamiento de Datos de <?php echo htmlspecialchars($business_name); ?>. Este dato se conserva mientras sea socio activo. Puedo conocer, actualizar, rectificar o solicitar su eliminacion escribiendo al gimnasio.
      </div>
      <form method="POST">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <button type="submit" name="confirmar" class="btn">Autorizo</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
