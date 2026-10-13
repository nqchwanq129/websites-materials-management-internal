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
            && normalize([bill.number,bill.invoice_number,bill.invoice_series,bill.supplier_name,bill.sender_name,bill.warehouse_name,...bill.items.map(item=>[item.product_name,item.product_code].join(' '))].join(' ')).includes(query);
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
const importEditForm=document.getElementById('import-edit-form');
let currentImport=null, importEditing=false, importEditDirty=false, importSaving=false;
const importTypes={purchase:'Mua hàng',return:'Nhập trả lại',opening:'Tồn đầu kỳ',transfer:'Điều chuyển',adjustment:'Điều chỉnh'};
const importStatuses={draft:'Nháp',confirmed:'Đã xác nhận',cancelled:'Đã hủy'};
function openImport(bill) {
    currentImport=bill;importEditDirty=false;
    importEditForm.elements.id.value=bill.id;importEditForm.elements.version.value=bill.version || '';
    importEditForm.dataset.successUrl='index.php?page=imports&id='+encodeURIComponent(bill.id);
    const excel=document.getElementById('import-excel');
    if(excel.tagName==='A')excel.href='index.php?page=import-excel&id='+encodeURIComponent(bill.id);
    document.getElementById('import-description').textContent=bill.number+' · '+bill.warehouse_name;
    for (const element of importDialog.querySelectorAll('[id^="import-detail-"]')) {
        const key=element.id.slice('import-detail-'.length);
        let value=bill[key];
        if (key==='receipt_type') value=importTypes[value];
        if (key==='status') value=importStatuses[value];
        if (['received_date','invoice_date'].includes(key) && value) value=value.split('-').reverse().join('/');
        element.textContent=value || 'Chưa có thông tin';
    }
    renderImportItems(false);
    const files=document.getElementById('import-attachments');files.replaceChildren();
    for(const file of bill.attachments || []) {
        const group=document.createElement('div');group.className='import-pdf-file';
        const name=document.createElement('span');name.textContent=file.original_name;
        const view=document.createElement('button');view.type='button';view.className='button button-primary';view.textContent='Xem PDF';
        const url='index.php?page=import-file&id='+encodeURIComponent(file.id);
        view.addEventListener('click',()=>showImportPdf(url,file.original_name));group.append(name,view);files.append(group);
    }
    document.getElementById('import-pdf-empty').hidden=Boolean(bill.attachments?.length);
    importDialog.showModal(); importDialog.querySelector('.account-dialog-body').scrollTop=0;
}
function renderImportItems(editing) {
    if (currentImport.can_edit === false) editing = false;
    importEditing=editing;importEditForm.querySelector('[data-form-error]').hidden=true;
    document.getElementById('import-dialog-title').textContent=editing?'Chỉnh sửa phiếu nhập':'Chi tiết phiếu nhập';
    document.getElementById('import-edit').hidden=editing || currentImport.can_edit === false;
    document.getElementById('import-edit').disabled=currentImport.status==='cancelled' || !currentImport.items.length;
    const confirm=document.getElementById('import-confirm');
    confirm.hidden=editing || currentImport.can_edit===false || currentImport.status!=='draft';
    confirm.disabled=!currentImport.items.length || Boolean(currentImport.remove_reason) || currentImport.receipt_type==='transfer';
    confirm.title=currentImport.remove_reason || (!currentImport.items.length?'Phiếu chưa có dòng hàng.':'');
    document.getElementById('import-edit-save').hidden=!editing;
    document.getElementById('import-edit-cancel').hidden=!editing;
    document.getElementById('import-edit-note').hidden=!editing;
    const rows=document.getElementById('import-item-rows'); rows.replaceChildren();
    for (const item of currentImport.items) {
        const row=document.createElement('tr');
        row.dataset.lineId=String(item.id);
        for (const [main,extra] of [[item.product_name,item.product_code], ['Chưa có ảnh',''], [item.received_quantity_display,item.unit], [item.unit_price_display,'VND'],
            [item.amount_before_tax_display,'VND'], [item.tax_amount_display,item.tax_code], [item.decision_number || (item.decision_status==='none' ? 'Không có quyết định' : currentImport.status==='cancelled' ? 'Phiếu đã hủy' : 'Chờ quyết định'),item.note]]) {
            const cell=document.createElement('td'); cell.textContent=main;
            if (extra) {const sub=document.createElement('small');sub.textContent=extra;cell.append(sub);}
            row.append(cell);
        }
        if(item.images?.length) {
            const photos=item.images.map(file=>({url:'index.php?page=import-image&id='+encodeURIComponent(file.id),name:file.original_name}));
            const cell=row.cells[1];
            const button=document.createElement('button');button.type='button';button.className='import-item-photo';button.setAttribute('aria-label','Xem '+photos.length+' ảnh của '+item.product_name);
            const image=document.createElement('img');image.src=photos[0].url;image.alt=item.product_name;image.loading='lazy';button.append(image);
            if(photos.length>1){const badge=document.createElement('small');badge.textContent='+'+(photos.length-1);button.append(badge);}
            button.addEventListener('click',()=>showImportImages(photos,0,item.product_name));
            cell.replaceChildren(button);
        }
        if(editing)editImportRow(row,item);
        else if(['ccdc','fixed_asset'].includes(item.category)) {
            const serials=document.createElement('small');
            serials.className='import-serial-list';serials.textContent=item.serial_numbers?.length
                ? item.serial_numbers.map((serial,index)=>`Chiếc ${index+1}: ${serial || 'Chưa có serial'}`).join('\n') : 'Serial: Chưa cập nhật';
            row.cells[0].append(serials);
        }
        rows.append(row);
    }
    document.getElementById('import-items-empty').hidden=currentImport.items.length!==0;
    if(!editing)for(const field of ['total_before_tax_display','total_after_tax_display'])document.getElementById('import-detail-'+field).textContent=currentImport[field];
}
function editImportRow(row,item) {
    const locked=Boolean(Number(item.stock_locked) || item.source_export_detail_id);
    const field=(column,key,label,value,readonly=false,maxLength=32)=>{
        const wrapper=document.createElement('label');wrapper.textContent=label;
        const input=document.createElement(key==='note'?'textarea':'input');input.dataset.field=key;input.value=value ?? '';input.maxLength=maxLength;input.readOnly=readonly;
        if(['received_quantity','unit_price','amount_before_tax','tax_amount'].includes(key))input.inputMode='decimal';
        input.required=key!=='note' && key!=='tax_code' && !(key==='unit' && item.unit===null) && !(key==='received_quantity' && (item.tracking_mode==='none' || item.received_quantity===null));
        wrapper.append(input);row.cells[column].append(wrapper);return input;
    };
    for(const index of [0,2,3,4,5,6])row.cells[index].replaceChildren();
    field(0,'product_name','Tên hàng trên phiếu',item.product_name,locked,255);
    if(['ccdc','fixed_asset'].includes(item.category)) {
        const label=document.createElement('label');label.textContent='Serial từng chiếc (mỗi dòng một chiếc)';
        const serials=document.createElement('textarea');serials.dataset.field='serial_numbers';serials.rows=3;serials.maxLength=202000;
        serials.value=(item.serial_numbers || []).join('\n');serials.placeholder='SN-001\n\nSN-003';label.append(serials);row.cells[0].append(label);
        const hint=document.createElement('small');hint.className='import-line-lock';hint.textContent='Giữ đúng thứ tự từng chiếc. Để dòng trống nếu chiếc đó chưa có serial.';row.cells[0].append(hint);
    }
    if(locked){const hint=document.createElement('small');hint.className='import-line-lock';hint.textContent='Đã xuất kho / nhận điều chuyển: giữ nguyên thông tin hàng và giá trị.';row.cells[0].append(hint);}
    const quantity=field(2,'received_quantity','Số lượng',item.received_quantity,locked || item.tracking_mode==='none');
    if(item.tracking_mode==='none')quantity.placeholder='Không theo dõi';
    field(2,'unit','Đơn vị',item.unit,locked,50);
    field(3,'unit_price','Đơn giá (VND)',item.unit_price,locked);
    field(4,'amount_before_tax','Trước thuế (VND)',item.amount_before_tax,locked);
    field(5,'tax_code','Thuế suất / Mã thuế',item.tax_code,locked);
    field(5,'tax_amount','Tiền thuế (VND)',item.tax_amount,locked);
    const label=document.createElement('label');label.textContent='Quyết định';
    const select=document.createElement('select');select.dataset.field='decision';
    for(const [value,text] of [['pending','Chờ quyết định'],['none','Không có quyết định']])select.add(new Option(text,value));
    for(const option of document.getElementById('import-decision-options').content.querySelectorAll('option'))if(option.dataset.warehouse===String(currentImport.warehouse_id)){
        const choice=option.cloneNode(true);choice.disabled=currentImport.status!=='confirmed' && choice.value!==String(item.decision_id);select.append(choice);
    }
    const decision=item.decision_id?String(item.decision_id):(item.decision_status || 'pending');
    if(![...select.options].some(option=>option.value===decision))select.add(new Option(item.decision_number || 'Quyết định đã liên kết',decision));
    select.value=decision;label.append(select);row.cells[6].append(label);
    const note=field(6,'note','Ghi chú',item.note,false,16000);note.required=decision==='none';
    select.addEventListener('change',()=>{note.required=select.value==='none';});
}
function importEditItems() {
    return [...document.querySelectorAll('#import-item-rows tr')].map(row=>{
        const item={id:row.dataset.lineId};for(const input of row.querySelectorAll('[data-field]'))item[input.dataset.field]=input.dataset.field==='serial_numbers'?input.value:input.value.trim();return item;
    });
}
function importDecimalInput(value) {return (value/1000000n).toString()+'.'+(value%1000000n).toString().padStart(6,'0');}
document.getElementById('import-item-rows').addEventListener('input',event=>{
    if(!importEditing)return;importEditDirty=true;
    const row=event.target.closest('tr'),key=event.target.dataset.field;
    const control=field=>row.querySelector('[data-field="'+field+'"]');
    try {
        if(['received_quantity','unit_price'].includes(key)){
            const quantity=control('received_quantity').value,price=parseReceiptDecimal(control('unit_price').value);
            control('amount_before_tax').value=importDecimalInput(quantity?parseReceiptDecimal(quantity)*price/1000000n:price);
        }
        if(['received_quantity','unit_price','amount_before_tax','tax_code'].includes(key)){
            const code=control('tax_code').value.trim(),rate=/^(\d{1,2}(?:[.,]\d{1,6})?)%?$/.exec(code);
            if(rate)control('tax_amount').value=importDecimalInput(parseReceiptDecimal(control('amount_before_tax').value)*parseReceiptDecimal(rate[1])/100000000n);
            else if(['','KCT','KKKNT'].includes(code.toUpperCase()))control('tax_amount').value='0.000000';
        }
        const items=importEditItems(),before=items.reduce((sum,item)=>sum+parseReceiptDecimal(item.amount_before_tax),0n),tax=items.reduce((sum,item)=>sum+parseReceiptDecimal(item.tax_amount),0n);
        document.getElementById('import-detail-total_before_tax_display').textContent=formatReceiptDecimal(before)+' ₫';
        document.getElementById('import-detail-total_after_tax_display').textContent=formatReceiptDecimal(before+tax)+' ₫';
    } catch { /* Partial decimal input is checked when saving. */ }
});
document.getElementById('import-edit').addEventListener('click',()=>renderImportItems(true));
function discardImportEdit() {return !importEditDirty || window.confirm('Bỏ các thay đổi chưa lưu trong phiếu nhập?');}
document.getElementById('import-edit-cancel').addEventListener('click',()=>{if(!importSaving && discardImportEdit()){importEditDirty=false;renderImportItems(false);}});
importDialog.querySelectorAll('[data-close-import]').forEach(button=>button.addEventListener('click',()=>{if(!importSaving && discardImportEdit())importDialog.close();}));
importDialog.addEventListener('cancel',event=>{if(importSaving || !discardImportEdit())event.preventDefault();});
importEditForm.addEventListener('submit',async event=>{
    event.preventDefault();if(!importEditing || importSaving)return;
    const error=importEditForm.querySelector('[data-form-error]');
    try {
        const items=importEditItems();
        for(const item of items){for(const key of ['unit_price','amount_before_tax','tax_amount'])parseReceiptDecimal(item[key]);if(item.received_quantity)parseReceiptDecimal(item.received_quantity);}
        importEditForm.elements.items.value=JSON.stringify(items);importSaving=true;
        await submitManagementForm(importEditForm,importDialog,'import-notice');
    } catch(failure){error.textContent=failure.message;error.hidden=false;showNotification(error.textContent,'warning');}
    finally{importSaving=false;}
});
document.querySelectorAll('.import-details').forEach(button=>button.addEventListener('click',()=>openImport(JSON.parse(button.closest('[data-import]').dataset.import))));
const importStateDialog=document.getElementById('import-state-dialog'),importStateForm=document.getElementById('import-state-form');
let importStateSaving=false;
function openImportState(bill,action) {
    if(importStateSaving || !['delete','cancel','confirm'].includes(action))return;
    importStateForm.reset();importStateForm.elements.action.value=action;importStateForm.elements.id.value=bill.id;importStateForm.elements.version.value=bill.version || '';
    importStateForm.querySelector('[data-form-error]').hidden=true;
    const deleting=action==='delete',confirming=action==='confirm';
    document.getElementById('import-state-title').textContent=confirming?'Xác nhận nhập kho':deleting?'Xóa phiếu nháp':'Hủy phiếu nhập';
    document.getElementById('import-state-description').textContent=bill.number+' · '+bill.warehouse_name;
    document.getElementById('import-state-number').textContent=bill.number;
    document.getElementById('import-state-warehouse').textContent=bill.warehouse_name;
    document.getElementById('import-state-date').textContent=bill.received_date.split('-').reverse().join('/');
    document.getElementById('import-state-items').textContent=bill.items.length+' dòng hàng';
    document.getElementById('import-state-warning').textContent=confirming?'Phiếu sẽ chuyển từ Nháp sang Đã xác nhận. Hàng hóa được tính vào tồn kho và có thể dùng để lập phiếu xuất. Hãy kiểm tra số lượng, đơn vị và tổng tiền trước khi xác nhận.':deleting?'Phiếu nháp, các dòng hàng và ảnh/PDF đính kèm sẽ được xóa. Nhật ký vẫn được giữ lại. Thao tác này không thể hoàn tác.':'Phiếu sẽ không còn tính vào tồn kho và các dòng hàng sẽ tự gỡ khỏi quyết định liên kết. Hồ sơ phiếu và ảnh/PDF vẫn được giữ; liên kết cũ được lưu trong nhật ký.';
    document.getElementById('import-cancel-reason-label').hidden=action!=='cancel';
    importStateForm.elements.cancel_reason.required=action==='cancel';importStateForm.elements.cancel_reason.disabled=action!=='cancel';
    const submit=document.getElementById('import-state-submit');submit.textContent=confirming?'Xác nhận nhập kho':deleting?'Xóa phiếu nháp':'Xác nhận hủy';
    submit.classList.toggle('button-danger',!confirming);submit.classList.toggle('button-primary',confirming);
    importStateDialog.showModal();importStateDialog.querySelector('.account-dialog-body').scrollTop=0;
}
document.querySelectorAll('.import-state').forEach(button=>button.addEventListener('click',()=>openImportState(JSON.parse(button.closest('[data-import]').dataset.import),button.dataset.action)));
document.getElementById('import-confirm').addEventListener('click',()=>openImportState(currentImport,'confirm'));
importStateDialog.querySelectorAll('[data-close-import-state]').forEach(button=>button.addEventListener('click',()=>{if(!importStateSaving)importStateDialog.close();}));
importStateDialog.addEventListener('cancel',event=>{if(importStateSaving)event.preventDefault();});
importStateForm.addEventListener('submit',async event=>{
    event.preventDefault();if(importStateSaving || !importStateForm.reportValidity())return;
    importStateSaving=true;
    try { await submitManagementForm(importStateForm,importStateDialog,'import-notice'); }
    finally { importStateSaving=false; }
});
