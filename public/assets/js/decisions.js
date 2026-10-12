const decisionRows = [...document.querySelectorAll('[data-decision]')];
const decisionSearch = document.getElementById('decision-search');
const decisionType = document.getElementById('decision-type');
const decisionWarehouse = document.getElementById('decision-warehouse');
const decisionLinked = document.getElementById('decision-linked');
let decisionPage = 1;
function filterDecisions(resetPage = true) {
    if (resetPage) decisionPage = 1;
    const query = normalize(decisionSearch.value.trim());
    const matches = decisionRows.filter(row => {
        row.hidden = true;
        const decision = JSON.parse(row.dataset.decision);
        return normalize([decision.number, decision.issuing_authority, decision.content, decision.warehouse_name].join(' ')).includes(query)
            && (!decisionType.value || decision.decision_type === decisionType.value)
            && (!decisionWarehouse.value || String(decision.warehouse_id) === decisionWarehouse.value)
            && (!decisionLinked.value || Boolean(decision.items.length) === (decisionLinked.value === 'linked'));
    });
    const pages = Math.max(1, Math.ceil(matches.length / 10));
    decisionPage = Math.min(decisionPage, pages);
    const start = (decisionPage - 1) * 10;
    matches.slice(start, start + 10).forEach(row => { row.hidden = false; });
    document.getElementById('decision-count').textContent = matches.length
        ? `Hiển thị ${start + 1}–${Math.min(start + 10, matches.length)} / ${matches.length} quyết định` : 'Hiển thị 0 / 0 quyết định';
    document.getElementById('decision-page-label').textContent = `${decisionPage} / ${pages}`;
    document.getElementById('decision-page-previous').disabled = decisionPage === 1;
    document.getElementById('decision-page-next').disabled = decisionPage === pages;
    document.querySelector('.decision-empty').hidden = matches.length !== 0;
}
decisionSearch.addEventListener('input', filterDecisions);
[decisionType, decisionWarehouse, decisionLinked].forEach(input => input.addEventListener('change', filterDecisions));
document.getElementById('decision-page-previous').addEventListener('click', () => { decisionPage--; filterDecisions(false); });
document.getElementById('decision-page-next').addEventListener('click', () => { decisionPage++; filterDecisions(false); });
filterDecisions();
const decisionDialog = document.getElementById('decision-dialog');
const decisionTypes = { increase: 'Tăng vật tư', allocation: 'Cấp phát vật tư', transfer: 'Điều chuyển vật tư', decrease: 'Giảm vật tư' };
function openDecision(decision) {
    document.getElementById('decision-detail-description').textContent = decision.number;
    for (const key of ['number', 'issued_date', 'decision_type', 'issuing_authority', 'warehouse_name', 'content']) {
        document.getElementById('decision-detail-' + key).textContent = key === 'decision_type' ? decisionTypes[decision[key]]
            : key === 'issued_date' ? decision[key].split('-').reverse().join('/') : (decision[key] || 'Chưa có thông tin');
    }
    const rows = document.getElementById('decision-item-rows');
    rows.replaceChildren();
    const statuses = { draft: 'Nháp', confirmed: 'Đã xác nhận', cancelled: 'Đã hủy' };
    for (const item of decision.items) {
        const row = document.createElement('tr');
        // Preserve DECIMAL quantities as text, avoiding floating-point rounding.
        const quantity = item.quantity == null ? 'Chưa có số lượng' : String(item.quantity).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
        for (const value of [item.product_name, `${item.direction === 'import' ? 'Nhập' : 'Xuất'}: ${item.number} · ${item.date.split('-').reverse().join('/')}`,
            item.warehouse_name, `${quantity} ${item.unit || ''}`, statuses[item.status] || item.status]) {
            const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
        }
        rows.append(row);
    }
    document.getElementById('decision-item-empty').hidden = decision.items.length !== 0;
    renderDecisionFiles(document.getElementById('decision-detail-files'), decision.attachments || []);
    decisionDialog.showModal();
    decisionDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.decision-details').forEach(button => button.addEventListener('click', () => openDecision(JSON.parse(button.closest('[data-decision]').dataset.decision))));

function renderDecisionFiles(container, files) {
    container.replaceChildren();
    container.classList.add('decision-file-list');
    if (!files.length) { container.textContent = 'Chưa có file PDF đính kèm.'; return; }
    for (const file of files) {
        const link = document.createElement('a');
        link.textContent = `Tải PDF: ${file.original_name}`;
        link.href = `index.php?page=decision-file&id=${encodeURIComponent(file.id)}`;
        container.append(link);
    }
}
const decisionEditor = document.getElementById('decision-editor');
const decisionForm = document.getElementById('decision-form');
const decisionLines = JSON.parse(document.getElementById('decision-lines-data').textContent);
function updateDecisionLines() {
    const query = normalize(document.getElementById('decision-line-search').value.trim());
    const warehouse = decisionForm.elements.warehouse.value;
    const rows = [...document.querySelectorAll('[data-decision-line]')];
    let visible = 0, selected = 0;
    for (const row of rows) {
        const checkbox = row.querySelector('input');
        // Search hides rows without losing selections; changing warehouse clears incompatible selections.
        checkbox.disabled = row.dataset.warehouse !== warehouse;
        if (checkbox.disabled) checkbox.checked = false;
        if (checkbox.checked) selected++;
        row.hidden = checkbox.disabled || !normalize(row.textContent).includes(query);
        if (!row.hidden) visible++;
    }
    document.getElementById('decision-selected-count').textContent = `${selected} dòng hàng được chọn`;
    const empty = document.getElementById('decision-line-empty');
    empty.hidden = visible !== 0;
    empty.textContent = warehouse ? 'Không có dòng hàng phù hợp để gắn quyết định.' : 'Chọn kho để xem các dòng hàng có thể liên kết.';
}
function editDecision(decision = null) {
    if (decision?.can_link === false) return;
    decisionForm.reset();
    decisionForm.querySelector('[data-form-error]').hidden = true;
    decisionForm.elements.id.value = decision?.id || '';
    decisionForm.elements.version.value = decision?.version || '';
    decisionForm.elements.links_version.value = decision?.links_version || '';
    const linksOnly = decisionForm.dataset.linksOnly === 'true';
    document.getElementById('decision-editor-title').textContent = linksOnly ? 'Gắn hàng hóa vào quyết định' : decision ? 'Sửa / Gắn quyết định' : 'Tạo quyết định';
    for (const key of ['number', 'issued_date', 'decision_type', 'issuing_authority', 'content']) decisionForm.elements[key].value = decision?.[key] || (key === 'decision_type' ? 'increase' : '');
    for (const key of ['number','issued_date','issuing_authority','content']) decisionForm.elements[key].readOnly = linksOnly;
    for (const option of decisionForm.elements.decision_type.options) option.disabled = linksOnly && option.value !== decision?.decision_type;
    decisionForm.elements.pdf.disabled = linksOnly;
    decisionForm.elements.warehouse.value = decision ? String(decision.warehouse_id) : '';
    for (const option of decisionForm.elements.warehouse.options) option.disabled = option.dataset.status === 'inactive' && option.value !== String(decision?.warehouse_id || '');
    decisionForm.elements.warehouse.disabled = false;
    // Keep the warehouse submitted, but prevent accidental changes on linked decisions.
    decisionForm.elements.warehouse.querySelectorAll('option').forEach(option => {
        if ((decision?.items.length || linksOnly) && option.value !== String(decision?.warehouse_id)) option.disabled = true;
    });
    document.getElementById('decision-line-search').value = '';
    const rows = document.getElementById('decision-line-options'); rows.replaceChildren();
    for (const line of decisionLines) {
        const linkedHere = decision && String(line.decision_id) === decision.id;
        if (!linkedHere && (line.decision_id != null || line.decision_status !== 'pending' || line.status !== 'confirmed')) continue;
        const row = document.createElement('tr'); row.dataset.decisionLine = line.id; row.dataset.warehouse = String(line.warehouse_id);
        const cell = document.createElement('td'), checkbox = document.createElement('input');
        checkbox.type = 'checkbox'; checkbox.name = `lines[${line.direction}][${line.id}]`; checkbox.value = line.version;
        checkbox.checked = Boolean(linkedHere); checkbox.setAttribute('aria-label', `Gắn ${line.product_name}, phiếu ${line.number}`);
        checkbox.addEventListener('change', updateDecisionLines); cell.append(checkbox); row.append(cell);
        for (const value of [`${line.product_name} · ${line.number}`, line.direction === 'import' ? 'Nhập' : 'Xuất', `${line.quantity ?? 'Chưa có số lượng'} ${line.unit || ''}`]) {
            const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
        }
        rows.append(row);
    }
    renderDecisionFiles(document.getElementById('decision-editor-files'), decision?.attachments || []);
    updateDecisionLines(); decisionEditor.showModal(); decisionEditor.querySelector('.account-dialog-body').scrollTop = 0;
}
document.getElementById('create-decision')?.addEventListener('click', () => editDecision());
document.querySelectorAll('.edit-decision').forEach(button => button.addEventListener('click', () => editDecision(JSON.parse(button.closest('[data-decision]').dataset.decision))));
decisionForm.querySelectorAll('[data-close-decision]').forEach(button => button.addEventListener('click', () => decisionEditor.close()));
decisionForm.elements.warehouse.addEventListener('change', updateDecisionLines);
document.getElementById('decision-line-search').addEventListener('input', updateDecisionLines);
decisionForm.addEventListener('submit', event => {
    event.preventDefault();
    const pdf = decisionForm.elements.pdf.files[0];
    if (pdf && (pdf.size > 2 * 1024 * 1024 || !pdf.name.toLowerCase().endsWith('.pdf'))) {
        const error = decisionForm.querySelector('[data-form-error]'); error.textContent = 'Chọn file PDF tối đa 2 MB.'; error.hidden = false;
        showNotification(error.textContent, 'warning'); return;
    }
    submitManagementForm(decisionForm, decisionEditor, 'decision-notice');
});
