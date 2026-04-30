const fnameInput = document.getElementById('reg-firstname');
const lnameInput = document.getElementById('reg-lastname');
const phoneInput = document.getElementById('reg-phone');
const emailInput = document.getElementById('reg-email');
const passInput = document.getElementById('reg-password');
const posInput = document.getElementById('reg-position');
const networkInput = document.getElementById('reg-network');

const regBtn = document.getElementById('reg-btn');
const successMsg = document.getElementById('reg-success');
const errorMsg = document.getElementById('reg-error');

async function tryRegister() {
    // --- ВАЛИДАЦИЯ НА СТОРОНЕ КЛИЕНТА ---
    const firstName = fnameInput.value.trim();
    const lastName = lnameInput.value.trim();
    const email = emailInput.value.trim();
    const phone = phoneInput.value.trim(); // Убедитесь, что валидация соответствует формату
    const password = passInput.value.trim();
    const position = posInput.value.trim();
    const network = networkInput ? networkInput.value.trim() : "";

    if (!firstName || !lastName || !email || !phone || !password) {
        errorMsg.textContent = "Заполните все обязательные поля.";
        errorMsg.classList.add("show");
        successMsg.classList.remove("show");
        return;
    }

    if (!email.includes('@')) {
        errorMsg.textContent = "Некорректный email";
        errorMsg.classList.add("show");
        successMsg.classList.remove("show");
        return;
    }

    if (password.length < 8) {
        errorMsg.textContent = "Пароль должен быть не менее 8 символов";
        errorMsg.classList.add("show");
        successMsg.classList.remove("show");
        return;
    }

    // --- ОТПРАВКА ДАННЫХ ---
    const data = {
        first_name: firstName, // Используем отвалидированные переменные
        last_name: lastName,
        phone: phone,
        email: email,
        password: password,
        position: position,
        network: network
    };

    // Скрываем предыдущие сообщения
    successMsg.classList.remove("show");
    errorMsg.classList.remove("show");

    const ok = await Auth.register(data);

    if (ok) {
        successMsg.classList.add("show");
        errorMsg.classList.remove("show");

        // Небольшая задержка перед редиректом, чтобы убедиться, что данные сохранились
        setTimeout(() => {
            // Принудительно проверяем, что токен сохранён
            const token = Auth.getToken();
            const user = Auth.getUser();
            if (!token || !user) {
                console.error("Token or user data not saved after registration");
                errorMsg.textContent = "Ошибка сохранения сессии. Попробуйте войти снова.";
                errorMsg.classList.add("show");
                return;
            }
            window.location.href = "../index.html";
        }, 500);
    } else {
        successMsg.classList.remove("show");
        errorMsg.classList.add("show");
        errorMsg.textContent = "Ошибка при регистрации. Проверьте данные или попробуйте позже."; // Более общее сообщение
    }
}

regBtn.addEventListener('click', tryRegister);