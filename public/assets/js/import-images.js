const importImageDialog=document.getElementById('import-image-dialog'), importImageFull=document.getElementById('import-image-full');
let importImageGallery=[], importImageIndex=0;
let importPdfVersion=0, importPdfRequest=null, importPdfPayload=null;
function clearImportPdf() {
    importPdfVersion++;importPdfRequest?.abort();importPdfRequest=null;importPdfPayload=null;
    document.getElementById('import-pdf-frame').removeAttribute('src');
    document.getElementById('import-pdf-loading').hidden=true;
}
function failImportPdf(error,version) {
    if(version!==importPdfVersion || error.name==='AbortError')return;
    document.getElementById('import-pdf-loading').hidden=true;
    const message=document.getElementById('import-image-error');
    message.textContent='Không mở được PDF đính kèm. Hãy tải lại trang và thử lại, hoặc bấm Tải PDF.';message.hidden=false;
}
function showImportImages(photos,index,title) {
    if(!photos.length)return;
    importImageDialog.classList.remove('import-pdf-dialog');
    document.getElementById('import-pdf-frame').hidden=true;
    clearImportPdf();
    document.getElementById('import-file-download').hidden=true;
    importImageFull.hidden=false;
    document.getElementById('import-image-previous').hidden=false;
    document.getElementById('import-image-next').hidden=false;
    importImageGallery=photos;importImageIndex=index;
    document.getElementById('import-image-title').textContent=title || 'Ảnh hàng hóa';
    renderImportImage();
    if(!importImageDialog.open)importImageDialog.showModal();
}
async function showImportPdf(url,name) {
    clearImportPdf();const version=importPdfVersion;
    importImageDialog.classList.add('import-pdf-dialog');
    importImageGallery=[];importImageIndex=0;importImageFull.hidden=true;importImageFull.removeAttribute('src');
    document.getElementById('import-image-title').textContent='Xem tài liệu PDF';
    document.getElementById('import-image-caption').textContent=name;
    document.getElementById('import-image-error').hidden=true;
    document.getElementById('import-image-previous').hidden=true;
    document.getElementById('import-image-next').hidden=true;
    const frame=document.getElementById('import-pdf-frame'),fileUrl=new URL(url,document.baseURI),viewerUrl=new URL(frame.dataset.viewerUrl,document.baseURI);
    fileUrl.searchParams.set('preview','1');viewerUrl.searchParams.set('file','');viewerUrl.hash='zoom=page-width';
    frame.hidden=true;document.getElementById('import-pdf-loading').hidden=false;
    const download=document.getElementById('import-file-download');download.href=url;download.hidden=false;
    if(!importImageDialog.open)importImageDialog.showModal();
    importPdfRequest=new AbortController();
    try {
        const response=await fetch(fileUrl.href,{headers:{Accept:'application/json'},credentials:'same-origin',signal:importPdfRequest.signal});
        if(!response.headers.get('content-type')?.includes('application/json')){await response.text();throw new Error('PDF response unavailable.');}
        const result=await response.json();
        if(!response.ok || !result.ok || typeof result.data!=='string' || !result.data)throw new Error('PDF data unavailable.');
        if(version!==importPdfVersion)return;
        importPdfPayload={bytes:Uint8Array.from(atob(result.data),char=>char.charCodeAt(0)),name:result.name || name};
        frame.src=viewerUrl.href;frame.hidden=false;
    } catch(error) { failImportPdf(error,version); }
    finally { if(version===importPdfVersion)importPdfRequest=null; }
}
document.addEventListener('webviewerloaded',async event=>{
    const source=event.detail?.source;
    if(source!==document.getElementById('import-pdf-frame').contentWindow)return;
    const options=source.PDFViewerApplicationOptions;
    options.set('disablePreferences',true);options.set('localeProperties',{lang:'vi'});
    options.set('enableScripting',false);options.set('annotationEditorMode',-1);
    options.set('enableAltText',false);options.set('enableAltTextModelDownload',false);options.set('defaultUrl','');
    source.document.getElementById('secondaryOpenFile').classList.add('hidden');
    const version=importPdfVersion,payload=importPdfPayload;
    try {
        await source.PDFViewerApplication.initializedPromise;
        if(version!==importPdfVersion || !payload)return;
        await source.PDFViewerApplication.open({data:payload.bytes,filename:payload.name});
        source.document.getElementById('fileInput').disabled=true;
        if(version===importPdfVersion)document.getElementById('import-pdf-loading').hidden=true;
    } catch(error) { failImportPdf(error,version); }
});
function renderImportImage() {
    const photo=importImageGallery[importImageIndex];
    document.getElementById('import-image-error').hidden=true;
    document.getElementById('import-image-error').textContent='Không tải được ảnh. Hãy kiểm tra phiên đăng nhập hoặc tải lại trang.';
    importImageFull.alt=photo.name;importImageFull.src=photo.url;
    document.getElementById('import-image-caption').textContent=`${importImageIndex+1} / ${importImageGallery.length} · ${photo.name}`;
    document.getElementById('import-image-previous').disabled=importImageIndex===0;
    document.getElementById('import-image-next').disabled=importImageIndex===importImageGallery.length-1;
    importImageDialog.querySelector('.account-dialog-body').scrollTop=0;
}
importImageFull.addEventListener('error',()=>{if(importImageDialog.open)document.getElementById('import-image-error').hidden=false;});
document.getElementById('import-image-previous').addEventListener('click',()=>{if(importImageIndex>0){importImageIndex--;renderImportImage();}});
document.getElementById('import-image-next').addEventListener('click',()=>{if(importImageIndex<importImageGallery.length-1){importImageIndex++;renderImportImage();}});
importImageDialog.addEventListener('keydown',event=>{
    if(importImageGallery.length && ['ArrowLeft','ArrowRight'].includes(event.key)){event.preventDefault();document.getElementById(event.key==='ArrowLeft'?'import-image-previous':'import-image-next').click();}
});
importImageDialog.addEventListener('close',()=>{if(importImageDialog.open)return;importImageFull.removeAttribute('src');clearImportPdf();importImageGallery=[];});
