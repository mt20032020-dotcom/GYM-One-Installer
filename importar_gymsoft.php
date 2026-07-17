<?php
// Importador de historicos Gymsoft -> GYM One
// Uso: php /app/importar_gymsoft.php           (simula, no toca nada)
//      php /app/importar_gymsoft.php importar  (ejecuta de verdad)
$modo = $argv[1] ?? 'simular';
$csvFile = '/app/importar_gymsoft.csv';
if (!file_exists($csvFile)) die("No existe $csvFile\n");

$env = [];
foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
$conn = new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
if ($conn->connect_error) die("BD inaccesible\n");
$conn->set_charset('utf8mb4');

$fh = fopen($csvFile, 'r');
$header = fgetcsv($fh);
$nuevos=0; $yaExistian=0; $pasesNuevos=0; $pasesSaltados=0; $errores=[];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 10) continue;
    $d = array_combine($header, $row);
    $cedula = trim($d['cedula']);
    if ($cedula === '') continue;

    // 1. Usuario: existe por cedula?
    $stmt = $conn->prepare("SELECT userid FROM users WHERE cedula = ?");
    $stmt->bind_param('s', $cedula);
    $stmt->execute();
    $ex = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($ex) {
        $uid = (int)$ex['userid'];
        $yaExistian++;
    } else {
        do { $uid = rand(1000000000, 9999999999); 
             $c1 = $conn->query("SELECT 1 FROM users WHERE userid = $uid")->num_rows;
        } while ($c1 > 0);
        if ($modo === 'importar') {
            $pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $gen = 'Male'; $conf = 'YES'; $reg = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("INSERT INTO users (userid, cedula, firstname, lastname, email, password, gender, birthdate, celular, registration_date, confirmed) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            // herencia hungara: firstname=APELLIDO, lastname=NOMBRE
            $stmt->bind_param('issssssssss', $uid, $cedula, $d['apellido'], $d['nombre'], $d['email'], $pass, $gen, $d['nacimiento'], $d['celular'], $reg, $conf);
            if (!$stmt->execute()) { $errores[] = "user $cedula: " . $stmt->error; $stmt->close(); continue; }
            $stmt->close();
        }
        $nuevos++;
    }

    // 2. Pase: proteccion anti doble ejecucion (mismo plan + mismo vencimiento)
    $stmt = $conn->prepare("SELECT id FROM current_tickets WHERE userid = ? AND ticketname = ? AND expiredate = ?");
    $stmt->bind_param('iss', $uid, $d['plan'], $d['vence']);
    $stmt->execute();
    $dup = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($dup) { $pasesSaltados++; continue; }

    if ($modo === 'importar') {
        $opp = ($d['ocasiones'] === '' ? null : (int)$d['ocasiones']);
        $stmt = $conn->prepare("INSERT INTO current_tickets (userid, ticketname, buydate, expiredate, opportunities) VALUES (?,?,?,?,?)");
        $stmt->bind_param('isssi', $uid, $d['plan'], $d['inicio'], $d['vence'], $opp);
        if (!$stmt->execute()) { $errores[] = "pase $cedula: " . $stmt->error; $stmt->close(); continue; }
        $stmt->close();
    }
    $pasesNuevos++;
}
fclose($fh);
$conn->close();

echo "=== MODO: " . strtoupper($modo) . " ===\n";
echo "Usuarios nuevos" . ($modo==='simular' ? ' (a crear)' : ' creados') . ": $nuevos\n";
echo "Ya existian: $yaExistian\n";
echo "Pases" . ($modo==='simular' ? ' (a crear)' : ' creados') . ": $pasesNuevos\n";
echo "Pases saltados (duplicados): $pasesSaltados\n";
if ($errores) { echo "ERRORES (" . count($errores) . "):\n" . implode("\n", array_slice($errores, 0, 10)) . "\n"; }
else { echo "Sin errores.\n"; }
