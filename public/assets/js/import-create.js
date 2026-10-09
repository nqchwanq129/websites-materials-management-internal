const importCreateForm=document.getElementById('import-create-form'), importLines=document.getElementById('import-lines');
const xmlInput=document.getElementById('import-xml'), xmlReplaceDialog=document.getElementById('xml-replace-dialog');
let xmlPending=null, xmlLoading=false;
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
        for (const key of ['code','name','unit','category','tracking_mode','specifications','version']) row.querySelector(`[data-field=${key}]`).value=data[key] || '';
    }
    for (const key of ['code','name','unit']) row.querySelector(`[data-field=${key}]`).readOnly=Boolean(product);
    for (const key of ['category','tracking_mode']) row.querySelector(`[data-field=${key}]`).disabled=Boolean(product);
    const quantity=row.querySelector('[data-field=quantity]'), tracked=row.querySelector('[data-field=tracking_mode]').value==='quantity';
    quantity.required=tracked;quantity.disabled=!tracked;
    updateImportTotals();
}
function addImportLine(data={}) {
    if (importLines.children.length>=200) {showNotification('Tối đa 200 dòng trong một phiếu.','warning');return;}
    const row=document.getElementById('import-line-template').content.firstElementChild.cloneNode(true);
    for (const [key,value] of Object.entries(data)) {const input=row.querySelector(`[data-field=${key}]`);if(input)input.value=value ?? '';}
    importLines.append(row);configureImportLine(row,Boolean(data.product_id));
    return row;
}
document.getElementById('add-import-line').addEventListener('click',()=>{const row=addImportLine();row?.querySelector('[data-field=name]').focus();});
importLines.addEventListener('input',()=>updateImportTotals());
importLines.addEventListener('change',event=>{if(['product_id','tracking_mode'].includes(event.target.dataset.field))configureImportLine(event.target.closest('.import-line'),event.target.dataset.field==='product_id');});
importLines.addEventListener('click',event=>{if(event.target.closest('.remove-import-line')){event.target.closest('.import-line').remove();updateImportTotals();}});
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
    importLines.replaceChildren();
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
        if([...importLines.children].some(row=>{const data=importLineData(row);return data.name || data.code || data.product_id;}) || importCreateForm.elements.invoice_number.value || importCreateForm.elements.supplier_name.value) {
            xmlPending=result.invoice;xmlReplaceDialog.returnValue='';xmlReplaceDialog.showModal();
        } else {applyImportInvoice(result.invoice);xmlInput.value='';}
    } catch(failure){error.textContent=failure.message;error.hidden=false;showNotification(failure.message,'warning');xmlInput.value='';}
    finally{xmlLoading=false;xmlInput.disabled=false;}
});
importCreateForm.addEventListener('submit',event=>{
    event.preventDefault();if(xmlLoading || xmlReplaceDialog.open){showNotification('Kiểm tra xong dữ liệu XML trước khi lưu.','warning');return;}
    importCreateForm.elements.items.value=JSON.stringify([...importLines.children].map(importLineData));
    submitManagementForm(importCreateForm,{close(){}},'import-create-notice');
});
addImportLine();
