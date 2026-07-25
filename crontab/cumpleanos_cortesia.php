<?php
header('Content-Type: text/plain; charset=utf-8');

function read_env_file($p) {
    $d = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) @file_get_contents($p)) as $l) {
        if (trim($l) === '' || strpos(ltrim($l), '#') === 0) continue;
        $parts = explode('=', $l, 2);
        if (count($parts) === 2) $d[trim($parts[0])] = trim($parts[1]);
    }
    return $d;
}
$env = read_env_file('/app/.env');

$providedKey = $_GET['key'] ?? '';
$expectedKey = $env['CRON_SECRET_KEY'] ?? '';
if (empty($expectedKey) || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(401);
    echo "No autorizado\n";
    exit();
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) { die("Error de conexion: " . $conn->connect_error . "\n"); }
$conn->set_charset('utf8mb4');

require_once '/app/includes/future_plans.php';
require_once '/app/includes/meta_whatsapp.php';

$conn->query("CREATE TABLE IF NOT EXISTS birthday_cortesia_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid BIGINT NOT NULL,
    year INT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_year (userid, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$hoyMes = date('m');
$hoyDia = date('d');
$anioActual = (int) date('Y');

$stmt = $conn->prepare("SELECT userid, lastname, email, celular FROM users WHERE MONTH(birthdate) = ? AND DAY(birthdate) = ? AND celular IS NOT NULL AND celular != ''");
$stmt->bind_param('ss', $hoyMes, $hoyDia);
$stmt->execute();
$cumpleanieros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$procesados = 0; $yaHechos = 0; $errores = 0;

foreach ($cumpleanieros as $u) {
    $uid = (int) $u['userid'];

    $chk = $conn->prepare("SELECT id FROM birthday_cortesia_log WHERE userid = ? AND year = ?");
    $chk->bind_param('ii', $uid, $anioActual);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) { $chk->close(); $yaHechos++; continue; }
    $chk->close();

    $res = add_plan($conn, $uid, 'Cortesia cumpleanos - 1 semana', 7, null, null);
    $accS = ($res['type'] === 'active') ? 'activada ahora' : ('encolada, inicia ' . ($res['start_date'] ?? '?'));
    $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES (" . $uid . ", 'Cortesia de cumpleanos otorgada: 1 semana (" . $accS . ")', 'success', NOW())");

    if ($res['type'] === 'active') {
        require_once '/app/iclock/lib/endtime.php';
        @sincronizar_acceso_speedface($uid);
    }

    $ins = $conn->prepare("INSERT INTO birthday_cortesia_log (userid, year, created_at) VALUES (?, ?, NOW())");
    $ins->bind_param('ii', $uid, $anioActual);
    $ins->execute();
    $ins->close();

    $envio = enviar_plantilla_whatsapp_meta($u['celular'], 'feliz_cumpleanos', 'es_CO', [], [$u['lastname']]);
    if ($envio['ok']) { $procesados++; } else { $errores++; }

    if (!empty($u['email']) && strpos($u['email'], '@') !== false && !empty($env['MAIL_HOST'])) {
        require_once '/app/includes/mailer.php';
        require_once '/app/includes/email_templates.php';

        $filasEmail = ['Regalo' => 'Semana de entrenamiento gratis'];
        if ($res['type'] === 'active') {
            $filasEmail['Vigente hasta'] = date('d/m/Y', strtotime($res['end_date']));
            $subEmail = 'Tu semana de cortesia ya esta activa. Nos vemos en el gym!';
        } else {
            $filasEmail['Inicia aprox.'] = $res['start_date'] ? date('d/m/Y', strtotime($res['start_date'])) : 'Al vencer tu plan actual';
            $subEmail = 'Tu semana de cortesia se activara automaticamente cuando termine tu plan actual.';
        }

        $bodyEmail = adrenaline_email(
            'FELIZ CUMPLEANOS',
            'Hola, ' . htmlspecialchars($u['lastname']) . '!',
            'Para celebrar tu cumpleanos, te regalamos una semana de entrenamiento gratis en Adrenaline Gym. ' . $subEmail,
            $filasEmail
        );
        @send_mail($env, $u['email'], 'Feliz cumpleanos - te regalamos una semana de entrenamiento', $bodyEmail, $env['BUSINESS_NAME'] ?? 'Adrenaline Gym', true);
    }

    usleep(300000);
}

$resumen = date('Y-m-d H:i:s') . " Cumpleanos: " . count($cumpleanieros) . " encontrados, $procesados procesados, $yaHechos ya hechos antes, $errores errores de envio\n";
@file_put_contents('/app/iclock/cumpleanos_cortesia.log', $resumen, FILE_APPEND);
echo $resumen;
$conn->close();
