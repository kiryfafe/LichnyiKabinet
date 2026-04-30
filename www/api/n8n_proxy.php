<?php
/**
 * PHP-прокси для запросов к n8n webhook
 * Обходит CORS ограничения, перенаправляя запросы через сервер
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Обрабатываем preflight запрос
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Загружаем конфигурацию
require_once __DIR__ . '/../config.php';

// Получаем URL webhook из конфигурации
$webhookUrl = defined('WEBHOOK_URL') ? WEBHOOK_URL : '';

if (empty($webhookUrl)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'WEBHOOK_URL not configured'
    ]);
    exit();
}

// Получаем endpoint из query параметров
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

if (empty($endpoint)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Endpoint parameter is required'
    ]);
    exit();
}

// Формируем полный URL
$url = rtrim($webhookUrl, '/') . '/' . ltrim($endpoint, '/');

// Логируем запрос (опционально)
error_log("[N8N_PROXY] Request to: $url");

// Инициализируем cURL
$ch = curl_init($url);

// Настраиваем cURL
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// Для POST запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    $inputData = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $inputData);
    error_log("[N8N_PROXY] POST data: " . substr($inputData, 0, 500));
}

// Добавляем заголовки
$headers = ['Content-Type: application/json'];

// Если есть Authorization header, передаем его
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Выполняем запрос
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

// Логируем ошибку или ответ
if ($curlError) {
    error_log("[N8N_PROXY] cURL error: $curlError");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Proxy request failed: ' . $curlError
    ]);
    exit();
}

// Возвращаем ответ от n8n
http_response_code($httpCode);
echo $response;
