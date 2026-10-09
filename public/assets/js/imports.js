const importRows = [...document.querySelectorAll('[data-import]')];
const importSearch = document.getElementById('import-search');
const importWarehouse = document.getElementById('import-warehouse'), importType = document.getElementById('import-type'), importStatus = document.getElementById('import-status');
const importStart = document.getElementById('import-start'), importEnd = document.getElementById('import-end'), importSort = document.getElementById('import-sort');
const importPrevious = document.getElementById('import-previous'), importNext = document.getElementById('import-next');
let importPage = 1;
function filterImports(reset = true) {
    if (reset) importPage = 1;
    const invalid = importStart.value && importEnd.value && importStart.value > importEnd.value;
    document.getElementById('import-error').hidden = !invalid;
    const query = normalize(importSearch.value.trim());
    const matches = importRows.filter(row => {
        row.hidden = true;
        const bill = JSON.parse(row.dataset.import);
        return !invalid && (!importWarehouse.value || bill.warehouse_id === importWarehouse.value)
            && (!importType.value || bill.receipt_type === importType.value) && (!importStatus.value || bill.status === importStatus.value)
            && (!importStart.value || bill.received_date >= importStart.value) && (!importEnd.value || bill.received_date <= importEnd.value)
            && normalize([bill.number,bill.invoice_number,bill.invoice_series,bill.supplier_name,bill.sender_name,bill.warehouse_name,...bill.items.map(item=>[item.product_name,item.product_code,item.specifications].join(' '))].join(' ')).includes(query);
    });
    matches.sort((a,b) => {
        const x=JSON.parse(a.dataset.import), y=JSON.parse(b.dataset.import);
        if (importSort.value === 'number') return x.number.localeCompare(y.number,'vi',{numeric:true}) || x.id.localeCompare(y.id,'en',{numeric:true});
        return (x.received_date.localeCompare(y.received_date) || x.id.localeCompare(y.id,'en',{numeric:true})) * (importSort.value === 'oldest' ? 1 : -1);
    });
    const pages=Math.max(1,Math.ceil(matches.length/10)); importPage=Math.min(importPage,pages);
    const start=(importPage-1)*10;
    matches.slice(start,start+10).forEach(row=>{row.hidden=false;row.parentNode.appendChild(row);});
    document.getElementById('import-count').textContent=matches.length ? `Hiển thị ${start+1}–${Math.min(start+10,matches.length)} / ${matches.length} phiếu nhập` : 'Hiển thị 0 / 0 phiếu nhập';
    document.getElementById('import-page').textContent=`${importPage} / ${pages}`;
    importPrevious.disabled=importPage===1; importNext.disabled=importPage===pages;
    document.querySelector('.import-empty').hidden=matches.length!==0;
}
importSearch.addEventListener('input',()=>filterImports());
[importWarehouse,importType,importStatus,importStart,importEnd,importSort].forEach(input=>input.addEventListener('change',()=>filterImports()));
importPrevious.addEventListener('click',()=>{importPage--;filterImports(false);});
importNext.addEventListener('click',()=>{importPage++;filterImports(false);});
document.getElementById('import-reset').addEventListener('click',()=>{[importSearch,importWarehouse,importType,importStatus,importStart,importEnd].forEach(input=>{input.value='';});importSort.value='newest';filterImports();});
filterImports();
const importDialog=document.getElementById('import-dialog');
const importTypes={purchase:'Mua hàng',return:'Nhập trả lại',opening:'Tồn đầu kỳ',transfer:'Điều chuyển',adjustment:'Điều chỉnh'};
const importStatuses={draft:'Nháp',confirmed:'Đã xác nhận',cancelled:'Đã hủy'};
function openImport(bill) {
    document.getElementById('import-description').textContent=bill.number+' · '+bill.warehouse_name;
    for (const element of importDialog.querySelectorAll('[id^="import-detail-"]')) {
        const key=element.id.slice('import-detail-'.length);
        let value=bill[key];
        if (key==='receipt_type') value=importTypes[value];
        if (key==='status') value=importStatuses[value];
        if (['received_date','invoice_date'].includes(key) && value) value=value.split('-').reverse().join('/');
        element.textContent=value || 'Chưa có thông tin';
    }
    const rows=document.getElementById('import-item-rows'); rows.replaceChildren();
    for (const item of bill.items) {
        const row=document.createElement('tr');
        for (const [main,extra] of [[item.product_name,item.specifications], [item.received_quantity_display,item.unit], [item.unit_price_display,'VND'],
            [item.amount_before_tax_display,'VND'], [item.tax_amount_display,item.tax_code], [item.decision_number || (item.decision_status==='none' ? 'Không có quyết định' : 'Chờ quyết định'),item.note]]) {
            const cell=document.createElement('td'); cell.textContent=main;
            if (extra) {const sub=document.createElement('small');sub.textContent=extra;cell.append(sub);}
            row.append(cell);
        }
        rows.append(row);
    }
    document.getElementById('import-items-empty').hidden=bill.items.length!==0;
    const files=document.getElementById('import-attachments');files.replaceChildren();
    for(const file of bill.attachments || []) {
        const link=document.createElement('a');link.className='button';link.textContent=file.original_name;
        link.href='index.php?page=import-file&id='+encodeURIComponent(file.id);files.append(link);
    }
    document.getElementById('import-pdf-empty').hidden=Boolean(bill.attachments?.length);
    importDialog.showModal(); importDialog.querySelector('.account-dialog-body').scrollTop=0;
}
document.querySelectorAll('.import-details').forEach(button=>button.addEventListener('click',()=>openImport(JSON.parse(button.closest('[data-import]').dataset.import))));
