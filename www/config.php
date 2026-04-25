<?php
function loadEnv($path) {
    if (!file_exists($path)) {
        // Логика на случай, если .env отсутствует
        error_log("Warning: .env file not found at $path");
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
    }
}

loadEnv(__DIR__ . '/.env');

// Проверяем существование переменных, иначе используем значения по умолчанию или бросаем исключение
$db_host = isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : null;
$db_name = isset($_ENV['DB_NAME']) ? $_ENV['DB_NAME'] : null;
$db_user = isset($_ENV['DB_USER']) ? $_ENV['DB_USER'] : null;
$db_pass = isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : null;
$grafana_url = isset($_ENV['GRAFANA_URL']) ? $_ENV['GRAFANA_URL'] : null;
$grafana_token = isset($_ENV['GRAFANA_TOKEN']) ? $_ENV['GRAFANA_TOKEN'] : null;
$pyrus_token = isset($_ENV['PYRUS_TOKEN']) ? $_ENV['PYRUS_TOKEN'] : null;
$pyrus_login = isset($_ENV['PYRUS_LOGIN']) ? $_ENV['PYRUS_LOGIN'] : null;
$pyrus_security_key = isset($_ENV['PYRUS_SECURITY_KEY']) ? $_ENV['PYRUS_SECURITY_KEY'] : null;

if (!$db_host || !$db_name || !$db_user || $db_pass === null || !$grafana_url || !$grafana_token) {
    // Более безопасно, чем просто ошибка, но в реальном приложении нужна логика обработки
    http_response_code(500);
    die('Configuration error: Missing required environment variables.');
}

// Определяем константы
define('DB_HOST', $db_host);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);
define('GRAFANA_URL', $grafana_url);
define('GRAFANA_TOKEN', $grafana_token);
define('PYRUS_TOKEN', $pyrus_token);
define('PYRUS_LOGIN', $pyrus_login);
define('PYRUS_SECURITY_KEY', $pyrus_security_key);

/**
 * Создает PDO с явной установкой UTF-8/utf8mb4, чтобы кириллица не искажалась.
 */
function createPdoUtf8()
{
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ];

    // Если доступна опция и драйвер, передадим INIT_COMMAND (не во всех сборках есть MYSQL_ATTR_INIT_COMMAND).
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    // Дополнительно фиксируем кодировку соединения на случай строгих настроек сервера.
    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
    return $pdo;
}

/**
 * Выполняет запрос к Pyrus API. Требует PYRUS_TOKEN в .env.
 */
function pyrusRequest($method, $path, $body = null)
{
    // 1) Если заранее выдан готовый токен, используем его
    $token = PYRUS_TOKEN;

    // 2) Если токена нет, но есть login + security_key — получаем токен через /auth
    if (!$token) {
        if (!PYRUS_LOGIN || !PYRUS_SECURITY_KEY) {
            throw new Exception("Missing PYRUS_TOKEN or PYRUS_LOGIN/PYRUS_SECURITY_KEY");
        }

        $authCh = curl_init('https://api.pyrus.com/v4/auth');
        curl_setopt($authCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($authCh, CURLOPT_POST, true);
        curl_setopt($authCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($authCh, CURLOPT_POSTFIELDS, json_encode([
            'login' => PYRUS_LOGIN,
            'security_key' => PYRUS_SECURITY_KEY,
        ], JSON_UNESCAPED_UNICODE));

        $authResp = curl_exec($authCh);
        if ($authResp === false) {
            throw new Exception("Pyrus auth error: " . curl_error($authCh));
        }
        $authStatus = curl_getinfo($authCh, CURLINFO_HTTP_CODE);
        curl_close($authCh);

        $authData = json_decode($authResp, true);
        if ($authStatus >= 400 || !is_array($authData) || empty($authData['access_token'])) {
            throw new Exception("Pyrus auth failed: HTTP $authStatus");
        }
        $token = $authData['access_token'];
    }

    $url = 'https://api.pyrus.com/v4' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("Pyrus request error: " . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($status >= 400) {
        $message = is_array($data) && isset($data["error"]) ? $data["error"] : "HTTP $status";
        throw new Exception("Pyrus error: " . $message);
    }
    return $data;
}

/**
 * Возвращает register-данные формы Pyrus.
 */
function pyrusFetchRegister($formId)
{
    return pyrusRequest('GET', '/forms/' . $formId . '/register');
}

/**
 * Строит карту column_name => column_id по структуре register.
 */
function pyrusBuildColumnMap(array $register)
{
    $map = [];
    if (!empty($register['columns'])) {
        foreach ($register['columns'] as $col) {
            if (isset($col['name'], $col['id'])) {
                $map[$col['name']] = $col['id'];
            }
        }
    }
    return $map;
}

/**
 * Преобразует одну строку register в ассоциативный массив "Название столбца" => значение.
 */
function pyrusRowToAssoc(array $row, array $columnMap)
{
    $assoc = [];
    if (empty($row['cells'])) {
        return $assoc;
    }
    foreach ($row['cells'] as $cell) {
        $colId = isset($cell['column_id']) ? $cell['column_id'] : null;
        $value = isset($cell['value']) ? $cell['value'] : null;
        if ($colId === null) {
            continue;
        }
        $name = array_search($colId, $columnMap, true);
        if ($name === false) {
            continue;
        }
        $assoc[$name] = $value;
    }
    return $assoc;
}

// Явно устанавливаем внутреннюю кодировку PHP, если доступно расширение mbstring.
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding("UTF-8");
}
?>