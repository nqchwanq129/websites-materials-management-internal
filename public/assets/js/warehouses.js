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
const warehouseDetailsDialog=document.getElementById('warehouse-details-dialog');
let viewedWarehouse=null;
function openWarehouseDetails(record) {
    viewedWarehouse=record;
    document.getElementById('warehouse-detail-description').textContent=record.code+' · '+record.name;
    document.getElementById('warehouse-detail-state').textContent=record.status==='active'?'Ngừng hoạt động':'Kích hoạt';
    for(const field of ['code','name','address','status','employee_count','active_employee_count','access_count']) {
        const value=field==='status'?(record.status==='active'?'Đang hoạt động':'Ngừng hoạt động'):record[field];
        document.getElementById('warehouse-detail-'+field).textContent=value==null || value===''?'Chưa cập nhật':value;
    }
    for(const element of warehouseDetailsDialog.querySelectorAll('[data-warehouse-print-detail]')) {
        const field=element.dataset.warehousePrintDetail;
        let value=record.print_settings?.[field];
        if(field.endsWith('_employee_id')) {
            const select=document.querySelector('[data-print-setting="'+field+'"]');
            value=value?[...select.options].find(option=>option.value===String(value))?.textContent:'';
        } else if(field==='department_address') value=value || record.address;
        element.textContent=value || 'Chưa cấu hình';
    }
    warehouseDetailsDialog.showModal();warehouseDetailsDialog.querySelector('.account-dialog-body').scrollTop=0;
}
document.querySelectorAll('.warehouse-details').forEach(button=>button.addEventListener('click',()=>openWarehouseDetails(JSON.parse(button.closest('[data-warehouse]').dataset.warehouse))));
document.getElementById('warehouse-detail-edit').addEventListener('click',()=>{warehouseDetailsDialog.close();openWarehouse(viewedWarehouse);});
const warehouseDialog = document.getElementById('warehouse-dialog');
const warehouseForm = document.getElementById('warehouse-form');
function updateWarehouseStatusNote() { document.getElementById('warehouse-inactive-note').hidden = warehouseForm.elements.status.value !== 'inactive'; }
function openWarehouse(record = null) {
    warehouseForm.reset();
    warehouseForm.querySelector('[data-form-error]').hidden = true;
    for (const key of ['id', 'version', 'code', 'name', 'address', 'status']) {
        warehouseForm.elements.namedItem(key).value = record?.[key] || (key === 'status' ? 'active' : '');
    }
    warehouseForm.querySelectorAll('[data-print-setting]').forEach(input => { input.value = record?.print_settings?.[input.dataset.printSetting] ?? ''; });
    document.getElementById('warehouse-dialog-title').textContent = record ? 'Chỉnh sửa kho' : 'Thêm kho';
    document.getElementById('warehouse-linked-summary').textContent = record
        ? `${record.employee_count} nhân viên · ${record.access_count} tài khoản được cấp quyền riêng`
        : 'Kho mới chưa có nhân viên hoặc quyền truy cập riêng.';
    updateWarehouseStatusNote();
    warehouseDialog.showModal();
}
document.getElementById('create-warehouse').addEventListener('click', () => openWarehouse());
document.querySelectorAll('.close-warehouse-dialog').forEach(button => button.addEventListener('click', () => warehouseDialog.close()));
warehouseForm.elements.status.addEventListener('change', updateWarehouseStatusNote);
warehouseForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(warehouseForm, warehouseDialog, 'warehouse-notice'); });
const warehouseStateDialog = document.getElementById('warehouse-state-dialog');
const warehouseStateForm = document.getElementById('warehouse-state-form');
warehouseStateForm.querySelectorAll('[data-close-state]').forEach(button => button.addEventListener('click', () => warehouseStateDialog.close()));
warehouseStateForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(warehouseStateForm, warehouseStateDialog, 'warehouse-notice'); });
document.getElementById('warehouse-detail-state').addEventListener('click', () => {
    const record = viewedWarehouse;
    warehouseStateForm.elements.id.value = record.id;
    warehouseStateForm.elements.version.value = record.version;
    warehouseStateForm.elements.status.value = record.status === 'active' ? 'inactive' : 'active';
    warehouseStateForm.querySelector('[data-form-error]').hidden = true;
    document.getElementById('warehouse-state-title').textContent = record.status === 'active' ? 'Ngừng hoạt động kho' : 'Kích hoạt kho';
    document.getElementById('warehouse-state-description').textContent = `${record.code} · ${record.name}`;
    warehouseStateDialog.showModal();
});
