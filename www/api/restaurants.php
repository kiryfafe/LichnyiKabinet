<?php
require_once __DIR__ . '/../config.php';
header("Content-Type: application/json; charset=utf-8");

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB connection error"]);
    exit;
}

// ==================== ПРОВЕРКА ТОКЕНА ====================
$headers = getallheaders();
if (!isset($headers["Authorization"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Missing token"]);
    exit;
}
list($type, $token) = explode(" ", $headers["Authorization"], 2);
if (strtolower($type) !== "bearer" || !$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid token"]);
    exit;
}

$stmt = $pdo->prepare("SELECT u.* FROM user_sessions s 
                       JOIN users u ON u.id = s.user_id
                       WHERE s.token = :token AND s.expires_at > NOW()
                       LIMIT 1");
$stmt->execute([":token" => $token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Invalid or expired token"]);
    exit;
}

// ==================== ЗАГРУЗКА ЗАВЕДЕНИЙ ИЗ PYRUS ====================
try {
    $register = pyrusFetchRegister(1310341);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Pyrus fetch error: " . $e->getMessage()]);
    exit;
}

$columnMap = pyrusBuildColumnMap($register);
$restaurantColId = isset($columnMap['Ресторан']) ? $columnMap['Ресторан'] : null;
if (!$restaurantColId) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Pyrus: column 'Ресторан' not found"]);
    exit;
}

// Список ресторанов из профиля пользователя
$allowedNames = [];
if (!empty($user["network"])) {
    $parts = preg_split('/[,\\n]+/', $user["network"]);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $allowedNames[] = $p;
        }
    }
}

// Если ничего не указано в профиле, вернем пусто
if (empty($allowedNames)) {
    echo json_encode(["success" => true, "restaurants" => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$restaurants = [];
$rows = isset($register['rows']) ? $register['rows'] : array();
foreach ($rows as $row) {
    $assoc = pyrusRowToAssoc($row, $columnMap);
    $name = isset($assoc['Ресторан']) ? trim($assoc['Ресторан']) : '';
    if ($name === '') {
        continue;
    }
    foreach ($allowedNames as $needle) {
        if (strcasecmp($needle, $name) == 0) {
            $id = null;
            if (isset($row['id'])) {
                $id = $row['id'];
            } elseif (isset($row['task_id'])) {
                $id = $row['task_id'];
            } else {
                $id = uniqid('rest_');
            }
            $restaurants[] = array(
                "id" => $id,
                "name" => $name
            );
            // Не break — нужны все строки с совпадающим названием
        }
    }
}

echo json_encode(["success" => true, "restaurants" => $restaurants], JSON_UNESCAPED_UNICODE);
?>