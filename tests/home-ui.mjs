// Run with Node 22+ and XAMPP: node tests/home-ui.mjs. Renders samples without database.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { once } from 'node:events';

const browserPath = [process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(path => path && existsSync(path));
assert.ok(browserPath, 'Install Chrome/Edge or set CHROME_PATH.');
const outputDir = mkdtempSync(join(tmpdir(), 'qlvt-home-check-'));
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
    let postRequests = 0;
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.method === 'Runtime.exceptionThrown') pageErrors.push(message.params.exceptionDetails.text);
        if (message.method === 'Network.loadingFailed') failedRequests.push(message.params.errorText);
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
    await evaluate('document.getElementById("receipt-search").value=""; document.getElementById("receipt-search").dispatchEvent(new Event("input")); document.querySelector("[data-feature=\\"Hướng dẫn sử dụng\\"]").click()');
    assert.equal(await evaluate('document.getElementById("feature-dialog").open'), true);
    await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    for (let i = 0; i < 20 && await evaluate('document.getElementById("feature-dialog").open'); i++) await new Promise(resolve => setTimeout(resolve, 50));
    assert.equal(await evaluate('document.getElementById("feature-dialog").open'), false);
    const originalGroups = await evaluate('[...document.querySelectorAll(".nav-group")].map(group=>group.open)');
    assert.equal(originalGroups[0], true);
    await evaluate('document.querySelector(".nav-group summary").click()');
    assert.equal(await evaluate('document.querySelector(".nav-group").open'), false, 'Second click should collapse the group.');
    await evaluate('document.querySelector(".nav-group summary").click()');
    assert.equal(await evaluate('document.querySelector(".nav-group").open'), true, 'Click should expand the group.');
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
    for (const [width, height] of [[1440, 1050], [1280, 800], [1280, 600], [768, 1024], [390, 844], [320, 568], [844, 390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
        await evaluate('window.scrollTo(0,0)');
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
    await evaluate('document.getElementById("account-admin").checked=true; document.getElementById("account-admin").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.getElementById("warehouse-permissions").disabled'), true);
    await evaluate('document.getElementById("account-username").value="sample-user"; document.getElementById("account-password").value="sample-only-password"; document.getElementById("account-form").requestSubmit()');
    assert.equal(await evaluate('document.getElementById("account-dialog").open'), false);
    assert.equal(await evaluate('document.getElementById("account-notice").hidden'), false);
    assert.equal(await evaluate('document.getElementById("account-password").value'), '', 'Do not retain the entered password.');
    assert.equal(await evaluate('document.querySelectorAll("[data-account]").length'), 6, 'Preview must not change sample accounts.');
    await evaluate('document.querySelectorAll(".edit-account")[1].click()');
    assert.equal(await evaluate('document.getElementById("account-username").value'), 'minhanh');
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
    await evaluate('document.querySelector(".edit-employee").click()');
    assert.equal(await evaluate('document.getElementById("edit-employee-code").value'), 'NV001');
    assert.equal(await evaluate('document.getElementById("employee-linked-account").textContent'), 'Tài khoản: minhanh');
    await evaluate('document.getElementById("edit-employee-status").value="inactive"; document.getElementById("edit-employee-status").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.getElementById("employee-inactive-note").hidden'), false);
    await evaluate('document.querySelector(".close-employee-dialog").click(); document.querySelector(".employee-state-preview").click()');
    assert.equal(await evaluate('document.getElementById("employee-state-dialog").open'), true);
    await evaluate('document.querySelector("#employee-state-dialog button[value=cancel]").click()');
    await evaluate('document.querySelector("[data-delete-record]").click()');
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
            await evaluate(editing ? 'document.querySelector(".edit-employee").click()' : 'document.getElementById("create-employee").click()');
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
    await evaluate('document.querySelector(".edit-warehouse").click()');
    assert.equal(await evaluate('warehouseForm.elements.namedItem("name").value'), 'Kho 1');
    assert.equal(await evaluate('warehouseForm.elements.id.value'), '1');
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
        for (const selector of ['.edit-warehouse', '.warehouse-state', '[data-delete-record]']) {
            await evaluate(`document.querySelector('${selector}').click()`);
            assert.deepEqual(await evaluate(`(() => {
                const dialog = document.querySelector('dialog[open]');
                const body = dialog.querySelector('.account-dialog-body');
                const heading = dialog.querySelector('.account-dialog-heading');
                const footer = dialog.querySelector('.account-dialog-footer');
                const before = [heading.getBoundingClientRect().top, footer.getBoundingClientRect().top];
                body.scrollTop = body.scrollHeight;
                return { fixed: before[0] === heading.getBoundingClientRect().top && before[1] === footer.getBoundingClientRect().top,
                    fits: dialog.scrollHeight <= dialog.clientHeight + 1 && dialog.scrollWidth <= dialog.clientWidth,
                    actionsVisible: [...footer.querySelectorAll('button'), heading.querySelector('button')].every(button => { const rect = button.getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; }) };
            })()`), { fixed: true, fits: true, actionsVisible: true }, `Warehouse dialog must keep its title/actions fixed at ${width}x${height}.`);
            await evaluate('document.querySelector("dialog[open] .account-dialog-body").scrollTop=0');
            const detail = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            writeFileSync(join(outputDir, `warehouse-${selector.slice(1)}-${width}x${height}.png`), Buffer.from(detail.data, 'base64'));
            await evaluate('document.querySelector("dialog[open]").close()');
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
    await evaluate('supplierSearch.value=""; filterSuppliers(); document.querySelectorAll(".supplier-details")[2].click()');
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
    for (const kind of ['inventory', 'materials', 'parts', 'tools', 'assets']) {
        const reportRender = spawnSync(php, ['tests/home.php', '--reports', '--report-kind=' + kind, '--render'], { encoding: 'utf8', windowsHide: true });
        assert.equal(reportRender.status, 0, reportRender.stderr);
        await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
        for (let i = 0; i < 100; i++) {
            if (await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))')) break;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        const reportFrame = await page('Page.getFrameTree');
        await page('Page.setDocumentContent', { frameId: reportFrame.frameTree.frame.id, html: reportRender.stdout });
        for (let i = 0; i < 100; i++) {
            if (await evaluate('document.readyState === "complete" && typeof paginateReport === "function" && typeof XLSX === "object"')) break;
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        const count = kind === 'inventory' ? 60 : 12;
        assert.equal(await evaluate('document.querySelectorAll("[data-report-row]:not([hidden])").length'), 10);
        assert.equal(await evaluate('document.querySelectorAll(".nav-group[open] .nav-child").length'), 5, 'All report navigation entries must be available.');
        assert.equal(await evaluate('document.querySelector(".nav-child[aria-current=page]").getAttribute("href").endsWith("kind=" + reportData.filters.kind)'), true);
        await evaluate('document.getElementById("report-next").click()');
        assert.equal(await evaluate('document.querySelectorAll("[data-report-row]:not([hidden])").length'), kind === 'inventory' ? 10 : 2);
        assert.equal(await evaluate(`(() => {
            const book=buildReportWorkbook(); const data=XLSX.write(book,{type:'base64',bookType:'xlsx'}); const read=XLSX.read(data,{type:'base64'});
            const rows=XLSX.utils.sheet_to_json(read.Sheets['Bao cao'],{header:1});
            return rows[1][0].includes('DỮ LIỆU MẪU') && rows.length === ${count + 7} && read.SheetNames.length === 2;
        })()`), true, 'Excel must include all filtered pages and sample label.');
        assert.equal(await evaluate("reportExcelValue('9007199254740991.123456')"), '9007199254740991.123456', 'Large DECIMAL values must remain text in Excel.');
        if (kind === 'inventory') {
            assert.equal(await evaluate(`(() => { const row=reportData.report.rows[0], name=row.name; row.name='=HYPERLINK("https://example.invalid")'; const cell=buildReportWorkbook().Sheets['Bao cao'].B7; row.name=name; return cell.t==='s' && !cell.f; })()`), true, 'Excel names must remain text, never formulas.');
            await evaluate('document.getElementById("export-report").click()');
            const filename = await evaluate('`VISHIPEL_MAU_${reportData.filters.kind}_${reportData.filters.from}_${reportData.filters.to}.xlsx`');
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
            assert.equal(await evaluate('[...document.querySelectorAll("[data-report-row]")].every(row=>getComputedStyle(row).display === "table-row")'), true, 'Print must include every page.');
            await page('Emulation.setEmulatedMedia', { media: '' });
        }
        for (const [width, height] of [[1440, 1000], [390, 844], [320, 568], [844, 390]]) {
            await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
            await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Report ${kind} overflow at ${width}.`);
            assert.equal(await evaluate('[...document.querySelectorAll(".report-money")].every(value => value.scrollWidth <= value.clientWidth)'), true, 'Report totals must fit their cards.');
            const shot = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            writeFileSync(join(outputDir, `report-${kind}-${width}x${height}.png`), Buffer.from(shot.data, 'base64'));
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
        ['materials','consumable',3,'outgoing'], ['spare-parts','spare_part',3,'specifications'],
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
        for (const [width,height] of [[1440,1000],[320,568],[844,390]]) {
            await page('Emulation.setDeviceMetricsOverride', {width,height,deviceScaleFactor:1,mobile:false});
            await evaluate('new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))');
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'), true, `${route} page overflow.`);
            const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`${route}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
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
    assert.equal(await evaluate('document.querySelector("#import-item-rows td").textContent.includes("<script>")'),true);
    assert.equal(await evaluate('document.querySelector("#import-item-rows td:nth-child(3)").textContent.includes("12.345.678.901.234,123456")'),true);
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
    assert.equal(await evaluate('importCreateForm.elements.warehouse.options.length'),2,'Inactive warehouse must be excluded.');
    await evaluate(`for(const [key,value] of Object.entries({name:'New product',unit:'Piece',quantity:'1.123456',unit_price:'2.5',tax_code:'10%'}))importLines.firstElementChild.querySelector('[data-field='+key+']').value=value; updateImportTotals()`);
    assert.equal(await evaluate('document.getElementById("import-total-after").textContent'),'3,089504 ₫','Preview totals must use exact decimals.');
    await evaluate('const selectedImportLine=importLines.firstElementChild;selectedImportLine.querySelector("[data-field=product_id]").value="1";configureImportLine(selectedImportLine,true)');
    assert.equal(await evaluate('importLines.firstElementChild.querySelector("[data-field=name]").readOnly'),true);
    assert.equal(await evaluate('importLines.firstElementChild.querySelector("[data-field=category]").disabled'),true);
    await evaluate('supplierSelect.value="1";configureImportSupplier()');
    assert.equal(await evaluate('importCreateForm.elements.tax_code.readOnly'),true);
    await evaluate(`applyImportInvoice({invoice_number:'HD-TEST',invoice_series:'C26T',invoice_date:'2026-10-10',supplier_name:'Seller',tax_code:'1234567890',items:[
        {product_id:'',code:'HH-001',name:'Invoice item',unit:'Chiếc',category:'other',tracking_mode:'quantity',quantity:'2',unit_price:'2',tax_code:'8%',tax_amount:'',specifications:'',note:''},
        {product_id:'',code:'XML-NEW',name:'Service <script>',unit:'Lần',category:'other',tracking_mode:'none',quantity:'',unit_price:'10',tax_code:'KCT',tax_amount:'',specifications:'',note:''}]})`);
    assert.equal(await evaluate('importLines.children.length'),2);
    assert.equal(await evaluate('importLines.firstElementChild.querySelector("[data-field=product_id]").value'),'1','XML must match an existing product code.');
    assert.equal(await evaluate('importLines.lastElementChild.querySelector("[data-field=quantity]").disabled'),true);
    assert.equal(await evaluate('document.getElementById("import-total-after").textContent'),'14,32 ₫');
    assert.equal(await evaluate('importCreateForm.elements.supplier_id.value'),'1','XML must match supplier tax code.');
    await evaluate('importCreateForm.elements.warehouse.value="1";importCreateForm.requestSubmit()');
    assert.equal(await evaluate('document.getElementById("import-create-notice").hidden'),false,'Preview must not save business data.');
    await evaluate('importLines.lastElementChild.querySelector(".remove-import-line").click()');
    assert.equal(await evaluate('importLines.children.length'),1);
    await evaluate(`applyImportInvoice({invoice_number:'00000548',invoice_series:'C26TNA',invoice_date:'2026-09-29',supplier_name:'Test supplier',invoice_before_tax:'2212963',invoice_tax:'177037',invoice_after_tax:'2390000',items:[{product_id:'',code:'',name:'Test kettle',unit:'Cái',category:'other',tracking_mode:'quantity',quantity:'1',unit_price:'2212962.96',amount_before_tax:'2212963',tax_code:'8%',tax_amount:'177037',specifications:'',note:''}]})`);
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
        const invoice={invoice_number:'00000548',invoice_series:'C26TNA',invoice_date:'2026-09-29',supplier_name:'Test supplier',invoice_before_tax:'2212963',invoice_tax:'177037',invoice_after_tax:'2390000',items:[{product_id:'',code:'',name:'Test kettle',unit:'Cái',category:'other',tracking_mode:'quantity',quantity:'1',unit_price:'2212962.96',amount_before_tax:'2212963',tax_code:'8%',tax_amount:'177037',specifications:'',note:''}]};
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
            assert.equal(await evaluate('document.getElementById("export-detail-receiver_name").textContent'),'Người nhận <script> 1');
            assert.equal(await evaluate('document.querySelector("#export-attachments a").getAttribute("href")'),'index.php?page=export-file&id=1');
            assert.equal(await evaluate('exportDialog.querySelectorAll("script").length'),0);
        } else {
            assert.equal(await evaluate('[...exportCreateForm.elements.warehouse.options].some(o=>o.value==="3")'),false);
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
                assert.equal(await evaluate(`(()=>{const body=exportDialog.querySelector('.account-dialog-body'),header=exportDialog.querySelector('header'),footer=exportDialog.querySelector('footer');const before=[header.getBoundingClientRect().top,footer.getBoundingClientRect().top];body.scrollTop=body.scrollHeight;return before[0]===header.getBoundingClientRect().top && before[1]===footer.getBoundingClientRect().top && exportDialog.scrollWidth<=exportDialog.clientWidth && exportDialog.scrollHeight<=exportDialog.clientHeight+1 && footer.getBoundingClientRect().bottom<=innerHeight;})()`),true,'Export detail heading/footer must remain fixed.');
            }
            const shot=await page('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
            writeFileSync(join(outputDir,`${route}-${width}x${height}.png`),Buffer.from(shot.data,'base64'));
        }
    }
    assert.deepEqual(pageErrors, [], 'Browser errors.');
    assert.deepEqual(failedRequests, [], 'Asset loading errors.');
    assert.equal(postRequests, 0, 'Dashboard sample controls must not submit business data.');
    console.log(`PASS: dashboard/sidebar, management, reports with XLSX/print, import/export forms and filters, lot allocation, notifications and responsive layout.\nScreenshots: ${outputDir}`);
    await send('Browser.close');
} finally {
    socket?.close();
    browser.kill();
}
