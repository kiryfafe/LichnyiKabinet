# Инструкция по настройке CORS для n8n

## Проблема
При запросах с фронтенда (`https://lk.standartmaster.ru`) к webhook n8n (`https://n8n.standartmaster.ru/webhook`) возникает ошибка CORS:
```
Access to fetch at 'https://n8n.your-domain.com/webhook/register' from origin 'https://lk.standartmaster.ru' has been blocked by CORS policy
```

## Решение

### Вариант 1: Настройка CORS в n8n (рекомендуется)

1. **Для Docker-версии n8n:**
   Добавьте переменные окружения в docker-compose.yml или .env файл n8n:
   ```bash
   N8N_EDITOR_BASE_URL=https://n8n.standartmaster.ru
   WEBHOOK_URL=https://n8n.standartmaster.ru/webhook
   N8N_CORS_ORIGIN=https://lk.standartmaster.ru
   ```

2. **Для облачной версии n8n:**
   - Откройте настройки workflow в n8n
   - В узле Webhook добавьте заголовки ответа:
     ```
     Access-Control-Allow-Origin: https://lk.standartmaster.ru
     Access-Control-Allow-Methods: GET, POST, OPTIONS
     Access-Control-Allow-Headers: Content-Type, Authorization
     ```

### Вариант 2: Использование PHP-прокси

Создайте файл `/workspace/www/api/n8n_proxy.php`:

```php
<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$webhookUrl = getenv('WEBHOOK_URL') ?: 'https://n8n.standartmaster.ru/webhook';
$endpoint = $_GET['endpoint'] ?? '';
$url = $webhookUrl . '/' . ltrim($endpoint, '/');

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode);
echo $response;
?>
```

Затем измените `N8N_CONFIG.BASE_URL` в `n8n_api.js` на `/api/n8n_proxy.php?endpoint=`

### Вариант 3: Настройка веб-сервера (nginx)

Если n8n и ЛК находятся на одном сервере, настройте nginx:

```nginx
location /webhook/ {
    proxy_pass http://localhost:5678/webhook/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    
    # CORS headers
    add_header Access-Control-Allow-Origin "https://lk.standartmaster.ru" always;
    add_header Access-Control-Allow-Methods "GET, POST, OPTIONS" always;
    add_header Access-Control-Allow-Headers "Content-Type, Authorization" always;
    
    if ($request_method = 'OPTIONS') {
        add_header Access-Control-Allow-Origin "https://lk.standartmaster.ru";
        add_header Access-Control-Allow-Methods "GET, POST, OPTIONS";
        add_header Access-Control-Allow-Headers "Content-Type, Authorization";
        add_header Content-Length 0;
        add_header Content-Type text/plain;
        return 204;
    }
}
```

## Проверка

После настройки проверьте:

1. Откройте консоль браузера (F12)
2. Попробуйте зарегистрироваться или войти
3. Убедитесь, что нет ошибок CORS
4. Проверьте Network tab - запросы должны возвращать статус 200/201

## Важные замечания

- URL webhook должен заканчиваться на `/webhook` (без слэша в конце или со слэшем - важно соответствие)
- В `.env` файле укажите правильный URL вашего n8n сервера
- Для продакшена используйте HTTPS везде
- Переменная `N8N_CORS_ORIGIN` должна содержать домен вашего ЛК
