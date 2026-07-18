<?php
session_start();
if (!isset($_SESSION['userid'])) {
    header("Location: ../../");
    exit();
}
$userid = $_SESSION['userid'];

$env_data = [];
foreach (file('/app/.env') as $line) {
    if (strpos($line, '=') !== false) {
        [$k, $v] = explode('=', trim($line), 2);
        $env_data[$k] = $v;
    }
}

$conn = new mysqli($env_data['DB_SERVER'], $env_data['DB_USERNAME'], $env_data['DB_PASSWORD'], $env_data['DB_NAME']);
if ($conn->connect_error) die("Error de conexión");

$business_name = $env_data['BUSINESS_NAME'] ?? 'Adrenaline Gym';

require_once '/app/includes/beneficiaries.php';

$mi_tiquetera = get_active_tiquetera($conn, $userid);
$soy_benef_de = get_titular_of($conn, $userid);
$benef_msg = "";

// Procesar acciones
if ($mi_tiquetera && isset($_POST['benef_action'])) {
    if ($_POST['benef_action'] === 'add' && !empty($_POST['benef_cedula'])) {
        $st = $conn->prepare("SELECT userid FROM users WHERE cedula = ?");
        $ced_x = trim($_POST['benef_cedula']);
        $st->bind_param("s", $ced_x);
        $st->execute();
        $u_x = $st->get_result()->fetch_assoc();
        if (!$u_x) {
            $benef_msg = "No existe una persona registrada con esa cédula. Pídele que se registre primero en la web o acércate a recepción.";
        } else {
            $r_x = add_beneficiary($conn, $userid, $u_x['userid'], !empty($_POST['benef_repl']));
            $benef_msg = ($r_x === true) ? "OK" : $r_x;
        }
    }
    if ($_POST['benef_action'] === 'remove' && !empty($_POST['benef_userid'])) {
        remove_beneficiary($conn, $userid, intval($_POST['benef_userid']));
        $benef_msg = "REMOVED";
    }
}

$mis_benefs = $mi_tiquetera ? get_beneficiaries($conn, $userid) : [];
$puedo_cambiar = $mi_tiquetera ? can_change_beneficiary($conn, $userid) : false;

