<?php
session_start();
if (isset($_GET['reset'])) { session_unset(); session_destroy(); header('Location: ./'); exit(); }
require_once __DIR__ . '/../includes/chatwoot.php';
require_once __DIR__ . '/../includes/meta_whatsapp.php';

function read_env_file($p){ $d=[]; foreach(file($p) as $l){ if(strpos($l,'=')!==false){ [$k,$v]=explode('=',trim($l),2); $d[trim($k)]=trim($v); } } return $d; }
$env = read_env_file(__DIR__ . '/../.env');
$conn = new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
mysqli_report(MYSQLI_REPORT_OFF);
$conn->set_charset('utf8mb4');

$error = ''; $ok = false; $sendWarning = '';

function buscar_usuario($conn, $cedula) {
    $stmt = $conn->prepare("SELECT userid, cedula, firstname, lastname, email, celular, birthdate, gender, city FROM users WHERE cedula = ?");
    $stmt->bind_param('s', $cedula);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $u;
}

function ya_actualizado($userid) {
    /* Antes se marcaba por tener foto de perfil, pero los usuarios migrados
       ya traen foto del SpeedFace y quedaban bloqueados sin poder crear clave. */
    global $conn;
    $st = $conn->prepare("SELECT datos_actualizados FROM users WHERE userid = ?");
    $st->bind_param('i', $userid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($row['datos_actualizados']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_cedula'])) {
    $ced = preg_replace('/\D/','', $_POST['cedula'] ?? '');
    $u = buscar_usuario($conn, $ced);

    if (!$u) {
        $error = 'No encontramos esa c&eacute;dula. Verifica el n&uacute;mero o ac&eacute;rcate a recepci&oacute;n.';
    } elseif (ya_actualizado($u['userid'])) {
        $error = 'Tus datos ya fueron actualizados. Si necesitas cambiarlos, ac&eacute;rcate a recepci&oacute;n.';
    } elseif (empty($u['celular'])) {
        $error = 'No tenemos un celular registrado para verificarte. Ac&eacute;rcate a recepci&oacute;n.';
    } else {
        $chk = $conn->prepare("SELECT created_at FROM verification_codes WHERE userid = ? ORDER BY id DESC LIMIT 1");
        $chk->bind_param('i', $u['userid']);
        $chk->execute();
        $last = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($last && (time() - strtotime($last['created_at'])) < 60) {
            $_SESSION['upd_userid'] = (int) $u['userid'];
            $_SESSION['upd_step'] = 'verificar';
            header('Location: ./'); exit();
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $ins = $conn->prepare("INSERT INTO verification_codes (userid, code, created_at, expires_at) VALUES (?, ?, ?, ?)");
        $ins->bind_param('isss', $u['userid'], $code, $now, $expires);
        $ins->execute();
        $ins->close();

        $envio = enviar_codigo_whatsapp_meta($u['celular'], $code);

        $_SESSION['upd_userid'] = (int) $u['userid'];
        $_SESSION['upd_step'] = 'verificar';
        if (!$envio['ok']) {
            $_SESSION['upd_send_error'] = $envio['error'];
        }
        header('Location: ./'); exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verificar_codigo'])) {
    $uid = (int) ($_SESSION['upd_userid'] ?? 0);
    $codeInput = preg_replace('/\D/', '', $_POST['codigo'] ?? '');

    if (!$uid) {
        $error = 'Tu sesi&oacute;n expir&oacute;. Vuelve a empezar.';
        unset($_SESSION['upd_step']);
    } else {
        $stmt = $conn->prepare("SELECT id, code, expires_at FROM verification_codes WHERE userid = ? AND used = 0 ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $error = 'No hay un c&oacute;digo pendiente. Solicita uno nuevo.';
        } elseif (strtotime($row['expires_at']) < time()) {
            $error = 'Tu c&oacute;digo expir&oacute;. Solicita uno nuevo.';
        } elseif (!hash_equals($row['code'], $codeInput)) {
            $error = 'C&oacute;digo incorrecto.';
        } else {
            $conn->query("UPDATE verification_codes SET used = 1 WHERE id = " . (int) $row['id']);
            $_SESSION['upd_verified'] = true;
            $_SESSION['upd_step'] = 'formulario';
            header('Location: ./'); exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $uid = (int) ($_SESSION['upd_userid'] ?? 0);
    $verificado = $_SESSION['upd_verified'] ?? false;

    if (!$uid || !$verificado) {
        $error = 'Verificaci&oacute;n requerida. Vuelve a empezar.';
        session_unset();
    } else {
        $stmt = $conn->prepare("SELECT userid, cedula, firstname, lastname FROM users WHERE userid = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$u || ya_actualizado($uid)) {
            $error = 'Este perfil ya fue actualizado o no existe.';
            session_unset();
        } else {
            $nombre = trim($_POST['nombre'] ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $celular = trim($_POST['celular'] ?? '');
            $barrio = trim($_POST['barrio'] ?? '');
            $nacimiento = $_POST['nacimiento'] ?? '';
            $generoInput = $_POST['genero'] ?? '';
            $genero = in_array($generoInput, ['Male', 'Female', 'Other'], true) ? $generoInput : 'Male';
            $pass = $_POST['password'] ?? '';
            $pass2 = $_POST['confirm_password'] ?? '';
            $aceptaReglamento = isset($_POST['accept_reglamento']);
            $aceptaBiometria = isset($_POST['accept_biometric']);

            if ($nombre === '' || $apellido === '') { $error = 'Nombre y apellido son obligatorios.'; }
            elseif (strlen($pass) < 6) { $error = 'La contrase&ntilde;a debe tener al menos 6 caracteres.'; }
            elseif ($pass !== $pass2) { $error = 'Las contrase&ntilde;as no coinciden.'; }
            elseif (!$aceptaReglamento) { $error = 'Debes aceptar el reglamento del gimnasio para continuar.'; }
            elseif (!$aceptaBiometria) { $error = 'Debes autorizar el tratamiento de tu foto para verificaci&oacute;n biom&eacute;trica de acceso.'; }
            elseif (empty($_FILES['profile_photo']['tmp_name'])) { $error = 'La foto es obligatoria (t&oacute;mala con la c&aacute;mara).'; }
            else {
                $tmp = $_FILES['profile_photo']['tmp_name'];
                $info = @getimagesize($tmp);
                if (!$info || $_FILES['profile_photo']['size'] > 8*1024*1024) { $error = 'Foto inv&aacute;lida o mayor a 8MB.'; }
                else {
                    switch ($info[2]) {
                        case IMAGETYPE_JPEG: $img = imagecreatefromjpeg($tmp); break;
                        case IMAGETYPE_PNG:  $img = imagecreatefrompng($tmp); break;
                        case IMAGETYPE_WEBP: $img = imagecreatefromwebp($tmp); break;
                        default: $img = false;
                    }
                    if (!$img) { $error = 'Formato de foto no soportado.'; }
                    else {
                        $dest = __DIR__ . '/../assets/img/profiles/' . $uid . '.png';
                        imagepng($img, $dest);
                        @chmod($dest, 0666);
                        imagedestroy($img);
                        $hash = password_hash($pass, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE users SET firstname=?, lastname=?, email=?, celular=?, city=?, birthdate=?, gender=?, password=?, datos_actualizados=NOW() WHERE userid=?");
                        $upd->bind_param('ssssssssi', $apellido, $nombre, $email, $celular, $barrio, $nacimiento, $genero, $hash, $uid);
                        $upd->execute();
                        $upd->close();

                        $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES (" . (int)$uid . ", 'Autorizacion de datos biometricos otorgada en actualizacion de perfil', 'success', NOW())");

                        require_once __DIR__ . '/../includes/future_plans.php';
                        $resCortesiaDia = add_plan($conn, $uid, 'Cortesia actualizacion de datos - 1 dia', 1, null, null);
                        $accSDia = ($resCortesiaDia['type'] === 'active') ? 'activada ahora' : ('encolada, inicia ' . ($resCortesiaDia['start_date'] ?? '?'));
                        $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES (" . (int)$uid . ", 'Cortesia por actualizacion web: 1 dia (" . $accSDia . ")', 'success', NOW())");

                        require_once __DIR__ . '/../iclock/lib/enroll.php';
                        @enrolar_en_speedface($uid);
                        require_once __DIR__ . '/../iclock/lib/endtime.php';
                        @sincronizar_acceso_speedface($uid);

                        $ok = true;
                        $nombreOk = $nombre;
                        session_unset();
                    }
                }
            }
        }
    }
}

$paso = $_SESSION['upd_step'] ?? 'cedula';
if ($ok) { $paso = 'listo'; }
$sendWarning = $_SESSION['upd_send_error'] ?? '';

$datosActuales = null;
if ($paso === 'formulario' && ($_SESSION['upd_verified'] ?? false)) {
    $uidForm = (int) ($_SESSION['upd_userid'] ?? 0);
    if ($uidForm) {
        $stmtD = $conn->prepare("SELECT firstname, lastname, email, celular, birthdate, gender, city FROM users WHERE userid = ?");
        $stmtD->bind_param('i', $uidForm);
        $stmtD->execute();
        $datosActuales = $stmtD->get_result()->fetch_assoc();
        $stmtD->close();
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Actualiza tus datos - Adrenaline Gym</title>
<link rel="icon" href="../assets/img/brand/logo.png">
<style>
*{ box-sizing:border-box; margin:0; padding:0; font-family:'Segoe UI',Arial,sans-serif; }
body{ min-height:100vh; background:linear-gradient(160deg,#0b0b0d 0%,#141418 60%,#2b0b0d 100%); display:flex; align-items:center; justify-content:center; padding:20px; }
.card{ background:#fff; border-radius:16px; border-top:5px solid #e31e24; box-shadow:0 18px 50px rgba(0,0,0,.5); width:100%; max-width:520px; padding:32px 28px; }
.logo{ display:block; margin:0 auto 10px; max-width:90px; background:#0b0b0d; border-radius:14px; padding:10px 14px; }
h1{ text-align:center; font-size:1.5em; letter-spacing:1px; text-transform:uppercase; color:#0b0b0d; }
.sub{ text-align:center; color:#71717a; margin:6px 0 20px; font-size:.95em; }
label{ display:block; font-weight:700; color:#3f3f46; margin:12px 0 4px; font-size:.9em; }
input, select{ width:100%; padding:11px 12px; border:1px solid #d4d4d8; border-radius:8px; font-size:1em; background:#f7f7f8; }
input:focus, select:focus{ outline:none; border-color:#e31e24; box-shadow:0 0 0 3px rgba(227,30,36,.18); background:#fff; }
.btn{ width:100%; margin-top:20px; padding:13px; background:#e31e24; color:#fff; border:none; border-radius:8px; font-size:1.05em; font-weight:800; text-transform:uppercase; letter-spacing:1px; cursor:pointer; }
.btn:hover{ background:#b3151a; }
.btn:disabled{ background:#a1a1aa; cursor:not-allowed; }
.alert{ padding:12px 14px; border-radius:8px; margin-bottom:14px; font-weight:600; }
.alert-err{ background:#fee2e2; color:#b91c1c; }
.alert-ok{ background:#dcfce7; color:#15803d; }
.alert-warn{ background:#fef3c7; color:#92400e; }
.foto-zone{ text-align:center; margin:16px 0; }
.foto-preview{ width:150px; height:150px; border-radius:50%; object-fit:cover; border:3px dashed #d4d4d8; display:none; margin:0 auto 10px; }
.btn-cam{ background:transparent; border:2px solid #e31e24; color:#e31e24; font-weight:700; border-radius:8px; padding:9px 18px; cursor:pointer; margin:4px; }
.btn-cam:hover{ background:#e31e24; color:#fff; }
.webcam-panel{ margin-top:12px; display:none; }
.webcam-panel video{ width:100%; max-width:300px; border-radius:12px; border:2px solid #e31e24; background:#000; transform:scaleX(-1); }
.row2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.ok-big{ text-align:center; font-size:3em; }
small.hint{ color:#a1a1aa; }
.codigo-input{ text-align:center; font-size:1.6em; letter-spacing:8px; font-weight:800; }
.consent-box{ display:flex; align-items:flex-start; gap:8px; margin-top:14px; padding:10px 12px; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb; }
.consent-box input{ width:auto; margin-top:3px; flex-shrink:0; }
.consent-box span{ font-size:.85em; color:#3f3f46; line-height:1.4; }
</style>
</head>
<body>
<div class="card">
<img class="logo" src="../assets/img/brand/logo.png" alt="Adrenaline Gym">

<?php if ($paso === 'listo'): ?>
    <div class="ok-big">&#128170;</div>
    <h1>&iexcl;Listo, <?php echo htmlspecialchars($nombreOk); ?>!</h1>
    <div class="alert alert-ok" style="margin-top:16px; text-align:center;">
        Tus datos quedaron actualizados y tu rostro quedar&aacute; activo en la entrada en unos minutos.
    </div>
    <p class="sub">Tu acceso funciona seg&uacute;n tu plan vigente. &iexcl;Nos vemos entrenando!</p>

<?php elseif ($paso === 'formulario' && ($_SESSION['upd_verified'] ?? false)): ?>
    <h1>Confirma tus datos</h1>
    <p class="sub">Revisa que todo est&eacute; correcto, t&oacute;mate la foto y crea tu contrase&ntilde;a.</p>
    <?php if ($error): ?><div class="alert alert-err"><?php echo $error; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="formActualizar">
        <div class="foto-zone">
            <img id="fotoPreview" class="foto-preview" alt="">
            <input type="file" id="profile_photo" name="profile_photo" accept="image/*" capture="user" style="display:none;">
            <button type="button" class="btn-cam" onclick="document.getElementById('profile_photo').click()">&#128194; Subir foto</button>
            <button type="button" class="btn-cam" id="btnWebcam">&#128247; Usar c&aacute;mara</button>
            <div class="webcam-panel" id="webcamPanel">
                <video id="webcamVideo" autoplay playsinline></video><br>
                <button type="button" class="btn-cam" id="btnCapture" style="background:#e31e24;color:#fff;">Capturar</button>
                <button type="button" class="btn-cam" id="btnWebcamCancel" style="border-color:#71717a;color:#71717a;">Cancelar</button>
            </div>
            <div><small class="hint">Foto de frente, buena luz (es la que usar&aacute; la entrada)</small></div>
        </div>
        <div class="row2">
            <div><label>Nombre(s)</label><input type="text" name="nombre" required value="<?php echo htmlspecialchars($datosActuales['lastname'] ?? ''); ?>"></div>
            <div><label>Apellido</label><input type="text" name="apellido" required value="<?php echo htmlspecialchars($datosActuales['firstname'] ?? ''); ?>"></div>
        </div>
        <label>Celular</label><input type="tel" name="celular" value="<?php echo htmlspecialchars($datosActuales['celular'] ?? ''); ?>">
        <label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($datosActuales['email'] ?? ''); ?>">
        <label>Barrio</label><input type="text" name="barrio" value="<?php echo htmlspecialchars($datosActuales['city'] ?? ''); ?>">
        <div class="row2">
            <div><label>Fecha de nacimiento</label><input type="date" name="nacimiento" value="<?php echo htmlspecialchars($datosActuales['birthdate'] ?? ''); ?>"></div>
            <div><label>G&eacute;nero</label>
                <select name="genero">
                    <option value="Male" <?php echo ($datosActuales['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Masculino</option>
                    <option value="Female" <?php echo ($datosActuales['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Femenino</option>
                    <option value="Other" <?php echo ($datosActuales['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Otro</option>
                </select>
            </div>
        </div>
        <div class="row2">
            <div><label>Crea tu contrase&ntilde;a</label><input type="password" name="password" required minlength="6"></div>
            <div><label>Conf&iacute;rmala</label><input type="password" name="confirm_password" required></div>
        </div>
        <div id="passAviso" style="color:#e31e24;font-size:.85em;font-weight:600;margin-top:4px;display:none;">Las contrase&ntilde;as no coinciden</div>

        <label>Pol&iacute;ticas</label>
        <div style="border:1px solid #d4d4d8;border-radius:8px;overflow:hidden;margin-bottom:8px;">
            <iframe src="../admin/boss/rule/rule.html" frameborder="0" style="width:100%;height:180px;display:block;"></iframe>
        </div>
        <div class="consent-box">
            <input type="checkbox" id="acceptReglamento" name="accept_reglamento" required>
            <span>&iexcl;Acepto las reglas del gimnasio!</span>
        </div>
        <div class="consent-box">
            <input type="checkbox" id="acceptBiometric" name="accept_biometric" required>
            <span>Autorizo el tratamiento de mi fotograf&iacute;a con fines de verificaci&oacute;n biom&eacute;trica de acceso (reconocimiento facial en el torniquete).</span>
        </div>

        <button type="submit" name="guardar" value="1" class="btn" id="btnGuardar">Actualizar mis datos</button>
    </form>

<?php elseif ($paso === 'verificar' && !empty($_SESSION['upd_userid'])): ?>
    <h1>Verifica tu identidad</h1>
    <p class="sub">Te enviamos un c&oacute;digo de 6 d&iacute;gitos por WhatsApp. Escr&iacute;belo aqu&iacute; para continuar.</p>
    <?php if ($sendWarning): ?>
        <div class="alert alert-warn">No pudimos enviarte el c&oacute;digo autom&aacute;ticamente (<?php echo htmlspecialchars($sendWarning); ?>). Ac&eacute;rcate a recepci&oacute;n para que te ayudemos.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-err"><?php echo $error; ?></div><?php endif; ?>
    <form method="post">
        <label>C&oacute;digo de verificaci&oacute;n</label>
        <input type="tel" name="codigo" class="codigo-input" maxlength="6" required autofocus placeholder="000000">
        <button type="submit" name="verificar_codigo" value="1" class="btn">Verificar</button>
    </form>
    <p class="sub" style="margin-top:14px;"><a href="?reset=1" style="color:#e31e24;font-weight:700;">&iexcl;No es mi turno / empezar de nuevo</a></p>

<?php else: ?>
    <h1>Actualiza tus datos</h1>
    <p class="sub">Adrenaline Gym estren&oacute; sistema. Digita tu c&eacute;dula, te llegar&aacute; un c&oacute;digo por WhatsApp para confirmar que eres t&uacute;.</p>
    <?php if ($error): ?><div class="alert alert-err"><?php echo $error; ?></div><?php endif; ?>
    <form method="post">
        <label>N&uacute;mero de c&eacute;dula</label>
        <input type="tel" name="cedula" required autofocus placeholder="Ej: 1085123456">
        <button type="submit" name="buscar_cedula" value="1" class="btn">Enviar c&oacute;digo</button>
    </form>
<?php endif; ?>
</div>
<script>
(function(){
  var input=document.getElementById('profile_photo'), prev=document.getElementById('fotoPreview');
  if(!input) return;
  function mostrar(file){ var r=new FileReader(); r.onload=function(e){ prev.src=e.target.result; prev.style.display='block'; }; r.readAsDataURL(file); }
  input.addEventListener('change', function(){ if(this.files[0]) mostrar(this.files[0]); });
  var btn=document.getElementById('btnWebcam'), panel=document.getElementById('webcamPanel'),
      video=document.getElementById('webcamVideo'), cap=document.getElementById('btnCapture'),
      cancel=document.getElementById('btnWebcamCancel'), stream=null;
  function stop(){ if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;} panel.style.display='none'; }
  btn.addEventListener('click', function(){
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){ alert('Este navegador no soporta camara'); return; }
    navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:720},height:{ideal:960}},audio:false})
    .then(function(s){ stream=s; video.srcObject=s; panel.style.display='block'; })
    .catch(function(){ alert('No se pudo acceder a la camara. Revisa permisos.'); });
  });
  cancel.addEventListener('click', stop);
  cap.addEventListener('click', function(){
    var c=document.createElement('canvas'); c.width=video.videoWidth; c.height=video.videoHeight;
    var ctx=c.getContext('2d'); ctx.translate(c.width,0); ctx.scale(-1,1); ctx.drawImage(video,0,0);
    c.toBlob(function(blob){
      var f=new File([blob],'captura.png',{type:'image/png'});
      var dt=new DataTransfer(); dt.items.add(f); input.files=dt.files;
      input.dispatchEvent(new Event('change',{bubbles:true})); stop();
    },'image/png');
  });
  var p1=document.querySelector('input[name="password"]'), p2=document.querySelector('input[name="confirm_password"]'),
      aviso=document.getElementById('passAviso'), guardar=document.getElementById('btnGuardar');
  if (p1 && p2) {
    function chk(){ var mal=p2.value!==''&&p1.value!==p2.value; aviso.style.display=mal?'block':'none'; guardar.disabled=mal; }
    p1.addEventListener('input',chk); p2.addEventListener('input',chk);
  }
})();
</script>
</body>
</html>
