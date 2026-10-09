const menuToggle = document.getElementById('menu-toggle');
const sidebar = document.getElementById('sidebar');
const backdrop = document.querySelector('.menu-backdrop');
const mobile = matchMedia('(max-width: 1000px)');
function setMenu(open) {
    sidebar.classList.toggle('is-open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
    backdrop.hidden = !open;
    document.body.classList.toggle('menu-open', open);
    if (mobile.matches) sidebar.inert = !open;
    if (open) sidebar.querySelector('.sidebar-close').focus();
    else if (sidebar.contains(document.activeElement)) menuToggle.focus();
}
menuToggle.addEventListener('click', () => setMenu(menuToggle.getAttribute('aria-expanded') !== 'true'));
backdrop.addEventListener('click', () => setMenu(false));
document.querySelector('.sidebar-close').addEventListener('click', () => setMenu(false));
document.addEventListener('keydown', event => {
    if (menuToggle.getAttribute('aria-expanded') !== 'true' || document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape') setMenu(false);
    if (event.key === 'Tab' && mobile.matches) {
        const controls = [...sidebar.querySelectorAll('a,button,input,summary')].filter(element => element.getClientRects().length > 0);
        const first = controls[0], last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
});
sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setMenu(false)));
mobile.addEventListener('change', () => { setMenu(false); sidebar.inert = mobile.matches; });
sidebar.inert = mobile.matches;
const featureDialog = document.getElementById('feature-dialog');
document.querySelectorAll('[data-feature]').forEach(button => button.addEventListener('click', () => {
    document.getElementById('feature-title').textContent = button.dataset.feature;
    featureDialog.showModal();
}));
const search = document.getElementById('receipt-search');
const warehouse = document.getElementById('warehouse-filter');
const tabs = [...document.querySelectorAll('[data-type].tab')];
const rows = [...document.querySelectorAll('[data-receipt]')];
let type = 'all';
const normalize = text => text.toLocaleLowerCase('vi').normalize('NFD').replace(/\p{M}/gu, '').replace(/đ/g, 'd');
const navigationSearch = document.getElementById('navigation-search');
const navigationGroups = [...sidebar.querySelectorAll('.nav-group')];
const overviewLink = sidebar.querySelector('[data-nav-home]');
let savedNavigationState = null;
navigationSearch.addEventListener('input', () => {
    const query = normalize(navigationSearch.value.trim());
    if (query && savedNavigationState === null) savedNavigationState = navigationGroups.map(group => group.open);
    overviewLink.hidden = !normalize(overviewLink.textContent).includes(query);
    let matches = overviewLink.hidden ? 0 : 1;
    navigationGroups.forEach((group, index) => {
        const groupMatches = normalize(group.querySelector('summary').textContent).includes(query);
        const children = [...group.querySelectorAll('.nav-child')];
        children.forEach(child => { child.hidden = !groupMatches && !normalize(child.textContent).includes(query); });
        group.hidden = children.every(child => child.hidden);
        if (!group.hidden) matches++;
        if (query) group.open = !group.hidden;
        else if (savedNavigationState !== null) group.open = savedNavigationState[index];
    });
    if (!query) savedNavigationState = null;
    sidebar.querySelector('.nav-empty').hidden = matches !== 0;
});
document.addEventListener('keydown', event => {
    if (!(event.ctrlKey || event.metaKey) || event.code !== 'Slash' || document.querySelector('dialog[open]')) return;
    event.preventDefault();
    if (mobile.matches && sidebar.inert) setMenu(true);
    navigationSearch.focus();
});
if (/Mac|iPhone|iPad/.test(navigator.platform)) sidebar.querySelector('kbd').textContent = '⌘ + /';
function filterReceipts() {
    const query = normalize(search.value.trim());
    let count = 0;
    for (const row of rows) {
        row.hidden = (type !== 'all' && row.dataset.type !== type)
            || (warehouse.value !== '' && row.dataset.warehouse !== warehouse.value)
            || !normalize(row.textContent).includes(query);
        if (!row.hidden) count++;
    }
    document.getElementById('receipt-count').textContent = `Hiển thị ${count} / ${rows.length} phiếu minh họa`;
    document.querySelector('.empty-state').hidden = count !== 0;
}
tabs.forEach(tab => tab.addEventListener('click', () => {
    type = tab.dataset.type;
    tabs.forEach(item => { item.classList.toggle('active', item === tab); item.setAttribute('aria-pressed', String(item === tab)); });
    filterReceipts();
}));
if (search && warehouse) {
    search.addEventListener('input', filterReceipts);
    warehouse.addEventListener('change', filterReceipts);
}

function showNotification(message, status = 'success') {
    if (typeof Notify !== 'function') return false;
    const center = document.getElementById('notification-center');
    if (center.matches(':popover-open')) center.hidePopover();
    (document.querySelector('dialog[open]') || document.body).appendChild(center);
    center.replaceChildren();
    const text = document.createElement('span');
    text.textContent = message;
    const notification = new Notify({ status, title: { success: 'Thành công', error: 'Không thể hoàn tất', warning: 'Cần kiểm tra', info: 'Thông báo' }[status],
        text: text.innerHTML, customClass: 'vishipel-notify', type: 'outline', effect: 'fade',
        speed: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180,
        position: 'right top', notificationsPadding: 16, notificationsGap: 10,
        autoclose: status === 'success' || status === 'info', autotimeout: 4500 });
    notification.wrapper.setAttribute('role', status === 'error' || status === 'warning' ? 'alert' : 'status');
    const close = notification.wrapper.querySelector('.sn-notify-close');
    close.setAttribute('role', 'button');
    close.setAttribute('aria-label', 'Đóng thông báo');
    close.tabIndex = 0;
    close.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        notification.close();
    });
    center.showPopover();
    return true;
}
document.querySelectorAll('[data-management-flash]').forEach(notice => {
    if (!notice.hidden && notice.textContent.trim() && showNotification(notice.textContent.trim())) notice.hidden = true;
});

