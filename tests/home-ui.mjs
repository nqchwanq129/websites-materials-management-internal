// Run with Node 22+ and XAMPP: node tests/home-ui.mjs. Renders samples without database.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
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
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 5);
    await evaluate('document.querySelectorAll(".tab")[1].click()');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 3);
    await evaluate('document.getElementById("warehouse-filter").selectedIndex=2; document.getElementById("warehouse-filter").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 1);
    await evaluate('document.querySelector(".tab[data-type=all]").click(); document.getElementById("warehouse-filter").value=""; document.getElementById("warehouse-filter").dispatchEvent(new Event("change")); document.getElementById("receipt-search").value="dieu chuyen"; document.getElementById("receipt-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelectorAll("[data-receipt]:not([hidden])").length'), 1, 'Accent-insensitive search failed.');
    await evaluate('document.getElementById("receipt-search").value="does-not-exist"; document.getElementById("receipt-search").dispatchEvent(new Event("input"))');
    assert.equal(await evaluate('document.querySelector(".empty-state").hidden'), false);
    await evaluate('document.getElementById("receipt-search").value=""; document.getElementById("receipt-search").dispatchEvent(new Event("input")); document.querySelector(".button-primary[data-feature]").click()');
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
    assert.deepEqual(pageErrors, [], 'Browser errors.');
    assert.deepEqual(failedRequests, [], 'Asset loading errors.');
    assert.equal(postRequests, 0, 'Dashboard sample controls must not submit business data.');
    console.log(`PASS: dashboard/sidebar, accounts and employee filters/pagination/forms, preview-only actions and responsive layout.\nScreenshots: ${outputDir}`);
    await send('Browser.close');
} finally {
    socket?.close();
    browser.kill();
}
