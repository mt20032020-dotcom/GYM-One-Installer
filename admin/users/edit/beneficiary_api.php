<?php
session_start();
header('Content-Type: application/json');

// Leer env y conectar
$env = [];
foreach (file('/app/.env') as $l) {
    if (strpos($l, '=') !== false) { [$k, $v] = explode('=', trim($l), 2); $env[$k] = $v; }
}
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) { echo json_encode(['error' => 'DB']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============ BUSCAR USUARIOS ============
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $like = "%$q%";
    $stmt = $conn->prepare("SELECT userid, firstname, lastname, cedula 
        FROM users 
        WHERE cedula LIKE ? OR firstname LIKE ? OR lastname LIKE ? 
        LIMIT 8");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

// ============ CREAR USUARIO NUEVO ============
if ($action === 'create') {
    $fn = trim($_POST['firstname'] ?? '');
    $ln = trim($_POST['lastname'] ?? '');
    $ced = trim($_POST['cedula'] ?? '');
    $cel = trim($_POST['celular'] ?? '');
    $em = trim($_POST['email'] ?? '');
    $gender = in_array($_POST['gender'] ?? '', ['Male','Female','Other']) ? $_POST['gender'] : 'Male';
    $birth = !empty($_POST['birthdate']) ? $_POST['birthdate'] : '1990-01-01';
    
    if (empty($fn) || empty($ln) || empty($ced)) {
        echo json_encode(['error' => 'Nombre, apellido y cédula son obligatorios.']); exit;
    }
    
    // Verificar duplicado
    $stmt = $conn->prepare("SELECT userid FROM users WHERE cedula = ?");
    $stmt->bind_param("s", $ced);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        echo json_encode(['error' => 'Ya existe un usuario con esa cédula.']); exit;
    }
    
    $new_userid = rand(pow(10, 9), pow(10, 10) - 1);
    $em = $em ?: ($ced . '@sincorreo.local');
    $pw = password_hash($ced, PASSWORD_DEFAULT); // clave inicial = su cédula
    $now = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("INSERT INTO users (userid, cedula, firstname, lastname, email, password, gender, birthdate, celular, registration_date, confirmed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Yes')");
    $stmt->bind_param("isssssssss", $new_userid, $ced, $fn, $ln, $em, $pw, $gender, $birth, $cel, $now);
    
    if ($stmt->execute()) {
        // Guardar foto si viene
        if (!empty($_POST['face_photo']) && strpos($_POST['face_photo'], 'data:image') === 0) {
            $img = explode(',', $_POST['face_photo'], 2);
            if (count($img) === 2) {
                $bin = base64_decode($img[1]);
                if ($bin !== false) {
                    @mkdir('/app/assets/img/profiles', 0777, true);
                    @file_put_contents('/app/assets/img/profiles/' . $new_userid . '.png', $bin);
                    // Enrolar en SpeedFace
                    require_once '/app/iclock/lib/enroll.php';
                    @enrolar_en_speedface($new_userid);
                }
            }
        }
        echo json_encode(['ok' => true, 'userid' => $new_userid, 'nombre' => "$ln $fn", 'cedula' => $ced]);
    } else {
        echo json_encode(['error' => 'Error al crear el usuario.']);
    }
    exit;
}

echo json_encode(['error' => 'Accion invalida']);
