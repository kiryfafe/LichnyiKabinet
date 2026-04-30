<?php
/**
 * Конфигурационный скрипт для передачи переменных окружения в JavaScript
 * Подключается в начале HTML-страниц перед загрузкой JS-скриптов
 */

// Загружаем .env файл
require_once __DIR__ . '/config.php';

// Устанавливаем заголовок Content-Type для корректной работы с UTF-8
header('Content-Type: text/html; charset=utf-8');

// Передаем переменные окружения в JavaScript через data-атрибут или inline-скрипт
$webhookUrl = isset($_ENV['WEBHOOK_URL']) ? $_ENV['WEBHOOK_URL'] : '';
$n8nCorsOrigin = isset($_ENV['N8N_CORS_ORIGIN']) ? $_ENV['N8N_CORS_ORIGIN'] : '';
?>
<script>
  // Передаем конфигурацию из .env в JavaScript
  window.N8N_CONFIG_ENV = {
    WEBHOOK_URL: <?php echo json_encode($webhookUrl); ?>,
    N8N_CORS_ORIGIN: <?php echo json_encode($n8nCorsOrigin); ?>
  };
  
  // Устанавливаем глобальную переменную для совместимости с n8n_api.js
  window.N8N_WEBHOOK_URL = window.N8N_CONFIG_ENV.WEBHOOK_URL;
  
  console.log('[CONFIG] Loaded from .env:', window.N8N_CONFIG_ENV);
</script>
