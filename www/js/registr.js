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
        errorMsg.style.display = "block";
        return;
    }

    if (!email.includes('@')) {
        errorMsg.textContent = "Некорректный email";
        errorMsg.style.display = "block";
        return;
    }

    if (password.length < 6) {
        errorMsg.textContent = "Пароль должен быть не менее 6 символов";
        errorMsg.style.display = "block";
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
    successMsg.style.display = "none";
    errorMsg.style.display = "none";

    const ok = await Auth.register(data);

    if (ok) {
        successMsg.style.display = "block";
        errorMsg.style.display = "none";

        // сразу редиректим в кабинет
        setTimeout(() => window.location.href = "../index.html", 1000);
    } else {
        successMsg.style.display = "none";
        errorMsg.style.display = "block";
        errorMsg.textContent = "Ошибка при регистрации. Проверьте данные или попробуйте позже."; // Более общее сообщение
    }
}

regBtn.addEventListener('click', tryRegister);