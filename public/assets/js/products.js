const productRows = [...document.querySelectorAll('[data-product]')];
const productSearch = document.getElementById('product-search');
const productWarehouse = document.getElementById('product-warehouse');
const productStock = document.getElementById('product-stock');
const productStatus = document.getElementById('product-status');
const productCategory = document.getElementById('products-list').dataset.category;
let productPage = 1;
function formatProductQuantity(value, tracking = 'none') {
    if (value == null) return tracking === 'quantity' ? 'Thiếu số lượng' : 'Không theo dõi';
    const [whole, decimal = ''] = String(value).split('.');
    const fraction = decimal.replace(/0+$/, '');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (fraction ? ',' + fraction : '');
}
function visibleProductBalance(product) {
    if (!productWarehouse.value) return product;
    return product.stocks.find(stock => String(stock.warehouse_id) === productWarehouse.value)
        || Object.fromEntries(['incoming', 'outgoing', 'closing'].map(key => [key, product.tracking_mode === 'none' ? null : '0.000000']));
}
function productStockState(value) {
    return value == null ? 'untracked' : /^-?0*(\.0*)?$/.test(String(value)) ? 'empty' : String(value).startsWith('-') ? 'negative' : 'stock';
}
function filterProducts(resetPage = true) {
    if (resetPage) productPage = 1;
    const query = normalize(productSearch.value.trim());
    const matches = productRows.filter(row => {
        row.hidden = true;
        const product = JSON.parse(row.dataset.product), balance = visibleProductBalance(product);
        row.querySelectorAll('[data-product-quantity]').forEach(cell => { cell.textContent = formatProductQuantity(balance[cell.dataset.productQuantity], product.tracking_mode); });
        return (!productCategory || product.category === productCategory)
            && (!productWarehouse.value || product.movements.some(item => String(item.warehouse_id) === productWarehouse.value))
            && (!productStock.value || (product.tracking_mode === 'quantity' && balance.closing == null ? 'unknown' : productStockState(balance.closing)) === productStock.value)
            && (!productStatus.value || product.status === productStatus.value)
            && normalize([product.code, product.name, ...product.movements.map(item => item.number)].join(' ')).includes(query);
    });
    const pages = Math.max(1, Math.ceil(matches.length / 10)); productPage = Math.min(productPage, pages);
    const start = (productPage - 1) * 10;
    matches.slice(start, start + 10).forEach(row => { row.hidden = false; });
    document.getElementById('product-count').textContent = matches.length ? `Hiển thị ${start + 1}–${Math.min(start + 10, matches.length)} / ${matches.length} hàng hóa` : 'Hiển thị 0 / 0 hàng hóa';
    document.getElementById('product-page').textContent = `${productPage} / ${pages}`;
    document.getElementById('product-previous').disabled = productPage === 1; document.getElementById('product-next').disabled = productPage === pages;
    document.querySelector('.product-empty').hidden = matches.length !== 0;
}
productSearch.addEventListener('input', filterProducts);
[productWarehouse, productStock, productStatus].forEach(input => input.addEventListener('change', filterProducts));
document.getElementById('product-previous').addEventListener('click', () => { productPage--; filterProducts(false); });
document.getElementById('product-next').addEventListener('click', () => { productPage++; filterProducts(false); });
filterProducts();
const productDialog = document.getElementById('product-dialog');
function appendProductRow(container, values) {
    const row = document.createElement('tr');
    for (const value of values) { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); }
    container.append(row);
}
const productCategories = { consumable: 'Vật tư', spare_part: 'Phụ tùng thay thế', ccdc: 'Công cụ, dụng cụ', fixed_asset: 'Tài sản cố định', other: 'Hàng hóa khác' };
function openProduct(product) {
    document.getElementById('product-detail-name').textContent = product.name;
    for (const field of ['code', 'category', 'unit', 'tracking_mode', 'status']) {
        const value = field === 'category' ? productCategories[product.category] : field === 'tracking_mode' ? (product.tracking_mode === 'quantity' ? 'Có' : 'Không')
            : field === 'status' ? (product.status === 'active' ? 'Đang sử dụng' : 'Ngừng sử dụng') : product[field];
        document.getElementById('product-detail-' + field).textContent = value || 'Chưa có thông tin';
    }
    const stocks = document.getElementById('product-stock-rows'), movements = document.getElementById('product-movement-rows');
    stocks.replaceChildren(); movements.replaceChildren();
    for (const stock of product.stocks) appendProductRow(stocks, [stock.warehouse_name, ...['incoming', 'outgoing', 'closing'].map(key => formatProductQuantity(stock[key], product.tracking_mode))]);
    const statuses = { draft: 'Nháp', confirmed: 'Đã xác nhận', cancelled: 'Đã hủy' };
    for (const item of product.movements) appendProductRow(movements, [`${item.date.split('-').reverse().join('/')} · ${item.number}`, `${item.warehouse_name} · ${item.direction === 'import' ? 'Nhập' : 'Xuất'}`,
        product.tracking_mode === 'none' ? 'Không theo dõi' : formatProductQuantity(item.quantity, product.tracking_mode), statuses[item.status], item.decision_number || (item.decision_status === 'none' ? 'Không có quyết định' : 'Chờ quyết định')]);
    document.getElementById('product-stock-empty').hidden = product.stocks.length !== 0;
    document.getElementById('product-movement-empty').hidden = product.movements.length !== 0;
    productDialog.showModal(); productDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.product-details').forEach(button => button.addEventListener('click', () => openProduct(JSON.parse(button.closest('[data-product]').dataset.product))));
const productEditor = document.getElementById('product-editor'), productForm = document.getElementById('product-form');
function editProduct(product) {
    productForm.reset(); productForm.querySelector('[data-form-error]').hidden = true;
    for (const field of ['id', 'version', 'code', 'name', 'category', 'unit', 'tracking_mode', 'status']) productForm.elements[field].value = product[field] || '';
    productForm.elements.unit.readOnly = product.movements.length !== 0;
    for (const option of productForm.elements.tracking_mode.options) option.disabled = product.movements.length !== 0 && option.value !== product.tracking_mode;
    document.getElementById('product-history-note').textContent = product.movements.length ? 'Hàng hóa đã có phiếu: giữ nguyên đơn vị và cách theo dõi số lượng để bảo toàn lịch sử.' : 'Chưa có phiếu liên quan; có thể điều chỉnh đơn vị và cách theo dõi số lượng.';
    productEditor.showModal(); productEditor.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.edit-product').forEach(button => button.addEventListener('click', () => editProduct(JSON.parse(button.closest('[data-product]').dataset.product))));
productForm.querySelectorAll('[data-close-product]').forEach(button => button.addEventListener('click', () => productEditor.close()));
productForm.addEventListener('submit', event => { event.preventDefault(); submitManagementForm(productForm, productEditor, 'product-notice'); });
const productExportForm = document.getElementById('product-export-form');
if (productExportForm) {
    const dialog = document.getElementById('product-export-dialog');
    const validateMonths = () => productExportForm.elements.to_month.setCustomValidity(
        productExportForm.elements.from_month.value > productExportForm.elements.to_month.value ? 'Tháng kết thúc phải sau hoặc bằng tháng bắt đầu.' : '');
    document.getElementById('product-export-open').addEventListener('click', () => {
        const options = [...productExportForm.elements.warehouse.options].filter(option=>option.value);
        productExportForm.elements.warehouse.value = productWarehouse.value || (options.length===1?options[0].value:'');
        validateMonths(); dialog.showModal(); dialog.querySelector('.account-dialog-body').scrollTop=0;
    });
    productExportForm.querySelectorAll('[data-close-product-export]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
    for (const name of ['from_month','to_month']) productExportForm.elements[name].addEventListener('input',validateMonths);
    productExportForm.addEventListener('submit',event=>{
        if (productExportForm.dataset.preview==='true') { event.preventDefault(); showNotification('Bản xem trước. Xuất Excel dùng dữ liệu thật tại trang danh mục hàng hóa.','info'); }
    });
}
