<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// ============================================
// ОСНОВНАЯ ЛОГИКА РЕГИСТРАЦИИ
// ============================================

// Rate limiting для регистрации (строже - 5 запросов в минуту)
if (!checkRateLimit('register', 5, 60)) {
    exit;
}

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
    Logger::error("DB connection error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB connection error"]);
    exit;
}

// --- ЧТЕНИЕ ЗАПРОСА ОТ ФРОНТА ---
$inputRaw = file_get_contents("php://input");
Logger::debug("Registration request received", ['raw_input' => substr($inputRaw, 0, 500)]);

$input = json_decode($inputRaw, true);

if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
    Logger::error("Invalid JSON input", ['error' => json_last_error_msg(), 'raw' => substr($inputRaw, 0, 200)]);
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid JSON format"]);
    exit;
}

Logger::debug("Decoded input", ['input' => $input]);

// --- ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ ---
$firstName = isset($input["first_name"]) ? trim($input["first_name"]) : "";
$lastName  = isset($input["last_name"])  ? trim($input["last_name"])  : "";
$email     = isset($input["email"])      ? trim($input["email"])      : "";
$phone     = isset($input["phone"])      ? trim($input["phone"])      : "";
$password  = isset($input["password"])   ? trim($input["password"])   : "";
$position  = isset($input["position"])   ? trim($input["position"])   : "";
$network   = isset($input["network"])    ? trim($input["network"])    : "";

Logger::debug("Extracted fields", [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'phone' => $phone,
    'position' => $position,
    'network' => $network
]);

// Проверка на обязательные поля
$validation = validateInput($input, ['first_name', 'last_name', 'email', 'phone', 'password']);
if ($validation !== true) {
    Logger::error("Validation failed", ['errors' => $validation]);
    http_response_code(400);
    echo json_encode(["success" => false, "errors" => $validation]);
    exit;
}

// Дополнительная валидация
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid email format"]);
    exit;
}

// Усиливаем требования к паролю: минимум 8 символов
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Password must be at least 8 characters long"]);
    exit;
}

// Проверка сложности пароля (хотя бы одна цифра и одна буква)
if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Password must contain at least one letter and one number"]);
    exit;
}

// Валидация имени и фамилии
$maxNameLength = 100;
if (strlen($firstName) > $maxNameLength || strlen($lastName) > $maxNameLength) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name is too long"]);
    exit;
}

if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $firstName) || !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $lastName)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name contains invalid characters"]);
    exit;
}

// --- ПРОВЕРКА СУЩЕСТВОВАНИЯ ПОЛЬЗОВАТЕЛЯ ---
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email OR phone = :phone");
    $stmt->execute([":email" => $email, ":phone" => $phone]);
    if ($stmt->fetch()) {
        Logger::security("Registration attempt for existing user", ['email' => sanitizeString($email)]);
        http_response_code(409);
        echo json_encode(["success" => false, "error" => "User already exists"]);
        exit;
    }
} catch (Exception $e) {
    Logger::error("Check user error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Check user error"]);
    exit;
}

// --- ХЭШИРОВАНИЕ ПАРОЛЯ ---
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// --- СОХРАНЕНИЕ В БАЗУ ---
try {
    $stmt = $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, phone, password_hash, position, network, created_at, role)
        VALUES (:first_name, :last_name, :email, :phone, :password_hash, :position, :network, NOW(), 'user')
    ");
    $stmt->execute([
        ":first_name"    => sanitizeString($firstName),
        ":last_name"     => sanitizeString($lastName),
        ":email"         => sanitizeString($email),
        ":phone"         => sanitizeString($phone),
        ":password_hash" => $passwordHash,
        ":position"      => sanitizeString($position),
        ":network"       => sanitizeString($network)
    ]);
    $userId = $pdo->lastInsertId();
} catch (Exception $e) {
    Logger::error("Insert user error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Insert user error"]);
    exit;
}

// --- СОЗДАНИЕ СЕССИИ ---
try {
    $token = generate_secure_token(32);
    $expires_at = date("Y-m-d H:i:s", strtotime("+1 day"));

    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, token, ip_address, user_agent, created_at, expires_at)
        VALUES (:user_id, :token, :ip, :ua, NOW(), :expires)
    ");
    $stmt->execute([
        ":user_id" => $userId,
        ":token"   => $token,
        ":ip"      => isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : null,
        ":ua"      => isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : null,
        ":expires" => $expires_at
    ]);
} catch (Exception $e) {
    Logger::error("Create session error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Create session error"]);
    exit;
}

// --- ВОЗВРАТ ДАННЫХ ---
echo json_encode([
    "success" => true,
    "token"   => $token,
    "user"    => [
        "id"        => $userId,
        "firstName" => $firstName,
        "lastName"  => $lastName,
        "fullName"  => $firstName . " " . $lastName,
        "phone"     => $phone,
        "email"     => $email,
        "position"  => $position,
        "network"   => $network,
        "role"      => "user"
    ]
], JSON_UNESCAPED_UNICODE);

Logger::info("New user registered", ['email' => sanitizeString($email), 'user_id' => $userId]);
?>