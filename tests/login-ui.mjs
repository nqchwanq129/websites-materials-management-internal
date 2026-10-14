// Run with Node 22+ and Chrome/Edge installed: node tests/login-ui.mjs
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { once } from 'node:events';

const browserPath = [process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(path => path && existsSync(path));
assert.ok(browserPath, 'Install Chrome/Edge or set CHROME_PATH.');
const outputDir = mkdtempSync(join(tmpdir(), 'qlvt-login-check-'));
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
        const result = await page('Runtime.evaluate', { expression, returnByValue: true });
        assert.ok(!result.exceptionDetails, result.exceptionDetails?.text);
        return result.result.value;
    };
    await page('Runtime.enable');
    await page('Network.enable');
    await page('Page.enable');
    await page('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await page('Page.navigate', { url: process.env.LOGIN_URL || 'http://localhost/qlvt-remake/public/' });
    let ready = false;
    for (let i = 0; i < 100 && !ready; i++) {
        ready = await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-form"))');
        if (!ready) await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.ok(ready, 'Login page failed to load.');
    assert.equal(await evaluate('document.querySelectorAll(".login-layout img").length'), 1, 'Show the VISHIPEL logo only on the left.');
    assert.equal(await evaluate('document.querySelector(".brand img").complete && document.querySelector(".brand img").naturalWidth > 0'), true, 'VISHIPEL logo failed to load.');
    assert.equal(await evaluate('document.querySelector(".brand-title").textContent'),'PHẦN MỀM QUẢN LÝ KHO VISHIPEL');
    assert.equal(await evaluate('document.querySelector(".brand-company").textContent'),'CÔNG TY TNHH MTV THÔNG TIN ĐIỆN TỬ HÀNG HẢI VIỆT NAM');
    const screenshot = async name => {
        const { data } = await page('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(join(outputDir, name), Buffer.from(data, 'base64'));
    };
    await screenshot('desktop.png');
    assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, 'Desktop overflow.');
    assert.equal(await evaluate('document.documentElement.scrollHeight <= innerHeight'), true,
        'Desktop should fit in the viewport: ' + JSON.stringify(await evaluate('({height:innerHeight,scroll:document.documentElement.scrollHeight,layout:document.querySelector(".login-layout").getBoundingClientRect().toJSON()})')));
    await page('Emulation.setDeviceMetricsOverride', { width: 1280, height: 768, deviceScaleFactor: 1, mobile: false });
    await screenshot('laptop.png');
    assert.equal(await evaluate('document.documentElement.scrollHeight <= innerHeight'), true,
        'Laptop should fit in the viewport: ' + JSON.stringify(await evaluate('({height:innerHeight,scroll:document.documentElement.scrollHeight})')));
    await evaluate('document.querySelector("#login-form button[type=submit]").click()');
    assert.equal(await evaluate('document.getElementById("login-notice").hidden'), true, 'Empty form should fail native validation.');
    await evaluate('document.getElementById("username").value="preview-user";document.getElementById("password").value="preview-password";document.getElementById("password-toggle").click()');
    assert.equal(await evaluate('document.getElementById("password").type'), 'text');
    assert.equal(await evaluate('document.getElementById("password-toggle").getAttribute("aria-pressed")'), 'true');
    await evaluate('document.getElementById("password-toggle").click()');
    assert.equal(await evaluate('document.getElementById("password").type'), 'password');
    for (const id of ['help-open', 'support-open']) {
        await evaluate(`document.getElementById('${id}').focus();document.getElementById('${id}').click()`);
        assert.equal(await evaluate('document.getElementById("login-help").open'), true);
        await page('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await page('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        assert.equal(await evaluate('document.getElementById("login-help").open'), false);
        assert.equal(await evaluate('document.activeElement.id'), id, 'Dialog should return focus.');
    }
    await evaluate('document.querySelector("#login-form button[type=submit]").click()');
    let rejected = false;
    for (let i = 0; i < 100 && !rejected; i++) {
        rejected = await evaluate('document.readyState === "complete" && Boolean(document.getElementById("login-notice")?.textContent.trim()) && document.getElementById("password")?.value === ""').catch(() => false);
        if (!rejected) await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.ok(rejected, 'Invalid credentials should return an error and clear the password.');
    assert.equal(await evaluate('document.getElementById("username").value'), 'preview-user', 'Preserve the username after a rejected login.');
    assert.equal(postRequests, 1, 'Submit credentials only once.');
    assert.equal(await evaluate('localStorage.length + sessionStorage.length'), 0);
    await evaluate('document.getElementById("login-form").reset();document.getElementById("username").value="";document.getElementById("login-notice").hidden=true;document.activeElement.blur()');
    for (const width of [390, 320]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height: 844, deviceScaleFactor: 1, mobile: true });
        assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'), true, `Mobile overflow at ${width}px.`);
        assert.equal(await evaluate('getComputedStyle(document.querySelector(".showcase")).display'), 'none');
        await screenshot(`mobile-${width}.png`);
    }
    for (const [width, height] of [[1920,1080], [1366,768], [1280,720], [1024,600], [390,844], [360,640], [320,568], [320,480], [844,390]]) {
        await page('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width <= 800 });
        for (const noticeVisible of [false, true]) {
            await evaluate(`document.getElementById('login-notice').hidden=${!noticeVisible}`);
            assert.equal(await evaluate('Math.abs(document.querySelector(".brand").getBoundingClientRect().left - document.getElementById("username").getBoundingClientRect().left) < 1'), true, 'Align the logo with the login form.');
            assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth && document.documentElement.scrollHeight <= innerHeight'), true, `Page must not scroll at ${width}x${height}, notice=${noticeVisible}.`);
            assert.deepEqual(await evaluate(`[...document.querySelectorAll('.brand,.brand-title,.brand-company,#login-title,#username,#password,#password-toggle,#help-open,#login-form button[type=submit],#support-open${noticeVisible ? ',#login-notice' : ''}')].filter(element => {
                const rect = element.getBoundingClientRect();
                return rect.width <= 0 || rect.height <= 0 || rect.top < 0 || rect.left < 0 || rect.bottom > innerHeight || rect.right > innerWidth;
            }).map(element => element.id || element.className)`), [], `Content must remain visible at ${width}x${height}, notice=${noticeVisible}.`);
        }
        await evaluate('document.getElementById("login-notice").hidden=true');
        if (height <= 720) await screenshot(`layout-${width}x${height}.png`);
    }
    assert.deepEqual(pageErrors, [], 'Browser errors.');
    assert.deepEqual(failedRequests, [], 'Asset loading errors.');
    console.log(`PASS: login validation, password toggle, support dialog, rejected login, desktop/mobile layout.\nScreenshots: ${outputDir}`);
    await send('Browser.close');
} finally {
    socket?.close();
    browser.kill();
}
