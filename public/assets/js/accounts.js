const accountRows = [...document.querySelectorAll('[data-account]')];
const accountSearch = document.getElementById('account-search');
const statusFilter = document.getElementById('account-status');
const roleFilter = document.getElementById('account-role');
const warehouseFilter = document.getElementById('account-warehouse');
const accountPageSize = 10;
let accountPage = 1;
const previousAccountPage = document.getElementById('account-page-previous');
const nextAccountPage = document.getElementById('account-page-next');
function filterAccounts(resetPage = true) {
    if (resetPage) accountPage = 1;
    const matches = [];
    for (const row of accountRows) {
        const account = JSON.parse(row.dataset.account);
        const roles = Object.values(account.warehouses);
        const roleMatch = !roleFilter.value || (roleFilter.value === 'admin' ? account.admin : !account.admin
            && (warehouseFilter.value ? account.warehouses[warehouseFilter.value] === roleFilter.value : roles.includes(roleFilter.value)));
        row.hidden = !normalize(row.textContent).includes(normalize(accountSearch.value.trim()))
            || (statusFilter.value && account.status !== statusFilter.value) || !roleMatch
            || (warehouseFilter.value && !account.admin && !Object.hasOwn(account.warehouses, warehouseFilter.value));
        if (!row.hidden) matches.push(row);
        row.hidden = true;
    }
    const pages = Math.max(1, Math.ceil(matches.length / accountPageSize));
    accountPage = Math.min(accountPage, pages);
    const start = (accountPage - 1) * accountPageSize;
    matches.slice(start, start + accountPageSize).forEach(row => { row.hidden = false; });
    document.getElementById('account-count').textContent = matches.length
        ? `Hiển thị ${start + 1}–${Math.min(start + accountPageSize, matches.length)} / ${matches.length} tài khoản`
        : 'Hiển thị 0 / 0 tài khoản';
    document.getElementById('account-page-label').textContent = `${accountPage} / ${pages}`;
    previousAccountPage.disabled = accountPage === 1;
    nextAccountPage.disabled = accountPage === pages;
    document.querySelector('.account-empty').hidden = matches.length !== 0;
}
previousAccountPage.addEventListener('click', () => { accountPage--; filterAccounts(false); });
nextAccountPage.addEventListener('click', () => { accountPage++; filterAccounts(false); });
accountSearch.addEventListener('input', filterAccounts);
[statusFilter, roleFilter, warehouseFilter].forEach(filter => filter.addEventListener('change', filterAccounts));
filterAccounts();
const accountDialog = document.getElementById('account-dialog');
const accountForm = document.getElementById('account-form');
const adminCheckbox = document.getElementById('account-admin');
const passwordInput = document.getElementById('account-password');
function updateGlobalAccess() {
    document.getElementById('warehouse-permissions').disabled = adminCheckbox.checked;
    document.getElementById('global-access-note').hidden = !adminCheckbox.checked;
}
function openAccount(account = null) {
    accountForm.reset();
    accountForm.querySelector('[data-form-error]').hidden = true;
    document.getElementById('account-record-id').value = account?.id || '';
    document.getElementById('account-version').value = account?.version || '';
    document.getElementById('account-dialog-title').textContent = account ? 'Chỉnh sửa tài khoản' : 'Thêm tài khoản';
    document.getElementById('account-username').value = account?.username || '';
    document.getElementById('account-employee').value = account?.employee || '';
    document.getElementById('edit-account-status').value = account?.status || 'active';
    adminCheckbox.checked = account?.admin || false;
    passwordInput.disabled = accountForm.dataset.preview === 'true' && Boolean(account);
    passwordInput.required = !account;
    document.getElementById('new-password-field').hidden = accountForm.dataset.preview === 'true' && Boolean(account);
    document.getElementById('account-password-label').textContent = account ? 'Mật khẩu mới (để trống nếu giữ nguyên)' : 'Mật khẩu ban đầu';
    document.querySelectorAll('[data-warehouse-role]').forEach(select => { select.value = account?.warehouses[select.dataset.warehouseRole] || ''; });
    updateGlobalAccess();
    accountDialog.showModal();
}
document.getElementById('create-account').addEventListener('click', () => openAccount());
document.querySelectorAll('.edit-account').forEach(button => button.addEventListener('click', () => openAccount(JSON.parse(button.closest('[data-account]').dataset.account))));
document.querySelectorAll('.close-account-dialog').forEach(button => button.addEventListener('click', () => accountDialog.close()));
adminCheckbox.addEventListener('change', updateGlobalAccess);
accountForm.addEventListener('submit', event => {
    event.preventDefault();
    submitManagementForm(accountForm, accountDialog, 'account-notice');
});
accountDialog.addEventListener('close', () => { passwordInput.value = ''; });
const stateDialog = document.getElementById('account-state-dialog');
const accountStateForm = document.getElementById('account-state-form');
accountStateForm.querySelector('[data-close-state]').addEventListener('click', () => stateDialog.close());
accountStateForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(accountStateForm, stateDialog, 'account-notice'); });
document.querySelectorAll('.account-state-preview').forEach(button => button.addEventListener('click', () => {
    const account = JSON.parse(button.closest('[data-account]').dataset.account);
    accountStateForm.elements.id.value = account.id || '';
    accountStateForm.elements.version.value = account.version || '';
    accountStateForm.elements.status.value = account.status === 'active' ? 'locked' : 'active';
    accountStateForm.querySelector('[data-form-error]').hidden = true;
    document.getElementById('account-state-title').textContent = account.status === 'active' ? 'Khóa tài khoản' : 'Mở khóa tài khoản';
    document.getElementById('account-state-description').textContent = `${account.status === 'active' ? 'Khóa' : 'Mở khóa'} tài khoản ${account.username}?`;
    stateDialog.returnValue = '';
    stateDialog.showModal();
}));
