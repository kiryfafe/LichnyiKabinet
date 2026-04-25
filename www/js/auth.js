const Auth = {
  KEY_USER: "user_profile_data",
  KEY_TOKEN: "auth_token",

  async login(identifier, password) {
    if (!identifier || !password) {
      console.error("Login: missing credentials");
      return false;
    }

    try {
      const res = await API.loginUser({ identifier, password });

      if (!res || !res.success) {
        console.error("Login failed:", res?.error || "Unknown error");
        return false;
      }

      if (!res.user || !res.token) {
        console.error("Login: missing user data or token");
        return false;
      }

      localStorage.setItem(this.KEY_USER, JSON.stringify(res.user));
      localStorage.setItem(this.KEY_TOKEN, res.token);
      return true;
    } catch (e) {
      console.error("Ошибка авторизации:", e);
      return false;
    }
  },

  async register(data) {
    // data = { first_name, last_name, phone, email, password, position, network }
    const res = await API.registerUser(data);

    if (res && res.success) {
      localStorage.setItem(this.KEY_USER, JSON.stringify(res.user));
      localStorage.setItem(this.KEY_TOKEN, res.token);
      return true;
    }
    return false;
  },

  logout() {
    localStorage.removeItem(this.KEY_USER);
    localStorage.removeItem(this.KEY_TOKEN);
    window.location.href = "./pages/login.html";
  },

  getUser() {
    const data = localStorage.getItem(this.KEY_USER);
    return data ? JSON.parse(data) : null;
  },

  getToken() {
    return localStorage.getItem(this.KEY_TOKEN);
  }
};

window.Auth = Auth;
