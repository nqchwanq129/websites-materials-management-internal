const supplierRows = [...document.querySelectorAll('[data-supplier]')];
const supplierSearch = document.getElementById('supplier-search');
const supplierStatus = document.getElementById('supplier-status');
const supplierTax = document.getElementById('supplier-tax');
const supplierLinked = document.getElementById('supplier-linked');
const previousSupplierPage = document.getElementById('supplier-page-previous');
const nextSupplierPage = document.getElementById('supplier-page-next');
let supplierPage = 1;
function filterSuppliers(resetPage = true) {
    if (resetPage) supplierPage = 1;
    const query = normalize(supplierSearch.value.trim());
    const matches = supplierRows.filter(row => {
        row.hidden = true;
        const supplier = JSON.parse(row.dataset.supplier);
        return normalize([supplier.name, supplier.tax_code, supplier.phone, supplier.email, supplier.address].join(' ')).includes(query)
            && (!supplierStatus.value || supplier.status === supplierStatus.value)
            && (!supplierTax.value || Boolean(supplier.tax_code) === (supplierTax.value === 'present'))
            && (!supplierLinked.value || (supplier.receipt_count > 0) === (supplierLinked.value === 'linked'));
    });
    const pages = Math.max(1, Math.ceil(matches.length / 10));
    supplierPage = Math.min(supplierPage, pages);
    const start = (supplierPage - 1) * 10;
    matches.slice(start, start + 10).forEach(row => { row.hidden = false; });
    document.getElementById('supplier-count').textContent = matches.length
        ? `Hiển thị ${start + 1}–${Math.min(start + 10, matches.length)} / ${matches.length} nhà cung cấp` : 'Hiển thị 0 / 0 nhà cung cấp';
    document.getElementById('supplier-page-label').textContent = `${supplierPage} / ${pages}`;
    previousSupplierPage.disabled = supplierPage === 1;
    nextSupplierPage.disabled = supplierPage === pages;
    document.querySelector('.supplier-empty').hidden = matches.length !== 0;
}
supplierSearch.addEventListener('input', filterSuppliers);
[supplierStatus, supplierTax, supplierLinked].forEach(filter => filter.addEventListener('change', filterSuppliers));
previousSupplierPage.addEventListener('click', () => { supplierPage--; filterSuppliers(false); });
nextSupplierPage.addEventListener('click', () => { supplierPage++; filterSuppliers(false); });
filterSuppliers();
const supplierDialog = document.getElementById('supplier-dialog');
function openSupplier(supplier) {
    document.getElementById('supplier-detail-description').textContent = supplier.name;
    for (const key of ['name', 'tax_code', 'address', 'phone', 'email', 'status']) {
        document.getElementById('supplier-detail-' + key).textContent = key === 'status'
            ? (supplier.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động') : (supplier[key] || 'Chưa có thông tin');
    }
    const rows = document.getElementById('supplier-receipt-rows');
    rows.replaceChildren();
    const labels = { draft: 'Nháp', confirmed: 'Đã xác nhận', cancelled: 'Đã hủy' };
    for (const receipt of supplier.receipts) {
        const row = document.createElement('tr');
        for (const value of [receipt.number, receipt.received_date.split('-').reverse().join('/'), receipt.warehouse_name, labels[receipt.status] || receipt.status]) {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.append(cell);
        }
        const invoice = document.createElement('small');
        invoice.textContent = receipt.invoice_number ? `Hóa đơn: ${receipt.invoice_number}` : 'Chưa có số hóa đơn';
        row.firstElementChild.append(invoice);
        rows.append(row);
    }
    document.getElementById('supplier-receipt-count').textContent = `${supplier.receipt_count} phiếu nhập liên kết`;
    document.getElementById('supplier-receipt-empty').hidden = supplier.receipts.length !== 0;
    supplierDialog.showModal();
    supplierDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.supplier-details').forEach(button => button.addEventListener('click', () => openSupplier(JSON.parse(button.closest('[data-supplier]').dataset.supplier))));
