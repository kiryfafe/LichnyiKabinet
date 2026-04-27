<?php
/**
 * API входа для Pyrus-only версии
 * Без MySQL - данные хранятся в JSON файлах
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting для защиты от brute-force (10 попыток в минуту)
if (!checkRateLimit('login', 10, 60)) {
    exit;
}

// ==================== ЧТЕНИЕ ЗАПРОСА ОТ ФРОНТА ====================
$rawInput = file_get_contents("php://input");
if ($rawInput === "" || $rawInput === false) {
    http_response_code(400);
    echo json_encode(array("success" => false, "error" => "Empty request body"));
    exit;
}

$input = json_decode($rawInput, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(array("success" => false, "error" => "Invalid JSON"));
    exit;
}

$identifier = isset($input["identifier"]) ? trim($input["identifier"]) : "";
$password   = isset($input["password"]) ? trim($input["password"]) : "";

if ($identifier === "" || $password === "") {
    Logger::security("Login attempt with missing credentials", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
    http_response_code(400);
    echo json_encode(array("success" => false, "error" => "Missing credentials"));
    exit;
}

// ==================== ПОИСК ПОЛЬЗОВАТЕЛЯ ====================
$usersData = loadUsers();
$user = null;

foreach ($usersData['users'] as $u) {
    if ($u['email'] === $identifier || $u['phone'] === $identifier) {
        $user = $u;
        break;
    }
}

if (!$user) {
    Logger::security("Login attempt for non-existent user", [
        'identifier' => sanitizeString($identifier),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    http_response_code(401);
    echo json_encode(array("success" => false, "error" => "User not found"));
    exit;
}

// ==================== ПРОВЕРКА ПАРОЛЯ ====================
if (!password_verify($password, $user["password_hash"])) {
    Logger::security("Failed login attempt (wrong password)", [
        'identifier' => sanitizeString($identifier),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_id' => $user['id']
    ]);
    http_response_code(401);
    echo json_encode(array("success" => false, "error" => "Invalid password"));
    exit;
}

// ==================== СОЗДАНИЕ СЕССИИ ====================
try {
    $token = createSession($user['id']);
    Logger::audit('USER_LOGIN', $user['id'], ['method' => 'password']);
} catch (Exception $e) {
    Logger::critical("Failed to create session in login", ['error' => $e->getMessage(), 'user_id' => $user['id']]);
    http_response_code(500);
    echo json_encode(array("success" => false, "error" => "Failed to create session"));
    exit;
}

// ==================== ВОЗВРАТ ДАННЫХ ====================
echo json_encode(array(
    "success" => true,
    "token"   => $token,
    "user"    => array(
        "id"         => $user["id"],
        "firstName"  => $user["first_name"],
        "lastName"   => $user["last_name"],
        "fullName"   => $user["first_name"] . " " . $user["last_name"],
        "phone"      => $user["phone"],
        "email"      => $user["email"],
        "position"   => $user["position"],
        "network"    => $user["network"],
        "role"       => isset($user["role"]) ? $user["role"] : "user"
    )
), JSON_UNESCAPED_UNICODE);

Logger::info("Successful login", [
    'user_id' => $user['id'],
    'identifier' => sanitizeString($identifier),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);
?>
