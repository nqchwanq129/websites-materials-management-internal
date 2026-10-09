const warehouseRows = [...document.querySelectorAll('[data-warehouse]')];
const warehouseSearch = document.getElementById('warehouse-search');
const warehouseStatus = document.getElementById('warehouse-status');
const previousWarehousePage = document.getElementById('warehouse-page-previous');
const nextWarehousePage = document.getElementById('warehouse-page-next');
let warehousePage = 1;
function filterWarehouses(resetPage = true) {
    if (resetPage) warehousePage = 1;
    const query = normalize(warehouseSearch.value.trim());
    const matches = warehouseRows.filter(row => {
        row.hidden = true;
        const record = JSON.parse(row.dataset.warehouse);
        return normalize(`${record.code} ${record.name} ${record.address}`).includes(query)
            && (!warehouseStatus.value || record.status === warehouseStatus.value);
    });
    const pages = Math.max(1, Math.ceil(matches.length / 10));
    warehousePage = Math.min(warehousePage, pages);
    const start = (warehousePage - 1) * 10;
    matches.slice(start, start + 10).forEach(row => { row.hidden = false; });
    document.getElementById('warehouse-count').textContent = matches.length
        ? `Hiển thị ${start + 1}–${Math.min(start + 10, matches.length)} / ${matches.length} kho` : 'Hiển thị 0 / 0 kho';
    document.getElementById('warehouse-page-label').textContent = `${warehousePage} / ${pages}`;
    previousWarehousePage.disabled = warehousePage === 1;
    nextWarehousePage.disabled = warehousePage === pages;
    document.querySelector('.warehouse-empty').hidden = matches.length !== 0;
}
warehouseSearch.addEventListener('input', filterWarehouses);
warehouseStatus.addEventListener('change', filterWarehouses);
previousWarehousePage.addEventListener('click', () => { warehousePage--; filterWarehouses(false); });
nextWarehousePage.addEventListener('click', () => { warehousePage++; filterWarehouses(false); });
filterWarehouses();
const warehouseDialog = document.getElementById('warehouse-dialog');
const warehouseForm = document.getElementById('warehouse-form');
function updateWarehouseStatusNote() { document.getElementById('warehouse-inactive-note').hidden = warehouseForm.elements.status.value !== 'inactive'; }
function openWarehouse(record = null) {
    warehouseForm.reset();
    warehouseForm.querySelector('[data-form-error]').hidden = true;
    for (const key of ['id', 'version', 'code', 'name', 'address', 'status']) {
        warehouseForm.elements.namedItem(key).value = record?.[key] || (key === 'status' ? 'active' : '');
    }
    document.getElementById('warehouse-dialog-title').textContent = record ? 'Chỉnh sửa kho' : 'Thêm kho';
    document.getElementById('warehouse-linked-summary').textContent = record
        ? `${record.employee_count} nhân viên · ${record.access_count} tài khoản được cấp quyền riêng`
        : 'Kho mới chưa có nhân viên hoặc quyền truy cập riêng.';
    updateWarehouseStatusNote();
    warehouseDialog.showModal();
}
document.getElementById('create-warehouse').addEventListener('click', () => openWarehouse());
document.querySelectorAll('.edit-warehouse').forEach(button => button.addEventListener('click', () => openWarehouse(JSON.parse(button.closest('[data-warehouse]').dataset.warehouse))));
document.querySelectorAll('.close-warehouse-dialog').forEach(button => button.addEventListener('click', () => warehouseDialog.close()));
warehouseForm.elements.status.addEventListener('change', updateWarehouseStatusNote);
warehouseForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(warehouseForm, warehouseDialog, 'warehouse-notice'); });
const warehouseStateDialog = document.getElementById('warehouse-state-dialog');
const warehouseStateForm = document.getElementById('warehouse-state-form');
warehouseStateForm.querySelectorAll('[data-close-state]').forEach(button => button.addEventListener('click', () => warehouseStateDialog.close()));
warehouseStateForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(warehouseStateForm, warehouseStateDialog, 'warehouse-notice'); });
document.querySelectorAll('.warehouse-state').forEach(button => button.addEventListener('click', () => {
    const record = JSON.parse(button.closest('[data-warehouse]').dataset.warehouse);
    warehouseStateForm.elements.id.value = record.id;
    warehouseStateForm.elements.version.value = record.version;
    warehouseStateForm.elements.status.value = record.status === 'active' ? 'inactive' : 'active';
    warehouseStateForm.querySelector('[data-form-error]').hidden = true;
    document.getElementById('warehouse-state-title').textContent = record.status === 'active' ? 'Ngừng hoạt động kho' : 'Kích hoạt kho';
    document.getElementById('warehouse-state-description').textContent = `${record.code} · ${record.name}`;
    warehouseStateDialog.showModal();
}));
