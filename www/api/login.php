<?php
// Простой JSON API без продвинутых конструкций, максимально совместимый со старыми версиями PHP
error_reporting(E_ALL);
ini_set('display_errors', 0);
header("Content-Type: application/json; charset=utf-8");

if (!function_exists('http_response_code')) {
    function http_response_code($code = null) {
        static $stored = 200;
        if ($code !== null) {
            $stored = $code;
        }
        return $stored;
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

// Rate limiting для защиты от brute-force (строже для login - 10 попыток в минуту)
if (!checkRateLimit('login', 10, 60)) {
    exit;
}

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
    Logger::critical("DB connection error in login", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(array("success" => false, "error" => "DB connection error"));
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
try {
    $stmt = $pdo->prepare("
        SELECT * FROM users 
        WHERE email = :identifier OR phone = :identifier 
        LIMIT 1
    ");
    $stmt->execute(array(":identifier" => $identifier));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    Logger::critical("Database query error in login", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(array("success" => false, "error" => "Database query error"));
    exit;
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

// Проверка активности пользователя (если есть поле is_active)
if (isset($user['is_active']) && $user['is_active'] == 0) {
    Logger::security("Login attempt for inactive user", [
        'identifier' => sanitizeString($identifier),
        'user_id' => $user['id'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
    http_response_code(403);
    echo json_encode(array("success" => false, "error" => "Account is deactivated"));
    exit;
}

// ==================== СОЗДАНИЕ СЕССИИ ====================
try {
    $token = generate_secure_token(32);
    $expires_at = date("Y-m-d H:i:s", strtotime("+1 day"));

    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, token, ip_address, user_agent, created_at, expires_at)
        VALUES (:user_id, :token, :ip, :ua, NOW(), :expires)
    ");
    $stmt->execute(array(
        ":user_id" => $user["id"],
        ":token"   => $token,
        ":ip"      => isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : null,
        ":ua"      => isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : null,
        ":expires" => $expires_at
    ));
    
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