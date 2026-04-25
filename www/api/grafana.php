<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_middleware.php';

if (!defined('GRAFANA_URL') || !defined('GRAFANA_TOKEN')) {
    http_response_code(500);
    echo "Configuration error";
    exit;
}

// Rate limiting
if (!checkRateLimit('grafana', 30, 60)) {
    exit;
}

$grafanaUrl = GRAFANA_URL;
$grafanaToken = GRAFANA_TOKEN;

// --- ПРОВЕРКА ТОКЕНА ПОЛЬЗОВАТЕЛЯ ---
$user = authenticateUser();
if ($user === null) {
    exit;
}

// --- ЧТЕНИЕ И ВАЛИДАЦИЯ ПАРАМЕТРА PATH ---
$requestedPath = isset($_GET["path"]) ? $_GET["path"] : "/";
// Разрешаем только пути для дашбордов
$allowedPrefixes = ['/d/', '/api/dashboards/'];
$isValidPath = false;

foreach ($allowedPrefixes as $prefix) {
    if (strpos($requestedPath, $prefix) === 0) {
        $isValidPath = true;
        break;
    }
}

if (!$isValidPath) {
    http_response_code(400);
    echo "Bad Request: Invalid path. Only dashboard paths are allowed.";
    exit;
}

// Защита от path traversal
if (strpos($requestedPath, '../') !== false || strpos($requestedPath, '..\\') !== false || strpos($requestedPath, '//') !== false) {
    logError("Invalid Grafana path attempt: " . $requestedPath);
    http_response_code(400);
    echo "Bad Request: Invalid path";
    exit;
}

$url = rtrim($grafanaUrl, "/") . $requestedPath;

// --- CURL ЗАПРОС ---
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $grafanaToken,
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    logError("Grafana request error: " . $error);
    http_response_code(502);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "error" => "Grafana service unavailable"]);
    exit;
}

if ($http_code >= 400) {
    logError("Grafana HTTP error: $http_code for path: $requestedPath");
    http_response_code($http_code);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "error" => "Grafana error"]);
    exit;
}

// Возвращаем JSON вместо HTML для безопасности
header("Content-Type: application/json; charset=utf-8");
echo $response;
?>