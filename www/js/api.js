const API = {
  async makeRequest(url, options = {}) {
    try {
      const res = await fetch(url, options);
      const contentType = (res.headers.get("content-type") || "").toLowerCase();
      const raw = await res.text();

      // Если сервер вернул не JSON
      if (!contentType.includes("application/json")) {
        console.error("Non-JSON response:", raw);
        return {
          success: false,
          error: `Server error (${res.status}): ${raw.substring(0, 200)}`
        };
      }

      if (!raw.trim()) {
        return {
          success: false,
          error: `Empty response from server (${res.status})`
        };
      }

      let parsed;
      try {
        parsed = JSON.parse(raw);
      } catch (parseError) {
        console.error("JSON parse error:", parseError, "Response text:", raw);
        return {
          success: false,
          error: `Invalid JSON response: ${raw.substring(0, 200)}`
        };
      }

      if (!res.ok) {
        return {
          success: false,
          error: parsed.error || `HTTP error! status: ${res.status}`
        };
      }

      return parsed;
    } catch (e) {
      console.error("API error:", e);
      return { success: false, error: e.message || "Network error" };
    }
  },
  // ==================== АВТОРИЗАЦИЯ ====================
  async loginUser({ identifier, password }) {
    return this.makeRequest("/api/login.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ identifier, password })
    });
  },
  async registerUser(data) {
    return this.makeRequest("/api/register.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data)
    });
  },
  // ==================== PYRUS ====================
  async getPyrusRestaurants() {
    return this.makeRequest("/api/restaurants.php", {
      headers: { "Authorization": `Bearer ${Auth.getToken()}` }
    });
  },
  async getPyrusTasks(restaurant = "") {
    const qs = restaurant ? `?restaurant=${encodeURIComponent(restaurant)}` : "";
    return this.makeRequest(`/api/pyrus_tasks.php${qs}`, {
      headers: { "Authorization": `Bearer ${Auth.getToken()}` }
    });
  },
  // ==================== ЗАЯВКИ ====================
  async getRequests(userId) {
    return this.makeRequest(`/api/requests.php?userId=${userId}`, {
      headers: { "Authorization": `Bearer ${Auth.getToken()}` }
    });
  },
  async addRequest(data) {
    return this.makeRequest("/api/requests.php", {
      method: "POST",
      headers: { 
        "Content-Type": "application/json",
        "Authorization": `Bearer ${Auth.getToken()}`
      },
      body: JSON.stringify(data)
    });
  },
  // ==================== ЗАВЕДЕНИЯ ====================
  async getApprovedRestaurants(userId) {
    return this.makeRequest(`/api/restaurants.php?userId=${userId}`, {
      headers: { "Authorization": `Bearer ${Auth.getToken()}` }
    });
  },
  // ==================== ПРОФИЛЬ ====================
  async updateUserProfile(userData) {
    return this.makeRequest("/api/profile.php", {
      method: "POST",
      headers: { 
        "Content-Type": "application/json",
        "Authorization": `Bearer ${Auth.getToken()}`
      },
      body: JSON.stringify(userData)
    });
  }
};

window.API = API;