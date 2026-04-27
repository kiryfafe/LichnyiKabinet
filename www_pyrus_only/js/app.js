document.addEventListener("DOMContentLoaded", async () => {
  const user = Auth.getUser();
  if (!user) {
    window.location.href = "pages/login.html";
    return;
  }

  /* ==================== ПРИВЕТСТВЕННЫЙ ЭКРАН ==================== */
  const welcomeScreen = document.getElementById("welcome-screen");
  const mainApp = document.getElementById("main-app");

  setTimeout(() => {
    welcomeScreen.classList.add("fade-in");
    const hours = new Date().getHours();
    const firstName = (user.fullName || `${user.firstName || ""} ${user.lastName || ""}`)
      .trim()
      .split(" ")[0] || "Гость";
    let greeting = "";
    if (hours >= 4 && hours < 10) {
      greeting = `Доброе утро, ${firstName}!`;
    } else if (hours >= 10 && hours < 16) {
      greeting = `Добрый день, ${firstName}!`;
    } else if (hours >= 16 && hours < 22) {
      greeting = `Добрый вечер, ${firstName}!`;
    } else {
      greeting = `Доброй ночи, ${firstName}!`;
    }
    const p = document.querySelector("#welcome-screen p");
    if (p) p.textContent = greeting;
  }, 200);

  setTimeout(() => {
    welcomeScreen.style.display = "none";
    mainApp.style.display = "block";
    const toAnimate = document.querySelectorAll(".nav-bar, .btn-Create, .btn-AddRestaurant, .dropdown-container, .requests-table, .table-filters");
    toAnimate.forEach(el => el.classList.add("slide-in"));
  }, 2000);

  /* ==================== ЗАПОЛНЕНИЕ ПРОФИЛЯ ==================== */
  const fullNameText = (user.fullName || `${user.firstName || ""} ${user.lastName || ""}`).trim();
  document.getElementById("user-fullname").textContent = fullNameText || "Имя Фамилия";
  document.getElementById("user-phone").textContent = user.phone || "";

  /* ==================== ДРОПДАУН И МОДАЛКА ЗАВЕДЕНИЙ ==================== */
  try {
    const dropdown = document.getElementById("main-dropdown");
    const estList = document.querySelector(".establishment-list");
    const resp = await API.getPyrusRestaurants();
    if (!resp.success) throw new Error("Failed to load restaurants");

    const restaurants = resp.restaurants || [];
    const filterSelect = document.getElementById("filter-establishment");

    restaurants.forEach(r => {
      const opt = document.createElement("option");
      opt.value = r.id;
      opt.textContent = r.name;
      dropdown.appendChild(opt);
      if (filterSelect) {
        const fopt = document.createElement("option");
        fopt.value = r.name;
        fopt.textContent = r.name;
        filterSelect.appendChild(fopt);
      }
      if (estList) {
        const btn = document.createElement("button");
        btn.className = "establishment-item btn-RestModal";
        btn.textContent = r.name;
        estList.appendChild(btn);
        btn.addEventListener("click", () => {
          document.querySelectorAll(".establishment-item").forEach(b => b.classList.remove("selected"));
          btn.classList.add("selected");
          const sel = document.getElementById("selected-establishment");
          if (sel) sel.textContent = r.name;
          closeModal("establishment-modal");
        });
      }
    });
  } catch (e) {
    console.error("Ошибка загрузки заведений:", e);
    alert("Ошибка загрузки заведений: " + e.message);
  }

  /* ==================== ЗАДАЧИ ИЗ PYRUS ==================== */
  async function loadTasks(selectedRestaurant = "") {
    try {
      const tbody = document.getElementById("requests-table-body");
      if (tbody) tbody.innerHTML = "";

      const res = await API.getPyrusTasks(selectedRestaurant);
      if (!res.success) throw new Error("Failed to load tasks");

      const list = res.tasks || [];
      list.forEach(task => {
        const tr = document.createElement("tr");
        const restaurantName = task.restaurant || "";
        const title = task.title || "";
        const status = task.status || (task.fields && task.fields["Статус"]) || "";
        const desc = task.fields ? JSON.stringify(task.fields) : "";
        tr.innerHTML = `
          <td>${task.id ?? ""}</td>
          <td data-sort="">-</td>
          <td data-sort="">-</td>
          <td>${restaurantName}</td>
          <td>${title}</td>
          <td>${desc}</td>
          <td>${status}</td>
        `;
        tbody && tbody.appendChild(tr);
      });
    } catch (e) {
      console.error("Ошибка загрузки задач:", e);
      alert("Ошибка загрузки задач: " + e.message);
    }
  }

  // начальная загрузка без фильтра
  loadTasks();

  const dropdown = document.getElementById("main-dropdown");
  if (dropdown) {
    dropdown.addEventListener("change", () => {
      const selectedOption = dropdown.options[dropdown.selectedIndex];
      const name = selectedOption ? selectedOption.textContent : "";
      loadTasks(name === "Выберите заведение" ? "" : name);
    });
  }

  const filterSelect = document.getElementById("filter-establishment");
  if (filterSelect) {
    filterSelect.addEventListener("change", () => {
      const name = filterSelect.value;
      loadTasks(name);
    });
  }

  /* ==================== ДАШБОРД (GRAFANA) ==================== */
  const grafanaFrame = document.getElementById("grafana-frame");
  if (grafanaFrame) {
    const token = Auth.getToken();
    if (token) {
      // Просто устанавливаем src на наш PHP-прокси
      // Важно: указать конкретный путь к дашборду, например, /d/ID
      // Убедитесь, что этот путь соответствует разрешенным в grafana.php
      grafanaFrame.src = `/api/grafana.php?path=/d/ВАШ_ДАШБОРД_ID`; // Замените ВАШ_ДАШБОРД_ID
    } else {
        // Обработка отсутствия токена
        console.error("Grafana: No user token available.");
        // Можно показать сообщение в UI
    }
  }
  
  /* ==================== ВЫХОД ==================== */
  document.getElementById("logout-btn").addEventListener("click", () => Auth.logout());

  /* ==================== НАВИГАЦИЯ ==================== */
  document.querySelectorAll(".nav-btn").forEach(btn => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      const page = btn.dataset.page;
      document.querySelectorAll(".page").forEach(p => p.classList.remove("active"));
      document.getElementById(page).classList.add("active");
      document.querySelectorAll(".nav-btn").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
    });
  });

  /* ==================== МОДАЛКИ ==================== */
  function openModal(id) {
    document.getElementById(id).classList.remove("hidden");
  }
  function closeModal(id) {
    document.getElementById(id).classList.add("hidden");
  }
  window.closeModal = closeModal;

  document.querySelectorAll("[data-open]").forEach(el => {
    el.addEventListener("click", () => openModal(el.dataset.open));
  });
  document.querySelectorAll("[data-close]").forEach(el => {
    el.addEventListener("click", () => closeModal(el.dataset.close));
  });

  /* ==================== СОХРАНЕНИЕ ПРОФИЛЯ ==================== */
  document.getElementById("save-profile-btn").addEventListener("click", async () => {
    const first = document.getElementById("edit-firstname").value.trim();
    const last = document.getElementById("edit-lastname").value.trim();
    if (first && last) {
      try {
        const resp = await API.updateUserProfile({ first_name: first, last_name: last });
        if (resp && resp.success) {
          localStorage.setItem("user_profile_data", JSON.stringify(resp.user));
          document.getElementById("user-fullname").textContent = resp.user.fullName;
        } else {
          throw new Error("Failed to update profile");
        }
      } catch (e) {
        console.error("Ошибка сохранения профиля:", e);
        alert("Ошибка сохранения профиля: " + e.message);
      }
    }
    closeModal("edit-modal");
  });
});