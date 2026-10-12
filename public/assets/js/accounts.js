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
const accountEmployeeSelect = document.getElementById('account-employee');
const accountEmployeeSearch = document.getElementById('account-employee-search');
const accountEmployeePanel = document.getElementById('account-employee-panel');
const accountEmployeeOptions = [...accountEmployeePanel.querySelectorAll('[data-employee-choice]')];
const accountEmployeeEntries = accountEmployeeOptions.map(option => ({ option, text: normalize(option.textContent) }));
let chosenAccountEmployee = '', activeAccountEmployee = null;
function closeAccountEmployees() {
    if (accountEmployeePanel.matches(':popover-open')) accountEmployeePanel.hidePopover();
    accountEmployeeSearch.setAttribute('aria-expanded', 'false');
    accountEmployeeSearch.removeAttribute('aria-activedescendant');
    activeAccountEmployee?.removeAttribute('data-active');
    activeAccountEmployee = null;
}
function chooseAccountEmployee(id = '') {
    const option = accountEmployeeOptions.find(option => option.dataset.employeeChoice === id);
    chosenAccountEmployee = option?.dataset.employeeChoice || '';
    accountEmployeeSelect.value = chosenAccountEmployee;
    accountEmployeeSearch.value = chosenAccountEmployee ? option.textContent : '';
    accountEmployeeSearch.setCustomValidity('');
    accountEmployeeOptions.forEach(option => option.setAttribute('aria-selected', String(option.dataset.employeeChoice === chosenAccountEmployee)));
    closeAccountEmployees();
}
function positionAccountEmployees() {
    const rect = accountEmployeeSearch.getBoundingClientRect();
    const body = accountDialog.querySelector('.account-dialog-body').getBoundingClientRect();
    if (rect.bottom <= body.top || rect.top >= body.bottom) { closeAccountEmployees(); return; }
    const below = innerHeight - rect.bottom - 12, above = rect.top - 12;
    const upward = below < 160 && above > below;
    const height = Math.min(240, upward ? above : below);
    Object.assign(accountEmployeePanel.style, { left: `${rect.left}px`, width: `${rect.width}px`, maxHeight: `${Math.max(48, height)}px`,
        top: upward ? 'auto' : `${rect.bottom + 6}px`, bottom: upward ? `${innerHeight - rect.top + 6}px` : 'auto' });
}
function openAccountEmployees(query = '') {
    const words = normalize(query.trim()).split(/\s+/);
    accountEmployeeEntries.forEach(({ option, text }) => { option.hidden = Boolean(option.dataset.employeeChoice) && !words.every(word => text.includes(word)); });
    document.getElementById('account-employee-hint').hidden = accountEmployeeOptions.some(option => option.dataset.employeeChoice && !option.hidden);
    activeAccountEmployee?.removeAttribute('data-active');
    activeAccountEmployee = null;
    accountEmployeeSearch.removeAttribute('aria-activedescendant');
    if (!accountEmployeePanel.matches(':popover-open')) accountEmployeePanel.showPopover();
    accountEmployeeSearch.setAttribute('aria-expanded', 'true');
    positionAccountEmployees();
}
accountEmployeeSearch.addEventListener('focus', () => { accountEmployeeSearch.select(); openAccountEmployees(); });
accountEmployeeSearch.addEventListener('click', () => openAccountEmployees());
accountEmployeeSearch.addEventListener('input', () => {
    const choice = accountEmployeeOptions.find(option => option.dataset.employeeChoice === chosenAccountEmployee);
    const selected = chosenAccountEmployee && accountEmployeeSearch.value === choice?.textContent;
    accountEmployeeSelect.value = selected ? chosenAccountEmployee : '';
    accountEmployeeSearch.setCustomValidity(accountEmployeeSearch.value.trim() && !selected ? 'Hãy chọn một nhân viên trong danh sách.' : '');
    openAccountEmployees(accountEmployeeSearch.value);
});
accountEmployeePanel.addEventListener('mousedown', event => event.preventDefault());
accountEmployeePanel.addEventListener('click', event => {
    const option = event.target.closest('[data-employee-choice]');
    if (option) chooseAccountEmployee(option.dataset.employeeChoice);
});
const accountEmployeeToggle = document.getElementById('account-employee-toggle');
accountEmployeeToggle.addEventListener('mousedown', event => event.preventDefault());
accountEmployeeToggle.addEventListener('click', () => {
    if (accountEmployeePanel.matches(':popover-open')) closeAccountEmployees();
    else { accountEmployeeSearch.focus(); openAccountEmployees(); }
});
accountEmployeeSearch.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!accountEmployeePanel.matches(':popover-open')) openAccountEmployees();
        const options = accountEmployeeOptions.filter(option => !option.hidden);
        const index = options.indexOf(activeAccountEmployee);
        const next = event.key === 'ArrowDown' ? Math.min(index + 1, options.length - 1) : (index < 0 ? options.length - 1 : Math.max(0, index - 1));
        activeAccountEmployee?.removeAttribute('data-active');
        activeAccountEmployee = options[next];
        activeAccountEmployee.setAttribute('data-active', '');
        accountEmployeeSearch.setAttribute('aria-activedescendant', activeAccountEmployee.id);
        activeAccountEmployee.scrollIntoView({ block: 'nearest' });
    } else if (event.key === 'Enter' && accountEmployeePanel.matches(':popover-open')) {
        event.preventDefault();
        const option = activeAccountEmployee || accountEmployeeOptions.find(option => !option.hidden
            && (accountEmployeeSelect.value ? option.dataset.employeeChoice === accountEmployeeSelect.value
                : accountEmployeeSearch.value.trim() && option.dataset.employeeChoice));
        if (option) chooseAccountEmployee(option.dataset.employeeChoice);
    } else if (event.key === 'Escape' && accountEmployeePanel.matches(':popover-open')) {
        event.preventDefault(); event.stopPropagation(); chooseAccountEmployee(chosenAccountEmployee);
    } else if (event.key === 'Tab') closeAccountEmployees();
});
document.addEventListener('pointerdown', event => { if (!event.target.closest('.account-employee-field')) closeAccountEmployees(); });
document.addEventListener('focusin', event => { if (!event.target.closest('.account-employee-field')) closeAccountEmployees(); });
accountDialog.querySelector('.account-dialog-body').addEventListener('scroll', () => {
    if (accountEmployeePanel.matches(':popover-open')) positionAccountEmployees();
});
window.addEventListener('resize', closeAccountEmployees);
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
    chooseAccountEmployee(account?.employee || '');
    document.getElementById('edit-account-status').value = account?.status || 'active';
    adminCheckbox.checked = account?.admin || false;
    passwordInput.disabled = accountForm.dataset.preview === 'true' && Boolean(account);
    passwordInput.required = !account;
    document.getElementById('new-password-field').hidden = accountForm.dataset.preview === 'true' && Boolean(account);
    document.getElementById('account-password-label').textContent = account ? 'Mật khẩu mới (để trống nếu giữ nguyên)' : 'Mật khẩu ban đầu';
    document.querySelectorAll('[data-warehouse-role]').forEach(select => { select.value = account?.warehouses[select.dataset.warehouseRole] || ''; });
    updateGlobalAccess();
    accountDialog.showModal();
    accountDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.getElementById('create-account').addEventListener('click', () => openAccount());
document.querySelectorAll('.edit-account').forEach(button => button.addEventListener('click', () => openAccount(JSON.parse(button.closest('[data-account]').dataset.account))));
document.querySelectorAll('.close-account-dialog').forEach(button => button.addEventListener('click', () => accountDialog.close()));
adminCheckbox.addEventListener('change', updateGlobalAccess);
accountForm.addEventListener('submit', event => {
    event.preventDefault();
    submitManagementForm(accountForm, accountDialog, 'account-notice');
});
accountDialog.addEventListener('close', () => { passwordInput.value = ''; closeAccountEmployees(); });
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
