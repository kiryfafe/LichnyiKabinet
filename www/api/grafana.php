<?php
require_once __DIR__ . '/../config.php';

if (!defined('GRAFANA_URL') || !defined('GRAFANA_TOKEN')) {
    http_response_code(500);
    echo "Configuration error";
    exit;
}

$grafanaUrl = GRAFANA_URL;
$grafanaToken = GRAFANA_TOKEN;

// --- ПРОВЕРКА ТОКЕНА ПОЛЬЗОВАТЕЛЯ ---
$headers = getallheaders();
$authHeader = isset($headers["Authorization"]) ? $headers["Authorization"] : "";
if (!preg_match("/Bearer\s+(.*)$/i", $authHeader, $matches)) {
    http_response_code(401);
    echo "Unauthorized (no token)";
    exit;
}
$userToken = $matches[1];

try {
    $pdo = createPdoUtf8();
    $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE token = ?");
    $stmt->execute([$userToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        echo "Forbidden (invalid token)";
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo "Database error";
    exit;
}

// --- ЧТЕНИЕ И ВАЛИДАЦИЯ ПАРАМЕТРА PATH ---
$requestedPath = isset($_GET["path"]) ? $_GET["path"] : "/";
// Пример простой валидации: разрешаем только пути, начинающиеся с /d/ (для дашбордов) или /api/
// Это НЕ полное решение, нужно адаптировать под конкретные нужды
$allowedPrefixes = ['/d/', '/api/'];
$isValidPath = false;

foreach ($allowedPrefixes as $prefix) {
    if (strpos($requestedPath, $prefix) === 0) {
        $isValidPath = true;
        break;
    }
}

if (!$isValidPath) {
    http_response_code(400);
    echo "Bad Request: Invalid path";
    exit;
}

// Дополнительно: убедимся, что путь не содержит '../' или другие потенциально опасные элементы
if (strpos($requestedPath, '../') !== false || strpos($requestedPath, '..\\') !== false) {
    http_response_code(400);
    echo "Bad Request: Invalid path";
    exit;
}

$url = rtrim($grafanaUrl, "/") . $requestedPath;

// --- CURL ЗАПРОС ---
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $grafanaToken
]);
// ВАЖНО: Убедитесь, что CURLOPT_FOLLOWLOCATION не включен, если вы не контролируете $url!
// В идеале, используйте proxy_pass веб-сервера, а не PHP для этого.
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($http_code >= 400) {
    http_response_code($http_code);
    echo "Grafana Error"; // В целях безопасности не стоит возвращать детали от Grafana напрямую
    exit;
}

// ВАЖНО: Не возвращайте HTML напрямую, если Grafana не настроена на возврат безопасного контента.
// Лучше использовать iframe с прямым URL или proxy_pass веб-сервера.
header("Content-Type: text/html; charset=utf-8");
echo $response; // Это потенциальная XSS уязвимость, если Grafana возвращает вредоносный JS
?>