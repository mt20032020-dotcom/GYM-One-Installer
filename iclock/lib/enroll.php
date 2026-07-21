<?php
/**
 * Enrola un usuario de GYM One en el SpeedFace via cola ADMS.
 * Uso: enrolar_en_speedface($useridGymOne) -> array resultado
 */
function enrolar_en_speedface($userid) {
    $r = ['ok' => false, 'pasos' => []];

    // 1. Datos del usuario desde la BD
    $env = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
    $conn = @new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
    if ($conn->connect_error) { $r['error']='BD inaccesible'; return $r; }
    $stmt = $conn->prepare("SELECT cedula, firstname, lastname FROM users WHERE userid = ?");
    $stmt->bind_param('i', $userid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$u || empty($u['cedula'])) { $r['error']='Usuario sin cedula'; return $r; }
    $pin = preg_replace('/\D/','',$u['cedula']);
    $nombre = trim($u['lastname'] . ' ' . $u['firstname']); // lastname=nombre por herencia hungara
    $r['pasos'][] = "usuario: $nombre pin: $pin";

    // 2. Foto: convertir a specs ZKTeco (JPG 480x640 <20KB)
    $src = "/app/assets/img/profiles/{$userid}.png";
    if (!file_exists($src)) { $r['error']="Sin foto en $src"; return $r; }
    $img = @imagecreatefrompng($src);
    if (!$img) { $img = @imagecreatefromjpeg($src); }
    if (!$img) { $r['error']='Foto ilegible'; return $r; }
    $w=imagesx($img); $h=imagesy($img); $ratio=480/640;
    if ($w/$h > $ratio) { $nh=$h; $nw=(int)round($h*$ratio); $sx=(int)(($w-$nw)/2); $sy=0; }
    else { $nw=$w; $nh=(int)round($w/$ratio); $sx=0; $sy=(int)(($h-$nh)/2); }
    $out=imagecreatetruecolor(480,640);
    imagefill($out,0,0,imagecolorallocate($out,255,255,255));
    imagecopyresampled($out,$img,0,0,$sx,$sy,480,640,$nw,$nh);
    $tmp = "/tmp/face_{$pin}_" . uniqid() . ".jpg"; $size = 999999;
    for ($q=85; $q>=25; $q-=5) {
        imagejpeg($out,$tmp,$q);
        clearstatcache(true,$tmp);
        $size = filesize($tmp);
        if ($size <= 20000) break;
    }
    if ($size > 20000) { $r['error']="Foto no baja de 20KB ($size)"; { @unlink($tmp); return $r; } }
    $r['pasos'][] = "foto: $size bytes";
    $b64 = base64_encode(file_get_contents($tmp));

    // 3. Encolar los 3 comandos (IDs unicos por timestamp)
    $base = time() % 100000;
    $cmds = [
        "C:{$base}1:DATA UPDATE user CardNo=\tPin={$pin}\tPassword=\tGroup=1\tStartTime=0\tEndTime=0\tName={$nombre}\tPrivilege=0",
        "C:{$base}2:DATA UPDATE biophoto PIN={$pin}\tType=9\tSize={$size}\tContent={$b64}",
    ];
    $fh = fopen('/app/iclock/cmd_queue.txt', 'a');
    if (!$fh) { $r['error']='No se pudo abrir la cola'; { @unlink($tmp); return $r; } }
    flock($fh, LOCK_EX);
    foreach ($cmds as $cmd) { fwrite($fh, $cmd . "\n"); }
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod('/app/iclock/cmd_queue.txt', 0666);
    $r['pasos'][] = 'encolados 3 comandos';
    $r['ok'] = true;
    @unlink($tmp); return $r;
}
