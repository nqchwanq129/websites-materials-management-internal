const exportRows = [...document.querySelectorAll('[data-export]')];
const exportSearch = document.getElementById('export-search');
const exportWarehouse = document.getElementById('export-warehouse'), exportType = document.getElementById('export-type'), exportStatus = document.getElementById('export-status');
const exportStart = document.getElementById('export-start'), exportEnd = document.getElementById('export-end'), exportSort = document.getElementById('export-sort');
const exportPrevious = document.getElementById('export-previous'), exportNext = document.getElementById('export-next');
let exportPage = 1;
function filterExports(reset = true) {
    if (reset) exportPage = 1;
    const invalid = exportStart.value && exportEnd.value && exportStart.value > exportEnd.value;
    document.getElementById('export-error').hidden = !invalid;
    const query = normalize(exportSearch.value.trim());
    const matches = exportRows.filter(row => {
        row.hidden = true;
        const bill = JSON.parse(row.dataset.export);
        return !invalid && (!exportWarehouse.value || bill.warehouse_id === exportWarehouse.value)
            && (!exportType.value || bill.issue_type === exportType.value) && (!exportStatus.value || bill.status === exportStatus.value)
            && (!exportStart.value || bill.issued_date >= exportStart.value) && (!exportEnd.value || bill.issued_date <= exportEnd.value)
            && normalize([bill.number,bill.receiver_name,bill.reason,bill.destination_warehouse_name,bill.warehouse_name,...bill.items.map(item=>[item.product_name,item.product_code,item.source_number,item.specifications].join(' '))].join(' ')).includes(query);
    });
    matches.sort((a,b) => {
        const x=JSON.parse(a.dataset.export), y=JSON.parse(b.dataset.export);
        if (exportSort.value === 'number') return x.number.localeCompare(y.number,'vi',{numeric:true}) || x.id.localeCompare(y.id,'en',{numeric:true});
        return (x.issued_date.localeCompare(y.issued_date) || x.id.localeCompare(y.id,'en',{numeric:true})) * (exportSort.value === 'oldest' ? 1 : -1);
    });
    const pages=Math.max(1,Math.ceil(matches.length/10)); exportPage=Math.min(exportPage,pages);
    const start=(exportPage-1)*10;
    matches.slice(start,start+10).forEach(row=>{row.hidden=false;row.parentNode.appendChild(row);});
    document.getElementById('export-count').textContent=matches.length ? `Hiển thị ${start+1}–${Math.min(start+10,matches.length)} / ${matches.length} phiếu xuất` : 'Hiển thị 0 / 0 phiếu xuất';
    document.getElementById('export-page').textContent=`${exportPage} / ${pages}`;
    exportPrevious.disabled=exportPage===1; exportNext.disabled=exportPage===pages;
    document.querySelector('.export-empty').hidden=matches.length!==0;
}
exportSearch.addEventListener('input',()=>filterExports());
[exportWarehouse,exportType,exportStatus,exportStart,exportEnd,exportSort].forEach(input=>input.addEventListener('change',()=>filterExports()));
exportPrevious.addEventListener('click',()=>{exportPage--;filterExports(false);});
exportNext.addEventListener('click',()=>{exportPage++;filterExports(false);});
document.getElementById('export-reset').addEventListener('click',()=>{[exportSearch,exportWarehouse,exportType,exportStatus,exportStart,exportEnd].forEach(input=>{input.value='';});exportSort.value='newest';filterExports();});
filterExports();
const exportDialog=document.getElementById('export-dialog');
const exportTypes=Object.fromEntries([...exportType.options].filter(o=>o.value).map(o=>[o.value,o.textContent]));
const exportStatuses={draft:'Nháp',confirmed:'Đã xác nhận',cancelled:'Đã hủy'};
function openExport(bill) {
    document.getElementById('export-description').textContent=bill.number+' · '+bill.warehouse_name;
    for (const element of exportDialog.querySelectorAll('[id^="export-detail-"]')) {
        const key=element.id.slice('export-detail-'.length);
        let value=bill[key];
        if (key==='issue_type') value=exportTypes[value];
        if (key==='status') value=exportStatuses[value];
        if (['issued_date'].includes(key) && value) value=value.split('-').reverse().join('/');
        element.textContent=value || 'Chưa có thông tin';
    }
    const rows=document.getElementById('export-item-rows'); rows.replaceChildren();
    for (const item of bill.items) {
        const row=document.createElement('tr');
        for (const [main,extra] of [[item.product_name,item.specifications], [item.source_number,item.source_date?.split('-').reverse().join('/')], [item.issued_quantity_display,item.unit], [item.unit_price_display,'VND'],
            [item.amount_before_tax_display,'VND'], [item.decision_number || (item.decision_status==='none' ? 'Không có quyết định' : 'Chờ quyết định'),item.note]]) {
            const cell=document.createElement('td'); cell.textContent=main;
            if (extra) {const sub=document.createElement('small');sub.textContent=extra;cell.append(sub);}
            row.append(cell);
        }
        rows.append(row);
    }
    document.getElementById('export-items-empty').hidden=bill.items.length!==0;
    const files=document.getElementById('export-attachments');files.replaceChildren();
    for(const file of bill.attachments || []) {
        const link=document.createElement('a');link.className='button';link.textContent=file.original_name;
        link.href='index.php?page=export-file&id='+encodeURIComponent(file.id);files.append(link);
    }
    document.getElementById('export-pdf-empty').hidden=Boolean(bill.attachments?.length);
    document.getElementById('export-transfers-section').hidden=!bill.transfers?.length;
    document.getElementById('export-transfers').textContent=(bill.transfers||[]).map(t=>t.number+' · '+exportStatuses[t.status]).join(', ');
    exportDialog.showModal(); exportDialog.querySelector('.account-dialog-body').scrollTop=0;
}
document.querySelectorAll('.export-details').forEach(button=>button.addEventListener('click',()=>openExport(JSON.parse(button.closest('[data-export]').dataset.export))));
const requestedExportId=new URLSearchParams(window.location.search).get('id');
const requestedExport=exportRows.find(row=>JSON.parse(row.dataset.export).id===requestedExportId);
if(requestedExport)openExport(JSON.parse(requestedExport.dataset.export));
