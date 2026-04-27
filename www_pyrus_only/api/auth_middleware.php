<?php
/**
 * Упрощенный auth_middleware для Pyrus-only версии
 * Без базы данных - используем сессию PHP и файл для хранения пользователей
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

// Файл для хранения пользователей (альтернатива БД)
define('USERS_FILE', dirname(__DIR__) . '/data/users.json');
define('SESSIONS_FILE', dirname(__DIR__) . '/data/sessions.json');

/**
 * Инициализация файлов данных
 */
function initDataFiles() {
    $dataDir = dirname(__DIR__) . '/data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0750, true);
    }
    
    if (!file_exists(USERS_FILE)) {
        @file_put_contents(USERS_FILE, json_encode(['users' => [], 'next_id' => 1], JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(SESSIONS_FILE)) {
        @file_put_contents(SESSIONS_FILE, json_encode(['sessions' => []], JSON_PRETTY_PRINT));
    }
}

/**
 * Загрузка пользователей из файла
 */
function loadUsers() {
    initDataFiles();
    $data = json_decode(file_get_contents(USERS_FILE), true);
    return $data ?: ['users' => [], 'next_id' => 1];
}

/**
 * Сохранение пользователей в файл
 */
function saveUsers($data) {
    initDataFiles();
    file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Загрузка сессий из файла
 */
function loadSessions() {
    initDataFiles();
    $data = json_decode(file_get_contents(SESSIONS_FILE), true);
    return $data ?: ['sessions' => []];
}

/**
 * Сохранение сессий в файл
 */
function saveSessions($data) {
    initDataFiles();
    file_put_contents(SESSIONS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Проверка токена и получение данных пользователя
 * @return array|null Возвращает массив с данными пользователя или null если токен невалиден
 */
function authenticateUser() {
    $headers = getallheaders();
    if (!isset($headers["Authorization"])) {
        Logger::security("Missing Authorization header", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Missing token"]);
        return null;
    }

    list($type, $token) = explode(" ", $headers["Authorization"], 2);
    if (strtolower($type) !== "bearer" || !$token) {
        Logger::security("Invalid token format", ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid token format"]);
        return null;
    }

    $sessionsData = loadSessions();
    $now = time();
    $user = null;
    $sessionKey = null;
    
    foreach ($sessionsData['sessions'] as $key => $session) {
        if ($session['token'] === $token && $session['expires_at'] > $now) {
            $sessionKey = $key;
            // Находим пользователя
            $usersData = loadUsers();
            foreach ($usersData['users'] as $u) {
                if ($u['id'] === $session['user_id']) {
                    $user = $u;
                    break;
                }
            }
            break;
        }
    }

    if (!$user) {
        Logger::security("Invalid or expired token attempt", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        http_response_code(401);
        echo json_encode(["success" => false, "error" => "Invalid or expired token"]);
        return null;
    }

    return $user;
}

/**
 * Создание новой сессии
 */
function createSession($userId) {
    $token = bin2hex(random_bytes(32));
    $expiresAt = time() + 86400; // 24 часа
    
    $sessionsData = loadSessions();
    $sessionsData['sessions'][] = [
        'token' => $token,
        'user_id' => $userId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => time(),
        'expires_at' => $expiresAt
    ];
    saveSessions($sessionsData);
    
    // Очистка старых сессий
    cleanupSessions();
    
    return $token;
}

/**
 * Очистка истекших сессий
 */
function cleanupSessions() {
    $sessionsData = loadSessions();
    $now = time();
    $sessionsData['sessions'] = array_filter($sessionsData['sessions'], function($s) use ($now) {
        return $s['expires_at'] > $now;
    });
    saveSessions($sessionsData);
}

/**
 * Валидация входных данных
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
 */
function sanitizeString($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Rate limiting: защита от brute-force
 */
function checkRateLimit($action = 'api', $limit = 30, $window = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    $tmpDir = sys_get_temp_dir() . '/lk_rate_limit';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0755, true);
    }
    
    $file = $tmpDir . '/' . md5($key);
    $now = time();
    
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return true;
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
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
            $data = ['time' => $now, 'count' => 1];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
        }
    } else {
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
?>
