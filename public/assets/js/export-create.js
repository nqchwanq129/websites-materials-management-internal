const exportCreateForm=document.getElementById('export-create-form'),exportLines=document.getElementById('export-lines');
const exportSourceSearch=document.getElementById('export-source-search');
const exportSources=[...document.getElementById('export-source-options').content.querySelectorAll('option')].map(option=>({option,source:JSON.parse(option.dataset.source)}));
function exportLineData(row){return Object.fromEntries([...row.querySelectorAll('[data-field]')].map(input=>[input.dataset.field,input.value.trim()]));}
function updateExportTotals(){
    const pools=new Map();let total=0n,valid=exportLines.children.length>0;
    [...exportLines.children].forEach((row,index)=>{
        row.querySelector('[data-line-number]').textContent=index+1;
        const select=row.querySelector('[data-field=source_import_detail_id]'),data=select.selectedOptions[0]?.dataset.source;
        const message=row.querySelector('[data-line-total]');
        try{
            if(!data)throw new Error('Chọn lô nhập để xuất.');
            const source=JSON.parse(data),quantity=parseReceiptDecimal(row.querySelector('[data-field=quantity]').value.trim());
            if(quantity===0n)throw new Error('Số lượng xuất phải lớn hơn 0.');
            if(!pools.has(source.id))pools.set(source.id,{quantity:parseReceiptDecimal(source.remaining_quantity),amount:parseReceiptDecimal(source.remaining_amount)});
            const pool=pools.get(source.id);
            if(quantity>pool.quantity)throw new Error('Vượt tồn lô này, bao gồm các dòng đã chọn ở trên.');
            const amount=quantity===pool.quantity?pool.amount:parseReceiptDecimal(source.amount_before_tax)*quantity/parseReceiptDecimal(source.received_quantity);
            if(amount>pool.amount)throw new Error('Giá trị xuất vượt giá trị còn lại của lô.');
            pool.quantity-=quantity;pool.amount-=amount;total+=amount;
            message.textContent=`Giá trị xuất: ${formatReceiptDecimal(amount)} ₫ · Còn sau phiếu: ${formatReceiptDecimal(pool.quantity)} ${source.unit}`;
        }catch(error){valid=false;message.textContent=error.message;}
    });
    document.getElementById('export-line-count').textContent=exportLines.children.length;
    document.getElementById('export-total').textContent=valid?formatReceiptDecimal(total)+' ₫':'Chưa đủ thông tin';
    document.getElementById('export-stock-state').textContent=valid?'Đủ lượng xuất':'Cần kiểm tra';
}
function showExportSource(row){
    const select=row.querySelector('[data-field=source_import_detail_id]'),data=select.selectedOptions[0]?.dataset.source,source=data?JSON.parse(data):null;
    row.querySelector('[data-field=version]').value=source?.version || '';
    row.querySelectorAll('[data-source-field]').forEach(input=>{input.value=source?.[input.dataset.sourceField] || '';});
}
function updateExportOptions(){
    const warehouse=exportCreateForm.elements.warehouse.value,date=exportCreateForm.elements.issued_date.value,query=normalize(exportSourceSearch.value.trim());
    const eligible=exportSources.filter(({source})=>source.warehouse_id===warehouse && source.received_date<=date);
    document.getElementById('export-source-count').textContent=`${eligible.length} lô có tồn tại kho này`;
    for(const row of exportLines.children){
        const select=row.querySelector('[data-field=source_import_detail_id]'),selected=select.value;
        select.replaceChildren(new Option(warehouse?'Chọn lô nhập':'Chọn kho xuất trước',''));
        for(const {option,source} of eligible)if(source.id===selected || normalize(option.textContent).includes(query))select.append(option.cloneNode(true));
        select.value=selected;if(!select.value)select.value='';showExportSource(row);
    }
    const transfer=exportCreateForm.elements.issue_type.value==='transfer',destination=exportCreateForm.elements.destination_warehouse;
    document.getElementById('export-destination-label').hidden=!transfer;destination.disabled=!transfer;destination.required=transfer;
    for(const option of destination.options)option.disabled=option.value!=='' && option.value===warehouse;
    if(!transfer || destination.value===warehouse)destination.value='';
    updateExportTotals();
}
function addExportLine(){
    if(exportLines.children.length>=200){showNotification('Tối đa 200 dòng trong một phiếu.','warning');return;}
    const row=document.getElementById('export-line-template').content.firstElementChild.cloneNode(true);exportLines.append(row);updateExportOptions();return row;
}
document.getElementById('add-export-line').addEventListener('click',()=>addExportLine()?.querySelector('select').focus());
for(const name of ['warehouse','issued_date','issue_type'])exportCreateForm.elements[name].addEventListener('change',updateExportOptions);
exportSourceSearch.addEventListener('input',updateExportOptions);
exportLines.addEventListener('change',event=>{if(event.target.dataset.field==='source_import_detail_id')showExportSource(event.target.closest('.export-line'));updateExportTotals();});
exportLines.addEventListener('input',updateExportTotals);
exportLines.addEventListener('click',event=>{if(event.target.closest('.remove-export-line')){event.target.closest('.export-line').remove();updateExportTotals();}});
exportCreateForm.addEventListener('submit',event=>{event.preventDefault();exportCreateForm.elements.items.value=JSON.stringify([...exportLines.children].map(exportLineData));submitManagementForm(exportCreateForm,{close(){}},'export-create-notice');});
addExportLine();