// Datos del usuario
$stmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE userid = ?");
$stmt->bind_param("i", $userid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beneficiarios - <?php echo htmlspecialchars($business_name); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="shortcut icon" href="../../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        body { background: #f4f5f7; font-family: 'Segoe UI', Arial, sans-serif; }
        .topbar { background: #111; padding: 12px 20px; display: flex; align-items: center; justify-content: space-between; }
        .topbar img { height: 44px; }
        .topbar a { color: #fff; text-decoration: none; font-size: 0.9em; }
        .page-wrap { max-width: 760px; margin: 30px auto; padding: 0 16px; }
        .card-x { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .benef-row { display: flex; align-items: center; justify-content: space-between; padding: 14px; background: rgba(14,165,233,0.06); border: 1px solid rgba(14,165,233,0.25); border-radius: 10px; margin-bottom: 10px; }
        .btn-adr { background: #e53935; color: #fff; border: none; }
        .btn-adr:hover { background: #c62828; color: #fff; }
        .info-blue { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px; color: #1e40af; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;">
        <img src="../../assets/img/logo.png" alt="Logo" onerror="this.style.display='none'">
        <span style="color:#fff;font-weight:bold;letter-spacing:0.5px;">BENEFICIARIOS</span>
    </div>
    <a href="../"><i class="bi bi-arrow-left"></i> Volver al inicio</a>
</div>

<div class="page-wrap">

    <?php if ($soy_benef_de): ?>
    <div class="card-x" style="display:flex;align-items:center;gap:16px;">
        <i class="bi bi-people-fill" style="font-size:2.4em;color:#0ea5e9;"></i>
        <div>
            <div style="font-weight:bold;font-size:1.1em;">Eres beneficiario de la tiquetera de <?php echo htmlspecialchars($soy_benef_de['firstname'] . ' ' . $soy_benef_de['lastname']); ?></div>
            <div style="font-size:0.9em;color:#888;">Cada día que ingreses al gimnasio se descuenta 1 tiket de su tiquetera (máximo 1 por día, sin importar cuántas veces marques).</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($mi_tiquetera): ?>
    <div class="card-x">
        <h3 style="margin-top:0;"><i class="bi bi-people"></i> Mis beneficiarios
            <span style="background:#0ea5e9;color:#fff;border-radius:12px;padding:3px 12px;font-size:0.5em;vertical-align:middle;"><?php echo count($mis_benefs); ?>/2</span>
        </h3>
        <p style="color:#777;font-size:0.92em;">
            Comparte tu <strong><?php echo htmlspecialchars($mi_tiquetera['ticketname']); ?></strong>
            (vence el <?php echo date('d/m/Y', strtotime($mi_tiquetera['expiredate'])); ?>, quedan <strong><?php echo (int)$mi_tiquetera['opportunities']; ?> tikets</strong>)
            con hasta 2 personas. Cuando un beneficiario ingresa, se descuenta 1 tiket de tu tiquetera.
        </p>
        <div class="info-blue" style="margin-bottom:16px;">
            <i class="bi bi-shield-check"></i> Solo puedes hacer <strong>1 cambio de beneficiario</strong> durante la vigencia de tu tiquetera.
            Cambio disponible: <strong style="color:<?php echo $puedo_cambiar ? '#16a34a' : '#dc2626'; ?>;"><?php echo $puedo_cambiar ? 'Sí' : 'Ya lo usaste — podrás cambiar cuando renueves'; ?></strong>
        </div>

        <?php if ($benef_msg === "OK"): ?>
            <div class="alert alert-success">Beneficiario agregado correctamente. ¡Ya puede ingresar al gym!</div>
        <?php elseif ($benef_msg === "REMOVED"): ?>
            <div class="alert alert-info">Beneficiario eliminado.</div>
        <?php elseif (!empty($benef_msg)): ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars($benef_msg); ?></div>
        <?php endif; ?>

        <?php if (empty($mis_benefs)): ?>
            <p style="color:#aaa;text-align:center;padding:16px;"><i class="bi bi-person-plus" style="font-size:1.6em;"></i><br>Aún no tienes beneficiarios. Agrega hasta 2 personas abajo.</p>
        <?php else: ?>
            <?php foreach ($mis_benefs as $mb): ?>
            <div class="benef-row">
                <div>
                    <strong><?php echo htmlspecialchars($mb['lastname'] . ' ' . $mb['firstname']); ?></strong>
                    <small style="color:#888;"> — CC <?php echo htmlspecialchars($mb['cedula']); ?></small><br>
                    <small style="color:#aaa;">Beneficiario desde el <?php echo date('d/m/Y', strtotime($mb['created_at'])); ?></small>
                </div>
                <form method="POST" style="margin:0;" onsubmit="return confirm('¿Quitar a este beneficiario? Recuerda: solo se permite 1 cambio por vigencia de tu tiquetera.');">
                    <input type="hidden" name="benef_action" value="remove">
                    <input type="hidden" name="benef_userid" value="<?php echo $mb['beneficiary_userid']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Quitar</button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (count($mis_benefs) < 2): ?>
        <hr>
        <h5><i class="bi bi-person-plus"></i> Agregar beneficiario</h5>
        <form method="POST">
            <input type="hidden" name="benef_action" value="add">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="text" name="benef_cedula" class="form-control" placeholder="Cédula de la persona" required style="max-width:240px;">
                <?php if (!empty($mis_benefs)): ?>
                <label style="font-weight:normal;font-size:0.88em;margin:0;"><input type="checkbox" name="benef_repl" value="1"> Es reemplazo</label>
                <?php endif; ?>
                <button type="submit" class="btn btn-adr"><i class="bi bi-person-plus"></i> Agregar</button>
                <button type="button" class="btn btn-success" onclick="abrirModalNB()"><i class="bi bi-person-plus-fill"></i> Registrar nuevo</button>
            </div>
        </form>
        <!-- Modal registrar nuevo beneficiario -->
        <div id="modalNB" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9998;justify-content:center;align-items:center;">
            <div style="background:#fff;border-radius:14px;padding:24px;max-width:480px;width:92%;max-height:90vh;overflow-y:auto;">
                <h4 style="margin-top:0;"><i class="bi bi-person-plus"></i> Registrar beneficiario nuevo</h4>
                <div id="nbErr" style="display:none;" class="alert alert-danger"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <input type="text" id="nbFn" class="form-control" placeholder="Nombre *">
                    <input type="text" id="nbLn" class="form-control" placeholder="Apellido *">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <input type="text" id="nbCed" class="form-control" placeholder="Cédula *">
                    <input type="tel" id="nbCel" class="form-control" placeholder="Celular">
                </div>
                <input type="email" id="nbEm" class="form-control" placeholder="Correo (opcional)" style="margin-bottom:8px;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
                    <select id="nbGen" class="form-control">
                        <option value="Male">Masculino</option>
                        <option value="Female">Femenino</option>
                        <option value="Other">Otro</option>
                    </select>
                    <input type="date" id="nbBd" class="form-control">
                </div>
                <div style="margin-bottom:12px;">
                    <button type="button" class="btn btn-default btn-sm" onclick="nbCam()"><i class="bi bi-camera"></i> Tomar foto</button>
                    <span id="nbFs" style="font-size:0.85em;color:#999;margin-left:8px;">Sin foto</span>
                    <small style="display:block;color:#888;font-size:0.78em;margin-top:3px;"><i class="bi bi-info-circle"></i> La foto es para que pueda ingresar con reconocimiento facial.</small>
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px;margin-top:8px;font-size:0.82em;color:#1e40af;">
                        <i class="bi bi-key"></i> Su contraseña inicial será su <strong>número de cédula</strong>. Podrá cambiarla desde su perfil.
                    </div>
                </div>
                <div id="nbCamBox" style="display:none;margin-bottom:12px;text-align:center;">
                    <video id="nbVid" autoplay playsinline style="max-width:100%;border-radius:10px;"></video>
                    <canvas id="nbCan" style="display:none;"></canvas>
                    <div style="margin-top:8px;">
                        <button type="button" class="btn btn-danger btn-sm" onclick="nbCap()"><i class="bi bi-camera-fill"></i> Capturar</button>
                        <button type="button" class="btn btn-default btn-sm" onclick="nbStop()">Cancelar</button>
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="btn btn-default" onclick="cerrarModalNB()">Cancelar</button>
                    <button type="button" class="btn btn-adr" onclick="nbSave()"><i class="bi bi-check-lg"></i> Registrar y agregar</button>
                </div>
            </div>
        </div>
        <small style="color:#999;display:block;margin-top:8px;">
            <i class="bi bi-info-circle"></i> La persona debe estar registrada en <?php echo htmlspecialchars($business_name); ?> (con su foto para el reconocimiento facial).
            Si aún no lo está, pídele que se registre en la web o acérquense a recepción.
        </small>
        <?php endif; ?>
    </div>
    <?php elseif (!$soy_benef_de): ?>
    <div class="card-x" style="text-align:center;padding:40px;">
        <i class="bi bi-ticket-perforated" style="font-size:3em;color:#ccc;"></i>
        <h4>Los beneficiarios son exclusivos de las tiqueteras</h4>
        <p style="color:#888;">Compra una <strong>Tiquetera</strong> y podrás compartirla con hasta 2 personas.</p>
        <a href="../../prices/" class="btn btn-adr">Ver planes</a>
    </div>
    <?php endif; ?>

</div>
<script>
function abrirModalNB(){ document.getElementById("modalNB").style.display="flex"; }
function cerrarModalNB(){ nbStop(); document.getElementById("modalNB").style.display="none"; }
var nbS=null, nbF="";
function nbCam(){
    document.getElementById("nbCamBox").style.display="block";
    navigator.mediaDevices.getUserMedia({video:{facingMode:"user"}})
        .then(function(s){nbS=s;document.getElementById("nbVid").srcObject=s;})
        .catch(function(e){alert("No se pudo acceder a la cámara: "+e.message);document.getElementById("nbCamBox").style.display="none";});
}
function nbCap(){
    var v=document.getElementById("nbVid"),c=document.getElementById("nbCan");
    c.width=v.videoWidth;c.height=v.videoHeight;
    c.getContext("2d").drawImage(v,0,0);
    nbF=c.toDataURL("image/jpeg",0.85);
    document.getElementById("nbFs").innerHTML="<span style='color:#16a34a;'><i class='bi bi-check-circle'></i> Foto lista</span>";
    nbStop();
}
function nbStop(){
    if(nbS){nbS.getTracks().forEach(t=>t.stop());nbS=null;}
    document.getElementById("nbCamBox").style.display="none";
}
function nbSave(){
    var e=document.getElementById("nbErr");e.style.display="none";
    if(!nbF){e.textContent="Por favor toma la foto del beneficiario para el reconocimiento facial.";e.style.display="block";return;}
    var d=new FormData();
    d.append("action","create");
    d.append("firstname",document.getElementById("nbFn").value.trim());
    d.append("lastname",document.getElementById("nbLn").value.trim());
    d.append("cedula",document.getElementById("nbCed").value.trim());
    d.append("celular",document.getElementById("nbCel").value.trim());
    d.append("email",document.getElementById("nbEm").value.trim());
    d.append("gender",document.getElementById("nbGen").value);
    d.append("birthdate",document.getElementById("nbBd").value);
    d.append("face_photo",nbF);
    fetch("api.php",{method:"POST",body:d})
        .then(r=>r.json())
        .then(function(res){
            if(res.error){e.textContent=res.error;e.style.display="block";return;}
            // Creado - agregarlo como beneficiario via form POST
            var f=document.createElement("form");f.method="POST";
            f.innerHTML="<input type=hidden name=benef_action value=add><input type=hidden name=benef_cedula value='"+res.cedula+"'>";
            document.body.appendChild(f);f.submit();
        })
        .catch(function(){e.textContent="Error de conexión.";e.style.display="block";});
}
</script>
</body>
</html>
