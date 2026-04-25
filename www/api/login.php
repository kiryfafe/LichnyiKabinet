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

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
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
    http_response_code(500);
    echo json_encode(array("success" => false, "error" => "Database query error"));
    exit;
}

if (!$user) {
    http_response_code(401);
    echo json_encode(array("success" => false, "error" => "User not found"));
    exit;
}

// ==================== ПРОВЕРКА ПАРОЛЯ ====================
if (!password_verify($password, $user["password_hash"])) {
    http_response_code(401);
    echo json_encode(array("success" => false, "error" => "Invalid password"));
    exit;
}

// ==================== СОЗДАНИЕ СЕССИИ ====================
// Генерация случайного токена с поддержкой старых версий PHP
function generate_secure_token($length = 32) {
    // Современный способ, если доступен
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($length));
    }
    // Fallback для старых версий PHP
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes !== false && $strong) {
            return bin2hex($bytes);
        }
    }
    // Самый простой (менее безопасный, но рабочий) резервный вариант
    return bin2hex(md5(uniqid(mt_rand(), true), true));
}

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
} catch (Exception $e) {
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
        "network"    => $user["network"]
    )
), JSON_UNESCAPED_UNICODE);
?>