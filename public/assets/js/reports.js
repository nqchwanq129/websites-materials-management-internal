const reportData = JSON.parse(document.getElementById('report-data').textContent);
const reportRows = [...document.querySelectorAll('[data-report-row]')];
let reportPage = 1;
function paginateReport() {
    const pages = Math.max(1, Math.ceil(reportRows.length / 10));
    reportPage = Math.max(1, Math.min(reportPage, pages));
    reportRows.forEach((row, i) => { row.hidden = i < (reportPage - 1) * 10 || i >= reportPage * 10; });
    document.getElementById('report-count').textContent = reportRows.length ? `Hiển thị ${(reportPage - 1) * 10 + 1}–${Math.min(reportPage * 10, reportRows.length)} / ${reportRows.length} dòng` : 'Hiển thị 0 / 0 dòng';
    document.getElementById('report-page').textContent = `${reportPage} / ${pages}`;
    document.getElementById('report-previous').disabled = reportPage === 1;
    document.getElementById('report-next').disabled = reportPage === pages;
}
document.getElementById('report-previous').addEventListener('click', () => { reportPage--; paginateReport(); });
document.getElementById('report-next').addEventListener('click', () => { reportPage++; paginateReport(); });
paginateReport();
const reportFilterForm = document.getElementById('report-filter-form');
function validateReportPeriod() {
    const valid = Boolean(reportFilterForm.elements.from.value && reportFilterForm.elements.to.value && reportFilterForm.elements.from.value <= reportFilterForm.elements.to.value);
    const error = document.getElementById('report-filter-error');
    error.hidden = valid;
    if (!valid) error.textContent = 'Chọn đủ hai ngày; ngày bắt đầu phải trước hoặc bằng ngày kết thúc.';
    return valid;
}
reportFilterForm.addEventListener('submit', event => { if (!validateReportPeriod()) event.preventDefault(); });
document.querySelectorAll('[data-report-period]').forEach(button => button.addEventListener('click', () => {
    const now = new Date(); let month = now.getMonth(), endMonth = month;
    if (button.dataset.reportPeriod === 'quarter') { month = Math.floor(month / 3) * 3; endMonth = month + 2; }
    if (button.dataset.reportPeriod === 'year') { month = 0; endMonth = 11; }
    const localDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    reportFilterForm.elements.from.value = localDate(new Date(now.getFullYear(), month, 1));
    reportFilterForm.elements.to.value = localDate(new Date(now.getFullYear(), endMonth + 1, 0));
    validateReportPeriod();
}));
function formatReportNumber(value) {
    if (value == null) return 'Không theo dõi';
    const [whole, decimal = ''] = String(value).split('.');
    const fraction = decimal.replace(/0+$/, '');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (fraction ? ',' + fraction : '');
}
const reportDialog = document.getElementById('report-dialog');
function openReportDetail(key) {
    const row = reportData.report.rows.find(row => row.key === key);
    document.getElementById('report-detail-name').textContent = `${row.code} · ${row.name} · ${row.warehouse_name}`;
    document.getElementById('report-detail-specifications').textContent = row.specifications;
    const balance = document.getElementById('report-detail-balance'); balance.replaceChildren();
    for (const [field, label] of [['opening', 'Tồn đầu'], ['incoming', 'Nhập'], ['outgoing', 'Xuất'], ['closing', 'Tồn cuối']]) {
        const item = document.createElement('p');
        item.textContent = `${label}: ${formatReportNumber(row[field])}${row[field] == null ? '' : ' ' + row.unit}`;
        balance.append(item);
    }
    const rows = document.getElementById('report-movement-rows'); rows.replaceChildren();
    for (const movement of row.movements) {
        const tr = document.createElement('tr');
        for (const value of [`${movement.date.split('-').reverse().join('/')} · ${movement.number}`, movement.direction === 'import' ? 'Nhập' : 'Xuất',
            formatReportNumber(movement.quantity), formatReportNumber(movement.amount_before_tax) + ' ₫', movement.decision_number || 'Chờ quyết định']) {
            const td = document.createElement('td'); td.textContent = value; tr.append(td);
        }
        rows.append(tr);
    }
    document.getElementById('report-movement-empty').hidden = row.movements.length !== 0;
    reportDialog.showModal(); reportDialog.querySelector('.account-dialog-body').scrollTop = 0;
}
document.querySelectorAll('.report-details').forEach(button => button.addEventListener('click', () => openReportDetail(button.dataset.key)));
function drawReportChart() {
    const months = Object.entries(reportData.report.months);
    const container = document.getElementById('report-chart');
    if (!months.length) { container.textContent = 'Không có dữ liệu biểu đồ.'; return; }
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg'); const width = Math.max(600, months.length * 65 + 70);
    svg.setAttribute('viewBox', `0 0 ${width} 230`); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Giá trị nhập xuất theo tháng, triệu VND');
    const element = (name, attributes, text = '') => {
        const node = document.createElementNS(ns, name);
        for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);
        if (text) node.textContent = text; svg.append(node); return node;
    };
    const maximum = Math.max(1, ...months.flatMap(([, values]) => [Number(values.import), Number(values.export)]));
    element('text', { x: 8, y: 16, class: 'report-axis' }, 'Triệu VND');
    for (let step = 0; step <= 4; step++) {
        const y = 35 + step * 37.5;
        element('line', { x1: 60, x2: width - 15, y1: y, y2: y, class: 'report-chart-grid' });
        element('text', { x: 52, y: y + 4, 'text-anchor': 'end', class: 'report-axis' }, new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 1 }).format(maximum * (4 - step) / 4 / 1000000));
    }
    months.forEach(([month, values], i) => {
        const x = 60 + (i + 0.5) * (width - 80) / months.length;
        for (const [direction, offset] of [['import', -24], ['export', 3]]) {
            const height = Number(values[direction]) / maximum * 150;
            const bar = element('rect', { x: x + offset, y: 185 - height, width: 21, height, rx: 3, class: `report-bar-${direction}` });
            const title = document.createElementNS(ns, 'title'); title.textContent = `${month}: ${direction === 'import' ? 'Nhập' : 'Xuất'} ${formatReportNumber(values[direction])} VND`; bar.append(title);
        }
        element('text', { x, y: 210, 'text-anchor': 'middle', class: 'report-axis' }, month.split('-').reverse().join('/'));
    });
    container.append(svg);
}
drawReportChart();
function reportExcelValue(value) {
    if (value == null) return 'Không theo dõi';
    // Excel has 15 significant digits; keep larger DECIMAL values as text.
    return String(value).replace(/[^0-9]/g, '').replace(/^0+/, '').length <= 15 ? Number(value) : String(value);
}
function buildReportWorkbook() {
    const headers = ['Mã hàng', 'Tên hàng', 'Kho', 'Đơn vị', 'Đơn giá nhập tham chiếu', 'Tồn đầu', 'Nhập trong kỳ', 'Xuất trong kỳ', 'Tồn cuối', 'Giá trị tồn đầu', 'Giá trị nhập', 'Giá trị xuất', 'Giá trị tồn cuối', 'Quy cách'];
    const rows = reportData.report.rows.map(row => [row.code, row.name, row.warehouse_name, row.unit, reportExcelValue(row.unit_price),
        ...['opening', 'incoming', 'outgoing', 'closing', 'opening_value', 'incoming_value', 'outgoing_value', 'closing_value'].map(key => reportExcelValue(row[key])), row.specifications]);
    const totals = reportData.report.summary;
    const sheet = XLSX.utils.aoa_to_sheet([['VISHIPEL — ' + reportData.title], ['DỮ LIỆU MẪU — CHƯA KẾT NỐI DB'],
        [`Kỳ: ${reportData.filters.from} đến ${reportData.filters.to}`], [`Kho: ${reportData.warehouses[reportData.filters.warehouse] || 'Tất cả kho mẫu'} · Giá trị trước thuế, VND`], [], headers, ...rows,
        ['Tổng giá trị', '', '', '', '', '', '', '', '', ...['opening_value', 'incoming_value', 'outgoing_value', 'closing_value'].map(key => reportExcelValue(totals[key]))]]);
    sheet['!cols'] = [16, 36, 22, 14, ...Array(9).fill({ wch: 22 }), { wch: 55 }].map(value => typeof value === 'number' ? { wch: value } : value);
    sheet['!autofilter'] = { ref: `A6:N${Math.max(6, rows.length + 6)}` };
    const movements = reportData.report.rows.flatMap(row => row.movements.map(movement => [row.code, row.name, row.warehouse_name, movement.date, movement.number,
        movement.direction === 'import' ? 'Nhập' : 'Xuất', reportExcelValue(movement.quantity), row.unit, reportExcelValue(movement.amount_before_tax), movement.decision_number || 'Chờ quyết định']));
    const detail = XLSX.utils.aoa_to_sheet([['DỮ LIỆU MẪU — PHÁT SINH ĐÃ XÁC NHẬN TRONG KỲ'], ['Mã hàng', 'Tên hàng', 'Kho', 'Ngày', 'Số phiếu', 'Loại', 'Số lượng', 'Đơn vị', 'Giá trị trước thuế', 'Quyết định'], ...movements]);
    detail['!cols'] = [16, 36, 22, 14, 28, 12, 20, 12, 24, 22].map(wch => ({ wch }));
    const workbook = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(workbook, sheet, 'Bao cao'); XLSX.utils.book_append_sheet(workbook, detail, 'Phat sinh');
    return workbook;
}
document.getElementById('export-report').addEventListener('click', () => {
    try { XLSX.writeFile(buildReportWorkbook(), `VISHIPEL_MAU_${reportData.filters.kind}_${reportData.filters.from}_${reportData.filters.to}.xlsx`); showNotification('Đã xuất toàn bộ dữ liệu mẫu theo bộ lọc, bao gồm các trang chưa hiển thị.', 'success'); }
    catch { showNotification('Không thể xuất Excel. Hãy tải lại trang và thử lại.', 'error'); }
});
document.getElementById('print-report').addEventListener('click', () => window.print());
