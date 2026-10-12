const auditRows = [...document.querySelectorAll('[data-audit]')];
const auditSearch = document.getElementById('audit-search');
const auditFrom = document.getElementById('audit-from');
const auditTo = document.getElementById('audit-to');
const auditAction = document.getElementById('audit-action');
const auditEntity = document.getElementById('audit-entity');
const auditWarehouse = document.getElementById('audit-warehouse');
let auditPage = 1;
const auditPageSize = 10;
const previousAuditPage = document.getElementById('audit-page-previous');
const nextAuditPage = document.getElementById('audit-page-next');
function filterAudit(resetPage = true) {
    if (resetPage) auditPage = 1;
    const query = normalize(auditSearch.value.trim());
    const invalidRange = Boolean(auditFrom.value && auditTo.value && auditFrom.value > auditTo.value);
    document.getElementById('audit-filter-error').hidden = !invalidRange;
    const matches = auditRows.filter(row => {
        row.hidden = true;
        const log = JSON.parse(row.dataset.audit);
        const date = log.created_at.slice(0, 10);
        return !invalidRange && normalize(row.textContent).includes(query)
            && (!auditFrom.value || date >= auditFrom.value) && (!auditTo.value || date <= auditTo.value)
            && (!auditAction.value || log.action === auditAction.value)
            && (!auditEntity.value || log.entity_type === auditEntity.value)
            && (!auditWarehouse.value || (auditWarehouse.value === 'global' ? log.warehouse === null : log.warehouse === auditWarehouse.value));
    });
    const pages = Math.max(1, Math.ceil(matches.length / auditPageSize));
    auditPage = Math.min(auditPage, pages);
    const start = (auditPage - 1) * auditPageSize;
    matches.slice(start, start + auditPageSize).forEach(row => { row.hidden = false; });
    document.getElementById('audit-count').textContent = matches.length ? `Hiển thị ${start + 1}–${Math.min(start + auditPageSize, matches.length)} / ${matches.length} bản ghi` : 'Hiển thị 0 / 0 bản ghi';
    document.getElementById('audit-page-label').textContent = `${auditPage} / ${pages}`;
    previousAuditPage.disabled = auditPage === 1;
    nextAuditPage.disabled = auditPage === pages;
    document.querySelector('.audit-empty').hidden = matches.length !== 0;
}
auditSearch.addEventListener('input', filterAudit);
[auditFrom, auditTo, auditAction, auditEntity, auditWarehouse].forEach(filter => filter.addEventListener('change', filterAudit));
previousAuditPage.addEventListener('click', () => { auditPage--; filterAudit(false); });
nextAuditPage.addEventListener('click', () => { auditPage++; filterAudit(false); });
document.getElementById('audit-reset').addEventListener('click', () => {
    [auditSearch, auditFrom, auditTo, auditAction, auditEntity, auditWarehouse].forEach(input => { input.value = ''; });
    filterAudit();
});
filterAudit();
const auditDialog = document.getElementById('audit-dialog');
const auditFields = { print_settings: 'Cấu hình in phiếu', print_snapshot: 'Thông tin in lúc lập phiếu', id: 'ID', address: 'Địa chỉ', employee_id: 'Nhân viên liên kết', warehouse_id: 'Kho làm việc', warehouses: 'Quyền theo kho', password_changed: 'Đã đổi mật khẩu', username: 'Tên đăng nhập', full_name: 'Họ và tên', email: 'Email', phone: 'Số điện thoại', status: 'Trạng thái', name: 'Tên', code: 'Mã', unit: 'Đơn vị', number: 'Số phiếu / quyết định', reason: 'Lý do', cancel_reason: 'Lý do hủy', total_before_tax: 'Giá trị trước thuế', is_super_admin: 'Quản trị viên toàn hệ thống', decision_type: 'Loại quyết định', issued_date: 'Ngày ban hành' };
function auditValue(data, key) {
    if (!data || !Object.hasOwn(data, key)) return 'Chưa có dữ liệu';
    const value = data[key];
    if (value === null) return 'Không có giá trị';
    if (value === '') return 'Trống';
    if (typeof value === 'boolean') return value ? 'Có' : 'Không';
    return typeof value === 'object' ? JSON.stringify(value, (field, content) => field === 'specifications' ? undefined : content, 2) : String(value);
}
function openAudit(log) {
    document.getElementById('audit-detail-description').textContent = log.description;
    const metadata = { id: `#${log.id}`, time: `${log.created_at.slice(8, 10)}/${log.created_at.slice(5, 7)}/${log.created_at.slice(0, 4)} · ${log.created_at.slice(11)}`, user: log.username ? `${log.full_name} (${log.username})` : 'Hệ thống · Tự động', warehouse: log.warehouse_name, action: log.action_name, entity: `${log.entity_name} · ${log.reference} · ID ${log.entity_id}` };
    Object.entries(metadata).forEach(([key, value]) => { document.getElementById('audit-detail-' + key).textContent = value; });
    const changes = document.getElementById('audit-change-rows');
    changes.replaceChildren();
    const keys = new Set([...Object.keys(log.before_data || {}), ...Object.keys(log.after_data || {})]);
    for (const key of keys) {
        if (key === 'specifications') continue;
        const row = document.createElement('tr');
        row.classList.toggle('audit-changed', JSON.stringify(log.before_data?.[key]) !== JSON.stringify(log.after_data?.[key]));
        for (const text of [auditFields[key] || key, auditValue(log.before_data, key), auditValue(log.after_data, key)]) {
            const cell = document.createElement('td');
            cell.textContent = text;
            row.append(cell);
        }
        changes.append(row);
    }
    auditDialog.showModal();
    auditDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.audit-details').forEach(button => button.addEventListener('click', () => openAudit(JSON.parse(button.closest('[data-audit]').dataset.audit))));
