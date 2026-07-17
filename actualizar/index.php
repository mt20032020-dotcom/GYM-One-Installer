<?php
session_start();
function read_env_file($p){ $d=[]; foreach(file($p) as $l){ if(strpos($l,'=')!==false){ [$k,$v]=explode('=',trim($l),2); $d[$k]=$v; } } return $d; }
$env = read_env_file(__DIR__ . '/../.env');
$conn = new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
$conn->set_charset('utf8mb4');

$paso = 'cedula'; $error = ''; $ok = false; $u = null;

function buscar_usuario($conn, $cedula) {
    $stmt = $conn->prepare("SELECT userid, cedula, firstname, lastname, email, celular, birthdate, gender FROM users WHERE cedula = ?");
    $stmt->bind_param('s', $cedula);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $u;
}

// PASO 2: buscar por cedula
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_cedula'])) {
    $ced = preg_replace('/\D/','', $_POST['cedula'] ?? '');
    $u = buscar_usuario($conn, $ced);
    if (!$u) {
        $error = 'No encontramos esa c&eacute;dula. Verifica el n&uacute;mero o ac&eacute;rcate a recepci&oacute;n.';
    } elseif (file_exists(__DIR__ . '/../assets/img/profiles/' . $u['userid'] . '.png')) {
        $error = 'Tus datos ya fueron actualizados. Si necesitas cambiarlos, ac&eacute;rcate a recepci&oacute;n.';
        $u = null;
    } else {
        $paso = 'formulario';
    }
}

