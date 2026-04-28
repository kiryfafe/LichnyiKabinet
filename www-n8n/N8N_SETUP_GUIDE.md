# Инструкция по настройке n8n для проекта

## Обзор архитектуры

```
Frontend (lk.standartmaster.ru)
        ↓
    n8n Webhooks (Backend)
        ↓
    ┌───────────────┬───────────────┬──────────────┐
    ↓               ↓               ↓              ↓
  MySQL         Pyrus API      Grafana API    JWT Auth
```

## Шаг 1: Подготовка n8n

### 1.1 Создайте credentials в n8n

#### MySQL Credential
- **Name**: `MySQL_LK_Database`
- **Host**: `u458257.mysql.masterhost.ru`
- **Database**: `u458257_lkdatabase`
- **User**: `u458257_lksm`
- **Password**: `SaCkyjA2ksc.a`
- **Port**: `3306`

#### Pyrus Credential
- **Name**: `Pyrus_API`
- **Login**: `[ваш логин Pyrus]`
- **Security Key**: `[ваш security key]`
- **Или Token**: `[если есть готовый токен]`

#### Grafana Credential
- **Name**: `Grafana_API`
- **URL**: `https://canieregen.beget.app`
- **Token**: `[ваш Grafana API token]`

#### JWT Secret
- **Name**: `JWT_Secret`
- **Secret**: Придумайте сложный секрет, например: `your-super-secret-jwt-key-2026-change-this`

---

## Шаг 2: Создание Workflow в n8n

### 2.1 Webhook для авторизации (Login)

**Endpoint**: `POST /login`

**Структура workflow:**

```
Webhook (POST /login)
    ↓
Read JSON Body (identifier, password)
    ↓
MySQL Query: SELECT * FROM users WHERE email=? OR phone=?
    ↓
Function: Проверка пароля (bcrypt.compare)
    ↓
IF Password Valid:
    ├→ Generate JWT Token
    ├→ Get User Data (без пароля)
    └→ Return: { success: true, user: {...}, token: "..." }
ELSE:
    └→ Return: { success: false, error: "Invalid credentials" }
```

**Пример кода для Function Node (генерация JWT):**
```javascript
// Установите npm пакет jsonwebtoken в настройках n8n
const jwt = require('jsonwebtoken');

const secret = 'your-super-secret-jwt-key-2026-change-this';
const userData = $input.first().json.user;

const token = jwt.sign(
  { 
    userId: userData.id, 
    email: userData.email,
    phone: userData.phone
  }, 
  secret, 
  { expiresIn: '24h' }
);

return {
  json: {
    success: true,
    user: {
      id: userData.id,
      first_name: userData.first_name,
      last_name: userData.last_name,
      full_name: `${userData.first_name} ${userData.last_name}`,
      email: userData.email,
      phone: userData.phone,
      position: userData.position,
      network: userData.network
    },
    token: token
  }
};
```

---

### 2.2 Webhook для регистрации (Register)

**Endpoint**: `POST /register`

**Структура workflow:**

```
Webhook (POST /register)
    ↓
Read JSON Body (first_name, last_name, email, phone, password, position, network)
    ↓
Validate Input (все поля заполнены, email формат, телефон формат)
    ↓
MySQL Query: SELECT * FROM users WHERE email=? OR phone=?
    ↓
IF User Exists:
    └→ Return: { success: false, error: "User already exists" }
ELSE:
    ├→ Hash Password (bcrypt.hash)
    ├→ MySQL Insert: INSERT INTO users (...)
    ├→ [Опционально] Pyrus: Создать задачу на регистрацию
    ├→ Generate JWT Token
    └→ Return: { success: true, user: {...}, token: "..." }
```

**Пример кода для хеширования пароля:**
```javascript
const bcrypt = require('bcryptjs');

const inputData = $input.first().json;
const saltRounds = 10;

const hashedPassword = await bcrypt.hash(inputData.password, saltRounds);

return {
  json: {
    ...inputData,
    password: hashedPassword
  }
};
```

---

### 2.3 Webhook для получения заведений (Restaurants)

**Endpoint**: `GET /restaurants`
**Headers**: `Authorization: Bearer <token>`

**Структура workflow:**

```
Webhook (GET /restaurants)
    ↓
Verify JWT Token (из Authorization header)
    ↓
IF Token Invalid:
    └→ Return 401: { success: false, error: "Unauthorized" }
ELSE:
    ├→ Pyrus: Get Form Register (ID: 1310341)
    ├→ Transform Data to [{id, name}, ...]
    └→ Return: { success: true, restaurants: [...] }
```

**Пример кода для Verify JWT (Function Node):**
```javascript
const jwt = require('jsonwebtoken');
const secret = 'your-super-secret-jwt-key-2026-change-this';

const authHeader = $request.header.authorization || '';
const token = authHeader.replace('Bearer ', '');

try {
  const decoded = jwt.verify(token, secret);
  
  return {
    json: {
      valid: true,
      userId: decoded.userId,
      email: decoded.email
    }
  };
} catch (error) {
  return {
    json: {
      valid: false,
      error: 'Invalid or expired token'
    }
  };
}
```

