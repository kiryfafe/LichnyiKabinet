# Pyrus-only версия проекта

Эта версия проекта работает **только с API Pyrus** без использования MySQL/PHPMyAdmin.

## Отличия от оригинальной версии

### Удалено:
- ✅ Зависимость от MySQL базы данных
- ✅ Интеграция с PHPMyAdmin
- ✅ Интеграция с Grafana (не требуется для работы с Pyrus)
- ✅ Файлы `requests.php`, `profile.php`, `grafana.php`, `sync_pyrus.php`

### Изменено:
- ✅ **Хранение данных**: Пользователи и сессии хранятся в JSON файлах (`data/users.json`, `data/sessions.json`) вместо MySQL
- ✅ **Конфигурация**: Упрощенный `config.php` без подключения к БД
- ✅ **Аутентификация**: Упрощенный `auth_middleware.php` с файловым хранением сессий
- ✅ **Регистрация**: `register.php` создает задачу в Pyrus при регистрации

### Оставлено:
- ✅ Полная интеграция с Pyrus API
- ✅ Получение задач из форм Pyrus
- ✅ Получение списка ресторанов из Pyrus
- ✅ Создание задач регистрации в Pyrus
- ✅ Rate limiting для защиты от brute-force
- ✅ Логирование событий

## Установка

### 1. Требования
- PHP 7.4 или выше
- Расширения PHP: `curl`, `json`, `mbstring`
- Веб-сервер (Apache/Nginx)

### 2. Настройка

#### Шаг 1: Скопируйте проект
```bash
cp -r /workspace/www_pyrus_only /path/to/your/webroot/
```

#### Шаг 2: Настройте .env файл
Отредактируйте файл `.env` и укажите ваши данные Pyrus:

```env
# Pyrus API credentials (обязательно заполните хотя бы один способ)
PYRUS_LOGIN=your_login@example.com
PYRUS_SECURITY_KEY=your_security_key
# ИЛИ используйте готовый токен:
PYRUS_TOKEN=your_pyrus_token

# Pyrus Form IDs (измените на ваши ID форм)
PYRUS_TASKS_FORM_ID=1463678
PYRUS_RESTAURANTS_FORM_ID=1310341
PYRUS_REGISTRATION_FORM_ID=2346974
```

#### Шаг 3: Установите права доступа
```bash
chmod 755 /path/to/webroot/www_pyrus_only
chmod 777 /path/to/webroot/www_pyrus_only/data
chmod 777 /path/to/webroot/www_pyrus_only/logs
```

#### Шаг 4: Настройте веб-сервер

**Apache (.htaccess):**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.html [L]

# Защита файлов данных
<FilesMatch "\.(json)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

**Nginx:**
```nginx
location / {
    try_files $uri $uri/ /index.html;
}

# Защита файлов данных
location ~* /data/.*\.json$ {
    deny all;
}

# Защита логов
location ~* /logs/.*\.log$ {
    deny all;
}
```

## Структура проекта

```
www_pyrus_only/
├── .env                    # Конфигурационный файл (настройки Pyrus)
├── config.php              # Конфигурация приложения
├── index.html              # Главная страница
├── api/
│   ├── logger.php          # Система логирования
│   ├── auth_middleware.php # Аутентификация и авторизация
│   ├── login.php           # API входа
│   ├── register.php        # API регистрации
│   ├── restaurants.php     # API получения ресторанов из Pyrus
│   └── pyrus_tasks.php     # API получения задач из Pyrus
├── js/
│   ├── api.js              # Клиентский API слой
│   ├── auth.js             # Аутентификация
│   └── app.js              # Основное приложение
├── css/
│   ├── style.css           # Основные стили
│   └── registr.css         # Стили регистрации
├── pages/
│   ├── login.html          # Страница входа
│   └── register.html       # Страница регистрации
├── assets/                 # Статические файлы (иконки, изображения)
├── data/                   # Хранилище данных (JSON файлы)
│   ├── users.json          # Пользователи
│   └── sessions.json       # Сессии
└── logs/                   # Логи приложения
    ├── app_YYYY-MM-DD.log
    └── security_YYYY-MM-DD.log
```

## API Endpoints

### POST /api/register.php
Регистрация нового пользователя.
```json
{
    "first_name": "Иван",
    "last_name": "Иванов",
    "email": "ivan@example.com",
    "phone": "+79991234567",
    "password": "password123",
    "position": "Менеджер",
    "network": "Ресторан 1"
}
```

### POST /api/login.php
Вход пользователя.
```json
{
    "identifier": "ivan@example.com",
    "password": "password123"
}
```

### GET /api/restaurants.php
Получение списка ресторанов из Pyrus (требуется токен).

### GET /api/pyrus_tasks.php
Получение задач из Pyrus (требуется токен).

## Безопасность

- ✅ Хеширование паролей (bcrypt)
- ✅ Rate limiting для защиты от brute-force
- ✅ Bearer токены для аутентификации
- ✅ Маскирование чувствительных данных в логах
- ✅ Защита директорий data и logs
- ✅ Валидация входных данных
- ✅ Санитизация данных перед сохранением

## Важные замечания

1. **Файловое хранилище**: Эта версия использует JSON файлы для хранения пользователей и сессий. Для продакшена с большой нагрузкой рекомендуется использовать базу данных или Redis.

2. **Очистка сессий**: Старые сессии автоматически очищаются при создании новых сессий.

3. **Pyrus API лимиты**: Учитывайте ограничения API Pyrus при частых запросах.

4. **Логи**: Файлы логов хранятся в директории `logs/` с разбивкой по датам. Рекомендуется настроить ротацию логов.

## Поддержка Pyrus Form IDs

Измените ID форм в `.env` файле в соответствии с вашей организацией Pyrus:
- `PYRUS_TASKS_FORM_ID` - Форма для задач
- `PYRUS_RESTAURANTS_FORM_ID` - Форма для ресторанов
- `PYRUS_REGISTRATION_FORM_ID` - Форма для регистрации

## Лицензия

Копия оригинального проекта с изменениями для работы только с Pyrus API.
