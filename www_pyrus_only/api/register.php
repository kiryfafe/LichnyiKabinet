<?php
/**
 * API регистрации для Pyrus-only версии
 * Без MySQL - данные хранятся в JSON файлах
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/auth_middleware.php';

header("Content-Type: application/json; charset=utf-8");

// Rate limiting для регистрации (строже - 5 запросов в минуту)
if (!checkRateLimit('register', 5, 60)) {
    exit;
}

// --- ЧТЕНИЕ ЗАПРОСА ОТ ФРОНТА ---
$inputRaw = file_get_contents("php://input");
Logger::debug("Registration request received", ['raw_input' => substr($inputRaw, 0, 500)]);

$input = json_decode($inputRaw, true);

if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
    Logger::error("Invalid JSON input", ['error' => json_last_error_msg()]);
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Invalid JSON format"]);
    exit;
}

// --- ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ ---
$firstName = isset($input["first_name"]) ? trim($input["first_name"]) : "";
$lastName  = isset($input["last_name"])  ? trim($input["last_name"])  : "";
$email     = isset($input["email"])      ? trim($input["email"])      : "";
$phone     = isset($input["phone"])      ? trim($input["phone"])      : "";
$password  = isset($input["password"])   ? trim($input["password"])   : "";
$position  = isset($input["position"])   ? trim($input["position"])   : "";
$network   = isset($input["network"])    ? trim($input["network"])    : "";

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

// Требования к паролю
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Password must be at least 8 characters long"]);
    exit;
}

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

if (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $firstName) || 
    !preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-\'\p{L}]+$/u', $lastName)) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Name contains invalid characters"]);
    exit;
}

// --- ПРОВЕРКА СУЩЕСТВОВАНИЯ ПОЛЬЗОВАТЕЛЯ ---
$usersData = loadUsers();
foreach ($usersData['users'] as $user) {
    if ($user['email'] === $email || $user['phone'] === $phone) {
        Logger::security("Registration attempt for existing user", ['email' => sanitizeString($email)]);
        http_response_code(409);
        echo json_encode(["success" => false, "error" => "User already exists"]);
        exit;
    }
}

// --- ХЭШИРОВАНИЕ ПАРОЛЯ ---
$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// --- СОХРАНЕНИЕ В ФАЙЛ ---
$newUserId = $usersData['next_id'];
$newUser = [
    'id' => $newUserId,
    'first_name' => sanitizeString($firstName),
    'last_name' => sanitizeString($lastName),
    'email' => sanitizeString($email),
    'phone' => sanitizeString($phone),
    'password_hash' => $passwordHash,
    'position' => sanitizeString($position),
    'network' => sanitizeString($network),
    'created_at' => date('Y-m-d H:i:s'),
    'role' => 'user'
];

$usersData['users'][] = $newUser;
$usersData['next_id']++;
saveUsers($usersData);

// --- СОЗДАНИЕ СЕССИИ ---
try {
    $token = createSession($newUserId);
} catch (Exception $e) {
    Logger::error("Create session error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Create session error"]);
    exit;
}

// --- СОЗДАНИЕ ЗАДАЧИ В PYRUS (если указаны рестораны) ---
$pyrusTaskCreated = false;
$pyrusTaskError = null;
if (!empty($network)) {
    try {
        $userData = [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone,
            'email'      => $email,
            'position'   => $position,
            'network'    => $network
        ];
        
        $pyrusResult = pyrusCreateRegistrationTask($userData);
        $pyrusTaskCreated = true;
        Logger::info("Pyrus task created", ['user_id' => $newUserId]);
    } catch (Exception $e) {
        $pyrusTaskError = $e->getMessage();
        Logger::error("Failed to create Pyrus task", ['user_id' => $newUserId, 'error' => $e->getMessage()]);
    }
}

// --- ВОЗВРАТ ДАННЫХ ---
$responseData = [
    "success" => true,
    "token"   => $token,
    "user"    => [
        "id"        => $newUserId,
        "firstName" => $firstName,
        "lastName"  => $lastName,
        "fullName"  => $firstName . " " . $lastName,
        "phone"     => $phone,
        "email"     => $email,
        "position"  => $position,
        "network"   => $network,
        "role"      => "user"
    ]
];

if (!empty($network)) {
    $responseData["pyrus_task"] = [
        "created" => $pyrusTaskCreated,
        "error" => $pyrusTaskError
    ];
}

echo json_encode($responseData, JSON_UNESCAPED_UNICODE);

Logger::info("New user registered", ['email' => sanitizeString($email), 'user_id' => $newUserId]);
?>
