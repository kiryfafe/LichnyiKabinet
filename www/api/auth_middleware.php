<?php
/**
 * Common authentication middleware
 * Проверяет Bearer токен и возвращает данные пользователя или завершает запрос
 */

require_once __DIR__ . '/../config.php';

/**
 * Проверка токена и получение данных пользователя
 * @return array|null Возвращает массив с данными пользователя или null если токен невалиден
 */
function authenticateUser() {
    try {
        $pdo = createPdoUtf8();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "DB connection error"]);
        return null;
    }

    $headers = getallheaders();
    if (!isset($headers["Authorization"])) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Missing token"]);
        return null;
    }

    list($type, $token) = explode(" ", $headers["Authorization"], 2);
    if (strtolower($type) !== "bearer" || !$token) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid token format"]);
        return null;
    }

    $stmt = $pdo->prepare("SELECT u.*, s.expires_at 
                           FROM user_sessions s 
                           JOIN users u ON u.id = s.user_id
                           WHERE s.token = :token AND s.expires_at > NOW()
                           LIMIT 1");
    $stmt->execute([":token" => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid or expired token"]);
        return null;
    }

    return $user;
}

/**
 * Проверка что пользователь имеет роль администратора
 * @param array $user Данные пользователя
 * @return bool
 */
function isAdmin($user) {
    return isset($user['role']) && strtolower($user['role']) === 'admin';
}

/**
 * Валидация входных данных
 * @param array $input Входные данные
 * @param array $required Список обязательных полей
 * @return array|bool Возвращает массив ошибок или true если всё ок
 */
function validateInput($input, $required) {
    $errors = [];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            $errors[] = "Field '$field' is required";
        }
    }
    return empty($errors) ? true : $errors;
}

/**
 * Санитизация строковых данных
 * @param string $data
 * @return string
 */
function sanitizeString($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Логирование ошибок (можно расширить до записи в файл)
 * @param string $message
 * @param string $level
 */
function logError($message, $level = 'ERROR') {
    error_log("[$level] " . date('Y-m-d H:i:s') . " - " . $message);
}

// Rate limiting: простая защита от brute-force (30 запросов в минуту на IP)
function checkRateLimit($action = 'api', $limit = 30, $window = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    // Используем файл для хранения счётчика (в продакшене лучше Redis/Memcached)
    $tmpDir = sys_get_temp_dir() . '/lk_rate_limit';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }
    
    $file = $tmpDir . '/' . md5($key);
    $now = time();
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($now - $data['time']) < $window) {
            if ($data['count'] >= $limit) {
                http_response_code(429);
                echo json_encode(["success" => false, "error" => "Too many requests. Try again later."]);
                return false;
            }
            $data['count']++;
            file_put_contents($file, json_encode($data));
            return true;
        }
    }
    
    file_put_contents($file, json_encode(['time' => $now, 'count' => 1]));
    return true;
}

/**
 * Генерация безопасного токена с поддержкой старых версий PHP
 * @param int $length Длина токена в байтах
 * @return string
 */
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
