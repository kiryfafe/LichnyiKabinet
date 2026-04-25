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

// ==================== ОБНОВЛЕНИЕ ПРОФИЛЯ ====================
$input = json_decode(file_get_contents("php://input"), true);
$firstName = isset($input["first_name"]) ? trim($input["first_name"]) : "";
$lastName  = isset($input["last_name"])  ? trim($input["last_name"])  : "";

if (!$firstName || !$lastName) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "First name and last name required"]);
    exit;
}

// Добавляем валидацию длины и/или содержания
$maxNameLength = 100; // Пример максимальной длины
if (strlen($firstName) > $maxNameLength || strlen($lastName) > $maxNameLength) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name is too long"]);
    exit;
}

// Проверка на недопустимые символы (пример: только буквы, пробелы, дефисы, апострофы)
if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $firstName) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $lastName)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name contains invalid characters"]);
    exit;
}

$stmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name WHERE id = :id");
$stmt->execute([
    ":first_name" => $firstName,
    ":last_name"  => $lastName,
    ":id"         => $user["id"]
]);