// PASO 3: guardar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $ced = preg_replace('/\D/','', $_POST['cedula'] ?? '');
    $u = buscar_usuario($conn, $ced);
    if (!$u) { $error = 'Sesi&oacute;n inv&aacute;lida, vuelve a empezar.'; }
    elseif (file_exists(__DIR__ . '/../assets/img/profiles/' . $u['userid'] . '.png')) { $error = 'Este perfil ya fue actualizado.'; $u = null; }
    else {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $nacimiento = $_POST['nacimiento'] ?? '';
        $genero = ($_POST['genero'] ?? '') === 'Female' ? 'Female' : 'Male';
        $pass = $_POST['password'] ?? '';
        $pass2 = $_POST['confirm_password'] ?? '';

        if ($nombre === '' || $apellido === '') { $error = 'Nombre y apellido son obligatorios.'; $paso='formulario'; }
        elseif (strlen($pass) < 6) { $error = 'La contrase&ntilde;a debe tener al menos 6 caracteres.'; $paso='formulario'; }
        elseif ($pass !== $pass2) { $error = 'Las contrase&ntilde;as no coinciden.'; $paso='formulario'; }
        elseif (empty($_FILES['profile_photo']['tmp_name'])) { $error = 'La foto es obligatoria (t&oacute;mala con la c&aacute;mara).'; $paso='formulario'; }
        else {
            $tmp = $_FILES['profile_photo']['tmp_name'];
            $info = @getimagesize($tmp);
            if (!$info || $_FILES['profile_photo']['size'] > 8*1024*1024) { $error = 'Foto inv&aacute;lida o mayor a 8MB.'; $paso='formulario'; }
            else {
                switch ($info[2]) {
                    case IMAGETYPE_JPEG: $img = imagecreatefromjpeg($tmp); break;
                    case IMAGETYPE_PNG:  $img = imagecreatefrompng($tmp); break;
                    case IMAGETYPE_WEBP: $img = imagecreatefromwebp($tmp); break;
                    default: $img = false;
                }
                if (!$img) { $error = 'Formato de foto no soportado.'; $paso='formulario'; }
                else {
                    $dest = __DIR__ . '/../assets/img/profiles/' . $u['userid'] . '.png';
                    imagepng($img, $dest);
                    @chmod($dest, 0666);
                    imagedestroy($img);
                    // Actualizar datos (herencia hungara: firstname=APELLIDO, lastname=NOMBRE)
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET firstname=?, lastname=?, email=?, celular=?, birthdate=?, gender=?, password=? WHERE userid=?");
                    $stmt->bind_param('sssssssi', $apellido, $nombre, $email, $celular, $nacimiento, $genero, $hash, $u['userid']);
                    $stmt->execute();
                    $stmt->close();
                    // Enrolar al SpeedFace + sincronizar acceso segun plan
                    require_once __DIR__ . '/../iclock/lib/enroll.php';
                    @enrolar_en_speedface((int)$u['userid']);
                    require_once __DIR__ . '/../iclock/lib/endtime.php';
                    @sincronizar_acceso_speedface((int)$u['userid']);
                    $ok = true;
                }
            }
        }
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
.foto-zone{ text-align:center; margin:16px 0; }
.foto-preview{ width:150px; height:150px; border-radius:50%; object-fit:cover; border:3px dashed #d4d4d8; display:none; margin:0 auto 10px; }
.btn-cam{ background:transparent; border:2px solid #e31e24; color:#e31e24; font-weight:700; border-radius:8px; padding:9px 18px; cursor:pointer; margin:4px; }
.btn-cam:hover{ background:#e31e24; color:#fff; }
.webcam-panel{ margin-top:12px; display:none; }
.webcam-panel video{ width:100%; max-width:300px; border-radius:12px; border:2px solid #e31e24; background:#000; transform:scaleX(-1); }
.row2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.ok-big{ text-align:center; font-size:3em; }
small.hint{ color:#a1a1aa; }
</style>
</head>
<body>
<div class="card">
<img class="logo" src="../assets/img/brand/logo.png" alt="Adrenaline Gym">
<?php if ($ok): ?>
    <div class="ok-big">&#128170;</div>
    <h1>&iexcl;Listo, <?php echo htmlspecialchars($_POST['nombre']); ?>!</h1>
    <div class="alert alert-ok" style="margin-top:16px; text-align:center;">
        Tus datos quedaron actualizados y tu rostro quedar&aacute; activo en la entrada en unos minutos.
    </div>
    <p class="sub">Tu acceso funciona seg&uacute;n tu plan vigente. &iexcl;Nos vemos entrenando!</p>
<?php elseif ($paso === 'formulario' && $u): ?>
    <h1>Confirma tus datos</h1>
    <p class="sub">Revisa que todo est&eacute; correcto, t&oacute;mate la foto y crea tu contrase&ntilde;a.</p>
    <?php if ($error): ?><div class="alert alert-err"><?php echo $error; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="formActualizar">
        <input type="hidden" name="cedula" value="<?php echo htmlspecialchars($u['cedula']); ?>">
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
            <div><label>Nombre(s)</label><input type="text" name="nombre" required value="<?php echo htmlspecialchars($u['lastname']); ?>"></div>
            <div><label>Apellido</label><input type="text" name="apellido" required value="<?php echo htmlspecialchars($u['firstname']); ?>"></div>
        </div>
        <label>C&eacute;dula</label><input type="text" value="<?php echo htmlspecialchars($u['cedula']); ?>" disabled>
        <label>Celular</label><input type="tel" name="celular" value="<?php echo htmlspecialchars($u['celular']); ?>">
        <label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($u['email']); ?>">
        <div class="row2">
            <div><label>Fecha de nacimiento</label><input type="date" name="nacimiento" value="<?php echo htmlspecialchars($u['birthdate']); ?>"></div>
            <div><label>G&eacute;nero</label><select name="genero"><option value="Male">Masculino</option><option value="Female">Femenino</option></select></div>
        </div>
        <div class="row2">
            <div><label>Crea tu contrase&ntilde;a</label><input type="password" name="password" required minlength="6"></div>
            <div><label>Conf&iacute;rmala</label><input type="password" name="confirm_password" required></div>
        </div>
        <div id="passAviso" style="color:#e31e24;font-size:.85em;font-weight:600;margin-top:4px;display:none;">Las contrase&ntilde;as no coinciden</div>
        <button type="submit" name="guardar" value="1" class="btn" id="btnGuardar">Actualizar mis datos</button>
    </form>
<?php else: ?>
    <h1>Actualiza tus datos</h1>
    <p class="sub">Adrenaline Gym estren&oacute; sistema. Digita tu c&eacute;dula para confirmar tus datos y activar tu rostro en la entrada.</p>
    <?php if ($error): ?><div class="alert alert-err"><?php echo $error; ?></div><?php endif; ?>
    <form method="post">
        <label>N&uacute;mero de c&eacute;dula</label>
        <input type="tel" name="cedula" required autofocus placeholder="Ej: 1085123456">
        <button type="submit" name="buscar_cedula" value="1" class="btn">Buscar mis datos</button>
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
  function chk(){ var mal=p2.value!==''&&p1.value!==p2.value; aviso.style.display=mal?'block':'none'; guardar.disabled=mal; }
  p1.addEventListener('input',chk); p2.addEventListener('input',chk);
})();
</script>
</body>
</html>
