<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting
if (!checkRateLimit('profile', 20, 60)) { // 20 запросов в минуту
    exit;
}

$user = authenticateUser();
if ($user === null) {
    exit;
}

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
    logError("DB connection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB connection error"]);
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
$maxNameLength = 100;
if (strlen($firstName) > $maxNameLength || strlen($lastName) > $maxNameLength) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name is too long"]);
    exit;
}

// Проверка на недопустимые символы
if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $firstName) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $lastName)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name contains invalid characters"]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE users SET first_name = :first_name, last_name = :last_name WHERE id = :id");
    $stmt->execute([
        ":first_name" => sanitizeString($firstName),
        ":last_name"  => sanitizeString($lastName),
        ":id"         => $user["id"]
    ]);
    
    echo json_encode(["success" => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    logError("Profile update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Failed to update profile"]);
}
?>