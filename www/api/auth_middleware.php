<?php
/**
 * Common authentication middleware
 * Проверяет Bearer токен и возвращает данные пользователя или завершает запрос
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

/**
 * Проверка токена и получение данных пользователя
 * @return array|null Возвращает массив с данными пользователя или null если токен невалиден
 */
function authenticateUser() {
    try {
        $pdo = createPdoUtf8();
    } catch (Exception $e) {
        Logger::critical("DB connection error in authenticateUser", ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(["success" => false, "error" => "DB connection error"]);
        return null;
    }

    $headers = getallheaders();
    if (!isset($headers["Authorization"])) {
        Logger::security("Missing Authorization header", ['ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown']);
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Missing token"]);
        return null;
    }

    list($type, $token) = explode(" ", $headers["Authorization"], 2);
    if (strtolower($type) !== "bearer" || !$token) {
        Logger::security("Invalid token format", ['ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown']);
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
        Logger::security("Invalid or expired token attempt", [
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown'
        ]);
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
 * Rate limiting: защита от brute-force с использованием файлового хранилища
 * В продакшене рекомендуется использовать Redis/Memcached
 * 
 * @param string $action Идентификатор действия (login, register, api и т.д.)
 * @param int $limit Максимальное количество запросов в окно времени
 * @param int $window Размер окна времени в секундах
 * @return bool true если запрос разрешён, false если превышен лимит
 */
function checkRateLimit($action = 'api', $limit = 30, $window = 60) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    // Директория для хранения счётчиков rate limit
    $tmpDir = sys_get_temp_dir() . '/lk_rate_limit';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0755, true);
    }
    
    $file = $tmpDir . '/' . md5($key);
    $now = time();
    
    // Блокировка файла для предотвращения race conditions
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        // Если не удалось открыть файл, разрешаем запрос (fail-open)
        Logger::warning("Failed to open rate limit file", ['file' => $file, 'ip' => $ip]);
        return true;
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        Logger::warning("Failed to lock rate limit file", ['file' => $file, 'ip' => $ip]);
        return true;
    }
    
    $shouldAllow = true;
    
    if (filesize($file) > 0) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($now - $data['time']) < $window) {
            if ($data['count'] >= $limit) {
                $shouldAllow = false;
                Logger::security("Rate limit exceeded", [
                    'action' => $action,
                    'ip' => $ip,
                    'count' => $data['count'],
                    'limit' => $limit
                ]);
            } else {
                $data['count']++;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data));
                fflush($fp);
            }
        } else {
            // Окно истекло, сбрасываем счётчик
            $data = ['time' => $now, 'count' => 1];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
        }
    } else {
        // Новый файл, создаём запись
        $data = ['time' => $now, 'count' => 1];
        fwrite($fp, json_encode($data));
        fflush($fp);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
    
    if (!$shouldAllow) {
        http_response_code(429);
        echo json_encode(["success" => false, "error" => "Too many requests. Try again later."]);
        return false;
    }
    
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
    Logger::warning("Using weak random generator for token", ['context' => 'generate_secure_token fallback']);
    return bin2hex(md5(uniqid(mt_rand(), true), true));
}

/**
 * Улучшенная функция логирования ошибок (обратная совместимость)
 * @deprecated Используйте Logger::error() напрямую
 * @param string $message
 * @param string $level
 */
// Функция logError удалена, так как уже определена в logger.php для предотвращения конфликта "Cannot redeclare"
?>