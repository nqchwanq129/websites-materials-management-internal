const activityPanel=document.getElementById('activity-panel'),activityToggle=document.getElementById('activity-toggle');
const activityList=document.getElementById('activity-list'),activityMore=document.getElementById('activity-more');
let activityItems=[],activityNext=null,activityCount=0,activityScope=null,activityLoading=false,activityStopped=false,activityTimer=null,activityReadRevision=0;
const activityReadIds=new Set(),activityBusyIds=new Set();
const activityUser=activityPanel.dataset.user,activityPreview=activityPanel.dataset.preview==='true';
let activityFrontier=null,activityAudio=null,activitySound=true,activityPendingSound=false;
try{activityFrontier=sessionStorage.getItem('activity-frontier-'+activityUser);activitySound=localStorage.getItem('activity-sound-'+activityUser)!=='off';}catch{}
function activityBeep(){
    if(!activitySound || !activityAudio || activityAudio.state!=='running')return;
    const oscillator=activityAudio.createOscillator(),gain=activityAudio.createGain(),now=activityAudio.currentTime;
    oscillator.type='sine';oscillator.frequency.setValueAtTime(880,now);oscillator.frequency.exponentialRampToValueAtTime(660,now+.16);
    gain.gain.setValueAtTime(0,now);gain.gain.linearRampToValueAtTime(.035,now+.015);gain.gain.exponentialRampToValueAtTime(.001,now+.18);
    oscillator.connect(gain);gain.connect(activityAudio.destination);oscillator.onended=()=>{oscillator.disconnect();gain.disconnect();};oscillator.start(now);oscillator.stop(now+.2);
}
async function armActivityAudio(){
    if(!activitySound)return;
    try{const Audio=window.AudioContext || window.webkitAudioContext;if(!Audio)return;activityAudio ||= new Audio();await activityAudio.resume();
        if(activityPendingSound){activityPendingSound=false;activityBeep();}}catch{}
}
document.addEventListener('pointerdown',armActivityAudio);
document.addEventListener('keydown',armActivityAudio);
function updateActivitySound(){const button=document.getElementById('activity-sound');button.setAttribute('aria-pressed',String(activitySound));button.textContent=activitySound?'Âm báo: Bật':'Âm báo: Tắt';}
document.getElementById('activity-sound').addEventListener('click',()=>{activitySound=!activitySound;activityPendingSound=false;try{localStorage.setItem('activity-sound-'+activityUser,activitySound?'on':'off');}catch{}updateActivitySound();if(activitySound)armActivityAudio();});
updateActivitySound();
function activityUpdateCount(count){
    activityCount=count;const badge=document.getElementById('activity-badge');badge.hidden=count===0;badge.textContent=count>99?'99+':String(count);
    activityToggle.setAttribute('aria-label',`Thông báo, ${count} chưa đọc`);document.getElementById('activity-count').textContent=count+' chưa đọc';
}
function drawActivityNotifications(){
    activityList.replaceChildren();
    for(const item of activityItems){
        const row=document.createElement('article');row.className='activity-item';row.setAttribute('role','listitem');row.dataset.id=item.id;
        const receipt=item.kind==='receipt',icon=document.createElement('span');icon.className='activity-item-icon '+(receipt?'receipt':'admin');icon.textContent=receipt?'↕':'◆';icon.setAttribute('aria-hidden','true');row.append(icon);
        const content=document.createElement('div');content.className='activity-item-content';
        const linked=receipt && /^index\.php\?page=(imports|exports)&id=[1-9][0-9]*$/.test(item.href || '');
        const title=document.createElement(linked?'a':'strong');title.className='activity-item-title';title.textContent=item.title;
        if(linked){title.href=item.href;title.addEventListener('click',event=>{
            if(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey){markActivityRead(item,true);return;}
            event.preventDefault();markActivityRead(item).then(ok=>{if(ok)window.location.assign(item.href);});});}
        const description=document.createElement('p');description.textContent=item.description;
        const time=document.createElement('time');time.dateTime=item.created_at;time.textContent=new Date(item.created_at).toLocaleString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',hour:'2-digit',minute:'2-digit',day:'2-digit',month:'2-digit',year:'numeric'});
        const seen=document.createElement('button');seen.type='button';seen.className='activity-seen';seen.textContent='Đã xem';seen.setAttribute('aria-label','Đánh dấu đã xem: '+item.title);seen.disabled=activityBusyIds.has(item.id);seen.addEventListener('click',()=>markActivityRead(item));
        content.append(title,description,time,seen);row.append(content);activityList.append(row);
    }
    document.getElementById('activity-empty').hidden=activityItems.length!==0;
    activityMore.hidden=activityNext===null;activityMore.disabled=activityLoading;
}
async function activityRequest(url,options={}){
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},...options});
    if(response.status===401){activityStopped=true;clearTimeout(activityTimer);activityItems=[];activityNext=null;activityUpdateCount(0);drawActivityNotifications();window.location.replace('index.php?page=login');throw new Error('Phiên đăng nhập đã kết thúc. Vui lòng đăng nhập lại.');}
    if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('Không tải được thông báo. Vui lòng thử lại.');
    const result=await response.json();if(!response.ok || !result.ok)throw new Error(result.message || 'Không tải được thông báo.');return result;
}
async function loadActivityNotifications(mode='poll'){
    if(activityLoading || activityStopped || activityPreview)return;
    activityLoading=true;activityMore.disabled=true;
    const readRevision=activityReadRevision;
    try{
        const query=new URLSearchParams({page:'notifications'});
        if(mode==='more' && activityNext)query.set('before',activityNext);
        // ponytail: refresh at most 2000 cached rows; use a separate history page if panels grow beyond this.
        for(const item of activityItems.slice(0,2000))query.append('known[]',item.id);
        const result=await activityRequest('index.php?'+query);
        if(readRevision!==activityReadRevision){setTimeout(()=>loadActivityNotifications(),0);return;}
        const reset=mode==='reset' || (mode==='poll' && !activityPanel.matches(':popover-open')) || (activityScope!==null && activityScope!==result.scope);
        const alive=new Set(result.alive_ids),items=reset?[]:activityItems.filter(item=>alive.has(item.id));
        const combined=new Map(items.map(item=>[item.id,item]));for(const item of result.items)if(!activityReadIds.has(item.id))combined.set(item.id,item);
        activityItems=[...combined.values()].sort((a,b)=>BigInt(a.id)>BigInt(b.id)?-1:1);
        if(reset || mode==='more' || activityNext===null)activityNext=result.next_before;
        activityScope=result.scope;activityUpdateCount(result.unread_count);
        const frontier=activityFrontier===null?null:BigInt(activityFrontier),latest=BigInt(result.latest_id);
        if(frontier!==null && latest>frontier && result.items.some(item=>BigInt(item.id)>frontier) && activitySound){
            if(activityAudio?.state==='running')activityBeep();else activityPendingSound=true;
        }
        activityFrontier=String(frontier===null || latest>frontier?latest:frontier);try{sessionStorage.setItem('activity-frontier-'+activityUser,activityFrontier);}catch{}
        document.getElementById('activity-error').hidden=true;
    }catch(error){const message=document.getElementById('activity-error');message.textContent=error.message;message.hidden=false;}
    finally{activityLoading=false;drawActivityNotifications();}
}
async function markActivityRead(item,keepalive=false){
    if(activityBusyIds.has(item.id))return false;
    activityBusyIds.add(item.id);drawActivityNotifications();
    try{
        if(!activityPreview){const body=new URLSearchParams({action:'read',id:item.id,csrf_token:document.getElementById('activity-csrf').value});await activityRequest('index.php?page=notifications',{method:'POST',body,keepalive});}
        activityReadRevision++;activityReadIds.add(item.id);activityItems=activityItems.filter(row=>row.id!==item.id);activityUpdateCount(Math.max(0,activityCount-1));drawActivityNotifications();return true;
    }catch(error){showNotification(error.message,'warning');return false;}
    finally{activityBusyIds.delete(item.id);drawActivityNotifications();}
}
activityMore.addEventListener('click',()=>loadActivityNotifications('more'));
document.getElementById('activity-retry').addEventListener('click',()=>loadActivityNotifications('reset'));
activityPanel.addEventListener('toggle',event=>{activityToggle.setAttribute('aria-expanded',String(event.newState==='open'));if(event.newState==='open')loadActivityNotifications('reset');});
async function pollActivityNotifications(){await loadActivityNotifications();if(!activityStopped)activityTimer=setTimeout(pollActivityNotifications,15000);}
if(!activityPreview)pollActivityNotifications();else drawActivityNotifications();
window.addEventListener('pagehide',()=>clearTimeout(activityTimer));
window.addEventListener('pageshow',event=>{if(event.persisted && !activityPreview && !activityStopped){clearTimeout(activityTimer);pollActivityNotifications();}});
