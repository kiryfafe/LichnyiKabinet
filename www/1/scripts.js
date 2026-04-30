// scripts.js

// Навигация между страницами
const navButtons = document.querySelectorAll('nav button');
const sections = document.querySelectorAll('main > section');
const dropdownHome = document.getElementById('dropdown-container-home');
const dropdownRequests = document.getElementById('dropdown-container-requests');

navButtons.forEach(btn => {
  btn.addEventListener('click', () => {
    navButtons.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const target = btn.getAttribute('data-target');
    sections.forEach(s => {
      s.classList.toggle('active', s.id === target);
    });
    // Показываем нужный выпадающий список (если есть)
    dropdownHome.style.display = 'none';
    dropdownRequests.style.display = 'none';
    if(target === 'home') dropdownHome.style.display = 'block';
    if(target === 'requests') dropdownRequests.style.display = 'block';
  });
});

// Инициализация демонстрационных данных для выпадающих списков
const sampleDropdownData = ['Выбор 1', 'Выбор 2', 'Выбор 3'];
function fillDropdown(id, items) {
  const select = document.getElementById(id);
  select.innerHTML = '';
  items.forEach(item => {
    const option = document.createElement('option');
    option.value = item;
    option.textContent = item;
    select.appendChild(option);
  });
}
fillDropdown('dropdown-home', sampleDropdownData);
fillDropdown('dropdown-requests', sampleDropdownData);

// Кнопка создания заявки - пока демонстрация
document.getElementById('go-to-create-request').addEventListener('click', () => {
  alert('Переход на страницу создания заявки (нужно реализовать)');
});

// Профиль - начальные данные и логика
const profileAvatar = document.getElementById('profile-avatar');
const profilePhone = document.getElementById('profile-phone');
const profileName = document.getElementById('profile-name');
const shareContactBtn = document.getElementById('share-contact-btn');

// Устанавливаем аватар заглушку (будет динамически подгружаться)
profileAvatar.style.backgroundImage = 'url(https://via.placeholder.com/50?text=User)';

// Кнопка редактирования имени (демо)
document.getElementById('edit-profile-btn').addEventListener('click', () => {
  const newName = prompt('Введите имя', profileName.textContent);
  if(newName) profileName.textContent = newName;
});

// Выпадающий список заведений в профиле
fillDropdown('establishments-dropdown', ['Заведение 1', 'Заведение 2', 'Заведение 3']);

// Логика отображения кнопки "Поделиться контактом"
function checkPhoneHidden() {
  // Пример: изначально показываем кнопку (симуляция скрытого номера)
  shareContactBtn.style.display = 'block';
}
checkPhoneHidden();

shareContactBtn.addEventListener('click', () => {
  if(confirm('Поделитесь ли вы номером телефона с приложением?')) {
    // Симуляция получения номера из Телеграма
    profilePhone.textContent = '+7 (999) 123-45-67';
    shareContactBtn.style.display='none';
  }
});