---

### 2.4 Webhook для получения задач (Tasks)

**Endpoint**: `GET /tasks?restaurant=<name>`
**Headers**: `Authorization: Bearer <token>`

**Структура workflow:**

```
Webhook (GET /tasks)
    ↓
Verify JWT Token
    ↓
IF Token Invalid:
    └→ Return 401
ELSE:
    ├→ Get Query Param: restaurant
    ├→ Pyrus: Get Form Register (ID: 1463678)
    ├→ Filter by Restaurant (если указан)
    ├→ Transform Data
    └→ Return: { success: true, tasks: [...] }
```

---

### 2.5 Webhook для заявок (Requests)

**GET /requests?userId=<id>** - получение списка заявок
**POST /requests** - создание новой заявки

---

### 2.6 Webhook для профиля (Profile)

**Endpoint**: `POST /profile`
**Headers**: `Authorization: Bearer <token>`

**Структура workflow:**

```
Webhook (POST /profile)
    ↓
Verify JWT Token
    ↓
IF Token Invalid:
    └→ Return 401
ELSE:
    ├→ Get User ID from token
    ├→ Get Update Data (first_name, last_name, etc.)
    ├→ MySQL Update: UPDATE users SET ... WHERE id=?
    ├→ Get Updated User Data
    └→ Return: { success: true, user: {...} }
```

---

### 2.7 Webhook для Grafana (Grafana Proxy)

**Endpoint**: `GET /grafana?path=<dashboard_path>`
**Headers**: `Authorization: Bearer <token>`

**Структура workflow:**

```
Webhook (GET /grafana)
    ↓
Verify JWT Token
    ↓
IF Token Invalid:
    └→ Return 401
ELSE:
    ├→ Get Path Parameter
    ├→ HTTP Request to Grafana API
    │   URL: https://canieregen.beget.app/api/...
    │   Headers: Authorization: Bearer <grafana_token>
    └→ Return Grafana Response
```

---

## Шаг 3: Настройка CORS в n8n

В файле `.env` n8n добавьте:

```bash
N8N_EDITOR_BASE_URL=https://n8n.your-domain.com
WEBHOOK_URL=https://n8n.your-domain.com/webhook/
N8N_HOST=n8n.your-domain.com
N8N_PORT=5678
N8N_PROTOCOL=https

# Разрешить CORS для вашего домена
N8N_CORS_ORIGIN=https://lk.standartmaster.ru
```

---

## Шаг 4: Обновление Frontend

### 4.1 В файле `index.html` замените подключение скриптов:

```html
<!-- Было -->
<script src="js/api.js"></script>
<script src="js/auth.js"></script>
<script src="js/app.js"></script>

<!-- Стало -->
<script src="js/auth.js"></script>
<script src="js/n8n_api.js"></script>
<script src="js/app_n8n.js"></script>
```

### 4.2 В файле `registr.js` (если есть) также замените API вызовы

### 4.3 В файле `n8n_api.js` укажите правильный URL:

```javascript
const N8N_CONFIG = {
  BASE_URL: 'https://n8n.your-domain.com/webhook', // Замените на ваш URL
  TIMEOUT: 30000,
  DEBUG: true
};
```

---

## Шаг 5: Тестирование

### 5.1 Проверка вебхуков

1. Откройте n8n Editor
2. Активируйте каждый workflow (toggle switch)
3. Используйте вкладку "Execute Workflow" для тестирования
4. Проверьте ответы через Postman или браузер

### 5.2 Проверка frontend

1. Очистите кэш браузера
2. Откройте консоль разработчика (F12)
3. Попробуйте войти/зарегистрироваться
4. Проверьте Network tab на наличие ошибок

---

## Шаг 6: Безопасность

### 6.1 Хранение секретов

Все секреты храните в n8n Credentials, не в коде workflow!

### 6.2 Rate Limiting

Добавьте узел "Limit" в начале каждого webhook workflow:
- Максимум запросов: 60 в минуту на IP
- Действие при превышении: Return 429

### 6.3 Логирование

Добавьте узел "Error Trigger" для глобального перехвата ошибок и отправки уведомлений.

---

## Структура базы данных

Убедитесь, что ваша таблица `users` имеет следующие поля:

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) UNIQUE NOT NULL,
  phone VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  position VARCHAR(100),
  network TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Troubleshooting

### Ошибка 401 Unauthorized
- Проверьте JWT secret (должен совпадать в frontend и backend)
- Проверьте, что токен передается в заголовке `Authorization: Bearer <token>`
- Проверьте срок действия токена

### Ошибка CORS
- Убедитесь, что N8N_CORS_ORIGIN настроен правильно
- Проверьте, что webhook URL доступен из браузера

### Ошибка подключения к БД
- Проверьте credentials MySQL
- Убедитесь, что хостинг разрешает внешние подключения к БД
- Проверьте firewall правила

### Pyrus Access Denied
- Проверьте, что аккаунт Pyrus имеет доступ к формам
- Проверьте правильность PYRUS_LOGIN и PYRUS_SECURITY_KEY
- Убедитесь, что формы с указанными ID существуют
