// Конфигурация API для работы с n8n
const N8N_CONFIG = {
  // Замените на URL вашего n8n инстанса
  BASE_URL: 'https://n8n.your-domain.com/webhook',
  
  // Таймаут запросов (мс)
  TIMEOUT: 30000,
  
  // Логирование
  DEBUG: true
};

const N8N_API = {
  /**
   * Универсальный метод для запросов к n8n
   */
  async makeRequest(endpoint, options = {}) {
    const url = `${N8N_CONFIG.BASE_URL}${endpoint}`;
    
    if (N8N_CONFIG.DEBUG) {
      console.log(`[N8N_API] Request to ${url}`, options);
    }
    
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), N8N_CONFIG.TIMEOUT);
      
      const response = await fetch(url, {
        ...options,
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          ...(options.headers || {})
        }
      });
      
      clearTimeout(timeoutId);
      
      const contentType = response.headers.get('content-type') || '';
      
      // Проверяем, что ответ JSON
      if (!contentType.includes('application/json')) {
        const text = await response.text();
        console.error('[N8N_API] Non-JSON response:', text.substring(0, 500));
        return {
          success: false,
          error: `Server returned non-JSON response (${response.status})`,
          status: response.status
        };
      }
      
      const data = await response.json();
      
      if (!response.ok) {
        return {
          success: false,
          error: data.error || data.message || `HTTP ${response.status}`,
          status: response.status,
          data: data
        };
      }
      
      return {
        success: true,
        data: data,
        status: response.status
      };
      
    } catch (error) {
      console.error('[N8N_API] Request failed:', error);
      return {
        success: false,
        error: error.name === 'AbortError' 
          ? 'Request timeout' 
          : error.message || 'Network error',
        status: 0
      };
    }
  },
  
  // ==================== АВТОРИЗАЦИЯ ====================
  
  /**
   * Вход пользователя
   * @param {string} identifier - email или телефон
   * @param {string} password - пароль
   */
  async login({ identifier, password }) {
    return this.makeRequest('/login', {
      method: 'POST',
      body: JSON.stringify({ identifier, password })
    });
  },
  
  /**
   * Регистрация нового пользователя
   * @param {Object} userData - данные пользователя
   */
  async register(userData) {
    return this.makeRequest('/register', {
      method: 'POST',
      body: JSON.stringify(userData)
    });
  },
  
  // ==================== PYRUS ====================
  
  /**
   * Получение списка заведений из Pyrus
   */
  async getRestaurants() {
    const token = Auth.getToken();
    return this.makeRequest('/restaurants', {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });
  },
  
  /**
   * Получение задач из Pyrus
   * @param {string} restaurant - фильтр по названию ресторана
   */
  async getTasks(restaurant = '') {
    const token = Auth.getToken();
    const params = restaurant ? `?restaurant=${encodeURIComponent(restaurant)}` : '';
    return this.makeRequest(`/tasks${params}`, {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });
  },
  
  // ==================== ЗАЯВКИ ====================
  
  /**
   * Получение списка заявок пользователя
   * @param {number|string} userId - ID пользователя
   */
  async getRequests(userId) {
    const token = Auth.getToken();
    return this.makeRequest(`/requests?userId=${userId}`, {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });
  },
  
  /**
   * Создание новой заявки
   * @param {Object} requestData - данные заявки
   */
  async createRequest(requestData) {
    const token = Auth.getToken();
    return this.makeRequest('/requests', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(requestData)
    });
  },
  
  // ==================== ПРОФИЛЬ ====================
  
  /**
   * Обновление данных профиля
   * @param {Object} userData - обновляемые данные
   */
  async updateProfile(userData) {
    const token = Auth.getToken();
    return this.makeRequest('/profile', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify(userData)
    });
  },
  
  // ==================== GRAFANA ====================
  
  /**
   * Получение данных из Grafana через прокси
   * @param {string} path - путь к дашборду
   */
  async getGrafanaData(path) {
    const token = Auth.getToken();
    return this.makeRequest(`/grafana?path=${encodeURIComponent(path)}`, {
      method: 'GET',
      headers: {
        'Authorization': `Bearer ${token}`
      }
    });
  }
};

// Экспортируем в глобальную область
window.N8N_API = N8N_API;
window.N8N_CONFIG = N8N_CONFIG;

console.log('[N8N_API] Loaded with base URL:', N8N_CONFIG.BASE_URL);
