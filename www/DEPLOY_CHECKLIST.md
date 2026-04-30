# Чек-лист развертывания на проде

## 1. Настройка .env файла

Скопируйте файл `.env` и заполните реальными значениями:

```bash
# Database Configuration
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

# Grafana Configuration
GRAFANA_URL=https://grafana.standartmaster.ru
GRAFANA_TOKEN=your_grafana_token

# Pyrus Configuration
PYRUS_TOKEN=your_pyrus_token
PYRUS_LOGIN=your_pyrus_login
PYRUS_SECURITY_KEY=your_pyrus_security_key

# N8N Webhook Configuration
WEBHOOK_URL=https://n8n.standartmaster.ru/webhook
N8N_CORS_ORIGIN=https://lk.standartmaster.ru
```

## 2. Настройка CORS (ВЫБЕРИТЕ ОДИН ВАРИАНТ)

### Вариант A: Использование PHP-прокси (рекомендуется для быстрого старта)

Файл `/api/n8n_proxy.php` уже создан. В `js/n8n_api.js` установлено `USE_PROXY = true`.

**Ничего дополнительно настраивать не нужно!**

### Вариант B: Настройка CORS на сервере n8n

Если хотите работать напрямую без прокси:

1. В файле `js/n8n_api.js` установите `USE_PROXY = false`

2. Настройте n8n (добавьте переменные окружения):
   ```bash
   N8N_EDITOR_BASE_URL=https://n8n.standartmaster.ru
   WEBHOOK_URL=https://n8n.standartmaster.ru/webhook
   N8N_CORS_ORIGIN=https://lk.standartmaster.ru
   ```

3. Для Docker добавьте в docker-compose.yml:
   ```yaml
   environment:
     - N8N_CORS_ORIGIN=https://lk.standartmaster.ru
   ```

### Вариант C: Настройка nginx proxy

Если n8n и ЛК на одном сервере, настройте nginx (см. CORS_SETUP.md).

## 3. Проверка работы

1. Откройте https://lk.standartmaster.ru/pages/register.html
2. Откройте консоль браузера (F12)
3. Заполните форму регистрации
4. Проверьте Network tab:
   - При использовании прокси: запрос идет на `/api/n8n_proxy.php?endpoint=/register`
   - При прямой работе: запрос идет на `https://n8n.standartmaster.ru/webhook/register`
5. Убедитесь, что нет ошибок CORS

## 4. Безопасность

- [ ] Установите сложные пароли для БД
- [ ] Используйте HTTPS везде
- [ ] Ограничьте доступ к админке n8n
- [ ] Настройте rate limiting
- [ ] Регулярно обновляйте зависимости

## 5. Мониторинг

Проверьте логи:
- PHP error log: `/var/log/php/error.log`
- Nginx access log: `/var/log/nginx/access.log`
- N8N logs: зависит от способа установки

## Troubleshooting

### Ошибка CORS
- Убедитесь, что `USE_PROXY = true` в `js/n8n_api.js`
- Или настройте CORS на сервере n8n

### Ошибка "WEBHOOK_URL not configured"
- Проверьте наличие `.env` файла
- Убедитесь, что `WEBHOOK_URL` задан в `.env`
- Перезагрузите PHP-FPM/Apache

### Стили не отображаются
- Проверьте пути к CSS файлам
- Убедитесь, что `env_config.php` подключен в `<head>`
- Очистите кэш браузера (Ctrl+F5)
