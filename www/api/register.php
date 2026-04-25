<?php
// Убираем отладочный error_log в продакшене
// error_log("Raw input: " . file_get_contents("php://input"));

require_once __DIR__ . '/../config.php';
header("Content-Type: application/json; charset=utf-8");

try {
    $pdo = createPdoUtf8();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "DB connection error"]);
    exit;
}

// --- ЧТЕНИЕ ЗАПРОСА ОТ ФРОНТА ---
$input = json_decode(file_get_contents("php://input"), true);

// --- ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ ---
$firstName = isset($input["first_name"]) ? trim($input["first_name"]) : "";
$lastName  = isset($input["last_name"])  ? trim($input["last_name"])  : "";
$email     = isset($input["email"])      ? trim($input["email"])      : "";
$phone     = isset($input["phone"])      ? trim($input["phone"])      : "";
$password  = isset($input["password"])   ? trim($input["password"])   : "";
$position  = isset($input["position"])   ? trim($input["position"])   : "";
$network   = isset($input["network"])    ? trim($input["network"])    : "";

// Проверка на обязательные поля
if (!$firstName || !$lastName || !$email || !$phone || !$password) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Missing required fields"]);
    exit;
}

// Дополнительная валидация (пример)
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid email format"]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Password must be at least 6 characters long"]);
    exit;
}

// --- ПРОВЕРКА СУЩЕСТВОВАНИЯ ПОЛЬЗОВАТЕЛЯ ---
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email OR phone = :phone");
    $stmt->execute([":email" => $email, ":phone" => $phone]);
    if ($stmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(["success" => false, "error" => "User already exists"]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Check user error"]);
    exit;
}

// --- ХЭШИРОВАНИЕ ПАРОЛЯ ---
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

// --- СОХРАНЕНИЕ В БАЗУ ---
try {
    $stmt = $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, phone, password_hash, position, network, created_at)
        VALUES (:first_name, :last_name, :email, :phone, :password_hash, :position, :network, NOW())
    ");
    $stmt->execute([
        ":first_name"    => $firstName,
        ":last_name"     => $lastName,
        ":email"         => $email,
        ":phone"         => $phone,
        ":password_hash" => $passwordHash,
        ":position"      => $position,
        ":network"       => $network
    ]);
    $userId = $pdo->lastInsertId();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Insert user error"]);
    exit;
}

// --- СОЗДАНИЕ СЕССИИ ---
try {
    $secure = false;
    $randomBytes = openssl_random_pseudo_bytes(32, $secure);
    if ($randomBytes === false || !$secure) {
        // Обработка ошибки генерации случайных байтов
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "Failed to generate secure token"]);
        exit;
}
$token = bin2hex($randomBytes);
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
        "network"   => $network
    ]
], JSON_UNESCAPED_UNICODE);
?>