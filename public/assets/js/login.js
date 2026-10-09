'use strict';

const form = document.getElementById('login-form');
const password = document.getElementById('password');
const toggle = document.getElementById('password-toggle');
const help = document.getElementById('login-help');

toggle.hidden = false;
toggle.addEventListener('click', () => {
    const visible = password.type === 'password';
    password.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.setAttribute('aria-label', visible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
    toggle.querySelector('use').setAttribute('href', visible ? '#eye-off' : '#eye');
});

for (const id of ['help-open', 'support-open']) {
    document.getElementById(id).addEventListener('click', () => help.showModal());
}

form.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Đang đăng nhập…';
});

window.addEventListener('pageshow', () => {
    const button = form.querySelector('button[type="submit"]');
    button.disabled = false;
    button.innerHTML = 'Đăng nhập<svg class="icon"><use href="#arrow"/></svg>';
});
