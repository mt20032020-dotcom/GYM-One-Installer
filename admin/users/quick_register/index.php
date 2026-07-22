<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['adminuser'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Solo POST']);
    exit();
}

require_once '/app/includes/mailer.php';

function qr_read_env($p) {
    $d = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($p)) as $l) {
        if (trim($l) === '' || strpos(ltrim($l), '#') === 0) continue;
        $parts = explode('=', $l, 2);
        if (count($parts) === 2) $d[trim($parts[0])] = trim($parts[1]);
    }
    return $d;
}

$env = qr_read_env('/app/.env');
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) { echo json_encode(['ok'=>false,'error'=>'BD inaccesible']); exit(); }
$conn->set_charset('utf8mb4');

$cedula   = trim($_POST['cedula'] ?? '');
$firstname = trim($_POST['firstname'] ?? '');
$lastname  = trim($_POST['lastname'] ?? '');
$email     = trim($_POST['email'] ?? '');
$celular   = trim($_POST['celular'] ?? '');
$barrio    = trim($_POST['barrio'] ?? '');
$gender    = $_POST['gender'] ?? 'Other';
$birthdate = $_POST['birthdate'] ?? null;

if ($cedula === '' || $firstname === '' || $lastname === '' || $email === '') {
    echo json_encode(['ok'=>false,'error'=>'Faltan campos obligatorios (cedula, nombre, apellido, email)']);
    exit();
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false,'error'=>'Email invalido']); exit();
}
if (!preg_match('/^[0-9]+$/', $cedula)) {
    echo json_encode(['ok'=>false,'error'=>'La cedula solo debe tener numeros']); exit();
}

// Duplicados (cedula o email)
$chk = $conn->prepare("SELECT userid FROM users WHERE cedula = ? OR email = ?");
$chk->bind_param("ss", $cedula, $email);
$chk->execute();
if ($chk->get_result()->num_rows > 0) {
    echo json_encode(['ok'=>false,'error'=>'Ya existe un socio con esa cedula o ese email']);
    exit();
}
$chk->close();

// Foto obligatoria (igual que el registro normal)
if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'La foto de perfil es obligatoria']); exit();
}
$tmpPath = $_FILES['profile_photo']['tmp_name'];
if (!is_uploaded_file($tmpPath) || ($_FILES['profile_photo']['size'] ?? 0) > 8*1024*1024) {
    echo json_encode(['ok'=>false,'error'=>'Foto invalida o muy pesada (max 8MB)']); exit();
}
$info = @getimagesize($tmpPath);
if ($info === false) { echo json_encode(['ok'=>false,'error'=>'El archivo no es una imagen valida']); exit(); }

$userid = random_int(pow(10,9), pow(10,10)-1);
$hashed = password_hash($cedula, PASSWORD_DEFAULT); // clave inicial = cedula
$regDate = date('Y-m-d H:i:s');

$stmt = $conn->prepare("INSERT INTO users (userid, cedula, firstname, lastname, email, password, gender, birthdate, celular, city, registration_date, confirmed) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'Yes')");
$typesIns = "i" . str_repeat("s", 10);
$stmt->bind_param($typesIns, $userid, $cedula, $firstname, $lastname, $email, $hashed, $gender, $birthdate, $celular, $barrio, $regDate);
if (!$stmt->execute()) {
    echo json_encode(['ok'=>false,'error'=>'Error al crear usuario: ' . $stmt->error]); exit();
}
$stmt->close();

// Guardar foto de perfil (esto NO es enrolamiento biometrico todavia, es solo la foto de identificacion del socio)
$destDir = '/app/assets/img/profiles';
if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
$destPath = $destDir . '/' . $userid . '.png';
$mime = $info['mime'] ?? '';
$src = null;
switch ($mime) {
    case 'image/jpeg': $src = @imagecreatefromjpeg($tmpPath); break;
    case 'image/png':  $src = @imagecreatefrompng($tmpPath); break;
    case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false; break;
    case 'image/gif':  $src = @imagecreatefromgif($tmpPath); break;
}
$photoSaved = false;
if ($src) {
    $w = imagesx($src); $h = imagesy($src);
    $side = min($w,$h); $sx=(int)(($w-$side)/2); $sy=(int)(($h-$side)/2);
    $dst = imagecreatetruecolor(512,512);
    imagealphablending($dst,false); imagesavealpha($dst,true);
    $tr = imagecolorallocatealpha($dst,0,0,0,127);
    imagefilledrectangle($dst,0,0,512,512,$tr);
    imagecopyresampled($dst,$src,0,0,$sx,$sy,512,512,$side,$side);
    $photoSaved = imagepng($dst, $destPath);
    imagedestroy($src); imagedestroy($dst);
}

// ===== Autorizacion biometrica pendiente (NO se enrola aun) =====
$consentToken = bin2hex(random_bytes(24));
$stmtC = $conn->prepare("INSERT INTO biometric_consents (userid, token, requested_at) VALUES (?, ?, NOW())");
$stmtC->bind_param("is", $userid, $consentToken);
$stmtC->execute();
$stmtC->close();

$conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES (" . (int)$userid . ", 'Registro asistido: pendiente de autorizacion biometrica del titular', 'warning', NOW())");

// ===== Correo unico: acceso facial primero, luego clave inicial =====
$domain_url = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$businessName = $env['BUSINESS_NAME'] ?? '';
$consentUrl = $domain_url . '/consent/?token=' . urlencode($consentToken);

$emailHtml = "
<div style='font-family:Segoe UI,Tahoma,sans-serif;max-width:560px;margin:0 auto;padding:20px;'>
  <div style='text-align:center;padding:24px 0;'><img src='{$domain_url}/assets/img/brand/logo.png' style='max-width:160px;'></div>
  <h2 style='color:#222;text-align:center;'>Bienvenido a {$businessName}, {$firstname}!</h2>
  <p style='color:#555;text-align:center;'>Tu cuenta ya esta activa y lista para usar.</p>

  <h3 style='color:#222;text-align:center;margin-top:28px;'>Un ultimo paso: acceso facial</h3>
  <p style='color:#555;text-align:center;'>Para poder entrar usando reconocimiento facial en el torniquete, necesitamos tu autorizacion para tratar tu fotografia con ese fin.</p>
  <div style='text-align:center;margin:20px 0;'>
    <a href='{$consentUrl}' style='background:#e53935;color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:700;font-size:15px;'>Autorizar acceso facial</a>
  </div>
  <p style='color:#94a3b8;font-size:12px;text-align:center;'>Si prefieres no autorizarlo, puedes solicitar en recepcion que te registren el ingreso de forma manual.</p>

  <hr style='border:none;border-top:1px solid #e5e7eb;margin:28px 0;'>

  <div style='background:#f8f9fa;border-radius:10px;padding:18px 22px;margin:20px 0;'>
    <p style='margin:4px 0;'><strong>Usuario (email):</strong> {$email}</p>
    <p style='margin:4px 0;'><strong>Clave inicial:</strong> tu numero de cedula ({$cedula})</p>
  </div>
  <p style='color:#e53935;font-weight:600;text-align:center;'>Por seguridad, te recomendamos entrar al portal y cambiar tu clave cuanto antes.</p>
  <div style='text-align:center;margin:20px 0;'>
    <a href='{$domain_url}/login/' style='background:#334155;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;'>Entrar al portal</a>
  </div>
</div>";
@send_mail($env, $email, "Bienvenido a {$businessName} - falta un paso", $emailHtml, $businessName, true);

echo json_encode(['ok' => true, 'userid' => $userid, 'firstname' => $firstname, 'lastname' => $lastname, 'pending_consent' => true]);
