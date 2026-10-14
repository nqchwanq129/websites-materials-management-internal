const employeeRows = [...document.querySelectorAll('[data-employee]')];
const employeeSearch = document.getElementById('employee-search');
const employeeStatus = document.getElementById('employee-status');
const employeeWarehouse = document.getElementById('employee-warehouse');
const employeeAccount = document.getElementById('employee-account');
const employeePageSize = 10;
let employeePage = 1;
const previousEmployeePage = document.getElementById('employee-page-previous');
const nextEmployeePage = document.getElementById('employee-page-next');
function filterEmployees(resetPage = true) {
    if (resetPage) employeePage = 1;
    const query = normalize(employeeSearch.value.trim());
    const matches = employeeRows.filter(row => {
        const employee = JSON.parse(row.dataset.employee);
        row.hidden = true;
        return normalize(row.textContent).includes(query)
            && (!employeeStatus.value || employee.status === employeeStatus.value)
            && (!employeeWarehouse.value || employee.warehouse === employeeWarehouse.value)
            && (!employeeAccount.value || Boolean(employee.account) === (employeeAccount.value === 'linked'));
    });
    const pages = Math.max(1, Math.ceil(matches.length / employeePageSize));
    employeePage = Math.min(employeePage, pages);
    const start = (employeePage - 1) * employeePageSize;
    matches.slice(start, start + employeePageSize).forEach(row => { row.hidden = false; });
    document.getElementById('employee-count').textContent = matches.length
        ? `Hiển thị ${start + 1}–${Math.min(start + employeePageSize, matches.length)} / ${matches.length} nhân viên`
        : 'Hiển thị 0 / 0 nhân viên';
    document.getElementById('employee-page-label').textContent = `${employeePage} / ${pages}`;
    previousEmployeePage.disabled = employeePage === 1;
    nextEmployeePage.disabled = employeePage === pages;
    document.querySelector('.employee-empty').hidden = matches.length !== 0;
}
previousEmployeePage.addEventListener('click', () => { employeePage--; filterEmployees(false); });
nextEmployeePage.addEventListener('click', () => { employeePage++; filterEmployees(false); });
employeeSearch.addEventListener('input', filterEmployees);
[employeeStatus, employeeWarehouse, employeeAccount].forEach(filter => filter.addEventListener('change', filterEmployees));
filterEmployees();
const employeeDetailsDialog = document.getElementById('employee-details-dialog');
let viewedEmployee = null;
function openEmployeeDetails(employee) {
    viewedEmployee = employee;
    document.getElementById('employee-detail-state').textContent = employee.status === 'active' ? 'Khóa' : 'Mở khóa';
    document.getElementById('employee-detail-description').textContent = employee.code + ' · ' + employee.full_name;
    for (const field of ['code', 'full_name', 'email', 'phone', 'warehouse', 'account', 'status']) {
        const value = field === 'warehouse' ? [...employeeWarehouse.options].find(option => option.value === employee.warehouse)?.textContent
            : field === 'status' ? (employee.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động') : employee[field];
        document.getElementById('employee-detail-' + field).textContent = value || (field === 'account' ? 'Chưa liên kết tài khoản' : 'Chưa cập nhật');
    }
    employeeDetailsDialog.showModal();
    employeeDetailsDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.employee-details').forEach(button => button.addEventListener('click', () => openEmployeeDetails(JSON.parse(button.closest('[data-employee]').dataset.employee))));
document.getElementById('employee-detail-edit').addEventListener('click', () => {
    employeeDetailsDialog.close();
    openEmployee(viewedEmployee);
});
const employeeDialog = document.getElementById('employee-dialog');
const employeeForm = document.getElementById('employee-form');
const editEmployeeStatus = document.getElementById('edit-employee-status');
function updateEmployeeStatusNote() { document.getElementById('employee-inactive-note').hidden = editEmployeeStatus.value !== 'inactive'; }
function openEmployee(employee = null) {
    employeeForm.reset();
    employeeForm.querySelector('[data-form-error]').hidden = true;
    document.getElementById('employee-record-id').value = employee?.id || '';
    document.getElementById('employee-version').value = employee?.version || '';
    document.getElementById('employee-dialog-title').textContent = employee ? 'Chỉnh sửa nhân viên' : 'Thêm nhân viên';
    for (const [field, id] of Object.entries({ code: 'code', full_name: 'name', email: 'email', phone: 'phone', warehouse: 'warehouse', status: 'status' })) {
        document.getElementById('edit-employee-' + id).value = employee?.[field] || (field === 'status' ? 'active' : '');
    }
    document.getElementById('employee-linked-account').textContent = employee?.account ? `Tài khoản: ${employee.account}` : 'Chưa liên kết tài khoản';
    updateEmployeeStatusNote();
    employeeDialog.showModal();
}
document.getElementById('create-employee').addEventListener('click', () => openEmployee());
document.querySelectorAll('.close-employee-dialog').forEach(button => button.addEventListener('click', () => employeeDialog.close()));
editEmployeeStatus.addEventListener('change', updateEmployeeStatusNote);
employeeForm.addEventListener('submit', event => {
    event.preventDefault();
    submitManagementForm(employeeForm, employeeDialog, 'employee-notice');
});
const employeeStateDialog = document.getElementById('employee-state-dialog');
const employeeStateForm = document.getElementById('employee-state-form');
employeeStateForm.querySelector('[data-close-state]').addEventListener('click', () => employeeStateDialog.close());
employeeStateForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(employeeStateForm, employeeStateDialog, 'employee-notice'); });
document.getElementById('employee-detail-state').addEventListener('click', () => {
    const employee = viewedEmployee;
    employeeStateForm.elements.id.value = employee.id || '';
    employeeStateForm.elements.version.value = employee.version || '';
    employeeStateForm.elements.status.value = employee.status === 'active' ? 'inactive' : 'active';
    employeeStateForm.querySelector('[data-form-error]').hidden = true;
    document.getElementById('employee-state-title').textContent = employee.status === 'active' ? 'Ngừng hoạt động nhân viên' : 'Kích hoạt nhân viên';
    document.getElementById('employee-state-description').textContent = `${employee.full_name} (${employee.code}). ${employee.status === 'active' ? 'Khi ngừng hoạt động, nhân viên không được đăng nhập bằng tài khoản liên kết; hồ sơ vẫn được giữ lại.' : 'Khi được kích hoạt, nhân viên có thể sử dụng tài khoản liên kết nếu tài khoản đó đang hoạt động.'}`;
    employeeStateDialog.returnValue = '';
    employeeStateDialog.showModal();
});
