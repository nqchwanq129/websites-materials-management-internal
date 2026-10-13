const importCreateForm=document.getElementById('import-create-form'), importLines=document.getElementById('import-lines');
const xmlInput=document.getElementById('import-xml'), xmlReplaceDialog=document.getElementById('xml-replace-dialog');
let xmlPending=null, xmlLoading=false;
const importPhotos=new WeakMap();
function clearImportPhotos(row) {
    for(const photo of importPhotos.get(row)||[])URL.revokeObjectURL(photo.url);
    importPhotos.delete(row);
}
function renderImportPhotos(row) {
    const photos=importPhotos.get(row)||[], gallery=row.querySelector('[data-image-previews]');gallery.replaceChildren();
    photos.forEach((photo,index)=>{
        const card=document.createElement('div');card.className='import-image-preview';
        const view=document.createElement('button');view.type='button';view.title='Xem '+photo.file.name;
        const image=document.createElement('img');image.src=photo.url;image.alt=photo.file.name;view.append(image);
        view.addEventListener('click',()=>showImportImages(photos.map(p=>({url:p.url,name:p.file.name})),index,importLineData(row).name));
        const remove=document.createElement('button');remove.type='button';remove.className='import-image-remove';remove.textContent='×';remove.setAttribute('aria-label','Bỏ ảnh '+photo.file.name);
        remove.addEventListener('click',()=>{URL.revokeObjectURL(photo.url);photos.splice(index,1);renderImportPhotos(row);});
        card.append(view,remove);gallery.append(card);
    });
}
function importLineData(row) {return Object.fromEntries([...row.querySelectorAll('[data-field]')].map(input=>[input.dataset.field,input.value.trim()]));}
function updateImportTotals() {
    let total=0n,taxTotal=0n,valid=true;
    [...importLines.children].forEach((row,index)=>{
        row.querySelector('[data-line-number]').textContent=index+1;
        const item=importLineData(row), message=row.querySelector('[data-line-total]');
        try {
            const quantity=item.tracking_mode==='none'?1000000n:parseReceiptDecimal(item.quantity);
            if (item.tracking_mode==='quantity' && quantity===0n) throw new Error('Số lượng phải lớn hơn 0.');
            const amount=item.amount_before_tax!==''?parseReceiptDecimal(item.amount_before_tax):quantity*parseReceiptDecimal(item.unit_price)/1000000n;
            let tax;
            if (item.tax_amount!=='') tax=parseReceiptDecimal(item.tax_amount);
            else if (/^\d{1,2}([.,]\d{1,6})?%?$/.test(item.tax_code)) tax=amount*parseReceiptDecimal(item.tax_code.replace('%',''))/100000000n;
            else if (['','KCT','KKKNT'].includes(item.tax_code.toUpperCase())) tax=0n;
            else throw new Error('Nhập số tiền thuế cho mã thuế này.');
            total+=amount;taxTotal+=tax;
            message.textContent=`Trước thuế: ${formatReceiptDecimal(amount)} ₫ · Thuế: ${formatReceiptDecimal(tax)} ₫ · Tổng: ${formatReceiptDecimal(amount+tax)} ₫`;
        } catch (error) {valid=false;message.textContent=error.message;}
    });
    for (const [id,value] of [['before',total],['tax',taxTotal],['after',total+taxTotal]]) document.getElementById('import-total-'+id).textContent=valid?formatReceiptDecimal(value)+' ₫':'Chưa đủ thông tin';
}
function configureImportLine(row,fill=false) {
    const select=row.querySelector('[data-field=product_id]'), product=select.selectedOptions[0].dataset.product;
    if (product && fill) {
        const data=JSON.parse(product);
        for (const key of ['code','name','unit','category','tracking_mode','version']) row.querySelector(`[data-field=${key}]`).value=data[key] || '';
    }
    for (const key of ['code','name','unit']) row.querySelector(`[data-field=${key}]`).readOnly=Boolean(product);
    row.querySelector('[data-field=category]').disabled=false;
    row.querySelector('[data-field=tracking_mode]').disabled=Boolean(product);
    const note=row.querySelector('[data-product-note]');note.hidden=!product;
    note.textContent=product?'Có thể đổi nhóm hàng tại đây. Khi lưu phiếu, nhóm mới sẽ cập nhật cho mặt hàng này trong danh mục và các báo cáo liên quan. Hàng dùng chung với kho ngoài quyền quản lý cần quản trị viên đổi nhóm.':'';
    const quantity=row.querySelector('[data-field=quantity]'), tracked=row.querySelector('[data-field=tracking_mode]').value==='quantity';
    quantity.required=tracked;quantity.disabled=!tracked;
    updateImportTotals();
}
function addImportLine(data={}) {
    if (importLines.children.length>=200) {showNotification('Tối đa 200 dòng trong một phiếu.','warning');return;}
    const row=document.getElementById('import-line-template').content.firstElementChild.cloneNode(true);
    for (const [key,value] of Object.entries(data)) {const input=row.querySelector(`[data-field=${key}]`);if(input)input.value=value ?? '';}
    importLines.append(row);configureImportLine(row,Boolean(data.product_id));
    importPhotos.set(row,[]);
    const picker=row.querySelector('[data-import-images]');
    row.querySelector('[data-pick-images]').addEventListener('click',()=>picker.click());
    picker.addEventListener('change',()=>{
        const files=[...picker.files],photos=importPhotos.get(row);
        const total=[...importLines.children].reduce((n,line)=>n+(importPhotos.get(line)?.length||0),0);
        const bytes=[...importLines.children].reduce((n,line)=>n+(importPhotos.get(line)||[]).reduce((sum,photo)=>sum+photo.file.size,0),0)+files.reduce((sum,file)=>sum+file.size,0);
        if(photos.length+files.length>5 || total+files.length>16)showNotification('Tối đa 5 ảnh mỗi dòng và 16 ảnh mỗi phiếu.','warning');
        else if(bytes>32*1024*1024)showNotification('Tổng ảnh của phiếu tối đa 32 MB.','warning');
        else if(files.some(file=>file.size>5*1024*1024 || !/\.(jpe?g|png|webp)$/i.test(file.name)))showNotification('Chọn ảnh JPG, PNG hoặc WebP, mỗi ảnh tối đa 5 MB.','warning');
        else {photos.push(...files.map(file=>({file,url:URL.createObjectURL(file)})));renderImportPhotos(row);}
        picker.value='';
    });
    return row;
}
document.getElementById('add-import-line').addEventListener('click',()=>{const row=addImportLine();row?.querySelector('[data-field=name]').focus();});
importLines.addEventListener('input',()=>updateImportTotals());
importLines.addEventListener('change',event=>{if(['product_id','tracking_mode'].includes(event.target.dataset.field))configureImportLine(event.target.closest('.import-line'),event.target.dataset.field==='product_id');});
importLines.addEventListener('click',event=>{if(event.target.closest('.remove-import-line')){const row=event.target.closest('.import-line');clearImportPhotos(row);row.remove();updateImportTotals();}});
const supplierSelect=importCreateForm.elements.supplier_id;
function configureImportSupplier(fill=true) {
    const data=supplierSelect.selectedOptions[0].dataset.supplier;
    for (const key of ['supplier_name','tax_code','address','phone','email']) {
        const input=importCreateForm.elements[key];
        if(data && fill)input.value=JSON.parse(data)[key==='supplier_name'?'name':key] || '';
        input.readOnly=Boolean(data);
    }
}
supplierSelect.addEventListener('change',()=>configureImportSupplier());
function applyImportInvoice(invoice) {
    supplierSelect.value='';
    for(const key of ['invoice_number','invoice_series','invoice_date','supplier_name','tax_code','address','phone','email','invoice_before_tax','invoice_tax','invoice_after_tax']) importCreateForm.elements[key].value=invoice[key] || '';
    importCreateForm.elements.receipt_type.value='purchase';
    if(invoice.tax_code) {
        const option=[...supplierSelect.options].find(option=>option.dataset.supplier && JSON.parse(option.dataset.supplier).tax_code===invoice.tax_code);
        if(option)supplierSelect.value=option.value;
    }
    configureImportSupplier(false);
    const products=[...document.getElementById('import-line-template').content.querySelector('[data-field=product_id]').options].filter(option=>option.dataset.product).map(option=>JSON.parse(option.dataset.product));
    [...importLines.children].forEach(clearImportPhotos);importLines.replaceChildren();
    for(const item of invoice.items) {
        const byCode=products.filter(product=>item.code && product.code===item.code);
        const candidates=byCode.length?byCode:products.filter(product=>product.name===item.name && product.unit===item.unit && product.tracking_mode===item.tracking_mode);
        addImportLine({...item,product_id:candidates.length===1?String(candidates[0].id):''});
    }
    updateImportTotals();showNotification('Đã điền hóa đơn XML. Kiểm tra nhóm hàng, số lượng và tiền thuế trước khi lưu.','info');
}
xmlReplaceDialog.addEventListener('close',()=>{if(xmlReplaceDialog.returnValue==='replace' && xmlPending)applyImportInvoice(xmlPending);xmlPending=null;xmlInput.value='';});
xmlInput.addEventListener('change',async()=>{
    const file=xmlInput.files[0];if(!file)return;
    if(importCreateForm.dataset.preview==='true'){showNotification('Đọc XML được thực hiện tại trang đang kết nối DB.','info');xmlInput.value='';return;}
    const error=importCreateForm.querySelector('[data-form-error]');error.hidden=true;
    xmlLoading=true;xmlInput.disabled=true;
    try {
        if(file.size>2*1024*1024 || !file.name.toLowerCase().endsWith('.xml'))throw new Error('Chọn file XML tối đa 2 MB.');
        const body=new FormData();body.set('csrf_token',importCreateForm.elements.csrf_token.value);body.set('action','xml');body.set('xml',file);
        const endpoint=new URL(importCreateForm.getAttribute('action'),document.baseURI).href;
        const response=await fetch(endpoint,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});
        if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Không nhận được nội dung XML. Hãy kiểm tra phiên đăng nhập và kết nối.');
        const result=await response.json();if(!response.ok || !result.ok)throw new Error(result.message || 'Không đọc được XML.');
        if([...importLines.children].some(row=>{const data=importLineData(row);return data.name || data.code || data.product_id || importPhotos.get(row)?.length;}) || importCreateForm.elements.invoice_number.value || importCreateForm.elements.supplier_name.value) {
            xmlPending=result.invoice;xmlReplaceDialog.returnValue='';xmlReplaceDialog.showModal();
        } else {applyImportInvoice(result.invoice);xmlInput.value='';}
    } catch(failure){error.textContent=failure.message;error.hidden=false;showNotification(failure.message,'warning');xmlInput.value='';}
    finally{xmlLoading=false;xmlInput.disabled=false;}
});
importCreateForm.addEventListener('submit',event=>{
    event.preventDefault();if(xmlLoading || xmlReplaceDialog.open){showNotification('Kiểm tra xong dữ liệu XML trước khi lưu.','warning');return;}
    importCreateForm.elements.items.value=JSON.stringify([...importLines.children].map(importLineData));
    const payload=new FormData(importCreateForm);let count=0;
    [...importLines.children].forEach((row,index)=>{for(const photo of importPhotos.get(row)||[]){payload.append(`images[${index}][]`,photo.file,photo.file.name);count++;}});
    payload.set('image_count',String(count));payload.set('form_complete','1');
    submitManagementForm(importCreateForm,{close(){}},'import-create-notice',payload);
});
window.addEventListener('pagehide',event=>{if(!event.persisted)[...importLines.children].forEach(clearImportPhotos);});
addImportLine();
