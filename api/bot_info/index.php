<?php
header('Content-Type: application/json; charset=utf-8');

$API_KEY = '0e231ea5656de9582899896e9d97384875b194c41e1ef3750349a6e0db874fbb';
$providedKey = $_SERVER['HTTP_X_BOT_KEY'] ?? '';
if (!hash_equals($API_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit();
}

function read_env_file($file_path) {
    $env_data = [];
    foreach (preg_split("/\r\n|\n|\r/", (string) @file_get_contents($file_path)) as $line) {
        if (trim($line) === '' || strpos(ltrim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) $env_data[trim($parts[0])] = trim($parts[1]);
    }
    return $env_data;
}
$env = read_env_file('/app/.env');
$conn = new mysqli($env['DB_SERVER'], $env['DB_USERNAME'], $env['DB_PASSWORD'], $env['DB_NAME']);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error de conexion']);
    exit();
}
$conn->set_charset('utf8mb4');

$result = $conn->query("SELECT categoria, contenido FROM bot_info ORDER BY orden ASC");
$info = [];
while ($row = $result->fetch_assoc()) {
    $info[] = ['categoria' => $row['categoria'], 'contenido' => $row['contenido']];
}

echo json_encode(['ok' => true, 'info' => $info], JSON_UNESCAPED_UNICODE);
