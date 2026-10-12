const receiptPrintDetails = document.querySelector('[data-receipt-print]');
if (receiptPrintDetails) {
    const form = receiptPrintDetails.closest('form');
    const settings = JSON.parse(receiptPrintDetails.dataset.settings), warehouses = JSON.parse(receiptPrintDetails.dataset.warehouses), versions = JSON.parse(receiptPrintDetails.dataset.versions);
    const validateSigner = select => select.setCustomValidity(select.selectedOptions[0]?.dataset.active === 'false' ? 'Người ký đã ngừng hoạt động. Hãy chọn nhân viên khác hoặc để trống.' : '');
    const updatePrintFields = () => {
        const id = form.elements.warehouse.value, config = settings[id] || {}, warehouse = warehouses[id] || {};
        receiptPrintDetails.querySelectorAll('[data-print-value]').forEach(input => { input.value = config[input.dataset.printValue] || (input.dataset.printValue === 'department_address' ? warehouse.address || '' : ''); });
        receiptPrintDetails.querySelectorAll('[data-print-signer]').forEach(select => {
            select.value = config[select.dataset.printSigner] || ''; validateSigner(select);
        });
        form.elements.print_version.value = versions[id] || '';
        form.elements.print_version.disabled = !versions[id];
    };
    form.elements.warehouse.addEventListener('change', updatePrintFields);
    form.addEventListener('invalid', event => { if (receiptPrintDetails.contains(event.target)) receiptPrintDetails.open = true; }, true);
    receiptPrintDetails.querySelectorAll('[data-print-signer]').forEach(select => select.addEventListener('change', () => validateSigner(select)));
    updatePrintFields();
}
