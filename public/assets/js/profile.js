const personalMenu = document.getElementById('user-menu');
const profileDialog = document.getElementById('profile-dialog');
const passwordDialog = document.getElementById('password-dialog');
const profileForm = document.getElementById('profile-form');
const passwordForm = document.getElementById('password-form');
let profileLoadController;

function personalError(form, failure) {
    const error = form.querySelector('[data-form-error]');
    error.textContent = failure.message || 'Không thể kết nối. Vui lòng thử lại.';
    error.hidden = false;
    form.querySelector('[data-profile-login]').hidden = failure.status !== 401;
    showNotification(error.textContent, failure.status === 422 ? 'warning' : 'error');
}
async function personalRequest(options = {}) {
    const response = await fetch(new URL(profileForm.getAttribute('action'), document.baseURI), { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options });
    if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Máy chủ trả về phản hồi không hợp lệ. Hãy tải lại trang.');
    const result = await response.json();
    if (!response.ok || !result.ok) throw Object.assign(new Error(result.message || 'Không thể cập nhật thông tin.'), { status: response.status });
    return result;
}
function displayOwnProfile(profile) {
    profileForm.elements.version.value = profile.version;
    for (const field of ['full_name', 'email', 'phone']) profileForm.elements[field].value = profile[field];
    for (const field of ['username', 'code', 'warehouse']) profileForm.querySelector(`[data-profile-${field}]`).value = profile[field];
    profileForm.querySelector('[data-profile-name]').textContent = profile.full_name;
    profileForm.querySelector('[data-profile-role]').textContent = profile.role;
    const initial = Array.from(profile.full_name.trim())[0]?.toLocaleUpperCase('vi') || '?';
    profileForm.querySelector('[data-profile-initial]').textContent = initial;
    document.querySelectorAll('[data-user-name]').forEach(element => { element.textContent = profile.full_name; });
    document.querySelectorAll('[data-user-initial]').forEach(element => { element.textContent = initial; });
    document.getElementById('profile-fields').disabled = !profile.editable;
    profileForm.querySelector('[type=submit]').disabled = !profile.editable;
    profileForm.querySelector('[data-profile-unlinked]').hidden = profile.editable;
}
async function openPersonalDialog(kind) {
    if (personalMenu.matches(':popover-open')) personalMenu.hidePopover();
    const dialog = kind === 'password' ? passwordDialog : profileDialog;
    const form = dialog.querySelector('form');
    form.reset();
    if (form === passwordForm) form.elements.password_confirmation.setCustomValidity('');
    form.querySelector('[data-form-error]').hidden = true;
    form.querySelector('[data-profile-login]').hidden = true;
    if (kind === 'profile') {
        profileForm.querySelector('[data-profile-loading]').hidden = false;
        profileForm.querySelector('[data-profile-unlinked]').hidden = true;
        document.getElementById('profile-fields').disabled = true;
        profileForm.querySelector('[type=submit]').disabled = true;
        profileForm.querySelector('[data-profile-role]').textContent = '';
    }
    dialog.showModal(); dialog.querySelector('.account-dialog-body').scrollTop = 0;
    if (kind === 'password') return;
    profileLoadController?.abort();
    const controller = new AbortController(); profileLoadController = controller;
    try {
        const profile = profileForm.dataset.preview === 'true'
            ? JSON.parse(document.getElementById('own-profile-preview').textContent)
            : (await personalRequest({ signal: controller.signal })).profile;
        if (controller.signal.aborted) return;
        displayOwnProfile(profile);
    } catch (failure) { if (failure.name !== 'AbortError') personalError(profileForm, failure); }
    finally { if (!controller.signal.aborted) profileForm.querySelector('[data-profile-loading]').hidden = true; }
}
document.querySelectorAll('[data-open-profile]').forEach(button => button.addEventListener('click', () => openPersonalDialog(button.dataset.openProfile)));
for (const dialog of [profileDialog, passwordDialog]) {
    dialog.querySelectorAll('[data-close-personal]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('cancel', event => { if (dialog.querySelector('form').dataset.saving === 'true') event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (dialog === profileDialog) profileLoadController?.abort();
        dialog.querySelectorAll('input[type=password],input[data-personal-password]').forEach(input => { input.value = ''; input.type = 'password'; });
        dialog.querySelectorAll('[data-personal-password-toggle]').forEach(button => { button.textContent = 'Hiện'; button.setAttribute('aria-pressed', 'false'); button.setAttribute('aria-label', 'Hiện mật khẩu'); });
    });
}
document.querySelectorAll('[data-personal-password-toggle]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    input.dataset.personalPassword = 'true';
    const visible = input.type === 'password'; input.type = visible ? 'text' : 'password';
    button.textContent = visible ? 'Ẩn' : 'Hiện';
    button.setAttribute('aria-label', visible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
    button.setAttribute('aria-pressed', String(visible));
}));
passwordForm.elements.password_confirmation.addEventListener('input', () => passwordForm.elements.password_confirmation.setCustomValidity(''));
passwordForm.elements.password.addEventListener('input', () => passwordForm.elements.password_confirmation.setCustomValidity(''));
for (const form of [profileForm, passwordForm]) form.addEventListener('submit', async event => {
    event.preventDefault();
    if (form.dataset.saving === 'true') return;
    if (form === passwordForm && form.elements.password.value !== form.elements.password_confirmation.value) {
        form.elements.password_confirmation.setCustomValidity('Nhập lại mật khẩu mới chưa khớp.');
        form.elements.password_confirmation.reportValidity(); return;
    }
    const dialog = form.closest('dialog');
    if (form.dataset.preview === 'true') { dialog.close(); showNotification('Bản xem trước: chưa lưu thay đổi vào dữ liệu.', 'info'); return; }
    const payload = new FormData(form);
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach(button => { button.disabled = true; });
    form.dataset.saving = 'true'; form.querySelector('[data-form-error]').hidden = true;
    try {
        const result = await personalRequest({ method: 'POST', body: payload });
        document.querySelectorAll('input[name=csrf_token]').forEach(input => { input.value = result.csrf_token; });
        displayOwnProfile(result.profile);
        dialog.close(); showNotification(result.message, 'success');
    } catch (failure) { personalError(form, failure); }
    finally {
        form.querySelectorAll('input[type=password],input[data-personal-password]').forEach(input => { input.value = ''; });
        form.dataset.saving = 'false'; buttons.forEach(button => { button.disabled = false; });
        if (form === profileForm) form.querySelector('[type=submit]').disabled = document.getElementById('profile-fields').disabled;
    }
});