async function submitManagementForm(form, dialog, noticeId) {
    const error = form.querySelector('[data-form-error]');
    if (form.dataset.preview === 'true') {
        const password = form.querySelector('input[type=password]');
        if (password) password.value = '';
        const notice = document.getElementById(noticeId);
        notice.textContent = 'Bản xem trước: chưa có dữ liệu nào được lưu hoặc thay đổi.';
        notice.hidden = false;
        dialog.close();
        showNotification(notice.textContent, 'info');
        return;
    }
    const payload = new FormData(form);
    const endpoint = new URL(form.getAttribute('action'), document.baseURI).href;
    const buttons = [...form.querySelectorAll('button')].filter(button => button.type === 'submit');
    buttons.forEach(button => { button.disabled = true; });
    error.hidden = true;
    let failureStatus = 'error';
    try {
        const response = await fetch(endpoint, { method: 'POST', body: payload, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Không thể lưu dữ liệu. Máy chủ trả về phản hồi không hợp lệ.');
        const result = await response.json();
        if (!response.ok || !result.ok) {
            if (response.status === 422) failureStatus = 'warning';
            throw new Error(result.message || 'Không thể lưu dữ liệu.');
        }
        window.location.assign(form.dataset.successUrl || endpoint);
    } catch (failure) {
        error.textContent = failure.message || 'Không thể kết nối. Vui lòng thử lại.';
        error.hidden = false;
        showNotification(error.textContent, failureStatus);
    } finally {
        const password = form.querySelector('input[type=password]');
        if (password) password.value = '';
        buttons.forEach(button => { button.disabled = false; });
    }
}

const managementDeleteForm = document.getElementById('management-delete-form');
if (managementDeleteForm) {
    const deleteDialog = document.getElementById('management-delete-dialog');
    const noticeId = document.getElementById('employee-notice') ? 'employee-notice' : 'warehouse-notice';
    document.querySelectorAll('[data-delete-record]').forEach(button => button.addEventListener('click', () => {
        const row = button.closest('[data-employee], [data-warehouse]');
        const record = JSON.parse(row.dataset.employee || row.dataset.warehouse);
        managementDeleteForm.elements.id.value = record.id;
        managementDeleteForm.elements.version.value = record.version || '';
        managementDeleteForm.querySelector('[data-form-error]').hidden = true;
        document.getElementById('management-delete-description').textContent = `${record.full_name || record.name} (${record.code})`;
        deleteDialog.showModal();
    }));
    managementDeleteForm.querySelectorAll('[data-cancel-delete]').forEach(button => button.addEventListener('click', () => deleteDialog.close()));
    managementDeleteForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(managementDeleteForm, deleteDialog, noticeId); });
}
