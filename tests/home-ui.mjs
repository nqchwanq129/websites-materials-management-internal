// Run with Node 22+ and XAMPP: node tests/home-ui.mjs. Renders samples without database.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { once } from 'node:events';

const browserPath = [process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(path => path && existsSync(path));
assert.ok(browserPath, 'Install Chrome/Edge or set CHROME_PATH.');
const outputDir = mkdtempSync(join(tmpdir(), 'qlvt-home-check-'));
mkdirSync(join(outputDir,'profile','Default'),{recursive:true});
writeFileSync(join(outputDir,'profile','Default','Preferences'),JSON.stringify({plugins:{always_open_pdf_externally:true}}));
const browser = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0',
    `--user-data-dir=${join(outputDir, 'profile')}`, '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
let socket;

try {
    const endpoint = await new Promise((resolve, reject) => {
        let stderr = '';
        const timeout = setTimeout(() => reject(new Error('Browser startup timed out.')), 15000);
        browser.once('error', error => { clearTimeout(timeout); reject(error); });
        browser.once('exit', code => { clearTimeout(timeout); reject(new Error(`Browser exited: ${code}`)); });
        browser.stderr.on('data', chunk => {
            stderr += chunk;
            const match = stderr.match(/DevTools listening on (ws:\/\/\S+)/);
            if (match) { clearTimeout(timeout); resolve(match[1]); }
        });
    });
    socket = new WebSocket(endpoint);
    await once(socket, 'open');
    let nextId = 0;
    const pending = new Map();
    const pageErrors = [];
    const failedRequests = [];
    const requestUrls = new Map();
    const downloads = [];
    let postRequests = 0;
    let notificationMockPosts = 0;
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.method === 'Runtime.exceptionThrown') pageErrors.push(message.params.exceptionDetails.text);
        if (message.method === 'Browser.downloadWillBegin') downloads.push(message.params.url);
        if (message.method === 'Network.requestWillBeSent') requestUrls.set(message.params.requestId,message.params.request.url);
        if (message.method === 'Network.loadingFailed') {
            const url=requestUrls.get(message.params.requestId);
            failedRequests.push({error:message.params.errorText,type:message.params.type,url});
        }
        if (message.method === 'Network.requestWillBeSent' && message.params.request.method === 'POST') postRequests++;
        if (!pending.has(message.id)) return;
        const request = pending.get(message.id);
        clearTimeout(request.timer);
        pending.delete(message.id);
        if (message.error) request.reject(new Error(message.error.message));
        else request.resolve(message.result);
    });
    const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
        const id = ++nextId;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error(`${method} timed out.`)); }, 10000);
        pending.set(id, { resolve, reject, timer });
        socket.send(JSON.stringify({ id, method, params, sessionId }));
    });
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    const page = (method, params) => send(method, params, sessionId);
    await send('Browser.setDownloadBehavior',{behavior:'deny',eventsEnabled:true});
    const evaluate = async expression => {
        const result = await page('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert.ok(!result.exceptionDetails, result.exceptionDetails?.exception?.description || result.exceptionDetails?.text);
        return result.result.value;
    };
    await page('Runtime.enable');
    await page('Network.enable');
    await page('Page.enable');
    const php = process.env.PHP_PATH || 'E:/Software Archive/01.Dev and Servers/xampp/php/php.exe';
    const rendered = spawnSync(php, ['tests/home.php', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(rendered.status, 0, rendered.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const { frameTree } = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: frameTree.frame.id, html: rendered.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.querySelector(".receipt-tabs")) && typeof filterReceipts === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('typeof filterReceipts'), 'function', 'Dashboard script failed to load.');
    assert.equal(await evaluate('document.querySelector(".topbar-warehouse").textContent'),'Toàn hệ thống');
    assert.equal(await evaluate('document.querySelector(".brand-copy strong").textContent'),'PHẦN MỀM QUẢN LÝ KHO VISHIPEL');
    assert.equal(await evaluate('document.querySelector(".brand-copy small").textContent'),'CÔNG TY TNHH MTV THÔNG TIN ĐIỆN TỬ HÀNG HẢI VIỆT NAM');
    assert.equal(await evaluate('getComputedStyle(document.querySelector(".topbar-warehouse")).backgroundColor'),'rgb(246, 247, 248)','Warehouse badge must use the neutral dashboard palette.');
    assert.equal(await evaluate('getComputedStyle(document.body).color'),'rgb(28, 28, 28)','Main text must use the black palette.');
    assert.equal(await evaluate('getComputedStyle(document.querySelector(".metric-value")).color'),'rgb(28, 28, 28)','Metric values must use black rather than decorative colors.');
    assert.equal(await evaluate('getComputedStyle(document.querySelector("th")).color'),'rgb(28, 28, 28)','Table headings must use black for legibility.');
    assert.equal(await evaluate('getComputedStyle(document.getElementById("user-menu-toggle")).display'),'flex','Avatar button must receive the current dashboard CSS.');
    assert.equal(await evaluate('getComputedStyle(document.getElementById("user-menu")).position'),'fixed','Account menu must receive the current dashboard CSS.');
    assert.equal(await evaluate('document.querySelector(".topbar-warehouse .icon use").getAttribute("href")'),'#i-shield');
    assert.equal(await evaluate('document.querySelector(".topbar-warehouse").title'),'Phạm vi quản trị: Toàn hệ thống');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 5);
    await evaluate('document.querySelectorAll(".tab")[1].click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 3);
    await evaluate('document.getElementById("warehouse-filter").selectedIndex=2; document.getElementById("warehouse-filter").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 1);
    await evaluate('document.querySelector(".tab[data-type=all]").click(); document.getElementById("warehouse-filter").value=""; document.getElementById("warehouse-filter").dispatchEvent(new Event("change")); document.getElementById("receipt-search").value="dieu chuyen"; document.getElementById("receipt-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 1, 'Accent-insensitive search failed.');
    await evaluate('document.getElementById("receipt-search").value="does-not-exist"; document.getElementById("receipt-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelector(".empty-state").hidden'), false);
    assert.equal(await evaluate('document.querySelector(".heading-actions .button-primary").getAttribute("href")'),'index.php?page=import-create');
    assert.equal(await evaluate('document.querySelector("[data-receipt] .receipt-link").getAttribute("href")'),'index.php?page=imports&id=48');
    assert.equal(await evaluate('document.querySelector(".donut .purple-ring").getAttribute("stroke-dasharray")'),'50.0000 100');
    assert.equal(await evaluate('document.querySelector("[data-receipt] small").textContent'),'Thiết bị thông tin <script>');
    await evaluate('document.getElementById("receipt-search").value=""; document.getElementById("receipt-search").dispatchEvent(new Event("input")); document.querySelector("[data-feature=\\"Tồn kho\\"]").click()');
    assert.equal(await evaluate('document.getElementById("feature-dialog").open'), true);
    await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    for (let i = 0; i < 20 && await evaluate('document.getElementById("feature-dialog").open'); i++) await new Promise(resolve => setTimeout(resolve, 50));
    assert.equal(await evaluate('document.getElementById("feature-dialog").open'), false);
    const originalGroups = await evaluate('[...document.querySelectorAll(".nav-group")].map(group=>group.open)');
    assert.deepEqual(await evaluate('[...document.querySelectorAll(".nav-group summary>span:first-of-type")].slice(0,3).map(item=>item.textContent)'), ['Nhập hàng hóa','Xuất hàng hóa','Danh Sách Hàng Hóa']);
    assert.deepEqual(await evaluate('[...document.querySelector(".nav-group").querySelectorAll(".nav-child")].map(item=>item.textContent)'), ['Nhập hóa đơn','DS phiếu nhập kho']);
    assert.equal(await evaluate('document.querySelector("main>.breadcrumb strong").textContent'), 'Trang chủ');
    assert.equal(await evaluate('document.querySelector(".topbar .breadcrumb")'), null);
    assert.equal(originalGroups[2], true);
    await evaluate('document.querySelector(".nav-group:has(a[href=\\"index.php?page=products\\"]) summary").click()');
    assert.equal(await evaluate('document.querySelector(".nav-group:has(a[href=\\"index.php?page=products\\"])").open'), false, 'Second click should collapse the group.');
    await evaluate('document.querySelector(".nav-group:has(a[href=\\"index.php?page=products\\"]) summary").click()');
    assert.equal(await evaluate('document.querySelector(".nav-group:has(a[href=\\"index.php?page=products\\"])").open'), true, 'Click should expand the group.');
    await evaluate('document.getElementById("navigation-search").value="tai san"; document.getElementById("navigation-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelectorAll(".nav-group:not([hidden])").length'), 2, 'Find matching child functions without Vietnamese accents.');
    assert.equal(await evaluate('[...document.querySelectorAll(".nav-group:not([hidden])")].every(group=>group.open)'), true, 'Search must reveal matching children.');
    await evaluate('document.getElementById("navigation-search").value="does-not-exist"; document.getElementById("navigation-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelector(".nav-empty").hidden'), false);
    await evaluate('document.getElementById("navigation-search").value=""; document.getElementById("navigation-search").dispatchEvent(new Event("input"))');
    assert.deepEqual(await evaluate('[...document.querySelectorAll(".nav-group")].map(group=>group.open)'), originalGroups, 'Clearing search restores expanded groups.');
    await page('Input.dispatchKeyEvent', { type: 'keyDown', key: '/', code: 'Slash', modifiers: 2, windowsVirtualKeyCode: 191 });
    await page('Input.dispatchKeyEvent', { type: 'keyUp', key: '/', code: 'Slash', modifiers: 2, windowsVirtualKeyCode: 191 });
    assert.equal(await evaluate('document.activeElement.id'), 'navigation-search', 'Ctrl+/ must focus sidebar search.');
    assert.equal(await evaluate('document.querySelector(".workspace")'), null, 'Remove the old workspace block.');
    await evaluate(`const originalSearchFetch=window.fetch;const searchRequests=[];window.fetch=async(url,options={})=>{
        const params=new URL(url,document.baseURI).searchParams;if(params.get('page')!=='search')return originalSearchFetch(url,options);
        searchRequests.push(params.get('q'));const older=params.get('offset_page')==='1',empty=params.get('q')==='missing';
        return new Response(JSON.stringify({ok:true,items:empty?[]:[{id:older?'12':'1',type:'imports',label:'Phiếu nhập kho',title:older?'PN-OLDER':'PN-<script> kiểm tra',description:'Kho Hà Nội',href:'index.php?page=imports&id='+(older?'12':'1')},
            ...(!older&&!empty?[{id:'1',type:'employees',label:'Nhân viên',title:'Đỗ Công Biên',description:'XLTHHN-001',href:'index.php?page=employees&id=1'}, {id:'99',type:'products',label:'Hàng hóa',title:'Unsafe URL',description:'',href:'javascript:alert(1)'}]:[])],next_page:older||empty?null:1}),{headers:{'Content-Type':'application/json'}});
    };
    globalSearchInput.value='PN';globalSearchForm.requestSubmit();`);
    for(let i=0;i<100 && !await evaluate('globalSearchItems.length');i++)await new Promise(resolve=>setTimeout(resolve,30));
    assert.equal(await evaluate('globalSearchResults.querySelectorAll("a").length'),2,'Search must group data results and reject unsafe links.');
    assert.equal(await evaluate('globalSearchResults.querySelector("strong").textContent'),'PN-<script> kiểm tra');
    assert.equal(await evaluate('globalSearchResults.querySelector("script")'),null,'Search titles must render as text.');
    await evaluate('globalSearchMore.click()');
    for(let i=0;i<100 && !await evaluate('globalSearchMore.hidden');i++)await new Promise(resolve=>setTimeout(resolve,30));
    assert.equal(await evaluate('globalSearchResults.querySelectorAll("a").length'),3,'Show more must append older matches.');
    await evaluate('globalSearchInput.value="missing";globalSearchForm.requestSubmit()');
    for(let i=0;i<100 && !await evaluate('globalSearchStatus.textContent.includes("Không tìm thấy")');i++)await new Promise(resolve=>setTimeout(resolve,30));
    assert.equal(await evaluate('globalSearchItems.length'),0,'An empty search must clear old matches.');
    await evaluate('searchRequests.length=0;globalSearchInput.value="P";globalSearchInput.dispatchEvent(new Event("input"));globalSearchInput.value="PN";globalSearchInput.dispatchEvent(new Event("input"))');
    await new Promise(resolve=>setTimeout(resolve,400));
    assert.deepEqual(await evaluate('searchRequests'),['PN'],'Typing must debounce requests.');
    await evaluate('globalSearchPanel.hidePopover();window.fetch=originalSearchFetch');
    for (const [width, height] of [[1440, 1050], [1280, 800], [1280, 600], [768, 1024], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        await evaluate('window.scrollTo(0,0)');
        assert.equal(await evaluate('(()=>{scrollTo(0,500);return Math.abs(document.querySelector(".topbar").getBoundingClientRect().top)<1;})()'),true,'Topbar must stay visible while scrolling.');
        await evaluate('scrollTo(0,0);showGlobalSearch()');
        assert.equal(await evaluate('(()=>{const r=globalSearchPanel.getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && r.bottom<=innerHeight && r.top>=globalSearchForm.getBoundingClientRect().bottom;})()'),true,'Search results must fit under the search box at every viewport.');
        const searchShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`global-search-${width}x${height}.png`),Buffer.from(searchShot.data,'base64'));
        await evaluate('globalSearchPanel.hidePopover()');
        assert.equal(await evaluate('document.querySelector(".topbar-warehouse").getClientRects().length>0'),true,'Warehouse name must remain visible on small screens.');
        assert.equal(await evaluate(`(()=>{const label=document.querySelector('.topbar-warehouse-text'),previous=label.textContent;label.textContent='Kho Hải Phòng với tên rất dài để kiểm tra';const fits=document.documentElement.scrollWidth<=innerWidth;label.textContent=previous;return fits;})()`),true,'Long warehouse names must not overflow the topbar.');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Page overflow at ${width}px: ` + JSON.stringify(await evaluate('({scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,body:document.body.scrollWidth,inner:innerWidth,main:document.querySelector("main").getBoundingClientRect().toJSON(),table:document.querySelector(".table-scroll").getBoundingClientRect().toJSON()})')) + JSON.stringify(await evaluate('[...document.querySelectorAll("body *")].filter(e=>e.getBoundingClientRect().right>innerWidth && !e.closest(".table-scroll")).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right})).slice(0,15)')));
        assert.equal(await evaluate('document.querySelector(".brand img").naturalWidth>0'), true);
        assert.deepEqual(await evaluate(`(() => {
            const sidebar = document.getElementById('sidebar');
            const nav = sidebar.querySelector('.navigation');
            const header = sidebar.querySelector('.sidebar-brand');
            const footer = sidebar.querySelector('.sidebar-bottom');
            const before = [header.getBoundingClientRect().top, footer.getBoundingClientRect().top];
            nav.scrollTop = nav.scrollHeight;
            const after = [header.getBoundingClientRect().top, footer.getBoundingClientRect().top];
            const result = { fixed: before.every((value, i) => value === after[i]),
                footerVisible: footer.getBoundingClientRect().bottom <= innerHeight,
                sidebarFits: sidebar.scrollHeight <= sidebar.clientHeight,
                scrollbarAtEdge: Math.abs(nav.getBoundingClientRect().right - (sidebar.getBoundingClientRect().right - 1)) <= 1,
                functionsScrollable: nav.scrollHeight <= nav.clientHeight || nav.scrollTop > 0 };
            nav.scrollTop = 0;
            return result;
        })()`), { fixed: true, footerVisible: true, sidebarFits: true, scrollbarAtEdge: true, functionsScrollable: true }, `Only functions should scroll at ${width}x${height}.`);
        if (width <= 1000) {
            await evaluate('document.getElementById("menu-toggle").click()');
            assert.equal(await evaluate('document.getElementById("sidebar").inert'), false);
            assert.equal(await evaluate('document.getElementById("menu-toggle").getAttribute("aria-expanded")'), 'true');
            if (width === 390) {
                const { data } = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
                writeFileSync(join(outputDir, 'sidebar-mobile.png'), Buffer.from(data, 'base64'));
            }
            await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            assert.equal(await evaluate('document.getElementById("sidebar").inert'), true);
        }
        const { data } = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `home-${width}x${height}.png`), Buffer.from(data, 'base64'));
    }
    // Render account UI in a fresh document; no sample accounts are inserted in the database.
    await evaluate(`document.querySelector('.tab[data-type=all]').click();warehouse.value='';search.value='';filterReceipts();document.querySelectorAll('[data-receipt] .compact-truncate').forEach(element=>{element.textContent='Receipt and warehouse with very long text '.repeat(10);element.title=element.textContent;});`);
    for(const [width,height] of [[1440,1000],[1280,800],[1024,800],[390,844],[320,568]]) {
        await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate(`(()=>{const region=document.querySelector('#receipts .compact-table-scroll');return region.scrollWidth<=region.clientWidth+1 && document.documentElement.scrollWidth<=innerWidth;})()`),true,'Recent receipts must fit without a horizontal scrollbar at '+width);
        assert.equal(await evaluate(`(()=>{const link=document.querySelector('[data-receipt] .receipt-link');return link.title===link.textContent && link.getAttribute('href')==='index.php?page=imports&id=48';})()`),true,'Long receipt text must retain hints and its exact detail link.');
    }
    // Subsequent checks render a fresh page; no business data was changed.
    const accountRender = spawnSync(php, ['tests/home.php', '--accounts', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(accountRender.status, 0, accountRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const accountFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: accountFrame.frameTree.frame.id, html: accountRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterAccounts === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('typeof filterAccounts'), 'function', 'Account page scripts failed.');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 6);
    await evaluate('document.getElementById("account-search").value="nguyen"; document.getElementById("account-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 1);
    await evaluate('document.getElementById("account-search").value=""; document.getElementById("account-role").value="keeper"; document.getElementById("account-warehouse").value="haiphong"; filterAccounts()');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 1, 'Role and warehouse must match the same assignment.');
    await evaluate('document.getElementById("account-role").value=""; document.getElementById("account-warehouse").value=""; document.getElementById("account-status").value="locked"; filterAccounts()');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 1);
    await evaluate('document.getElementById("account-search").value="missing-user"; filterAccounts()');
    assert.equal(await evaluate('document.querySelector(".account-empty").hidden'), false);
    await evaluate('document.getElementById("account-search").value=""; document.getElementById("account-status").value=""; filterAccounts()');
    assert.equal(await evaluate('document.querySelector(".account-tabs, #permissions-panel")'), null, 'Account list should display directly without role tabs.');
    await evaluate('document.getElementById("create-account").focus(); document.getElementById("create-account").click()');
    assert.equal(await evaluate('document.getElementById("account-dialog").open'), true);
    assert.equal(await evaluate('document.getElementById("account-form").checkValidity()'), false);
    assert.equal(await evaluate('document.querySelectorAll(".account-employee-field input:not([type=hidden]),.account-employee-field select").length'), 1, 'Employee selection and search must share one visible input.');
    await evaluate('document.getElementById("account-employee-search").focus()');
    assert.equal(await evaluate('document.getElementById("account-employee-search").getAttribute("aria-expanded")'), 'true');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee-choice]:not([hidden])").length'), 6, 'Opening the field must list all employees and the unlink option.');
    await evaluate('document.getElementById("account-employee-search").value="do tung"; document.getElementById("account-employee-search").dispatchEvent(new Event("input"))');
    assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-employee-choice]:not([hidden])")].map(option=>option.dataset.employeeChoice)'), ['', 'NV005'], 'Find Vietnamese names without accents and with separate words.');
    await evaluate('document.querySelector("[data-employee-choice=NV005]").click()');
    assert.equal(await evaluate('new FormData(document.getElementById("account-form")).get("employee")'), 'NV005', 'Submit the selected employee ID, not the search text.');
    assert.ok(await evaluate('document.getElementById("account-employee-search").value.includes("Đỗ Thanh Tùng")'));
    assert.equal(await evaluate('document.getElementById("account-employee-panel").matches(":popover-open")'), false);
    await evaluate('document.getElementById("account-employee-search").value="nv003"; document.getElementById("account-employee-search").dispatchEvent(new Event("input"))');
    assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-employee-choice]:not([hidden])")].map(option=>option.dataset.employeeChoice)'), ['', 'NV003'], 'Find employees by code regardless of letter case.');
    assert.equal(await evaluate('(() => { const event=new KeyboardEvent("keydown",{key:"Enter",bubbles:true,cancelable:true}); document.getElementById("account-employee-search").dispatchEvent(event); return event.defaultPrevented && document.getElementById("account-employee").value==="NV003" && document.getElementById("account-dialog").open; })()'), true, 'Enter must select a result without saving the account.');
    await evaluate('document.getElementById("account-employee-search").value="missing employee"; document.getElementById("account-employee-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.getElementById("account-employee-hint").hidden'), false);
    assert.equal(await evaluate('document.getElementById("account-employee-search").checkValidity()'), false, 'Unmatched text must not be saved as an employee link.');
    await evaluate('document.getElementById("account-username").value="sample-user"; document.getElementById("account-password").value="sample-only-password"; document.getElementById("account-form").requestSubmit()');
    assert.equal(await evaluate('document.getElementById("account-dialog").open'), true, 'Pending search text must block saving.');
    await page('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    await page('Input.dispatchKeyEvent', {type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
    assert.equal(await evaluate('document.getElementById("account-dialog").open'), true, 'Escape in the list must not close the account dialog.');
    assert.equal(await evaluate('document.getElementById("account-employee").value'), 'NV003', 'Escape must restore the previous employee selection.');
    await evaluate('document.getElementById("account-employee-search").click(); document.getElementById("account-employee-search").dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowUp",bubbles:true,cancelable:true}))');
    assert.equal(await evaluate('document.getElementById("account-employee-search").getAttribute("aria-activedescendant")'), 'account-employee-option-NV005');
    await evaluate('document.getElementById("account-employee-search").dispatchEvent(new KeyboardEvent("keydown",{key:"Enter",bubbles:true,cancelable:true}))');
    assert.equal(await evaluate('document.getElementById("account-employee").value'), 'NV005', 'Arrow keys and Enter must select employees.');
    await evaluate('document.getElementById("account-employee-search").click(); document.getElementById("account-employee-option-none").click()');
    assert.equal(await evaluate('document.getElementById("account-employee").value'), '', 'The unlink option must clear the selected ID.');
    await evaluate('document.getElementById("account-admin").checked=true; document.getElementById("account-admin").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.getElementById("warehouse-permissions").disabled'), true);
    await evaluate('document.getElementById("account-username").value="sample-user"; document.getElementById("account-password").value="sample-only-password"; document.getElementById("account-form").requestSubmit()');
    assert.equal(await evaluate('document.getElementById("account-dialog").open'), false);
    assert.equal(await evaluate('document.getElementById("account-notice").hidden'), false);
    assert.equal(await evaluate('document.getElementById("account-password").value'), '', 'Do not retain the entered password.');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]").length'), 6, 'Preview must not change sample accounts.');
    await evaluate('document.querySelectorAll(".edit-account")[1].click()');
    assert.equal(await evaluate('document.getElementById("account-username").value'), 'minhanh');
    assert.equal(await evaluate('document.getElementById("account-employee").value'), 'NV001', 'Editing must restore the account employee.');
    await evaluate('document.getElementById("account-employee-search").value="nv005"; document.getElementById("account-employee-search").dispatchEvent(new Event("input")); document.querySelector(".close-account-dialog").click(); document.getElementById("create-account").click()');
    assert.equal(await evaluate('document.getElementById("account-employee-search").value'), '', 'Reopening the form must clear the previous employee search.');
    assert.equal(await evaluate('document.getElementById("account-employee").value'), '', 'A new account must not inherit the previously edited employee.');
    await evaluate('document.getElementById("account-employee-search").focus()');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee-choice]:not([hidden])").length'), 6);
    await evaluate('document.querySelector(".close-account-dialog").click(); document.querySelectorAll(".edit-account")[1].click()');
    assert.equal(await evaluate('document.querySelector("[data-warehouse-role=haiphong]").value'), 'viewer');
    assert.equal(await evaluate('document.getElementById("account-password").disabled'), true);
    await evaluate('document.querySelector(".close-account-dialog").click(); document.querySelector(".account-state-preview").click()');
    assert.equal(await evaluate('document.getElementById("account-state-dialog").open'), true);
    await evaluate('document.querySelector("#account-state-dialog button[value=cancel]").click()');
    for (const [width, height] of [[1440, 1000], [1280, 800], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Account page overflow at ${width}px.`);
        const { data } = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `accounts-${width}x${height}.png`), Buffer.from(data, 'base64'));
        await evaluate('document.getElementById("create-account").click()');
        assert.equal(await evaluate('document.getElementById("account-dialog").scrollWidth <= document.getElementById("account-dialog").clientWidth'), true, `Account form overflow at ${width}px.`);
        const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `account-form-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        await evaluate('document.getElementById("account-employee-search").focus()');
        const employeeBounds = await evaluate('(() => { const panel=document.getElementById("account-employee-panel"),rect=panel.getBoundingClientRect(),input=document.getElementById("account-employee-search").getBoundingClientRect(); return {open:panel.matches(":popover-open"),fits:rect.left>=0 && rect.right<=innerWidth && rect.top>=0 && rect.bottom<=innerHeight,rect:rect.toJSON(),input:input.toJSON()}; })()');
        assert.ok(employeeBounds.open && employeeBounds.fits, `Employee dropdown must stay visible at ${width}x${height}: ${JSON.stringify(employeeBounds)}`);
        const employeeShot = await page('Page.captureScreenshot', {format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir, `account-employee-${width}x${height}.png`), Buffer.from(employeeShot.data, 'base64'));
        await evaluate('document.getElementById("account-username").focus()');
        assert.equal(await evaluate('document.getElementById("account-employee-panel").matches(":popover-open")'), false, 'Moving focus out must close the dropdown.');
        for (const editing of [false, true]) {
            if (editing) {
                await evaluate('document.querySelector(".close-account-dialog").click(); document.querySelectorAll(".edit-account")[1].click()');
            }
            assert.deepEqual(await evaluate(`(() => {
                const dialog = document.getElementById('account-dialog');
                const body = dialog.querySelector('.account-dialog-body');
                const heading = dialog.querySelector('.account-dialog-heading');
                const footer = dialog.querySelector('.account-dialog-footer');
                const before = [heading.getBoundingClientRect().top, footer.getBoundingClientRect().top];
                body.scrollTop = body.scrollHeight;
                const controls = [...footer.querySelectorAll('button'), heading.querySelector('button')];
                return {
                    fixed: before[0] === heading.getBoundingClientRect().top && before[1] === footer.getBoundingClientRect().top,
                    dialogFits: dialog.scrollHeight <= dialog.clientHeight + 1,
                    bodyScrollable: body.scrollHeight <= body.clientHeight || body.scrollTop > 0,
                    actionsVisible: controls.every(button => { const rect = button.getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; })
                };
            })()`), { fixed: true, dialogFits: true, bodyScrollable: true, actionsVisible: true }, `Only form content should scroll at ${width}x${height}, editing=${editing}.`);
        }
        await evaluate('document.querySelector(".close-account-dialog").click()');
    }
    await evaluate(`(() => {
        for (let i = 0; i < 17; i++) {
            const row = accountRows[0].cloneNode(true);
            const account = JSON.parse(row.dataset.account);
            account.username = 'pagination-user-' + i;
            row.dataset.account = JSON.stringify(account);
            row.querySelector('.account-identity strong').textContent = account.username;
            document.querySelector('.accounts-table tbody').append(row);
            accountRows.push(row);
        }
        filterAccounts();
    })()`);
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 10, 'Limit each page to 10 accounts.');
    await evaluate('document.getElementById("account-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 10);
    await evaluate('document.getElementById("account-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 3);
    assert.equal(await evaluate('document.getElementById("account-page-next").disabled'), true);
    await evaluate('document.getElementById("account-page-previous").click()');
    assert.equal(await evaluate('document.getElementById("account-page-label").textContent'), '2 / 3');
    await evaluate('document.getElementById("account-search").value="pagination-user"; document.getElementById("account-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.getElementById("account-page-label").textContent'), '1 / 2', 'Search resets pagination.');
    await evaluate('document.getElementById("account-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]:not([hidden])").length'), 7, 'Paginate matching accounts only.');
    await evaluate('document.getElementById("account-search").value="missing-user"; document.getElementById("account-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.getElementById("account-page-previous").disabled && document.getElementById("account-page-next").disabled'), true);
    assert.equal(await evaluate('document.querySelector(".account-empty").hidden'), false);
    await evaluate('accountRows.splice(6).forEach(row=>row.remove()); document.getElementById("account-search").value=""; filterAccounts()');
    const employeeRender = spawnSync(php, ['tests/home.php', '--employees', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(employeeRender.status, 0, employeeRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const employeeFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: employeeFrame.frameTree.frame.id, html: employeeRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterEmployees === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('typeof filterEmployees'), 'function');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee]:not([hidden])").length'), 10);
    await evaluate('document.getElementById("employee-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee]:not([hidden])").length'), 2);
    assert.equal(await evaluate('document.getElementById("employee-page-next").disabled'), true);
    await evaluate('document.getElementById("employee-search").value="nguyen"; document.getElementById("employee-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.getElementById("employee-page-label").textContent'), '1 / 1');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee]:not([hidden])").length'), 2);
    await evaluate('document.getElementById("employee-search").value=""; document.getElementById("employee-status").value="inactive"; document.getElementById("employee-warehouse").value="central"; document.getElementById("employee-account").value="linked"; filterEmployees()');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee]:not([hidden])").length'), 1);
    await evaluate('document.getElementById("employee-search").value="missing-employee"; filterEmployees()');
    assert.equal(await evaluate('document.querySelector(".employee-empty").hidden'), false);
    assert.equal(await evaluate('document.getElementById("employee-page-next").disabled && document.getElementById("employee-page-previous").disabled'), true);
    await evaluate('document.getElementById("employee-search").value=""; document.getElementById("employee-status").value=""; document.getElementById("employee-warehouse").value=""; document.getElementById("employee-account").value=""; filterEmployees(); document.getElementById("create-employee").click()');
    assert.equal(await evaluate('document.getElementById("employee-form").checkValidity()'), false, 'Require employee code, name and warehouse.');
    await evaluate('document.getElementById("edit-employee-code").value="NV013"; document.getElementById("edit-employee-name").value="Sample employee"; document.getElementById("edit-employee-warehouse").value="central"; document.getElementById("edit-employee-email").value="invalid-email"');
    assert.equal(await evaluate('document.getElementById("employee-form").checkValidity()'), false, 'Validate email format.');
    await evaluate('document.getElementById("edit-employee-email").value=""; document.getElementById("employee-form").requestSubmit()');
    assert.equal(await evaluate('document.getElementById("employee-dialog").open'), false);
    assert.equal(await evaluate('document.getElementById("employee-notice").hidden'), false);
    assert.equal(await evaluate('document.querySelectorAll("[data-employee]").length'), 12, 'Preview must not write employee records.');
    await evaluate('document.querySelector(".employee-details").click(); document.getElementById("employee-detail-edit").click()');
    assert.equal(await evaluate('document.getElementById("edit-employee-code").value'), 'NV001');
    assert.equal(await evaluate('document.getElementById("employee-linked-account").textContent'), 'Tài khoản: minhanh');
    await evaluate('document.getElementById("edit-employee-status").value="inactive"; document.getElementById("edit-employee-status").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.getElementById("employee-inactive-note").hidden'), false);
    await evaluate('document.querySelector(".close-employee-dialog").click(); document.querySelector(".employee-details").click(); document.getElementById("employee-detail-state").click()');
    assert.equal(await evaluate('document.getElementById("employee-state-dialog").open'), true);
    assert.equal(await evaluate('employeeStateForm.elements.id.value'), '1');
    assert.equal(await evaluate('employeeStateForm.elements.status.value'), 'inactive');
    assert.equal(await evaluate('[...document.querySelectorAll("[data-employee] .account-actions")].every(actions=>actions.children.length===2 && actions.children[0].textContent==="Xem" && actions.children[1].textContent==="Xóa")'),true,'Employee list actions must only show view/delete.');
    await evaluate('document.querySelector("#employee-state-dialog button[value=cancel]").click()');
    await evaluate('employeeDetailsDialog.close();openEmployeeDetails(JSON.parse(employeeRows[4].dataset.employee))');
    assert.equal(await evaluate('document.getElementById("employee-detail-state").textContent'), 'Mở khóa');
    await evaluate('document.getElementById("employee-detail-state").click()');
    assert.equal(await evaluate('employeeStateForm.elements.id.value==="5" && employeeStateForm.elements.status.value==="active"'),true,'Unlock must target the employee shown in details.');
    await evaluate('employeeStateForm.querySelector("[data-close-state]").click()');
    await evaluate('employeeDetailsDialog.close();document.querySelector("[data-delete-record]").click()');
    assert.equal(await evaluate('document.getElementById("management-delete-dialog").open'), true);
    assert.equal(await evaluate('managementDeleteForm.elements.action.value'), 'delete');
    assert.equal(await evaluate('document.getElementById("management-delete-description").textContent.includes("NV001")'), true);
    await evaluate('managementDeleteForm.querySelector("[data-cancel-delete]").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-employee]").length'), 12, 'Cancel must leave employee rows intact.');
    await evaluate('document.querySelector("[data-delete-record]").click(); managementDeleteForm.requestSubmit()');
    assert.equal(await evaluate('document.getElementById("management-delete-dialog").open'), false);
    for (const [width, height] of [[1440, 1000], [1280, 800], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Employee page overflow at ${width}px.`);
        const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `employees-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        for (const editing of [false, true]) {
            await evaluate(editing ? 'document.querySelector(".employee-details").click(); document.getElementById("employee-detail-edit").click()' : 'document.getElementById("create-employee").click()');
            assert.deepEqual(await evaluate(`(() => {
                const dialog = document.getElementById('employee-dialog');
                const body = dialog.querySelector('.account-dialog-body');
                const heading = dialog.querySelector('.account-dialog-heading');
                const footer = dialog.querySelector('.account-dialog-footer');
                const before = [heading.getBoundingClientRect().top, footer.getBoundingClientRect().top];
                body.scrollTop = body.scrollHeight;
                return { fixed: before[0] === heading.getBoundingClientRect().top && before[1] === footer.getBoundingClientRect().top,
                    fits: dialog.scrollHeight <= dialog.clientHeight + 1 && dialog.scrollWidth <= dialog.clientWidth,
                    actionsVisible: [...footer.querySelectorAll('button'), heading.querySelector('button')].every(button => { const rect = button.getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; }) };
            })()`), { fixed: true, fits: true, actionsVisible: true }, `Employee dialog must keep header/actions visible at ${width}x${height}.`);
            if (!editing) {
                await evaluate('document.querySelector("#employee-dialog .account-dialog-body").scrollTop=0');
                const formShot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
                writeFileSync(join(outputDir, `employee-form-${width}x${height}.png`), Buffer.from(formShot.data, 'base64'));
            }
            await evaluate('document.querySelector(".close-employee-dialog").click()');
        }
    }
    const auditRender = spawnSync(php, ['tests/home.php', '--audit', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(auditRender.status, 0, auditRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const auditFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: auditFrame.frameTree.frame.id, html: auditRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterAudit === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('typeof filterAudit'), 'function');
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 10);
    await evaluate('document.getElementById("audit-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 4);
    await evaluate('document.getElementById("audit-search").value="nguyen"; document.getElementById("audit-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 3);
    assert.equal(await evaluate('document.getElementById("audit-page-label").textContent'), '1 / 1');
    await evaluate('document.getElementById("audit-reset").click(); document.getElementById("audit-from").value="2026-10-09"; document.getElementById("audit-to").value="2026-10-09"; filterAudit()');
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 7, 'Date boundaries must be inclusive.');
    await evaluate('document.getElementById("audit-from").value="2026-10-10"; filterAudit()');
    assert.equal(await evaluate('document.getElementById("audit-filter-error").hidden'), false);
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 0);
    await evaluate('document.getElementById("audit-reset").click(); document.getElementById("audit-entity").value="users"; document.getElementById("audit-action").value="assign_access"; document.getElementById("audit-warehouse").value="global"; filterAudit()');
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 1);
    await evaluate('document.getElementById("audit-reset").click(); document.getElementById("audit-warehouse").value="danang"; filterAudit()');
    assert.equal(await evaluate('document.querySelectorAll("[data-audit]:not([hidden])").length'), 1);
    await evaluate('document.getElementById("audit-search").value="missing-log"; filterAudit()');
    assert.equal(await evaluate('document.querySelector(".audit-empty").hidden'), false);
    await evaluate('document.getElementById("audit-reset").click(); document.querySelectorAll(".audit-details")[3].click()');
    assert.equal(await evaluate('document.getElementById("audit-dialog").open'), true);
    assert.equal(await evaluate('document.querySelectorAll("#audit-change-rows .audit-changed").length'), 1, 'Highlight only changed fields.');
    assert.equal(await evaluate('document.getElementById("audit-detail-id").textContent'), '#1045');
    await evaluate('document.querySelector("#audit-dialog footer button").click(); document.querySelectorAll(".audit-details")[6].click()');
    assert.equal(await evaluate('document.querySelector("#audit-change-rows td:nth-child(2)").textContent'), 'Chưa có dữ liệu');
    assert.equal(await evaluate('document.querySelector("#audit-change-rows tr:last-child td:last-child").textContent'), 'Không', 'Display false values accurately.');
    await evaluate('document.querySelector("#audit-dialog footer button").click(); openAudit({...JSON.parse(auditRows[0].dataset.audit), description:"<img src=x onerror=alert(1)>", after_data:{note:"<script>alert(1)</script>"}})');
    assert.equal(await evaluate('document.querySelector("#audit-detail-description img, #audit-change-rows script")'), null, 'Render audit values as text.');
    await evaluate('document.querySelector("#audit-dialog footer button").click(); document.querySelectorAll(".audit-details")[13].click()');
    assert.equal(await evaluate('document.getElementById("audit-detail-user").textContent'), 'Hệ thống · Tự động');
    await evaluate('document.querySelector("#audit-dialog footer button").click()');
    for (const [width, height] of [[1440, 1000], [1280, 800], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Audit page overflow at ${width}px.`);
        const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `audit-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        await evaluate('document.querySelectorAll(".audit-details")[3].click()');
        assert.deepEqual(await evaluate(`(() => {
            const dialog = document.getElementById('audit-dialog');
            const body = dialog.querySelector('.account-dialog-body');
            const heading = dialog.querySelector('.account-dialog-heading');
            const footer = dialog.querySelector('.account-dialog-footer');
            const before = [heading.getBoundingClientRect().top, footer.getBoundingClientRect().top];
            body.scrollTop = body.scrollHeight;
            return { fixed: before[0] === heading.getBoundingClientRect().top && before[1] === footer.getBoundingClientRect().top,
                fits: dialog.scrollHeight <= dialog.clientHeight + 1 && dialog.scrollWidth <= dialog.clientWidth,
                actionsVisible: [...footer.querySelectorAll('button'), heading.querySelector('button')].every(button => { const rect = button.getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; }) };
        })()`), { fixed: true, fits: true, actionsVisible: true }, `Audit detail header/actions must remain visible at ${width}x${height}.`);
        await evaluate('document.querySelector("#audit-dialog .account-dialog-body").scrollTop=0');
        const detail = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `audit-detail-${width}x${height}.png`), Buffer.from(detail.data, 'base64'));
        await evaluate('document.querySelector("#audit-dialog footer button").click()');
    }
    const warehouseRender = spawnSync(php, ['tests/home.php', '--warehouses', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(warehouseRender.status, 0, warehouseRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const warehouseFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: warehouseFrame.frameTree.frame.id, html: warehouseRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterWarehouses === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('document.querySelectorAll("[data-warehouse]:not([hidden])").length'), 10);
    assert.equal(await evaluate('[...document.querySelectorAll("[data-warehouse] .account-actions")].every(actions=>actions.children.length===2 && actions.children[0].textContent==="Xem" && actions.children[1].textContent==="Xóa")'),true,'Warehouse list must only offer view/delete.');
    await evaluate('document.querySelector(".warehouse-details").click()');
    assert.equal(await evaluate('warehouseDetailsDialog.querySelectorAll("input,select,textarea").length'),0,'Warehouse details must be read-only.');
    assert.equal(await evaluate('document.querySelector("[data-warehouse-print-detail=unit_name]").textContent'),'Đơn vị thử');
    assert.equal(await evaluate('document.querySelector("[data-warehouse-print-detail=keeper_employee_id]").textContent'),'NV01 · Người ký <script>');
    await evaluate('document.getElementById("warehouse-detail-state").click()');
    assert.equal(await evaluate('warehouseStateForm.elements.id.value==="1" && warehouseStateForm.elements.status.value==="inactive" && warehouseStateForm.elements.version.value===viewedWarehouse.version'),true,'Warehouse state actions must target the viewed record/version.');
    await evaluate('warehouseStateForm.querySelector("[data-close-state]").click();warehouseDetailsDialog.close();openWarehouseDetails(JSON.parse(warehouseRows[2].dataset.warehouse))');
    assert.equal(await evaluate('document.getElementById("warehouse-detail-state").textContent'),'Kích hoạt');
    await evaluate('document.getElementById("warehouse-detail-state").click()');
    assert.equal(await evaluate('warehouseStateForm.elements.id.value==="3" && warehouseStateForm.elements.status.value==="active"'),true,'Inactive warehouses must offer activation from details.');
    await evaluate('warehouseStateForm.requestSubmit()');
    assert.equal(await evaluate('warehouseStateDialog.open'),false,'Preview state changes must not persist.');
    assert.equal(await evaluate('viewedWarehouse.status'),'inactive');
    await evaluate('warehouseDetailsDialog.close()');
    assert.equal(await evaluate('document.querySelector("a[href=\\"index.php?page=warehouses\\"]").getAttribute("aria-current")'), 'page');
    await evaluate('document.getElementById("warehouse-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-warehouse]:not([hidden])").length'), 2);
    await evaluate('warehouseStatus.value="inactive"; warehouseStatus.dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-warehouse]:not([hidden])").length'), 2);
    assert.equal(await evaluate('document.getElementById("warehouse-page-label").textContent'), '1 / 1');
    await evaluate('warehouseStatus.value=""; warehouseSearch.value="nguyen trai"; filterWarehouses()');
    assert.equal(await evaluate('document.getElementById("warehouse-count").textContent.endsWith("/ 12 kho")'), true, 'Search ignores Vietnamese accents.');
    await evaluate('warehouseSearch.value="missing-warehouse"; filterWarehouses()');
    assert.equal(await evaluate('document.querySelector(".warehouse-empty").hidden'), false);
    assert.equal(await evaluate('previousWarehousePage.disabled && nextWarehousePage.disabled'), true);
    await evaluate('warehouseSearch.value=""; filterWarehouses(); document.getElementById("create-warehouse").click()');
    assert.equal(await evaluate('warehouseForm.checkValidity()'), false, 'Warehouse code and name are required.');
    await evaluate('warehouseForm.elements.code.value="KHO13"; warehouseForm.elements.namedItem("name").value="Kho mới"; warehouseForm.requestSubmit()');
    assert.equal(await evaluate('warehouseDialog.open'), false);
    assert.equal(await evaluate('document.getElementById("warehouse-notice").hidden'), false);
    assert.deepEqual(await evaluate(`(async () => {
        const originalFetch = window.fetch;
        const originalPreview = warehouseForm.dataset.preview;
        const requests = [];
        const results = [];
        const error = warehouseForm.querySelector('[data-form-error]');
        try {
            warehouseForm.dataset.preview = 'false';
            window.fetch = async (endpoint, options) => {
                requests.push({ endpoint, action: options.body.get('action'), method: options.method });
                return new Response(JSON.stringify({ ok: false, message: 'Phiên đăng nhập đã hết hạn.' }), { status: 401, headers: { 'Content-Type': 'application/json' } });
            };
            await submitManagementForm(warehouseForm, warehouseDialog, 'warehouse-notice');
            results.push(error.textContent === 'Phiên đăng nhập đã hết hạn.' && !error.hidden);
            window.fetch = async () => new Response('Not Found', { status: 404, headers: { 'Content-Type': 'text/html' } });
            await submitManagementForm(warehouseForm, warehouseDialog, 'warehouse-notice');
            results.push(error.textContent === 'Không thể lưu dữ liệu. Máy chủ trả về phản hồi không hợp lệ.' && !error.hidden);
            return { correctEndpoint: requests[0].endpoint === new URL('index.php?page=warehouses', document.baseURI).href,
                correctAction: requests[0].action === 'save', correctMethod: requests[0].method === 'POST',
                errors: results, enabled: !warehouseForm.querySelector('button[type=submit]').disabled };
        } finally { window.fetch = originalFetch; warehouseForm.dataset.preview = originalPreview; }
    })()`), { correctEndpoint: true, correctAction: true, correctMethod: true, errors: [true, true], enabled: true }, 'Hidden action fields must not shadow the submission URL; non-JSON errors must not claim session expiry.');
    await evaluate('document.querySelector(".warehouse-details").click();document.getElementById("warehouse-detail-edit").click()');
    assert.equal(await evaluate('warehouseForm.elements.namedItem("name").value'), 'Kho 1');
    assert.equal(await evaluate('warehouseForm.elements.id.value'), '1');
    assert.equal(await evaluate('warehouseForm.querySelector("[data-print-setting=unit_name]").value'), 'Đơn vị thử');
    assert.equal(await evaluate('warehouseForm.querySelector("[data-print-setting=keeper_employee_id]").value'), '1');
    assert.deepEqual(await evaluate(`(async () => {
        const originalFetch = window.fetch;
        const originalPreview = warehouseForm.dataset.preview;
        try {
            warehouseForm.dataset.preview = 'false';
            window.fetch = async () => new Response(JSON.stringify({ ok: false, message: 'Mã kho <img src=x onerror=alert(1)> đã tồn tại.' }), { status: 422, headers: { 'Content-Type': 'application/json' } });
            await submitManagementForm(warehouseForm, warehouseDialog, 'warehouse-notice');
            const center = document.getElementById('notification-center');
            const toast = center.querySelector('.sn-notify');
            return { warning: toast.classList.contains('sn-notify-warning'),
                topLayer: center.matches(':popover-open') && center.parentElement === warehouseDialog,
                dialogOpen: warehouseDialog.open, escaped: !toast.querySelector('img') && toast.textContent.includes('<img'),
                accessible: toast.getAttribute('role') === 'alert' && toast.querySelector('.sn-notify-close').tabIndex === 0,
                retained: warehouseForm.elements.namedItem('name').value === 'Kho 1' };
        } finally { window.fetch = originalFetch; warehouseForm.dataset.preview = originalPreview; }
    })()`), { warning: true, topLayer: true, dialogOpen: true, escaped: true, accessible: true, retained: true }, 'Validation toast must display above the open dialog, escape content and preserve the form.');
    await evaluate('document.querySelector("#notification-center .sn-notify-close").dispatchEvent(new KeyboardEvent("keydown", { key:"Enter", bubbles:true }))');
    await new Promise(resolve => setTimeout(resolve, 250));
    assert.equal(await evaluate('document.querySelectorAll("#notification-center .sn-notify").length'), 0, 'Keyboard closes notification.');
    await evaluate('warehouseForm.elements.status.value="inactive"; updateWarehouseStatusNote()');
    assert.equal(await evaluate('document.getElementById("warehouse-inactive-note").hidden'), false);
    await evaluate('warehouseDialog.close()');
    for (const [width, height] of [[1440, 1000], [1280, 800], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Warehouse page overflow at ${width}px.`);
        const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `warehouses-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        for (const selector of ['.warehouse-details', '#warehouse-detail-edit', '#warehouse-detail-state', '[data-delete-record]']) {
            if(selector.startsWith('#warehouse-detail-'))await evaluate('document.querySelector(".warehouse-details").click()');
            await evaluate(`document.querySelector('${selector}').click()`);
            assert.deepEqual(await evaluate(`(() => {
                const dialog = [...document.querySelectorAll('dialog[open]')].at(-1);
                const body = dialog.querySelector('.account-dialog-body');
                const heading = dialog.querySelector('.account-dialog-heading');
                const footer = dialog.querySelector('.account-dialog-footer');
                const before = [heading.getBoundingClientRect().top, footer.getBoundingClientRect().top];
                body.scrollTop = body.scrollHeight;
                return { fixed: before[0] === heading.getBoundingClientRect().top && before[1] === footer.getBoundingClientRect().top,
                    fits: dialog.scrollHeight <= dialog.clientHeight + 1 && dialog.scrollWidth <= dialog.clientWidth,
                    actionsVisible: [...footer.querySelectorAll('button'), heading.querySelector('button')].every(button => { const rect = button.getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; }) };
            })()`), { fixed: true, fits: true, actionsVisible: true }, `Warehouse dialog must keep its title/actions fixed at ${width}x${height}.`);
            await evaluate('[...document.querySelectorAll("dialog[open]")].at(-1).querySelector(".account-dialog-body").scrollTop=0');
            const detail = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            writeFileSync(join(outputDir, `warehouse-${selector.slice(1)}-${width}x${height}.png`), Buffer.from(detail.data, 'base64'));
            await evaluate('document.querySelectorAll("dialog[open]").forEach(dialog=>dialog.close())');
        }
    }
    await evaluate('document.querySelector("[data-delete-record]").click()');
    assert.equal(await evaluate('managementDeleteForm.elements.id.value'), '1', 'Delete the selected warehouse.');
    assert.equal(await evaluate('managementDeleteForm.elements.version.value'), '2026-10-09 12:00:00.000000');
    await evaluate('managementDeleteForm.querySelector("[data-cancel-delete]").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-warehouse]").length'), 12, 'Cancel must leave warehouse rows intact.');
    await evaluate('document.querySelector("[data-delete-record]").click(); managementDeleteForm.requestSubmit()');
    assert.equal(await evaluate('document.getElementById("management-delete-dialog").open'), false);
    await evaluate('showNotification("Đã lưu thông tin kho.", "success")');
    await new Promise(resolve => setTimeout(resolve, 250));
    assert.equal(await evaluate('document.querySelector("#notification-center .sn-notify-success").getAttribute("role")'), 'status');
    for (const width of [1440, 390, 320]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate(`(() => { const rect=document.querySelector('#notification-center .sn-notify').getBoundingClientRect(); return rect.left>=0 && rect.right<=innerWidth && document.documentElement.scrollWidth<=innerWidth; })()`), true, 'Notification must fit desktop and mobile.');
        const toastShot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `notification-${width}.png`), Buffer.from(toastShot.data, 'base64'));
    }
    await new Promise(resolve => setTimeout(resolve, 5000));
    assert.equal(await evaluate('document.querySelectorAll("#notification-center .sn-notify").length'), 0, 'Success notification closes automatically.');
    const supplierRender = spawnSync(php, ['tests/home.php', '--suppliers', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(supplierRender.status, 0, supplierRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const supplierFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: supplierFrame.frameTree.frame.id, html: supplierRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterSuppliers === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('document.querySelectorAll("[data-supplier]:not([hidden])").length'), 10);
    assert.equal(await evaluate('document.querySelectorAll("[data-delete-record], #create-supplier, #supplier-form").length'), 0, 'Supplier tab is for lookup, not entry.');
    await evaluate('nextSupplierPage.click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-supplier]:not([hidden])").length'), 2);
    for (const [filter, value, count] of [['supplierStatus', 'inactive', 3], ['supplierTax', 'missing', 3], ['supplierLinked', 'unlinked', 4]]) {
        await evaluate(`supplierStatus.value=''; supplierTax.value=''; supplierLinked.value=''; ${filter}.value='${value}'; filterSuppliers()`);
        assert.equal(await evaluate('document.querySelectorAll("[data-supplier]:not([hidden])").length'), count);
        assert.equal(await evaluate('document.getElementById("supplier-page-label").textContent'), '1 / 1');
    }
    await evaluate('supplierLinked.value=""; supplierSearch.value="0100000001"; filterSuppliers()');
    assert.equal(await evaluate('document.querySelectorAll("[data-supplier]:not([hidden])").length'), 1, 'Tax-code search preserves leading zeros.');
    await evaluate('supplierSearch.value="thiet bi"; filterSuppliers()');
    assert.equal(await evaluate('document.getElementById("supplier-count").textContent.endsWith("/ 12 nhà cung cấp")'), true);
    await evaluate('supplierSearch.value="missing-supplier"; filterSuppliers()');
    assert.equal(await evaluate('document.querySelector(".supplier-empty").hidden'), false);
    await evaluate('supplierSearch.value=""; filterSuppliers(); supplierRows[2].querySelector(".supplier-details").click()');
    assert.equal(await evaluate('document.getElementById("supplier-receipt-empty").hidden'), false);
    await evaluate('supplierDialog.close(); const maliciousSupplier=JSON.parse(supplierRows[0].dataset.supplier); maliciousSupplier.name="<img src=x onerror=alert(1)>"; openSupplier(maliciousSupplier)');
    assert.equal(await evaluate('document.getElementById("supplier-detail-name").querySelector("img") === null'), true, 'Supplier detail names are plain text.');
    await evaluate('supplierDialog.close()');
    for (const [width, height] of [[1440, 1000], [1280, 800], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Supplier page overflow at ${width}px.`);
        const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `suppliers-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        await evaluate('document.querySelector(".supplier-details").click()');
        assert.equal(await evaluate('document.querySelectorAll("#supplier-receipt-rows tr").length'), 14);
        assert.equal(await evaluate('document.getElementById("supplier-detail-tax_code").textContent'), '0100000001');
        assert.deepEqual(await evaluate(`(() => {
            const dialog = supplierDialog;
            const body = dialog.querySelector('.account-dialog-body');
            const heading = dialog.querySelector('.account-dialog-heading');
            const footer = dialog.querySelector('.account-dialog-footer');
            const before = [heading.getBoundingClientRect().top, footer.getBoundingClientRect().top];
            body.scrollTop = body.scrollHeight;
            return { fixed: before[0] === heading.getBoundingClientRect().top && before[1] === footer.getBoundingClientRect().top,
                fits: dialog.scrollHeight <= dialog.clientHeight + 1 && dialog.scrollWidth <= dialog.clientWidth,
                actionsVisible: [...footer.querySelectorAll('button'), heading.querySelector('button')].every(button => { const rect=button.getBoundingClientRect(); return rect.top>=0 && rect.bottom<=innerHeight; }) };
        })()`), { fixed: true, fits: true, actionsVisible: true }, `Supplier detail title/actions must remain visible at ${width}x${height}.`);
        await evaluate('supplierDialog.querySelector(".account-dialog-body").scrollTop=0');
        const detail = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `supplier-detail-${width}x${height}.png`), Buffer.from(detail.data, 'base64'));
        await evaluate('supplierDialog.querySelector("footer button").click()');
    }
    const decisionRender = spawnSync(php, ['tests/home.php', '--decisions', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(decisionRender.status, 0, decisionRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const decisionFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: decisionFrame.frameTree.frame.id, html: decisionRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterDecisions === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('document.querySelectorAll("[data-decision]:not([hidden])").length'), 10);
    assert.equal(await evaluate(`document.querySelector('a[href="index.php?page=decisions"]').closest('.nav-children').children.length`), 1);
    await evaluate('document.getElementById("decision-page-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-decision]:not([hidden])").length'), 2);
    for (const [filter, value, count] of [['decisionType', 'increase', 3], ['decisionWarehouse', '1', 6], ['decisionLinked', 'unlinked', 4]]) {
        await evaluate(`decisionType.value=''; decisionWarehouse.value=''; decisionLinked.value=''; ${filter}.value='${value}'; filterDecisions()`);
        assert.equal(await evaluate('document.querySelectorAll("[data-decision]:not([hidden])").length'), count);
        assert.equal(await evaluate('decisionPage'), 1);
    }
    await evaluate('decisionLinked.value=""; decisionSearch.value="quyet dinh"; filterDecisions()');
    assert.equal(await evaluate('document.getElementById("decision-count").textContent.endsWith("/ 12 quyết định")'), true);
    await evaluate('decisionSearch.value="missing-decision"; filterDecisions()');
    assert.equal(await evaluate('document.querySelector(".decision-empty").hidden'), false);
    await evaluate('decisionSearch.value=""; filterDecisions(); document.querySelectorAll(".decision-details")[2].click()');
    assert.equal(await evaluate('document.getElementById("decision-item-empty").hidden'), false);
    await evaluate('decisionDialog.close()');
    for (const [width, height] of [[1440, 1000], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Decision page overflow.');
        let shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `decisions-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        await evaluate('document.querySelector(".decision-details").click()');
        assert.equal(await evaluate('document.querySelectorAll("#decision-item-rows tr").length'), 14);
        assert.equal(await evaluate('document.querySelector("#decision-item-rows tr td:nth-child(4)").textContent'), '12345678901234.123456 Chiếc');
        assert.equal(await evaluate('document.getElementById("decision-detail-content").querySelector("script") === null'), true);
        assert.equal(await evaluate(`(() => {
            const body=decisionDialog.querySelector('.account-dialog-body'), header=decisionDialog.querySelector('header'), footer=decisionDialog.querySelector('footer');
            const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top]; body.scrollTop=body.scrollHeight;
            return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top
                && decisionDialog.scrollHeight<=decisionDialog.clientHeight+1 && decisionDialog.scrollWidth<=decisionDialog.clientWidth
                && footer.getBoundingClientRect().bottom<=innerHeight;
        })()`), true, 'Decision title/footer must remain fixed and fit viewport.');
        await evaluate('decisionDialog.querySelector(".account-dialog-body").scrollTop=0');
        shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `decision-detail-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        await evaluate('decisionDialog.querySelector("footer button").click()');
    }
    await evaluate('document.querySelector(".edit-decision").click()');
    assert.equal(await evaluate('document.querySelectorAll("#decision-line-options input:checked").length'), 14, 'Editing must preserve existing links.');
    assert.equal(await evaluate('document.querySelectorAll("#decision-line-options tr:not([hidden])").length'), 14, 'Other decisions must not appear as selectable lines.');
    await evaluate('document.getElementById("decision-line-search").value="missing"; updateDecisionLines()');
    assert.equal(await evaluate('document.querySelectorAll("#decision-line-options input:checked").length'), 14, 'Search must retain selections.');
    await evaluate('decisionEditor.close(); document.getElementById("create-decision").click(); decisionForm.elements.warehouse.value="1"; updateDecisionLines()');
    assert.equal(await evaluate('document.querySelectorAll("#decision-line-options tr:not([hidden])").length'), 1);
    await evaluate('document.querySelector("#decision-line-options input:not(:disabled)").click(); decisionForm.elements.warehouse.value="2"; updateDecisionLines()');
    assert.equal(await evaluate('document.querySelectorAll("#decision-line-options input:checked").length'), 0, 'Warehouse change must clear incompatible selections.');
    await evaluate(`decisionForm.elements.number.value='QD-NEW'; decisionForm.elements.issued_date.value='2026-10-09'; decisionForm.elements.issuing_authority.value='VISHIPEL'; decisionForm.elements.warehouse.value='1'; updateDecisionLines();
        const files=new DataTransfer(); files.items.add(new File(['bad'], 'fake.php')); decisionForm.elements.pdf.files=files.files; decisionForm.requestSubmit()`);
    assert.equal(await evaluate('decisionForm.querySelector("[data-form-error]").hidden'), false, 'Client must reject non-PDF files.');
    await evaluate('decisionForm.elements.pdf.value=""; decisionForm.requestSubmit()');
    assert.equal(await evaluate('decisionEditor.open'), false, 'Preview save should close the dialog without a request.');
    for (const [width, height] of [[1440, 1000], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('document.querySelector(".edit-decision").click(); new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate(`(() => {
            const body=decisionEditor.querySelector('.account-dialog-body'), header=decisionEditor.querySelector('header'), footer=decisionEditor.querySelector('footer');
            const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top]; body.scrollTop=body.scrollHeight;
            return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top
                && decisionEditor.scrollWidth<=decisionEditor.clientWidth && decisionEditor.scrollHeight<=decisionEditor.clientHeight+1
                && footer.getBoundingClientRect().bottom<=innerHeight;
        })()`), true, 'Decision editor title/footer must stay fixed on short/mobile screens.');
        await evaluate('decisionEditor.querySelector(".account-dialog-body").scrollTop=0');
        const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, `decision-editor-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
        await evaluate('decisionEditor.close()');
    }
    await send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: outputDir });
    for (const variant of ['inventory', 'materials', 'parts', 'tools', 'assets', 'live']) {
        const kind = variant === 'live' ? 'inventory' : variant;
        const live = variant === 'live';
        const reportRender = spawnSync(php, ['tests/home.php', '--reports', '--report-kind=' + kind, '--render', ...(live ? ['--live-report', '--report-checks'] : [])], { encoding: 'utf8', windowsHide: true });
        assert.equal(reportRender.status, 0, reportRender.stderr);
        await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
        for (let i = 0; i < 100; i++) {
            if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        const reportFrame = await page('Page.getFrameTree');
        // Live-report samples have no login session; notifications are tested separately below.
        await page('Page.setDocumentContent', { frameId: reportFrame.frameTree.frame.id, html: reportRender.stdout.replace('data-user="1" data-preview="false"', 'data-user="1" data-preview="true"') });
        for (let i = 0; i < 100; i++) {
            if (await evaluate('document.readyState === "complete" && typeof paginateReport === "function" && typeof XLSX === "object"')) break;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        const count = kind === 'inventory' ? 60 : 12;
        assert.equal(await evaluate('reportData.preview'), !live);
        assert.equal(await evaluate('formatReportNumber(null)'), 'Chưa đủ dữ liệu');
        assert.equal(await evaluate("formatReportNumber(null,'none')"), 'Không theo dõi');
        assert.equal(await evaluate("reportDecisionLabel({decision_status:'none'})"), 'Không có quyết định');
        if (live) {
            assert.equal(await evaluate("reportData.report.unknown_count===1 && document.querySelector('.report-warning').textContent.includes('1 dòng chưa đủ')"), true);
            assert.equal(await evaluate("document.querySelector('.report-reconciliation').textContent.includes('CHECK-<script>') && !document.querySelector('.report-reconciliation script')"), true, 'Reconciliation receipt names must be escaped.');
        }
        assert.equal(await evaluate('document.querySelectorAll("[data-report-row]:not([hidden])").length'), 10);
        assert.equal(await evaluate('document.querySelectorAll(".nav-group[open] .nav-child").length'), 5, 'All report navigation entries must be available.');
        assert.equal(await evaluate('document.querySelector(".nav-child[aria-current=page]").getAttribute("href").endsWith("kind=" + reportData.filters.kind)'), true);
        await evaluate('document.getElementById("report-next").click()');
        assert.equal(await evaluate('document.querySelectorAll("[data-report-row]:not([hidden])").length'), kind === 'inventory' ? 10 : 2);
        assert.equal(await evaluate(`(() => {
            const book=buildReportWorkbook(); const data=XLSX.write(book,{type:'base64',bookType:'xlsx'}); const read=XLSX.read(data,{type:'base64'});
            const rows=XLSX.utils.sheet_to_json(read.Sheets['Bao cao'],{header:1});
            return rows[1][0].includes('${live ? 'DỮ LIỆU THỰC TẾ' : 'DỮ LIỆU MẪU'}') && rows.length === ${count + 7} && read.SheetNames.length === ${live ? 3 : 2};
        })()`), true, 'Excel must include all filtered pages and correct data source label.');
        assert.equal(await evaluate("reportExcelValue('9007199254740991.123456')"), '9007199254740991.123456', 'Large DECIMAL values must remain text in Excel.');
        if (kind === 'inventory') {
            assert.equal(await evaluate(`(() => { const row=reportData.report.rows[0], name=row.name; row.name='=HYPERLINK("https://example.invalid")'; const cell=buildReportWorkbook().Sheets['Bao cao'].B7; row.name=name; return cell.t==='s' && !cell.f; })()`), true, 'Excel names must remain text, never formulas.');
            await evaluate('document.getElementById("export-report").click()');
            const filename = await evaluate('`VISHIPEL_${reportData.preview ? "MAU_" : ""}${reportData.filters.kind}_${reportData.filters.from}_${reportData.filters.to}.xlsx`');
            for (let i = 0; i < 50 && !existsSync(join(outputDir, filename)); i++) await new Promise(resolve => setTimeout(resolve, 100));
            assert.equal(existsSync(join(outputDir, filename)), true, 'Excel download did not complete.');
            assert.equal(readFileSync(join(outputDir, filename)).subarray(0, 2).toString(), 'PK', 'Downloaded Excel must be a genuine XLSX ZIP file.');
            await evaluate('reportFilterForm.elements.from.value="2026-10-31"; reportFilterForm.elements.to.value="2026-10-01"');
            assert.equal(await evaluate('validateReportPeriod()'), false);
            await evaluate('document.querySelector("[data-report-period=year]").click()');
            assert.equal(await evaluate('reportFilterForm.elements.from.value.endsWith("01-01") && reportFilterForm.elements.to.value.endsWith("12-31")'), true);
            await evaluate('document.querySelector("[data-report-period=quarter]").click()');
            assert.equal(await evaluate('validateReportPeriod()'), true);
            await page('Emulation.setEmulatedMedia', { media: 'print' });
            await evaluate("window.dispatchEvent(new Event('beforeprint'))");
            assert.equal(await evaluate('[...document.querySelectorAll("[data-report-row]")].every(row=>getComputedStyle(row).display === "table-row")'), true, 'Print must include every page.');
            if (live) assert.equal(await evaluate("reportReconciliation.open && reportReconciliation.querySelector('tbody').getBoundingClientRect().height>0"), true, 'Print must expose collapsed reconciliation details.');
            await page('Emulation.setEmulatedMedia', { media: '' });
            await evaluate("window.dispatchEvent(new Event('afterprint'))");
            if (live) assert.equal(await evaluate('reportReconciliation.open'), false, 'Print must restore the reconciliation section state.');
        }
        for (const [width, height] of [[1440, 1000], [390, 844], [320, 568], [844, 390]]) {
            await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
            await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Report ${kind} overflow at ${width}.`);
            if (live) {
                await evaluate('reportReconciliation.open=true; reportReconciliation.scrollIntoView({block:"center"})');
                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Expanded reconciliation must scroll only its table on narrow screens.');
                const warningShot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
                writeFileSync(join(outputDir, `report-checks-${width}x${height}.png`), Buffer.from(warningShot.data, 'base64'));
                await evaluate('reportReconciliation.open=false; scrollTo(0,0)');
            }
            assert.equal(await evaluate('[...document.querySelectorAll(".report-money")].every(value => value.scrollWidth <= value.clientWidth)'), true, 'Report totals must fit their cards.');
            const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            writeFileSync(join(outputDir, `report-${variant}-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
            await evaluate('document.querySelector(".report-details").click()');
            assert.equal(await evaluate('document.querySelectorAll("#report-movement-rows tr").length'), 2);
            assert.equal(await evaluate(`(() => {
                const body=reportDialog.querySelector('.account-dialog-body'), header=reportDialog.querySelector('header'), footer=reportDialog.querySelector('footer');
                const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top]; body.scrollTop=body.scrollHeight;
                return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top
                    && reportDialog.scrollWidth<=reportDialog.clientWidth && reportDialog.scrollHeight<=reportDialog.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight;
            })()`), true, 'Report detail header/footer must remain fixed.');
            await evaluate('reportDialog.close()');
        }
        if (live) {
            await evaluate(`reportData.report.unknown_count=1; reportData.report.reconciliation=[{direction:'import',number:'CHECK',date:reportData.filters.from,warehouse_id:'1',header:'10.000001',details:'10.000000',line_count:1}]`);
            assert.equal(await evaluate(`(() => {
                const book=buildReportWorkbook(); const data=XLSX.write(book,{type:'base64',bookType:'xlsx'}); const read=XLSX.read(data,{type:'base64'});
                const rows=XLSX.utils.sheet_to_json(read.Sheets['Doi soat'],{header:1});
                return rows[4][1]==='CHECK' && rows[4][4]===10.000001 && rows[4][5]===10 && rows[2][0].includes('1 dòng chưa đủ');
            })()`), true, 'Excel must retain reconciliation and unknown-quantity warnings.');
        }
    }
    const productRender = spawnSync(php, ['tests/home.php', '--products', '--render'], { encoding: 'utf8', windowsHide: true });
    assert.equal(productRender.status, 0, productRender.stderr);
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    const productFrame = await page('Page.getFrameTree');
    await page('Page.setDocumentContent', { frameId: productFrame.frameTree.frame.id, html: productRender.stdout });
    for (let i = 0; i < 100; i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterProducts === "function"')) break;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(await evaluate('document.querySelectorAll("[data-product]:not([hidden])").length'), 10);
    await evaluate('document.getElementById("product-next").click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-product]:not([hidden])").length'), 2);
    assert.equal(await evaluate('document.querySelectorAll(".product-groups, .product-group").length'), 0, 'Product categories should only be selected from the sidebar.');
    for (const route of ['products', 'materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products']) {
        assert.equal(await evaluate(`document.querySelectorAll('.navigation a[href="index.php?page=${route}"]').length`), 1);
    }
    await evaluate(`productStock.value='untracked'; filterProducts()`);
    assert.equal(await evaluate('document.querySelectorAll("[data-product]:not([hidden])").length'), 3);
    await evaluate('productStock.value=""; productStatus.value="inactive"; filterProducts()');
    assert.equal(await evaluate('document.querySelectorAll("[data-product]:not([hidden])").length'), 4);
    await evaluate('productStatus.value=""; productSearch.value="hang hai"; filterProducts()');
    assert.equal(await evaluate('document.getElementById("product-count").textContent.endsWith("/ 12 hàng hóa")'), true);
    await evaluate('productSearch.value=""; productWarehouse.value="2"; filterProducts()');
    assert.equal(await evaluate('document.querySelector(".product-empty").hidden'), false);
    await evaluate(`const productTest=JSON.parse(productRows[0].dataset.product); productTest.movements.push({...productTest.movements[0],warehouse_id:'2'}); productTest.stocks.push({warehouse_id:'2',incoming:'1.000000',outgoing:'3.000000',closing:'-2.000000'}); productRows[0].dataset.product=JSON.stringify(productTest); productStock.value='negative'; filterProducts()`);
    assert.equal(await evaluate('document.querySelectorAll("[data-product]:not([hidden])").length'), 1);
    assert.equal(await evaluate('productRows[0].querySelector("[data-product-quantity=closing]").textContent'), '-2');
    await evaluate('productWarehouse.value=""; productStock.value=""; filterProducts()');
    assert.equal(await evaluate('formatProductQuantity("12345678901234.123456")'), '12.345.678.901.234,123456');
    await evaluate('document.querySelector(".edit-product").click()');
    assert.equal(await evaluate('productForm.elements.unit.readOnly'), true);
    await evaluate('productForm.requestSubmit()');
    assert.equal(await evaluate('productEditor.open'), false, 'Preview editing must not post business data.');
    for (const [width, height] of [[1440, 1000], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Product page overflow.');
        let shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`products-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        for (const [button, dialog] of [['.product-details','productDialog'],['.edit-product','productEditor']]) {
            await evaluate(`document.querySelector('${button}').click()`);
            assert.equal(await evaluate(`(() => { const dialog=${dialog},body=dialog.querySelector('.account-dialog-body'),header=dialog.querySelector('header'),footer=dialog.querySelector('footer');
                const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;
                return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top && dialog.scrollWidth<=dialog.clientWidth && dialog.scrollHeight<=dialog.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight; })()`),true,'Product dialogs must keep header/footer fixed.');
            await evaluate(`${dialog}.querySelector('.account-dialog-body').scrollTop=0`);
            shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`${dialog}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
            await evaluate(`${dialog}.close()`);
        }
    }
    for (const [route, category, count, column] of [
        ['materials','consumable',3,'outgoing'], ['spare-parts','spare_part',3,'unit'],
        ['tools','ccdc',2,'warehouses'], ['fixed-assets','fixed_asset',2,'decisions'], ['other-products','other',2,'tracking_mode']
    ]) {
        const render = spawnSync(php, ['tests/home.php', `--${route}`, '--render'], { encoding: 'utf8', windowsHide: true });
        assert.equal(render.status, 0, render.stderr);
        await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
        for (let i=0;i<100;i++) {
            if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
            await new Promise(resolve=>setTimeout(resolve,100));
        }
        const frame = await page('Page.getFrameTree');
        await page('Page.setDocumentContent', { frameId: frame.frameTree.frame.id, html: render.stdout });
        for (let i=0;i<100;i++) {
            if (await evaluate('document.readyState === "complete" && typeof filterProducts === "function"')) break;
            await new Promise(resolve=>setTimeout(resolve,100));
        }
        assert.equal(await evaluate('productRows.length'), count);
        assert.equal(await evaluate(`productRows.every(row=>JSON.parse(row.dataset.product).category==='${category}')`), true);
        assert.equal(await evaluate(`Boolean(document.querySelector('[data-product-quantity="${column}"], .product-cell-${column}'))`), true);
        assert.equal(await evaluate(`productForm.getAttribute('action')==='index.php?page=${route}' && productForm.elements.category.options.length===1 && productForm.elements.category.value==='${category}'`), true);
        assert.equal(await evaluate('Boolean(document.getElementById("product-export-open"))'), ['materials','spare-parts','tools','fixed-assets'].includes(route), 'Only the four stock-book categories should offer the template export.');
        if (['materials','spare-parts','tools','fixed-assets'].includes(route)) {
            await evaluate('document.getElementById("product-warehouse").value="1"; document.getElementById("product-export-open").click()');
            assert.equal(await evaluate('productExportForm.elements.warehouse.value'), '1', 'Export should preselect the currently filtered warehouse.');
            assert.equal(await evaluate('productExportForm.elements.kind.value'), route);
            assert.equal(await evaluate('productExportForm.method'), 'get');
            await evaluate('productExportForm.elements.from_month.value="2026-11";productExportForm.elements.to_month.value="2026-10";productExportForm.elements.to_month.dispatchEvent(new Event("input"))');
            assert.equal(await evaluate('productExportForm.checkValidity()'),false);
            await evaluate('productExportForm.elements.from_month.value="2026-09";productExportForm.elements.from_month.dispatchEvent(new Event("input"))');
            assert.equal(await evaluate('productExportForm.checkValidity()'),true);
            await evaluate('document.querySelector("[data-close-product-export]").click()');
        }
        for (const [width,height] of [[1440,1000],[320,568],[844,390]]) {
            await page('Emulation.setDeviceMetricsOverride', {width,height,deviceScaleFactor:1,mobile:false});
            await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'), true, `${route} page overflow.`);
            const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`${route}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
            if (['materials','spare-parts','tools','fixed-assets'].includes(route)) {
                await evaluate('document.getElementById("product-export-open").click()');
                assert.equal(await evaluate('(() => { const dialog=document.getElementById("product-export-dialog"),body=dialog.querySelector(".account-dialog-body"),header=dialog.querySelector("header"),footer=dialog.querySelector("footer"); const top=header.getBoundingClientRect().top,bottom=footer.getBoundingClientRect().top;body.scrollTop=body.scrollHeight;return top===header.getBoundingClientRect().top && bottom===footer.getBoundingClientRect().top && dialog.scrollWidth<=dialog.clientWidth && footer.getBoundingClientRect().bottom<=innerHeight; })()'),true,'Export dialog must keep headings/actions fixed and fit small screens.');
                const exportShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
                writeFileSync(join(outputDir,`${route}-export-${width}x${height}.png`),Buffer.from(exportShot.data,'base64'));
                await evaluate('document.querySelector("[data-close-product-export]").click()');
            }
        }
    }
    const importRender=spawnSync(php,['tests/home.php','--imports','--render'],{encoding:'utf8',windowsHide:true});
    assert.equal(importRender.status,0,importRender.stderr);
    await page('Page.navigate',{url:(process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/')+'?id=1'});
    for (let i=0;i<100;i++) {
        if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    const importFrame=await page('Page.getFrameTree');
    await page('Page.setDocumentContent',{frameId:importFrame.frameTree.frame.id,html:importRender.stdout});
    for (let i=0;i<100;i++) {
        if (await evaluate('document.readyState === "complete" && typeof filterImports === "function"')) break;
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    assert.equal(await evaluate('importDialog.open && document.getElementById("import-detail-number").textContent==="PN-001"'),true,'Dashboard link must open the requested import.');
    await evaluate('importDialog.close()');
    assert.equal(await evaluate('document.querySelectorAll("[data-import]:not([hidden])").length'),10);
    await page('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    await evaluate('importStatus.value="draft";filterImports()');
    const importActionSizes=await evaluate('importRows[0].querySelectorAll(".account-actions>.button").length && [...importRows[0].querySelectorAll(".account-actions>.button")].map(button=>button.getBoundingClientRect().width)');
    assert.equal(await evaluate('importRows[0].cells[2].querySelector("button").title.includes(JSON.parse(importRows[0].dataset.import).supplier_name)'),true,'Supplier hints must contain the full stored name.');
    await evaluate(`const importSavedText=[...importRows[0].querySelectorAll('.compact-cell .compact-truncate,td:nth-child(6)>.compact-cell')].map(element=>[element,element.textContent]);
        const importSavedTitles=[...importRows[0].querySelectorAll('.compact-cell')].map(element=>[element,element.title]);
        importSavedText.forEach(([element],index)=>element.textContent=index===importSavedText.length-1?'12.345.678.901.234,123456 ₫':'Nội dung rất dài để kiểm tra bảng phiếu nhập và thông tin nhà cung cấp '+ 'chi nhánh Hà Nội '.repeat(8));
        importSavedTitles.forEach(([element])=>element.title=element.textContent.trim());`);
    for(const [width,height] of [[1440,1000],[1280,800],[1024,800],[768,1024],[390,844],[320,568],[844,390]]) {
        await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('(()=>{const region=document.querySelector(".compact-table-scroll");return region.scrollWidth<=region.clientWidth+1 && document.documentElement.scrollWidth<=innerWidth;})()'),true,'The import list must fit without horizontal scrolling at '+width);
        assert.deepEqual(await evaluate('[...importRows[0].querySelectorAll(".account-actions>.button")].map(button=>button.getBoundingClientRect().width)'),importActionSizes,'Import action buttons must retain their original sizes at '+width);
        assert.equal(await evaluate('[...importRows[0].querySelectorAll(".compact-cell")].every(button=>button.title===button.textContent.trim() && button.textContent.includes("…")===false)'),true,'Long text must remain available in hints rather than being truncated in the data.');
        await evaluate('importRows[0].cells[2].querySelector("button").click()');
        assert.equal(await evaluate('importDialog.open && importEditForm.elements.id.value==="1"'),true,'Tapping truncated content must open the same receipt.');
        await evaluate('importDialog.close();document.querySelector(".compact-table-scroll").scrollIntoView({block:"start"})');
        const listShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(join(outputDir,`import-list-${width}x${height}.png`),Buffer.from(listShot.data,'base64'));
    }
    await evaluate('importSavedText.forEach(([element,text])=>element.textContent=text);importSavedTitles.forEach(([element,title])=>element.title=title);importStatus.value="";filterImports()');
    assert.equal(await evaluate(`importRows[0].querySelector('.import-state').dataset.action`),'delete','Draft receipts need a delete action.');
    assert.equal(await evaluate(`importRows[1].querySelector('.import-state').dataset.action`),'cancel','Confirmed receipts need a cancellation action.');
    assert.equal(await evaluate(`importRows[2].querySelector('.import-state')===null`),true,'Cancelled receipts must remain read-only history.');
    assert.equal(await evaluate(`importRows[3].querySelector('.import-state').disabled && importRows[3].querySelector('.import-state').title.includes('điều chuyển')`),true,'Transfer receipts must explain their blocked action.');
    await evaluate(`importRows[0].querySelector('.import-state').click()`);
    assert.equal(await evaluate(`importStateDialog.open && importStateForm.elements.action.value==='delete' && importStateForm.elements.id.value==='1' && importStateForm.elements.cancel_reason.disabled && document.getElementById('import-cancel-reason-label').hidden`),true,'Delete confirmation must target the selected draft and omit cancellation reason.');
    const deletedPreview=await evaluate(`(async()=>{const original=submitManagementForm;let captured;submitManagementForm=async (...args)=>{captured=Object.fromEntries(new FormData(args[0]));await original(...args);};try{importStateForm.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await new Promise(resolve=>setTimeout(resolve,0));return captured;}finally{submitManagementForm=original;}})()`);
    assert.equal(deletedPreview.action,'delete');assert.equal(deletedPreview.id,'1');assert.equal('cancel_reason' in deletedPreview,false);
    assert.equal(await evaluate(`!importStateDialog.open && importRows.length===12`),true,'Preview confirmation must not remove real or sample receipt records.');
    assert.equal(await evaluate(`document.querySelector('.imports-card .account-actions a[href*="import-excel"],.imports-card .account-actions [data-action="confirm"]')===null`),true,'Excel and confirmation must be available in details rather than list actions.');
    await evaluate(`openImport(JSON.parse(importRows[0].dataset.import));document.getElementById('import-confirm').click()`);
    assert.equal(await evaluate(`importStateDialog.open && importStateForm.elements.action.value==='confirm' && importStateForm.elements.cancel_reason.disabled && document.getElementById('import-state-submit').classList.contains('button-primary') && document.getElementById('import-state-warning').textContent.includes('tồn kho')`),true,'Draft confirmation must explain stock effects and omit cancellation fields.');
    const confirmedPreview=await evaluate(`(async()=>{const original=submitManagementForm;let captured;submitManagementForm=async(...args)=>{captured=Object.fromEntries(new FormData(args[0]));await original(...args);};try{importStateForm.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await new Promise(resolve=>setTimeout(resolve,0));return captured;}finally{submitManagementForm=original;}})()`);
    assert.equal(confirmedPreview.action,'confirm');assert.equal(confirmedPreview.id,'1');assert.equal('cancel_reason' in confirmedPreview,false);
    assert.equal(await evaluate(`!importStateDialog.open && JSON.parse(importRows[0].dataset.import).status==='draft'`),true,'Preview confirmation must not change real or sample stock.');
    await evaluate(`openImport(JSON.parse(importRows[0].dataset.import))`);
    assert.equal(await evaluate(`!document.getElementById('import-confirm').hidden && !document.getElementById('import-confirm').disabled`),true,'Editable draft details must offer confirmation.');
    await evaluate(`document.getElementById('import-confirm').click()`);
    assert.equal(await evaluate(`importDialog.open && importStateDialog.open && importStateForm.elements.action.value==='confirm'`),true,'The detail action must use the shared confirmation dialog.');
    await evaluate(`importStateDialog.close();importDialog.close()`);
    await evaluate(`importRows[1].querySelector('.import-state').click()`);
    assert.equal(await evaluate(`importStateForm.elements.action.value==='cancel' && importStateForm.elements.cancel_reason.required && !importStateForm.checkValidity()`),true,'Cancellation must require a reason.');
    for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]){
        await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate(`(()=>{const header=importStateDialog.querySelector('header'),footer=importStateDialog.querySelector('footer'),body=importStateDialog.querySelector('.account-dialog-body'),before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;return header.getBoundingClientRect().top===before[0] && footer.getBoundingClientRect().top===before[1] && footer.getBoundingClientRect().bottom<=innerHeight && importStateDialog.scrollWidth<=importStateDialog.clientWidth && importStateDialog.scrollHeight<=importStateDialog.clientHeight+1;})()`),true,'Receipt action dialogs must keep header/actions fixed and scroll only their bodies.');
        await evaluate(`importStateDialog.querySelector('.account-dialog-body').scrollTop=0`);
        const stateShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(join(outputDir,`import-cancel-${width}x${height}.png`),Buffer.from(stateShot.data,'base64'));
    }
    await evaluate(`importStateForm.elements.cancel_reason.value='Sai thông tin hóa đơn';`);
    const cancelledPreview=await evaluate(`(async()=>{const original=submitManagementForm;let captured;submitManagementForm=async (...args)=>{captured=Object.fromEntries(new FormData(args[0]));await original(...args);};try{importStateForm.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await new Promise(resolve=>setTimeout(resolve,0));return captured;}finally{submitManagementForm=original;}})()`);
    assert.equal(cancelledPreview.action,'cancel');assert.equal(cancelledPreview.id,'2');assert.equal(cancelledPreview.cancel_reason,'Sai thông tin hóa đơn');
    assert.equal(await evaluate(`!importStateDialog.open && JSON.parse(importRows[1].dataset.import).status==='confirmed'`),true,'Preview cancellation must not change receipt status.');
    assert.equal(await evaluate(`Boolean(document.querySelector('.navigation a[href="index.php?page=imports"].active[aria-current=page]')?.closest('details[open]'))`),true,'Import sidebar must link to the real page and expand its group.');
    await evaluate('importNext.click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-import]:not([hidden])").length'),2);
    await evaluate('importStatus.value="confirmed";filterImports()');
    assert.equal(await evaluate('document.querySelectorAll("[data-import]:not([hidden])").length'),4);
    await evaluate('importStatus.value="";importWarehouse.value="2";filterImports()');
    assert.equal(await evaluate('document.querySelectorAll("[data-import]:not([hidden])").length'),6);
    await evaluate('importWarehouse.value="";importType.value="purchase";filterImports()');
    assert.equal(await evaluate('document.querySelectorAll("[data-import]:not([hidden])").length'),3);
    await evaluate('importType.value="";importSearch.value="thiet bi";filterImports()');
    assert.equal(await evaluate('document.getElementById("import-count").textContent.endsWith("/ 12 phiếu nhập")'),true);
    await evaluate('importSearch.value="";importStart.value="2026-10-03";importEnd.value="2026-10-05";filterImports()');
    assert.equal(await evaluate('document.querySelectorAll("[data-import]:not([hidden])").length'),3);
    await evaluate('importStart.value="2026-10-06";filterImports()');
    assert.equal(await evaluate('document.getElementById("import-error").hidden'),false);
    await evaluate('document.getElementById("import-reset").click();importSort.value="oldest";filterImports()');
    assert.equal(await evaluate('JSON.parse(document.querySelector("[data-import]:not([hidden])").dataset.import).number'),'PN-001');
    await evaluate('document.querySelector("[data-import]:not([hidden]) .import-details").click()');
    assert.equal(await evaluate('document.querySelectorAll("#import-item-rows tr").length'),14);
    assert.equal(await evaluate('document.getElementById("import-excel").disabled'),true,'Sample receipts must not download real database records.');
    assert.equal(await evaluate('document.querySelector("#import-item-rows td").textContent.includes("<script>")'),true);
    assert.equal(await evaluate('document.querySelector("#import-item-rows td:nth-child(4)").textContent.includes("12.345.678.901.234,123456")'),true);
    assert.equal(await evaluate('document.querySelectorAll("#import-item-rows tr:first-child td").length'),7,'Receipt rows must include a separate image column.');
    await evaluate('document.getElementById("import-edit").click()');
    assert.equal(await evaluate('document.getElementById("import-confirm").hidden'),true,'Confirmation must be hidden while unsaved receipt edits are open.');
    assert.equal(await evaluate('document.querySelectorAll("#import-item-rows [data-field=product_name]").length'),14);
    assert.equal(await evaluate('document.querySelector("#import-item-rows [data-field=unit_price]").value'),'12345678901234.123456','Editing must use exact database values, without display separators.');
    await evaluate(`(() => { const row=document.querySelector('#import-item-rows tr'),quantity=row.querySelector('[data-field=received_quantity]'),price=row.querySelector('[data-field=unit_price]');
        price.value='2.000001';quantity.value='3.123456';quantity.dispatchEvent(new Event('input',{bubbles:true})); })()`);
    assert.equal(await evaluate('document.querySelector("#import-item-rows [data-field=amount_before_tax]").value'),'6.246915');
    assert.equal(await evaluate('document.querySelector("#import-item-rows [data-field=tax_amount]").value'),'0.624691','Receipt editing must use six-decimal arithmetic.');
    const editPayload=await evaluate(`(async () => { const original=submitManagementForm;let captured;submitManagementForm=async form=>{captured=JSON.parse(new FormData(form).get('items'));};
        try { importEditForm.dispatchEvent(new Event('submit',{cancelable:true,bubbles:true}));await new Promise(resolve=>setTimeout(resolve,0));return captured; }finally{submitManagementForm=original;} })()`);
    assert.equal(editPayload.length,14);assert.equal(editPayload[0].id,'1');assert.equal(editPayload[0].received_quantity,'3.123456');assert.equal(editPayload[0].decision,'pending');
    await evaluate('importEditDirty=false;document.getElementById("import-edit-cancel").click()');
    assert.equal(await evaluate('document.querySelector("#import-item-rows td:nth-child(4)").textContent.includes("12.345.678.901.234,123456")'),true,'Cancel must restore the original snapshot.');
    await evaluate(`importDialog.close();const serialBill=JSON.parse(importRows[0].dataset.import);Object.assign(serialBill.items[0],{category:'fixed_asset',received_quantity:'2.000000',stock_locked:1,serial_numbers:['','SN-002']});openImport(serialBill)`);
    assert.equal(await evaluate(`document.querySelector('.import-serial-list').textContent.includes('Chiếc 1: Chưa có serial') && document.querySelector('.import-serial-list').textContent.includes('Chiếc 2: SN-002')`),true,'Missing serials must retain their piece position.');
    await evaluate('document.getElementById("import-edit").click()');
    assert.equal(await evaluate(`(()=>{const row=document.querySelector('#import-item-rows tr'),serial=row.querySelector('[data-field=serial_numbers]');return serial.tagName==='TEXTAREA' && serial.value==='\\nSN-002' && !serial.readOnly && row.querySelector('[data-field=product_name]').readOnly;})()`),true,'Serial metadata must remain editable for locked lots.');
    assert.equal(await evaluate(`importEditItems()[0].serial_numbers`),'\nSN-002','The edit payload must preserve blank leading serial slots.');
    await evaluate('importEditDirty=false;importDialog.close();openImport(JSON.parse(importRows[0].dataset.import))');
    let previewPdf='%PDF-1.4\n';const pdfOffsets=[0];
    const red='1 0 0 rg 0 0 200 200 re f\n',green='0 1 0 rg 0 0 200 200 re f\n';
    for(const object of ['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
        `<< /Length ${red.length} >>\nstream\n${red}endstream`,'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 6 0 R >>',`<< /Length ${green.length} >>\nstream\n${green}endstream`]) {
        pdfOffsets.push(Buffer.byteLength(previewPdf));previewPdf+=`${pdfOffsets.length-1} 0 obj\n${object}\nendobj\n`;
    }
    const xref=Buffer.byteLength(previewPdf);previewPdf+=`xref\n0 ${pdfOffsets.length}\n0000000000 65535 f \n`+pdfOffsets.slice(1).map(offset=>String(offset).padStart(10,'0')+' 00000 n \n').join('')+`trailer\n<< /Size ${pdfOffsets.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
    const previewBytes=process.env.RECEIPT_PDF_PATH?readFileSync(process.env.RECEIPT_PDF_PATH):Buffer.from(previewPdf),downloadsBeforePdf=downloads.length;
    let pdfPreviewFails=false;const pdfPreviewRequests=[];
    const receiptPreviewHandler=async event=>{
        const message=JSON.parse(event.data);if(message.method!=='Fetch.requestPaused')return;
        pdfPreviewRequests.push(message.params);
        const response=pdfPreviewFails?{ok:false,message:'Không tải được PDF.'}:{ok:true,name:'Hóa đơn PDF kiểm tra.pdf',data:previewBytes.toString('base64')};
        await page('Fetch.fulfillRequest',{requestId:message.params.requestId,responseCode:pdfPreviewFails?503:200,responseHeaders:[
            {name:'Content-Type',value:'application/json; charset=utf-8'}],body:Buffer.from(JSON.stringify(response)).toString('base64')});
    };
    socket.addEventListener('message',receiptPreviewHandler);
    await page('Fetch.enable',{patterns:[{urlPattern:'*index.php?page=import-file&id=preview-fixture*'}]});
    await evaluate(`importDialog.close();const bill=JSON.parse(importRows[0].dataset.import);bill.attachments=[{id:'preview-fixture',original_name:'Hóa đơn PDF kiểm tra.pdf'}];openImport(bill);importImageIndex=2;document.querySelector('#import-attachments button').click()`);
    for(let attempt=0;attempt<150;attempt++){
        if(await evaluate(`document.getElementById('import-pdf-frame').contentWindow.PDFViewerApplication?.pdfViewer?.getPageView(0)?.renderingState===3`))break;
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    assert.equal(await evaluate('importImageDialog.open && !document.getElementById("import-pdf-frame").hidden && importImageFull.hidden'),true,'PDF must open inside the web dialog.');
    assert.equal(await evaluate(`importImageIndex===0 && importImageDialog.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true,cancelable:true}))`),true,'PDF viewing must reset photo navigation and preserve native arrow keys.');
    const pdfShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(join(outputDir,'import-pdf-viewer.png'),Buffer.from(pdfShot.data,'base64'));
    const pdfPixels=await evaluate(`(() => {const canvas=document.getElementById('import-pdf-frame').contentDocument?.querySelector('.page[data-page-number="1"][data-loaded="true"] canvas');
        if(!canvas)return null;const pixels=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height).data;let painted=0;for(let i=0;i<pixels.length;i+=4)if(pixels[i+3]>0 && (pixels[i]<240 || pixels[i+1]<240 || pixels[i+2]<240))painted++;return painted;})()`);
    assert.ok(pdfPixels>500,'PDF.js must paint PDF content, not merely load a PDF MIME response.');
    assert.equal(await evaluate(`document.getElementById('import-pdf-frame').contentWindow.PDFViewerApplicationOptions.get('enableScripting')`),false,'PDF scripting must remain disabled.');
    assert.equal(await evaluate(`getComputedStyle(document.getElementById('import-pdf-frame').contentDocument.getElementById('secondaryOpenFile')).display`),'none','Linked receipt viewer must not ask the user to choose an unrelated PDF.');
    assert.equal(await evaluate(`document.getElementById('import-pdf-frame').contentDocument.getElementById('fileInput').disabled`),true,'The linked viewer must disable the external file picker.');
    assert.equal(pdfPreviewRequests.length,1,'The linked PDF must be loaded once, as data.');
    assert.ok(['Fetch','XHR'].includes(pdfPreviewRequests[0].resourceType),'Preview must use a data request, not navigate the browser to a PDF file URL.');
    assert.equal(pdfPreviewRequests[0].request.headers.Accept,'application/json');
    assert.equal(await evaluate(`new URL(document.getElementById('import-pdf-frame').src).searchParams.get('file')`),'','PDF.js must receive bytes instead of fetching a PDF URL that IDM can capture.');
    if(!process.env.RECEIPT_PDF_PATH){
        assert.equal(await evaluate(`document.getElementById('import-pdf-frame').contentWindow.PDFViewerApplication.pagesCount`),2);
        await evaluate(`document.getElementById('import-pdf-frame').contentWindow.PDFViewerApplication.page=2`);
        for(let attempt=0;attempt<100;attempt++){if(await evaluate(`document.getElementById('import-pdf-frame').contentWindow.PDFViewerApplication.pdfViewer.getPageView(1).renderingState===3`))break;await new Promise(resolve=>setTimeout(resolve,100));}
        assert.equal(await evaluate(`(() => {const canvas=document.getElementById('import-pdf-frame').contentDocument.querySelector('.page[data-page-number="2"] canvas'),pixel=canvas.getContext('2d').getImageData(Math.floor(canvas.width/2),Math.floor(canvas.height/2),1,1).data;return pixel[1]>230 && pixel[0]<30 && pixel[2]<30;})()`),true,'PDF page navigation must render the actual second page.');
    }
    assert.equal(downloads.length,downloadsBeforePdf,'Viewing PDF must never start a download, even with the browser PDF plugin disabled.');
    assert.equal(await evaluate(`(() => { const body=importImageDialog.querySelector('.account-dialog-body'),footer=importImageDialog.querySelector('footer');return body.scrollHeight<=body.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight; })()`),true,'PDF should fit the dialog body while its actions stay visible.');
    assert.equal(await evaluate('document.getElementById("import-file-download").getAttribute("href")'),'index.php?page=import-file&id=preview-fixture');
    pdfPreviewFails=true;
    await evaluate(`importImageDialog.close();showImportPdf('index.php?page=import-file&id=preview-fixture','Hóa đơn PDF kiểm tra.pdf')`);
    for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!document.getElementById('import-image-error').hidden`))break;await new Promise(resolve=>setTimeout(resolve,100));}
    assert.equal(await evaluate(`!document.getElementById('import-image-error').hidden && document.getElementById('import-pdf-frame').hidden && document.getElementById('import-pdf-loading').hidden`),true,'Failed PDF loading must show an error, not a blank viewer or file picker.');
    await evaluate('importImageDialog.close();importDialog.close()');
    await page('Fetch.disable');socket.removeEventListener('message',receiptPreviewHandler);
    for (const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]) {
        await evaluate('importDialog.close()');
        await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Import page overflow.');
        let shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`imports-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        await evaluate('document.querySelector("[data-import]:not([hidden]) .import-details").click()');
        assert.equal(await evaluate(`(() => { const body=importDialog.querySelector('.account-dialog-body'),header=importDialog.querySelector('header'),footer=importDialog.querySelector('footer');
            const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;
            return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top && importDialog.scrollWidth<=importDialog.clientWidth && importDialog.scrollHeight<=importDialog.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight; })()`),true,'Import detail must keep header/footer fixed.');
        await evaluate('importDialog.querySelector(".account-dialog-body").scrollTop=0');
        shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`import-dialog-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
    }
    const createRender=spawnSync(php,['tests/home.php','--import-create','--render'],{encoding:'utf8',windowsHide:true});
    assert.equal(createRender.status,0,createRender.stderr);
    await page('Page.navigate',{url:process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/'});
    for(let i=0;i<100;i++) {
        if(await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))'))break;
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    const createFrame=await page('Page.getFrameTree');
    await page('Page.setDocumentContent',{frameId:createFrame.frameTree.frame.id,html:createRender.stdout});
    for(let i=0;i<100;i++) {
        if(await evaluate('document.readyState === "complete" && typeof addImportLine === "function"'))break;
        await new Promise(resolve=>setTimeout(resolve,100));
    }
    assert.equal(await evaluate('importLines.children.length'),1);
    const photoPng=readFileSync('public/assets/images/company-logo.png').toString('base64');
    await evaluate(`(()=>{const picker=importLines.firstElementChild.querySelector('[data-import-images]'),files=new DataTransfer(),bytes=Uint8Array.from(atob(${JSON.stringify(photoPng)}),char=>char.charCodeAt(0));files.items.add(new File([bytes],'first.png',{type:'image/png'}));files.items.add(new File([bytes],'second.png',{type:'image/png'}));picker.files=files.files;picker.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    assert.equal(await evaluate('importLines.querySelectorAll(".import-image-preview").length'),2,'Multiple optional photos must have previews.');
    await evaluate('Promise.all([...importLines.querySelectorAll(".import-image-previews img")].map(image=>image.decode()))');
    await evaluate('importLines.querySelector(".import-image-preview>button").click()');
    assert.equal(await evaluate('importImageDialog.open'),true,'Photo previews must enlarge in a native dialog.');
    await evaluate('document.getElementById("import-image-next").click()');
    assert.equal(await evaluate('document.getElementById("import-image-caption").textContent.startsWith("2 / 2")'),true,'Photo viewer must navigate between selected photos.');
    await evaluate('importImageFull.decode()');
    await evaluate('importImageDialog.close()');
    await evaluate('importLines.querySelector(".import-image-remove").click()');
    assert.equal(await evaluate('importLines.querySelectorAll(".import-image-preview").length'),1,'A photo can be removed without deleting its receipt row.');
    await evaluate(`(async()=>{const first=importLines.firstElementChild,second=addImportLine(),files=new DataTransfer(),bytes=Uint8Array.from(atob(${JSON.stringify(photoPng)}),char=>char.charCodeAt(0));files.items.add(new File([bytes],'second-row.png',{type:'image/png'}));const picker=second.querySelector('[data-import-images]');picker.files=files.files;picker.dispatchEvent(new Event('change',{bubbles:true}));await Promise.all([...second.querySelectorAll('.import-image-previews img')].map(image=>image.decode()));first.querySelector('.remove-import-line').click();let payload;const original=submitManagementForm;submitManagementForm=(form,dialog,notice,data)=>{payload=data;};try{importCreateForm.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));if(payload.getAll('images[0][]').length!==1 || payload.has('images[1][]') || payload.get('image_count')!=='1' || payload.get('form_complete')!=='1')throw new Error('Removed rows must reindex photo uploads to the final receipt line order.');}finally{submitManagementForm=original;clearImportPhotos(second);second.remove();addImportLine();}})()`);
    assert.equal(await evaluate('importCreateForm.elements.warehouse.options.length'),2,'Inactive warehouse must be excluded.');
    await evaluate('importCreateForm.elements.warehouse.value="1";importCreateForm.elements.warehouse.dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.querySelector("[data-print-value=department_address]").value'), 'Địa chỉ bộ phận thử');
    assert.equal(await evaluate('document.querySelector("[data-print-value=location]").value'), 'Địa chỉ kho 1', 'Import preview must use the warehouse address, ignoring legacy location settings.');
    assert.equal(await evaluate('document.querySelector("[data-print-signer=keeper_employee_id]").value'), '1');
    assert.equal(await evaluate('importCreateForm.elements.print_version.value'), 'b'.repeat(64));
    await evaluate(`for(const [key,value] of Object.entries({name:'New product',unit:'Piece',quantity:'1.123456',unit_price:'2.5',tax_code:'10%'}))importLines.firstElementChild.querySelector('[data-field='+key+']').value=value; updateImportTotals()`);
    assert.equal(await evaluate('document.getElementById("import-total-after").textContent'),'3,089504 ₫','Preview totals must use exact decimals.');
    await evaluate('const selectedImportLine=importLines.firstElementChild;selectedImportLine.querySelector("[data-field=product_id]").value="1";configureImportLine(selectedImportLine,true)');
    assert.equal(await evaluate('importLines.firstElementChild.querySelector("[data-field=name]").readOnly'),true);
    assert.equal(await evaluate('importLines.firstElementChild.querySelector("[data-field=category]").disabled'),false);
    await evaluate('supplierSelect.value="1";configureImportSupplier()');
    assert.equal(await evaluate('importCreateForm.elements.tax_code.readOnly'),true);
    await evaluate(`applyImportInvoice({invoice_number:'HD-TEST',invoice_series:'C26T',invoice_date:'2026-10-10',supplier_name:'Seller',tax_code:'1234567890',items:[
        {product_id:'',code:'HH-001',name:'Invoice item',unit:'Chiếc',category:'other',tracking_mode:'quantity',quantity:'2',unit_price:'2',tax_code:'8%',tax_amount:'',note:''},
        {product_id:'',code:'XML-NEW',name:'Service <script>',unit:'Lần',category:'other',tracking_mode:'none',quantity:'',unit_price:'10',tax_code:'KCT',tax_amount:'',note:''}]})`);
    assert.equal(await evaluate('importLines.children.length'),2);
    assert.equal(await evaluate('importLines.firstElementChild.querySelector("[data-field=product_id]").value'),'1','XML must match an existing product code.');
    assert.equal(await evaluate(`(()=>{const row=importLines.firstElementChild;return !row.querySelector('[data-field=category]').disabled && row.querySelector('[data-field=category]').value==='spare_part' && !row.querySelector('[data-product-note]').hidden && row.querySelector('[data-product-note]').textContent.includes('Khi lưu phiếu');})()`),true,'XML matching existing goods must allow reclassification and explain its catalogue-wide effect.');
    assert.equal(await evaluate(`(()=>{const row=importLines.firstElementChild;row.querySelector('[data-field=category]').value='fixed_asset';return importLineData(row).category==='fixed_asset' && importLineData(row).product_id==='1' && row.querySelector('[data-field=tracking_mode]').disabled;})()`),true,'Changing category must keep the linked product identity and stock tracking mode.');
    assert.equal(await evaluate(`(()=>{const row=importLines.lastElementChild,category=row.querySelector('[data-field=category]');category.value='ccdc';category.dispatchEvent(new Event('change',{bubbles:true}));return !category.disabled && importLineData(row).category==='ccdc' && row.querySelector('[data-product-note]').hidden;})()`),true,'New XML goods must allow category selection and serialize that selection.');
    await evaluate(`(()=>{const row=importLines.firstElementChild;row.querySelector('[data-field=product_id]').value='';configureImportLine(row,true);})()`);
    assert.equal(await evaluate(`!importLines.firstElementChild.querySelector('[data-field=category]').disabled && importLines.firstElementChild.querySelector('[data-product-note]').hidden`),true,'Choosing new goods must unlock the category and hide the catalogue explanation.');
    await evaluate(`(()=>{const row=importLines.firstElementChild;row.querySelector('[data-field=product_id]').value='1';configureImportLine(row,true);})()`);
    assert.equal(await evaluate('importLines.lastElementChild.querySelector("[data-field=quantity]").disabled'),true);
    assert.equal(await evaluate('document.getElementById("import-total-after").textContent'),'14,32 ₫');
    assert.equal(await evaluate('importCreateForm.elements.supplier_id.value'),'1','XML must match supplier tax code.');
    await evaluate('importCreateForm.elements.warehouse.value="1";importCreateForm.requestSubmit()');
    assert.equal(await evaluate('document.getElementById("import-create-notice").hidden'),false,'Preview must not save business data.');
    await evaluate('importLines.lastElementChild.querySelector(".remove-import-line").click()');
    assert.equal(await evaluate('importLines.children.length'),1);
    await evaluate(`applyImportInvoice({invoice_number:'00000548',invoice_series:'C26TNA',invoice_date:'2026-09-29',supplier_name:'Test supplier',invoice_before_tax:'2212963',invoice_tax:'177037',invoice_after_tax:'2390000',items:[{product_id:'',code:'',name:'Test kettle',unit:'Cái',category:'other',tracking_mode:'quantity',quantity:'1',unit_price:'2212962.96',amount_before_tax:'2212963',tax_code:'8%',tax_amount:'177037',note:''}]})`);
    assert.equal(await evaluate('importCreateForm.elements.invoice_tax.value'),'177037');
    assert.equal(await evaluate('importCreateForm.elements.invoice_number.value'),'00000548');
    assert.equal(await evaluate('document.getElementById("import-total-after").textContent'),'2.390.000 ₫','Explicit XML line/tax values must preserve the invoice rounding.');
    for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]) {
        await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Import create page overflow.');
        let shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`import-create-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        await evaluate('xmlReplaceDialog.showModal()');
        assert.equal(await evaluate('xmlReplaceDialog.scrollWidth<=xmlReplaceDialog.clientWidth && xmlReplaceDialog.scrollHeight<=xmlReplaceDialog.clientHeight+1 && xmlReplaceDialog.querySelector("footer").getBoundingClientRect().bottom<=innerHeight'),true,'XML confirmation must fit small screens.');
        shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`import-xml-dialog-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        await evaluate('xmlReplaceDialog.close()');
    }
    // Exercise the actual file-change handler, including a form control named action.
    // Stub only the transport so the preview fixture never sends business requests.
    const uploadCheck=await evaluate(`(async()=>{
        const previousFetch=window.fetch;
        const invoice={invoice_number:'00000548',invoice_series:'C26TNA',invoice_date:'2026-09-29',supplier_name:'Test supplier',invoice_before_tax:'2212963',invoice_tax:'177037',invoice_after_tax:'2390000',items:[{product_id:'',code:'',name:'Test kettle',unit:'Cái',category:'other',tracking_mode:'quantity',quantity:'1',unit_price:'2212962.96',amount_before_tax:'2212963',tax_code:'8%',tax_amount:'177037',note:''}]};
        let request;
        window.fetch=async(url,options)=>{
            request={url:String(url),method:options.method,action:options.body.get('action'),file:options.body.get('xml')?.name,csrf:options.body.get('csrf_token')};
            return new Response(JSON.stringify({ok:true,invoice}),{status:200,headers:{'Content-Type':'application/json'}});
        };
        try {
            importCreateForm.dataset.preview='false';
            const files=new DataTransfer();files.items.add(new File(['<TDiep/>'],'invoice.xml',{type:'application/xml'}));xmlInput.files=files.files;
            xmlInput.dispatchEvent(new Event('change',{bubbles:true}));
            for(let i=0;i<100 && xmlLoading;i++)await new Promise(resolve=>setTimeout(resolve,10));
            const dialogOpened=xmlReplaceDialog.open;
            if(dialogOpened){xmlReplaceDialog.returnValue='replace';xmlReplaceDialog.close();await new Promise(resolve=>setTimeout(resolve,20));}
            return {...request,expectedUrl:new URL(importCreateForm.getAttribute('action'),document.baseURI).href,dialogOpened,
                errorHidden:importCreateForm.querySelector('[data-form-error]').hidden,invoiceNumber:importCreateForm.elements.invoice_number.value,
                total:document.getElementById('import-total-after').textContent,inputEnabled:!xmlInput.disabled};
        } finally {window.fetch=previousFetch;importCreateForm.dataset.preview='true';}
    })()`);
    assert.equal(uploadCheck.url,uploadCheck.expectedUrl,'XML upload must use the form action attribute even when an input is named action.');
    assert.equal(uploadCheck.action,'xml');
    assert.equal(uploadCheck.method,'POST');
    assert.equal(uploadCheck.file,'invoice.xml');
    assert.equal(uploadCheck.csrf.length,64);
    assert.equal(uploadCheck.dialogOpened,true);
    assert.equal(uploadCheck.errorHidden,true);
    assert.equal(uploadCheck.inputEnabled,true);
    assert.equal(uploadCheck.invoiceNumber,'00000548');
    assert.equal(uploadCheck.total,'2.390.000 ₫');
    for (const route of ['empty-dashboard','exports','export-create']) {
        const render=spawnSync(php,['tests/home.php',route==='empty-dashboard'?'--empty':'--'+route,'--render'],{encoding:'utf8',windowsHide:true});
        assert.equal(render.status,0,render.stderr);
        await page('Page.navigate',{url:(process.env.LOGIN_URL||'http://localhost/qlvt-remake/public/')+(route==='exports'?'?id=1':'')});
        for(let i=0;i<100;i++) {
            if(await evaluate('document.readyState==="complete" && Boolean(document.getElementById("login-form"))'))break;
            await new Promise(resolve=>setTimeout(resolve,100));
        }
        const frame=await page('Page.getFrameTree');
        await page('Page.setDocumentContent',{frameId:frame.frameTree.frame.id,html:render.stdout});
        for(let i=0;i<100;i++) {
            if(await evaluate(`document.readyState==="complete" && typeof ${route==='empty-dashboard'?'filterReceipts':route==='exports'?'filterExports':'updateExportTotals'}==="function"`))break;
            await new Promise(resolve=>setTimeout(resolve,100));
        }
        if(route==='empty-dashboard') {
            assert.equal(await evaluate('[...document.querySelectorAll("[data-dashboard-metric]")].every(value=>value.textContent==="0")'),true);
            assert.equal(await evaluate('rows.length===0 && !document.querySelector(".empty-state").hidden'),true,'Empty database must not show sample receipts.');
            assert.equal(await evaluate('[...document.querySelectorAll(".category-ring [stroke-dasharray]")].every(circle=>circle.getAttribute("stroke-dasharray")==="0.0000 100")'),true,'Empty catalogue must not show invented category shares.');
        } else if(route==='exports') {
            assert.equal(await evaluate('exportDialog.open && document.getElementById("export-detail-number").textContent==="PX-001"'),true,'Dashboard link must open the requested export.');
            assert.equal(await evaluate('document.getElementById("export-confirm").hidden'),false,'Editable draft exports must offer confirmation in details.');
            await evaluate('document.getElementById("export-confirm").click()');
            assert.equal(await evaluate('exportConfirmDialog.open && exportConfirmForm.elements.action.value==="confirm" && exportConfirmForm.elements.id.value==="1" && exportConfirmForm.elements.version.value==="a".repeat(64)'),true,'Export confirmation must target the selected receipt/version.');
            await evaluate('exportConfirmForm.requestSubmit()');
            for(let i=0;i<20 && await evaluate('exportConfirmSaving');i++)await new Promise(resolve=>setTimeout(resolve,20));
            assert.equal(await evaluate('!exportConfirmDialog.open && currentExport.status==="draft"'),true,'Preview confirmation must not change real or sample stock.');
            await evaluate('openExport({...currentExport,can_confirm:false})');
            assert.equal(await evaluate('document.getElementById("export-confirm").hidden'),true,'Viewer exports must not offer confirmation.');
            await evaluate('openExport({...currentExport,can_confirm:true,status:"confirmed"})');
            assert.equal(await evaluate('document.getElementById("export-confirm").hidden'),true,'Confirmed exports must not offer repeated confirmation.');
            await evaluate('exportDialog.close()');
            assert.equal(await evaluate('document.querySelectorAll("[data-export]:not([hidden])").length'),10);
            await evaluate('exportNext.click()');
            assert.equal(await evaluate('document.querySelectorAll("[data-export]:not([hidden])").length'),2);
            await evaluate('exportStatus.value="confirmed";filterExports()');
            assert.equal(await evaluate('document.querySelectorAll("[data-export]:not([hidden])").length'),4);
            await evaluate('document.getElementById("export-reset").click();exportWarehouse.value="1";filterExports()');
            assert.equal(await evaluate('document.querySelectorAll("[data-export]:not([hidden])").length'),6);
            await evaluate('document.getElementById("export-reset").click();exportType.value="transfer";filterExports()');
            assert.equal(await evaluate('document.querySelectorAll("[data-export]:not([hidden])").length'),3);
            await evaluate('document.querySelector("[data-export]:not([hidden]) .export-details").click()');
            assert.equal(await evaluate('document.getElementById("export-transfers-section").hidden'),false);
            await evaluate('exportDialog.close();document.getElementById("export-reset").click();exportSearch.value="nguoi nhan <script> 1";filterExports()');
            assert.equal(await evaluate('document.querySelectorAll("[data-export]:not([hidden])").length'),4);
            await evaluate('document.getElementById("export-reset").click();exportStart.value="2026-10-12";exportEnd.value="2026-10-01";filterExports()');
            assert.equal(await evaluate('document.getElementById("export-error").hidden'),false);
            await evaluate('document.getElementById("export-reset").click();openExport(JSON.parse(exportRows.find(row=>JSON.parse(row.dataset.export).id==="1").dataset.export))');
            assert.equal(await evaluate('document.querySelectorAll("#export-item-rows tr").length'),14);
            assert.equal(await evaluate('document.getElementById("export-excel").disabled'),true,'Sample receipts must not download real database records.');
            assert.equal(await evaluate('document.getElementById("export-detail-receiver_name").textContent'),'Người nhận <script> 1');
            assert.equal(await evaluate('document.querySelector("#export-attachments a").getAttribute("href")'),'index.php?page=export-file&id=1');
            assert.equal(await evaluate('exportDialog.querySelectorAll("script").length'),0);
        } else {
            assert.equal(await evaluate('[...exportCreateForm.elements.warehouse.options].some(o=>o.value==="3")'),false);
            await evaluate('exportCreateForm.elements.warehouse.value="1";exportCreateForm.elements.warehouse.dispatchEvent(new Event("change"))');
            assert.equal(await evaluate('document.querySelector("[data-print-value=unit_name]").value'), 'Đơn vị thử');
            assert.equal(await evaluate('document.querySelector("[data-print-value=location]").value'), 'Địa chỉ kho 1', 'Export preview must use the warehouse address.');
            assert.equal(await evaluate('document.querySelector("[data-print-signer=keeper_employee_id]").value'), '1');
            await evaluate('document.querySelector("[data-print-signer=keeper_employee_id]").value="";exportCreateForm.elements.warehouse.value="2";exportCreateForm.elements.warehouse.dispatchEvent(new Event("change"))');
            assert.equal(await evaluate('document.querySelector("[data-print-value=department_address]").value'), 'Địa chỉ kho 2');
            assert.equal(await evaluate('document.querySelector("[data-print-value=location]").value'), 'Địa chỉ kho 2', 'Changing the warehouse must update its address in the print preview.');
            assert.equal(await evaluate('document.querySelector("[data-print-signer=keeper_employee_id]").value'), '');
            await evaluate('exportCreateForm.elements.warehouse.value="1";exportCreateForm.elements.issued_date.value="2026-10-10";updateExportOptions()');
            assert.equal(await evaluate('exportLines.firstElementChild.querySelector("select").options.length'),2,'Only dated lots at the selected warehouse can be selected.');
            await evaluate('exportLines.firstElementChild.querySelector("select").value="1";showExportSource(exportLines.firstElementChild);exportLines.firstElementChild.querySelector("[data-field=quantity]").value="1";updateExportTotals()');
            assert.equal(await evaluate('document.getElementById("export-total").textContent'),'3,333333 ₫');
            await evaluate('addExportLine();exportLines.lastElementChild.querySelector("select").value="1";showExportSource(exportLines.lastElementChild);exportLines.lastElementChild.querySelector("[data-field=quantity]").value="2";updateExportTotals()');
            assert.equal(await evaluate('document.getElementById("export-total").textContent'),'10 ₫','Final line must allocate invoice rounding exactly.');
            assert.equal(await evaluate('exportLines.lastElementChild.querySelector("[data-line-total]").textContent.includes("6,666667")'),true);
            await evaluate('exportLines.lastElementChild.querySelector("[data-field=quantity]").value="3";updateExportTotals()');
            assert.equal(await evaluate('document.getElementById("export-stock-state").textContent'),'Cần kiểm tra');
            await evaluate('exportCreateForm.elements.warehouse.value="2";updateExportOptions()');
            assert.equal(await evaluate('[...exportLines.children].every(row=>!row.querySelector("select").value)'),true,'Changing warehouse must clear source lots.');
            await evaluate('exportCreateForm.elements.warehouse.value="1";exportCreateForm.elements.issue_type.value="transfer";updateExportOptions()');
            assert.equal(await evaluate('exportCreateForm.elements.destination_warehouse.required && !exportCreateForm.elements.destination_warehouse.disabled && exportCreateForm.elements.destination_warehouse.querySelector("option[value=\\"1\\"]").disabled'),true);
            await evaluate('exportCreateForm.elements.destination_warehouse.value="2";exportCreateForm.elements.receiver_name.value="Người nhận kiểm thử";exportCreateForm.elements.reason.value="Điều chuyển kiểm thử";exportLines.firstElementChild.querySelector("select").value="1";showExportSource(exportLines.firstElementChild);exportLines.lastElementChild.querySelector(".remove-export-line").click();updateExportTotals();exportCreateForm.requestSubmit()');
            assert.equal(await evaluate('document.getElementById("export-create-notice").hidden'),false,'Export preview must not write business data.');
            assert.equal(await evaluate('JSON.parse(exportCreateForm.elements.items.value)[0].source_import_detail_id'),'1');
        }
        for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]) {
            await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
            await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,route+' outer overflow at '+width);
            if(route==='exports') {
                assert.equal(await evaluate(`(()=>{const body=exportDialog.querySelector('.account-dialog-body'),header=exportDialog.querySelector('header'),footer=exportDialog.querySelector('footer');const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top && exportDialog.scrollWidth<=exportDialog.clientWidth && exportDialog.scrollHeight<=exportDialog.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight;})()`),true,'Export detail heading/footer must remain fixed at '+width);
            }
            const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`${route}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        }
    }
    for (const [label, options] of [['admin',[]],['viewer',['--warehouse-label']],['unlinked',['--unlinked-profile']]]) {
        const personalRender=spawnSync(php,['tests/home.php','--render',...options],{encoding:'utf8',windowsHide:true});
        assert.equal(personalRender.status,0,personalRender.stderr);
        await page('Page.navigate',{url:process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/'});
        for(let i=0;i<100;i++) {
            if(await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
            await new Promise(resolve=>setTimeout(resolve,100));
        }
        const frame=await page('Page.getFrameTree');
        await page('Page.setDocumentContent',{frameId:frame.frameTree.frame.id,html:personalRender.stdout});
        for(let i=0;i<100;i++) {
            if(await evaluate('document.readyState === "complete" && typeof openPersonalDialog === "function"')) break;
            await new Promise(resolve=>setTimeout(resolve,100));
        }
        for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]) {
            await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
            await evaluate('scrollTo(0,0);document.getElementById("user-menu-toggle").focus();document.getElementById("user-menu-toggle").click()');
            assert.equal(await evaluate('personalMenu.matches(":popover-open")'),true,'Avatar must open the account menu.');
            assert.equal(await evaluate('personalMenu.getBoundingClientRect().right<=innerWidth && personalMenu.getBoundingClientRect().bottom<=innerHeight'),true,'Account menu must fit the viewport.');
            const menuShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`personal-menu-${label}-${width}x${height}.png`),Buffer.from(menuShot.data,'base64'));
            await evaluate('personalMenu.querySelector("[data-open-profile=profile]").click()');
            assert.equal(await evaluate('profileDialog.open && !personalMenu.matches(":popover-open")'),true);
            assert.equal(await evaluate('profileForm.elements.full_name.value'), 'Quản trị viên <script>');
            assert.equal(await evaluate('profileForm.querySelectorAll("[readonly]").length'),3,'Account identifiers and warehouse must stay read-only.');
            assert.equal(await evaluate('profileForm.querySelector("[type=submit]").disabled'),label==='unlinked');
            assert.equal(await evaluate('profileForm.querySelector("[data-profile-unlinked]").hidden'),label!=='unlinked');
            assert.equal(await evaluate('profileForm.querySelector("[data-change-avatar]").disabled'),false,'All roles, including unlinked accounts, must be able to change their avatar.');
            assert.equal(await evaluate('profileDialog.getBoundingClientRect().width<=600'),true,'Personal popup must use its compact width.');
            const fixedDialog=`(() => {
                const dialog=document.querySelector('dialog[open]'),body=dialog.querySelector('.account-dialog-body'),header=dialog.querySelector('header'),footer=dialog.querySelector('footer');
                const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;
                return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top
                    && dialog.scrollWidth<=dialog.clientWidth && dialog.scrollHeight<=dialog.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight;
            })()`;
            assert.equal(await evaluate(fixedDialog),true,'Personal dialog header/footer must remain fixed; only body may scroll.');
            await evaluate('profileDialog.querySelector(".account-dialog-body").scrollTop=0');
            const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`personal-profile-${label}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
            await evaluate('profileForm.querySelector("[data-close-personal]").click();openPersonalDialog("password")');
            assert.equal(await evaluate('passwordDialog.open'),true);
            assert.equal(await evaluate("getComputedStyle(passwordForm.querySelector('.personal-password-grid')).gridTemplateColumns.split(' ').length"),1,'Password fields must stack in one column.');
            await evaluate('passwordForm.elements.current_password.value="Current-example";passwordForm.elements.password.value="New-password-example";passwordForm.elements.password_confirmation.value="different-password";passwordForm.requestSubmit()');
            assert.equal(await evaluate('passwordForm.elements.password_confirmation.validity.customError'),true,'Password confirmation must be checked.');
            await evaluate('passwordForm.querySelector("[data-personal-password-toggle]").click()');
            assert.equal(await evaluate('passwordForm.elements.current_password.type'),'text','Password visibility must toggle.');
            assert.equal(await evaluate(fixedDialog),true,'Password dialog must preserve fixed header/footer.');
            await evaluate('passwordDialog.querySelector(".account-dialog-body").scrollTop=0');
            const passwordShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`personal-password-${label}-${width}x${height}.png`),Buffer.from(passwordShot.data,'base64'));
            await page('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            await page('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            await evaluate('new Promise(resolve=>setTimeout(resolve,30))');
            assert.equal(await evaluate('passwordDialog.open'),false,'Escape must close the dialog.');
            assert.equal(await evaluate('[...passwordForm.querySelectorAll("input[autocomplete$=password]")].every(input=>input.value==="" && input.type==="password")'),true,'Closing must clear all passwords and reset visibility.');
            await evaluate('openPersonalDialog("password");passwordForm.elements.current_password.value="Current-example";passwordForm.elements.password.value="New-password-example";passwordForm.elements.password_confirmation.value="New-password-example";passwordForm.requestSubmit()');
            assert.equal(await evaluate('passwordDialog.open'),false,'Valid preview must close without writing data.');
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Personal controls must not cause horizontal overflow.');
        }
        if(label==='admin') {
            await evaluate(`const personalNativeFetch=window.fetch; let personalMockState=JSON.parse(document.getElementById('own-profile-preview').textContent); let personalMockFailure=false; const personalPayloads=[];
                window.fetch=async(url,options={})=>{
                    if(options.method==='POST') personalPayloads.push(Object.fromEntries(options.body.entries()));
                    if(personalMockFailure) return new Response(JSON.stringify({ok:false,message:'Phiên đăng nhập đã hết hạn.'}),{status:401,headers:{'Content-Type':'application/json'}});
                    if(options.method==='POST' && options.body.get('action')==='profile') personalMockState={...personalMockState,full_name:options.body.get('full_name')};
                    if(options.method==='POST' && options.body.get('action')==='avatar') personalMockState={...personalMockState,version:'new-avatar-version',avatar_url:options.body.get('remove_avatar')==='1'?'':URL.createObjectURL(options.body.get('avatar'))};
                    const token=options.body?.get('action')==='password'?'c'.repeat(64):'b'.repeat(64);
                    return new Response(JSON.stringify({ok:true,message:'Đã lưu.',csrf_token:token,profile:personalMockState}),{headers:{'Content-Type':'application/json'}});
                };
                profileForm.dataset.preview='false';passwordForm.dataset.preview='false';`);
            await evaluate('openPersonalDialog("profile")');
            await evaluate('profileForm.elements.full_name.value="Tên mới <script>";profileForm.requestSubmit()');
            await evaluate('new Promise(resolve=>setTimeout(resolve,50))');
            assert.equal(await evaluate('profileDialog.open'),false,'Successful live response must close the editor.');
            assert.equal(await evaluate('[...document.querySelectorAll("[data-user-name]")].every(element=>element.textContent==="Tên mới <script>" && !element.querySelector("script"))'),true,'Saved identity must update immediately and remain text.');
            assert.equal(await evaluate('personalPayloads[0].full_name'),'Tên mới <script>');
            assert.equal(await evaluate('Object.keys(personalPayloads[0]).sort().join(",")'),'action,csrf_token,email,full_name,phone,version','Only editable fields should be submitted.');
            await evaluate(`(async()=>{await openPersonalDialog('profile');profileForm.elements.full_name.value='Liên hệ chưa lưu';
                const canvas=document.createElement('canvas');canvas.width=canvas.height=20;
                canvas.getContext('2d').fillRect(0,0,20,20);
                const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
                await updatePersonalAvatar(new File([blob],'avatar.png',{type:'image/png'}));
                await new Promise(resolve=>setTimeout(resolve,50));})()`);
            assert.equal(await evaluate('document.querySelectorAll("[data-user-initial] img,[data-profile-initial] img").length'),4,'Saving an avatar must update every shared identity immediately.');
            assert.equal(await evaluate('profileDialog.open && profileForm.elements.full_name.value==="Liên hệ chưa lưu"'),true,'Avatar saving must preserve the dialog and unsaved contact fields.');
            assert.equal(await evaluate('personalPayloads[1].action'),'avatar');
            assert.equal(await evaluate('personalPayloads[1].avatar instanceof File'),true,'Avatar upload must send an actual file.');
            await evaluate('updatePersonalAvatar(null)');
            assert.equal(await evaluate('document.querySelectorAll("[data-user-initial] img,[data-profile-initial] img").length'),0,'Removing the avatar must restore initials everywhere.');
            assert.equal(await evaluate('profileForm.querySelector("[data-remove-avatar]").hidden'),true);
            await evaluate('profileDialog.close()');
            await evaluate('openPersonalDialog("password");passwordForm.elements.current_password.value="Current-example";passwordForm.elements.password.value="New-password-example";passwordForm.elements.password_confirmation.value="New-password-example";passwordForm.requestSubmit()');
            await evaluate('new Promise(resolve=>setTimeout(resolve,50))');
            assert.equal(await evaluate('[...document.querySelectorAll("input[name=csrf_token]")].every(input=>input.value==="c".repeat(64))'),true,'New CSRF must update every form, including logout.');
            assert.equal(await evaluate('passwordDialog.open'),false);
            await evaluate('personalMockFailure=true;openPersonalDialog("password");passwordForm.elements.current_password.value="Current-example";passwordForm.elements.password.value="New-password-example";passwordForm.elements.password_confirmation.value="New-password-example";passwordForm.requestSubmit()');
            await evaluate('new Promise(resolve=>setTimeout(resolve,50))');
            assert.equal(await evaluate('passwordDialog.open && !passwordForm.querySelector("[data-profile-login]").hidden && !passwordForm.querySelector("[data-form-error]").hidden && !passwordForm.querySelector("[type=submit]").disabled'),true,'Expired sessions must show login guidance and release saving controls.');
            assert.equal(await evaluate('[...passwordForm.querySelectorAll("input[type=password]")].every(input=>input.value==="")'),true,'Failed password requests must clear secrets.');
            await evaluate('passwordDialog.close();window.fetch=personalNativeFetch');
        }
    }
    for (const role of ['admin','viewer','keeper']) {
        const helpRender=spawnSync(php,['tests/home.php','--help','--render',...(role==='admin'?[]:['--'+role])],{encoding:'utf8',windowsHide:true});
        assert.equal(helpRender.status,0,helpRender.stderr);
        await page('Page.navigate',{url:process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/'});
        for(let attempt=0;attempt<100;attempt++){if(await evaluate('document.readyState==="complete" && Boolean(document.getElementById("login-form"))'))break;await new Promise(resolve=>setTimeout(resolve,100));}
        const frame=await page('Page.getFrameTree');
        await page('Page.setDocumentContent',{frameId:frame.frameTree.frame.id,html:helpRender.stdout});
        for(let attempt=0;attempt<100;attempt++){if(await evaluate('document.readyState==="complete" && typeof helpSearch === "object"'))break;await new Promise(resolve=>setTimeout(resolve,100));}
        assert.equal(await evaluate('Boolean(document.querySelector(".sidebar-bottom a[aria-current=page]"))'),true,'Help must be a real page in the fixed sidebar footer.');
        assert.equal(await evaluate('Boolean(document.getElementById("help-accounts"))'),role==='admin','Help must respect management permissions.');
        await evaluate('helpSearch.value="anh dai dien";helpSearch.dispatchEvent(new Event("input"))');
        assert.equal(await evaluate('document.querySelectorAll("[data-help-topic]:not([hidden])").length'),1,'Help search must match Vietnamese without accents.');
        assert.equal(await evaluate('document.getElementById("help-profile").hidden'),false);
        await evaluate('helpSearch.value="khong-ton-tai-012345";helpSearch.dispatchEvent(new Event("input"))');
        assert.equal(await evaluate('document.getElementById("help-empty").hidden'),false,'Help must explain no matching results.');
        await evaluate('helpSearch.value="";helpSearch.dispatchEvent(new Event("input"))');
        assert.equal(await evaluate('document.querySelectorAll("[data-help-topic]:not([hidden])").length'),role==='admin'?14:10,'Clearing help search must restore all topics.');
        await evaluate('document.querySelector("[data-help-faq] summary").click()');
        assert.equal(await evaluate('document.querySelector("[data-help-faq]").open'),true,'FAQs must expand using native controls.');
        for (const [width,height] of [[1440,1000],[1366,768],[390,844],[320,568],[844,390]]) {
            await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
            await evaluate('scrollTo(0,0);new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Help must fit the screen at '+width);
            const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`help-${role}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        }
        await evaluate('document.querySelector("#help-profile [data-open-profile]").click()');
        assert.equal(await evaluate('profileDialog.open'),true,'Help must open the existing personal editor.');
        await evaluate('profileDialog.close()');
    }
    for (const [route, role, mixed] of [['imports','viewer',false],['imports','keeper',true],['decisions','keeper',false],['products','viewer',false],['reports','viewer',false]]) {
        const roleRender=spawnSync(php,['tests/home.php','--'+route,'--'+role,...(mixed?['--mixed']:[]),'--render'],{encoding:'utf8',windowsHide:true});
        assert.equal(roleRender.status,0,roleRender.stderr);
        await page('Page.navigate',{url:process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/'});
        for(let attempt=0;attempt<100;attempt++){if(await evaluate('document.readyState==="complete" && Boolean(document.getElementById("login-form"))'))break;await new Promise(resolve=>setTimeout(resolve,100));}
        const roleFrame=await page('Page.getFrameTree');
        await page('Page.setDocumentContent',{frameId:roleFrame.frameTree.frame.id,html:roleRender.stdout});
        for(let attempt=0;attempt<100;attempt++){if(await evaluate(`document.readyState==="complete" && typeof ${route==='imports'?'openImport':route==='decisions'?'editDecision':route==='products'?'editProduct':'drawReportChart'} === "function"`))break;await new Promise(resolve=>setTimeout(resolve,100));}
        assert.equal(await evaluate('Boolean(document.querySelector("a[href=\\"index.php?page=accounts\\"],a[href=\\"index.php?page=employees\\"],a[href=\\"index.php?page=warehouses\\"],a[href=\\"index.php?page=audit\\"]"))'),false,'Admin-only sidebar entries must be absent.');
        if(route==='imports') {
            assert.equal(await evaluate('Boolean(document.querySelector("a[href=\\"index.php?page=import-create\\"]"))'),role==='keeper');
            await evaluate('openImport(JSON.parse(importRows.find(row=>JSON.parse(row.dataset.import).can_edit===false).dataset.import))');
            assert.equal(await evaluate('document.getElementById("import-edit").hidden'),true,'A viewer-only warehouse must hide editing even in a mixed-role session.');
            assert.equal(await evaluate('document.getElementById("import-confirm").hidden'),true,'Viewer receipt details must not offer confirmation.');
            await evaluate('renderImportItems(true)');
            assert.equal(await evaluate('importEditing'),false,'Read-only receipt details must remain read-only.');
            await evaluate('importDialog.close()');
            if(mixed) {
                await evaluate('openImport(JSON.parse(importRows.find(row=>JSON.parse(row.dataset.import).can_edit===true).dataset.import))');
                assert.equal(await evaluate('document.getElementById("import-edit").hidden'),false,'The same account must edit receipts in its keeper warehouse.');
                await evaluate('importDialog.close()');
            }
        } else if(route==='decisions') {
            assert.equal(await evaluate('document.getElementById("create-decision")===null'),true);
            await evaluate('document.querySelector(".edit-decision").click()');
            assert.equal(await evaluate('decisionForm.elements.number.readOnly && decisionForm.elements.content.readOnly && decisionForm.elements.pdf.disabled'),true,'Keeper decision editing must allow links while protecting metadata/PDF.');
            await evaluate('decisionEditor.close()');
        } else if(route==='products') assert.equal(await evaluate('document.querySelectorAll(".edit-product").length'),0);
        else assert.equal(await evaluate('document.querySelector(".report-account-summary")===null'),true);
        await page('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true);
        const roleShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
        writeFileSync(join(outputDir,`${role}-${route}${mixed?'-mixed':''}.png`),Buffer.from(roleShot.data,'base64'));
    }
    for(const route of ['imports','exports','products','materials','spare-parts','tools','fixed-assets','other-products','employees','warehouses','suppliers','decisions','accounts','audit']) {
        const linkedRender=spawnSync(php,['tests/home.php','--'+route,'--render'],{encoding:'utf8',windowsHide:true});assert.equal(linkedRender.status,0,linkedRender.stderr);
        const linkedId={accounts:'6',audit:'1035',materials:'11',tools:'8','fixed-assets':'9','other-products':'10'}[route] || '12';
        await page('Page.navigate',{url:(process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/')+'?id='+linkedId});
        for(let i=0;i<100;i++){if(await evaluate('document.readyState==="complete" && Boolean(document.getElementById("login-form"))'))break;await new Promise(resolve=>setTimeout(resolve,30));}
        const linkedFrame=await page('Page.getFrameTree');await page('Page.setDocumentContent',{frameId:linkedFrame.frameTree.frame.id,html:linkedRender.stdout});
        for(let i=0;i<100;i++){if(await evaluate('document.readyState==="complete" && Boolean(document.querySelector("dialog[open]"))'))break;await new Promise(resolve=>setTimeout(resolve,30));}
        assert.equal(await evaluate('Boolean(document.querySelector("dialog[open]"))'),true,'A search result link must open its record: '+route);
        if(route==='accounts')assert.equal(await evaluate('document.querySelector("dialog[open] input[name=id]").value'),linkedId,'Editable results must target the requested record.');
        if(route==='warehouses') {
            assert.equal(await evaluate('viewedWarehouse.id'),linkedId,'Warehouse search must open the requested view.');
            assert.equal(await evaluate('warehouseDetailsDialog.querySelectorAll("input,select,textarea").length'),0,'Warehouse search must display a read-only view.');
        }
        if(route==='employees') {
            assert.equal(await evaluate('viewedEmployee.id'),linkedId,'Employee search must open the requested read-only profile.');
            assert.equal(await evaluate('employeeDetailsDialog.querySelectorAll("input,select,textarea").length'),0,'Employee details must display data without editable controls.');
            for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]) {
                await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
                assert.equal(await evaluate('(()=>{const d=employeeDetailsDialog,b=d.querySelector(".account-dialog-body"),h=d.querySelector("header"),f=d.querySelector("footer"),before=[h.getBoundingClientRect().top,f.getBoundingClientRect().top];b.scrollTop=b.scrollHeight;return before[0]===h.getBoundingClientRect().top && before[1]===f.getBoundingClientRect().top && d.scrollWidth<=d.clientWidth && d.scrollHeight<=d.clientHeight+1;})()'),true,'Employee view must keep its heading/actions fixed.');
                const viewShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(join(outputDir,`employee-view-${width}x${height}.png`),Buffer.from(viewShot.data,'base64'));
            }
            await evaluate('document.getElementById("employee-detail-edit").click()');
            assert.equal(await evaluate('employeeDialog.open && employeeForm.elements.id.value===viewedEmployee.id'),true,'Edit from employee details must use the same employee.');
        }
        await evaluate('document.querySelector("dialog[open]").close()');
        if(await evaluate('Boolean(document.querySelector(".compact-table"))')) {
            await page('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            const buttonSizes=await evaluate('(()=>{const row=document.querySelector(".compact-table tbody tr:not([hidden])");return [...row.lastElementChild.querySelectorAll(".button,.icon-button")].map(button=>button.getBoundingClientRect().width);})()');
            await evaluate(`globalThis.compactTestRow=document.querySelector('.compact-table tbody tr:not([hidden])');
                globalThis.compactTestText=[...compactTestRow.querySelectorAll('.compact-cell')].flatMap(button=>button.children.length?[...button.querySelectorAll('strong,span,small')]:[button]).map(element=>[element,element.textContent]);
                globalThis.compactTestTitles=[...compactTestRow.querySelectorAll('.compact-cell')].map(button=>[button,button.title]);
                compactTestText.forEach(([element])=>element.textContent='Tên, địa chỉ và thông tin rất dài '+ 'chi nhánh Hà Nội '.repeat(12));
                compactTestTitles.forEach(([button])=>button.title=button.textContent.trim());`);
            for(const [width,height] of [[1440,1000],[1280,800],[1024,800],[390,844],[320,568],[844,390]]) {
                await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
                assert.equal(await evaluate('(()=>{const region=document.querySelector(".compact-table-scroll");return region.scrollWidth<=region.clientWidth+1 && document.documentElement.scrollWidth<=innerWidth;})()'),true,'Long data must not cause horizontal scrolling: '+route+' '+width);
                assert.deepEqual(await evaluate('[...compactTestRow.lastElementChild.querySelectorAll(".button,.icon-button")].map(button=>button.getBoundingClientRect().width)'),buttonSizes,'Action buttons must retain their sizes: '+route+' '+width);
                assert.equal(await evaluate('[...compactTestRow.querySelectorAll(".compact-cell")].every(button=>button.title===button.textContent.trim())'),true,'Hints must preserve the full data: '+route);
                await evaluate('compactTestRow.querySelector(".compact-cell").click()');
                assert.equal(await evaluate('Boolean(document.querySelector("dialog[open]"))'),true,'Long content must open its record: '+route);
                await evaluate('document.querySelector("dialog[open]").close();document.querySelector(".compact-table-scroll").scrollIntoView({block:"start"})');
                const compactShot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(join(outputDir,`compact-${route}-${width}x${height}.png`),Buffer.from(compactShot.data,'base64'));
            }
            await evaluate('compactTestText.forEach(([element,text])=>element.textContent=text);compactTestTitles.forEach(([button,title])=>button.title=title)');
        }
    }
    const noticeSource=spawnSync(php,['tests/home.php','--render'],{encoding:'utf8',windowsHide:true});assert.equal(noticeSource.status,0);
    const noticeHtml=noticeSource.stdout.replace('data-user="1" data-preview="true"','data-user="1" data-preview="false"');
    let noticeRecords=Array.from({length:9},(_,index)=>({id:String(9-index),kind:index===0?'admin':'receipt',title:index===0?'Thêm kho <script> mới':'Phiếu nhập mới PN-'+(9-index),description:index===0?'Thông báo quản trị · Toàn hệ thống':'Kho Hà Nội',created_at:'2026-10-13T10:00:00+07:00',href:index===0?null:'index.php?page=imports&id='+(9-index)}));
    const noticeRead=new Set();let noticeScope='scope-A',noticeReadFails=false,noticeRequestCount=0,noticeDestination=null,noticeSessionExpired=false;
    const noticeHandler=async event=>{const message=JSON.parse(event.data);if(message.method!=='Fetch.requestPaused')return;
        const request=message.params.request,url=new URL(request.url);let response,status=200;
        if(url.searchParams.get('page')==='imports'){noticeDestination=request.url;await page('Fetch.fulfillRequest',{requestId:message.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],body:Buffer.from('<!doctype html><title>Receipt destination</title>').toString('base64')});return;}
        noticeRequestCount++;
        if(noticeSessionExpired){status=401;response={ok:false,message:'Tài khoản đã đăng nhập ở nơi khác.'};}
        else if(request.method==='POST'){notificationMockPosts++;const fields=new URLSearchParams(request.postData);if(noticeReadFails){status=503;response={ok:false,message:'Không lưu được trạng thái đã xem.'};}else{noticeRead.add(fields.get('id'));response={ok:true};}}
        else{const unread=noticeRecords.filter(item=>!noticeRead.has(item.id)),before=url.searchParams.get('before'),rows=noticeRecords.filter(item=>!before || BigInt(item.id)<BigInt(before)),known=url.searchParams.getAll('known[]');
            response={ok:true,items:rows.slice(0,5).map(item=>({...item,is_read:noticeRead.has(item.id)})),unread_count:unread.length,latest_id:noticeRecords[0]?.id || '0',next_before:rows.length>5?rows[4].id:null,alive_ids:noticeRecords.filter(item=>known.includes(item.id)).map(item=>item.id),read_ids:noticeRecords.filter(item=>known.includes(item.id)&&noticeRead.has(item.id)).map(item=>item.id),scope:noticeScope};}
        await page('Fetch.fulfillRequest',{requestId:message.params.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json; charset=utf-8'}],body:Buffer.from(JSON.stringify(response)).toString('base64')});
    };
    socket.addEventListener('message',noticeHandler);await page('Fetch.enable',{patterns:[{urlPattern:'*index.php?page=notifications*'},{urlPattern:'*index.php?page=imports&id=*'}]});
    await page('Page.navigate',{url:'http://localhost/qlvt-remake/public/index.php?page=login'});
    for(let i=0;i<100;i++){if(await evaluate('document.readyState==="complete" && Boolean(document.getElementById("login-form"))'))break;await new Promise(resolve=>setTimeout(resolve,100));}
    const noticeFrame=await page('Page.getFrameTree');await page('Page.setDocumentContent',{frameId:noticeFrame.frameTree.frame.id,html:noticeHtml});
    for(let i=0;i<100;i++){if(await evaluate('typeof loadActivityNotifications==="function" && !activityLoading && activityItems.length===5'))break;await new Promise(resolve=>setTimeout(resolve,100));}
    assert.equal(await evaluate('activityItems.length'),5);assert.equal(await evaluate('document.getElementById("activity-badge").textContent'),'9');
    await page('Input.dispatchKeyEvent',{type:'keyDown',key:'a',code:'KeyA',windowsVirtualKeyCode:65});
    await page('Input.dispatchKeyEvent',{type:'keyUp',key:'a',code:'KeyA',windowsVirtualKeyCode:65});
    await evaluate('armActivityAudio()');
    assert.equal(await evaluate('activityPanel.dataset.sound.startsWith("assets/sounds/notification.mp4?v=")'),true,'Notification sound must use a versioned local MP4 asset.');
    assert.equal(await evaluate('activityAudio.state==="running" && activityAudioBuffer.duration>0 && activityAudioBuffer.duration<2'),true,'The browser must decode the downloaded AAC/MP4 sound after interaction.');
    assert.equal(await evaluate('activityAudioBuffer.getChannelData(0).some(sample=>Math.abs(sample)>.001)'),true,'The downloaded notification clip must contain audible samples.');
    assert.equal(await evaluate('[...activityList.querySelectorAll(".activity-seen")].every(button=>button.textContent==="Đánh dấu đã đọc" && button.getAttribute("aria-label").startsWith("Đánh dấu đã đọc: "))'),true,'Unread notifications must show a clear mark-as-read action rather than a read-status label.');
    await evaluate('clearTimeout(activityTimer);activityAudio=null;activityPendingSound=false;activityFrontier=null;window.noticeBeeps=0;window.originalActivityBeep=activityBeep;activityBeep=()=>{window.noticeBeeps++;};activityAudio={state:"running"};activitySound=true;loadActivityNotifications("reset")');
    for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('noticeBeeps'),0,'Existing unread notices must not replay a sound after initial loading.');
    await evaluate('document.getElementById("activity-toggle").click()');
    for(let i=0;i<100;i++){if(await evaluate('activityPanel.matches(":popover-open") && !activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('activityPanel.querySelectorAll("script").length'),0,'Notification titles must be escaped text.');
    assert.equal(await evaluate('activityList.firstElementChild.querySelector("a")===null && activityList.firstElementChild.textContent.includes("<script>")'),true,'Admin messages must remain plain content without navigation.');
    await evaluate('activityMore.click()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('activityItems.length'),9,'Show more must append older notification history.');
    for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]){
        await page('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        assert.equal(await evaluate(`(()=>{const body=activityPanel.querySelector('.activity-body'),header=activityPanel.querySelector('header'),footer=activityPanel.querySelector('footer'),before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;const rect=activityPanel.getBoundingClientRect();return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top && rect.left>=0 && rect.right<=innerWidth && rect.bottom<=innerHeight && activityPanel.scrollWidth<=activityPanel.clientWidth && document.documentElement.scrollWidth<=innerWidth;})()`),true,'Notification panel must fit the screen with fixed heading/actions and a scrolling body.');
        await evaluate('activityPanel.querySelector(".activity-body").scrollTop=0');const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});writeFileSync(join(outputDir,`activity-notifications-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
    }
    const beforeReadPosts=notificationMockPosts;
    await evaluate(`activityList.querySelector('[data-id="9"] .activity-seen').click()`);
    for(let i=0;i<100;i++){if(await evaluate('!activityBusyIds.has("9")'))break;await new Promise(resolve=>setTimeout(resolve,20));}
    assert.equal(await evaluate('document.getElementById("activity-count").textContent'),'8 chưa đọc','Marking read must reduce the displayed count.');
    assert.equal(await evaluate('activityItems.find(row=>row.id==="9").is_read'),true,'Read notices must remain in the list with a read state.');
    assert.equal(await evaluate(`activityList.querySelector('[data-id="9"]').classList.contains("is-read") && !activityList.querySelector('[data-id="9"] .activity-seen') && getComputedStyle(activityList.querySelector('[data-id="9"] .activity-item-title')).fontWeight < getComputedStyle(activityList.querySelector('[data-id="8"] .activity-item-title')).fontWeight`),true,'Read notices must look lighter and show a read label.');assert.equal(notificationMockPosts,beforeReadPosts+1);
    await evaluate('loadActivityNotifications("reset")');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('activityItems.find(row=>row.id==="9").is_read'),true,'Read history must retain its state after refreshing.');
    const repeatReadPosts=notificationMockPosts;await evaluate('markActivityRead(activityItems.find(row=>row.id==="9"))');assert.equal(notificationMockPosts,repeatReadPosts,'Read notices must not be marked or counted twice.');
    noticeReadFails=true;await evaluate('markActivityRead(activityItems.find(row=>row.id==="8"))');assert.equal(await evaluate('activityItems.some(row=>row.id==="8")'),true,'Failed read requests must retain unread notices.');noticeReadFails=false;
    noticeRecords.unshift({id:'10',kind:'admin',title:'Kho mới',description:'Thông báo quản trị',created_at:'2026-10-13T10:01:00+07:00',href:null});
    await evaluate('loadActivityNotifications()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('noticeBeeps'),1,'A new event during login must produce one soft chime.');
    await evaluate('loadActivityNotifications()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}assert.equal(await evaluate('noticeBeeps'),1,'Polling the same event must not replay sound.');
    await evaluate('document.getElementById("activity-sound").click()');noticeRecords.unshift({id:'11',kind:'admin',title:'Cập nhật danh mục',description:'Thông báo quản trị',created_at:'2026-10-13T10:02:00+07:00',href:null});
    await evaluate('loadActivityNotifications()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}assert.equal(await evaluate('noticeBeeps'),1,'Muted notifications must remain silent.');
    noticeRead.add('8');await evaluate('loadActivityNotifications()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('activityItems.find(row=>row.id==="8").is_read'),true,'Polling must refresh the read state from another device/tab without removing history.');
    noticeScope='scope-B';noticeRecords=noticeRecords.filter(row=>row.kind==='admin');
    await evaluate('loadActivityNotifications()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    assert.equal(await evaluate('activityItems.every(row=>row.kind==="admin")'),true,'Scope changes must clear cached warehouse notices.');
    await evaluate(`window.savedActivityRequest=activityRequest;window.readPollRelease=null;activityRequest=async(url,options={})=>{const result=await savedActivityRequest(url,options);if(options.method!=='POST')await new Promise(resolve=>{window.readPollRelease=resolve;});return result;};window.delayedReadPoll=loadActivityNotifications();true;`);
    for(let i=0;i<100;i++){if(await evaluate('readPollRelease!==null'))break;await new Promise(resolve=>setTimeout(resolve,20));}
    await evaluate('markActivityRead(activityItems.find(row=>row.id==="10"))');
    await evaluate('activityRequest=savedActivityRequest;readPollRelease();delayedReadPoll');
    assert.equal(await evaluate('activityCount'),1,'An old poll finishing after a read must not restore the previous unread count.');
    for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,20));}
    noticeRecords.unshift({id:'12',kind:'receipt',title:'Phiếu đích',description:'Kho Hà Nội',created_at:'2026-10-13T10:03:00+07:00',href:'index.php?page=imports&id=712'});
    await evaluate('loadActivityNotifications()');for(let i=0;i<100;i++){if(await evaluate('!activityLoading'))break;await new Promise(resolve=>setTimeout(resolve,30));}
    await evaluate('activityAudio=null;activityBeep=originalActivityBeep;activityList.querySelector("a").click()');
    for(let i=0;i<100;i++){if(noticeDestination)break;await new Promise(resolve=>setTimeout(resolve,50));}
    assert.equal(new URL(noticeDestination).searchParams.get('id'),'712','Clicking a receipt notice must mark it read and open the exact linked receipt.');assert.equal(noticeRead.has('12'),true);
    noticeSessionExpired=true;const expiredFrame=await page('Page.getFrameTree');await page('Page.setDocumentContent',{frameId:expiredFrame.frameTree.frame.id,html:noticeHtml});
    for(let i=0;i<150;i++){if(await evaluate('new URL(location.href).searchParams.get("page")==="login" && document.readyState==="complete" && Boolean(document.getElementById("login-form"))'))break;await new Promise(resolve=>setTimeout(resolve,50));}
    assert.equal(await evaluate('new URL(location.href).searchParams.get("page")==="login" && Boolean(document.getElementById("login-form"))'),true,'A replaced/expired login detected by background polling must automatically return to login.');
    assert.ok(noticeRequestCount>0);await page('Fetch.disable');socket.removeEventListener('message',noticeHandler);
    assert.deepEqual(pageErrors, [], 'Browser errors.');
    assert.deepEqual(failedRequests, [], 'Asset loading errors.');
    assert.equal(postRequests, notificationMockPosts, 'Sample controls must not submit business data; notification read requests use mocked endpoints only.');
    console.log(`PASS: dashboard/sidebar, management, reports with XLSX/print, import/export forms and filters, lot allocation, notifications and responsive layout.\nScreenshots: ${outputDir}`);
    await send('Browser.close');
} finally {
    socket?.close();
    browser.kill();
}
