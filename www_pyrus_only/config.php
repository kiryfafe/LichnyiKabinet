<?php
function loadEnv($path) {
    if (!file_exists($path)) {
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

// Pyrus API credentials - обязательные переменные
$pyrus_token = isset($_ENV['PYRUS_TOKEN']) ? $_ENV['PYRUS_TOKEN'] : null;
$pyrus_login = isset($_ENV['PYRUS_LOGIN']) ? $_ENV['PYRUS_LOGIN'] : null;
$pyrus_security_key = isset($_ENV['PYRUS_SECURITY_KEY']) ? $_ENV['PYRUS_SECURITY_KEY'] : null;

// Pyrus Form IDs
$pyrus_tasks_form_id = isset($_ENV['PYRUS_TASKS_FORM_ID']) ? $_ENV['PYRUS_TASKS_FORM_ID'] : '1463678';
$pyrus_restaurants_form_id = isset($_ENV['PYRUS_RESTAURANTS_FORM_ID']) ? $_ENV['PYRUS_RESTAURANTS_FORM_ID'] : '1310341';
$pyrus_registration_form_id = isset($_ENV['PYRUS_REGISTRATION_FORM_ID']) ? $_ENV['PYRUS_REGISTRATION_FORM_ID'] : '2346974';

// Проверяем наличие хотя бы одного способа авторизации в Pyrus
if ((!$pyrus_token) && (!$pyrus_login || !$pyrus_security_key)) {
    http_response_code(500);
    die('Configuration error: Missing PYRUS_TOKEN or PYRUS_LOGIN/PYRUS_SECURITY_KEY.');
}

// Определяем константы
define('PYRUS_TOKEN', $pyrus_token);
define('PYRUS_LOGIN', $pyrus_login);
define('PYRUS_SECURITY_KEY', $pyrus_security_key);
define('PYRUS_TASKS_FORM_ID', $pyrus_tasks_form_id);
define('PYRUS_RESTAURANTS_FORM_ID', $pyrus_restaurants_form_id);
define('PYRUS_REGISTRATION_FORM_ID', $pyrus_registration_form_id);

/**
 * Выполняет запрос к Pyrus API.
 * Поддерживает два способа авторизации:
 * 1. Готовый токен (PYRUS_TOKEN)
 * 2. Логин + security_key (автоматическое получение токена)
 */
function pyrusRequest($method, $path, $body = null)
{
    // 1. Если заранее выдан готовый токен, используем его
    $token = PYRUS_TOKEN;

    // 2. Если токена нет, но есть login + security_key — получаем токен через /auth
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

/**
 * Создает задачу в Pyrus для новой формы регистрации (форма 2346974).
 * @param array $userData Данные пользователя
 * @return array Ответ от API Pyrus
 */
function pyrusCreateRegistrationTask(array $userData)
{
    // Получаем структуру формы для маппинга колонок
    $register = pyrusFetchRegister(PYRUS_REGISTRATION_FORM_ID);
    $columnMap = pyrusBuildColumnMap($register);
    
    // Формируем ячейки задачи согласно маппингу
    $cells = [];
    
    $fieldMapping = [
        'Имя' => 'first_name',
        'Фамилия' => 'last_name',
        'Телефон' => 'phone',
        'Эл. почта' => 'email',
        'Должность' => 'position',
        'Заведения' => 'network'
    ];
    
    foreach ($fieldMapping as $columnName => $dbField) {
        $colId = isset($columnMap[$columnName]) ? $columnMap[$columnName] : null;
        $value = isset($userData[$dbField]) ? trim($userData[$dbField]) : '';
        
        if ($colId !== null && $value !== '') {
            $cells[] = [
                'column_id' => $colId,
                'value' => $value
            ];
        }
    }
    
    // Формируем заголовок задачи
    $firstName = isset($userData['first_name']) ? trim($userData['first_name']) : '';
    $lastName = isset($userData['last_name']) ? trim($userData['last_name']) : '';
    $fullName = trim($firstName . ' ' . $lastName);
    $title = $fullName !== '' ? 'Регистрация: ' . $fullName : 'Новая регистрация';
    
    // Создаем задачу через API Pyrus
    $taskData = [
        'form_id' => PYRUS_REGISTRATION_FORM_ID,
        'title' => $title,
        'cells' => $cells
    ];
    
    return pyrusRequest('POST', '/tasks', $taskData);
}

// Устанавливаем внутреннюю кодировку PHP
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding("UTF-8");
}
?>
