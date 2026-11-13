// Real workspace + API + judge smoke, synthetic data only. No npm or browser downloads.
// Run: node C:/xampp/htdocs/AlgoJudge/backend/tests/browser_workspace_output_smoke.mjs
// Helper-only tests (no browser, network, files, or DB): append --test-save-wait.
// Retains its new algojudge_test_workspace_* schema, logs, screenshots, and Chrome profile.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdtemp, mkdir, writeFile, appendFile, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';

// This function is also serialized into the page; keep it dependency-free.
function observeManualSave({ status, button, Observer = globalThis.MutationObserver,
  timeout = 20000, setTimer = setTimeout, clearTimer = clearTimeout }) {
  if (!status || !button) throw new Error('Missing manual draft-save controls');
  let clicked = false, finished = false, timer, resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  // The click/settle CDP commands can still be running when this times out.
  promise.catch(() => {});
  const finish = (error, text) => {
    if (finished) return;
    finished = true;
    observer.disconnect();
    button.removeEventListener('click', onClick, true);
    clearTimer(timer);
    if (error) reject(error); else resolve(text);
  };
  const onClick = () => {
    if (clicked) return;
    observer.takeRecords(); // Discard mutations queued before this button click.
    clicked = true;
  };
  const observer = new Observer(records => {
    if (!clicked || finished) return;
    // Read the NEW text for each mutation, not just the final badge. Autosave can
    // replace a successful manual badge before this callback (or a 100ms poll).
    const textAt = (target, index) => {
      const next = records.slice(index + 1).find(r => r.type === 'characterData' && r.target === target);
      return next ? next.oldValue : target.textContent;
    };
    for (let i = 0; i < records.length; i++) {
      const record = records[i];
      let text;
      if (record.type === 'childList' && record.target === status) {
        text = Array.from(record.addedNodes, node => textAt(node, i)).join('');
      } else if (record.type === 'characterData') {
        text = textAt(record.target, i);
      }
      // Only newly written manual confirmations count. Neither an old manual
      // badge nor any "Tự động lưu:" badge proves that the button worked.
      if (text?.startsWith('✔ Đã lưu nháp lúc ')) { finish(null, text); return; }
      if (text?.startsWith('⚠ ')) { finish(new Error('Manual draft save failed: ' + text)); return; }
    }
  });
  observer.observe(status, { childList: true, subtree: true, characterData: true, characterDataOldValue: true });
  button.addEventListener('click', onClick, true);
  timer = setTimer(() => finish(new Error('Timed out waiting for manual draft-save confirmation')), timeout);
  return { promise, cancel: () => finish(new Error('Manual draft-save wait cancelled')) };
}

async function testManualSaveWait() {
  let passed = 0;
  function harness(initialText = 'Đã sẵn sàng') {
    const listeners = new Set(), timers = new Map();
    class Button extends EventTarget {
      addEventListener(type, listener, options) {
        super.addEventListener(type, listener, options);
        if (type === 'click') listeners.add(listener);
      }
      removeEventListener(type, listener, options) {
        super.removeEventListener(type, listener, options);
        if (type === 'click') listeners.delete(listener);
      }
    }
    const status = { textContent: initialText }, button = new Button();
    let observer, timeoutMs;
    class Observer {
      constructor(callback) { this.callback = callback; this.records = []; observer = this; }
      observe(target, options) { assert.equal(target, status); assert.equal(options.characterDataOldValue, true); this.connected = true; }
      takeRecords() { return this.records.splice(0); }
      disconnect() { this.connected = false; this.records.length = 0; }
      flush() { if (this.connected && this.records.length) this.callback(this.takeRecords()); }
    }
    const wait = observeManualSave({ status, button, Observer, timeout: 123,
      setTimer(fn, ms) { timeoutMs = ms; timers.set(fn, ms); return fn; },
      clearTimer(id) { timers.delete(id); } });
    return { wait, status,
      click() { button.dispatchEvent(new Event('click')); },
      replace(text) {
        const oldText = status.textContent;
        status.textContent = text;
        observer.records.push({ type: 'childList', target: status,
          addedNodes: [{ textContent: text }], removedNodes: [{ textContent: oldText }] });
      },
      characters(text) {
        observer.records.push({ type: 'characterData', target: status, oldValue: status.textContent });
        status.textContent = text;
      },
      flush() { observer.flush(); },
      expire() { assert.equal(timeoutMs, 123); for (const fn of [...timers.keys()]) { timers.delete(fn); fn(); } },
      assertWatching() { assert.equal(observer.connected, true); assert.equal(listeners.size, 1); assert.equal(timers.size, 1); },
      assertClean() { assert.equal(observer.connected, false); assert.equal(listeners.size, 0); assert.equal(timers.size, 0); },
    };
  }
  async function test(label, fn) { await fn(); passed++; console.log('PASS ' + label); }
  const manual = '✔ Đã lưu nháp lúc 12:34:56', auto = 'Tự động lưu: 12:34:56';
  await test('manual success is latched before autosave replaces text in the same mutation batch', async () => {
    const h = harness();
    h.assertWatching(); h.click(); h.replace(manual); h.replace(auto); h.flush();
    assert.equal(h.status.textContent, auto);
    assert.equal(await h.wait.promise, manual);
    h.assertClean(); h.wait.cancel(); h.assertClean();
  });
  await test('autosave alone cannot satisfy the clicked manual wait, even with an old manual badge', async () => {
    const h = harness(manual);
    h.click(); h.replace(auto); h.flush(); h.assertWatching(); h.expire();
    await assert.rejects(h.wait.promise, /Timed out/); h.assertClean();
  });
  await test('a manual badge without the save-button click is not success', async () => {
    const h = harness();
    h.replace(manual); h.flush(); h.assertWatching(); h.expire();
    await assert.rejects(h.wait.promise, /Timed out/); h.assertClean();
  });
  await test('queued pre-click manual mutations are discarded', async () => {
    const h = harness();
    h.replace(manual); h.click(); h.replace(auto); h.flush(); h.assertWatching(); h.expire();
    await assert.rejects(h.wait.promise, /Timed out/); h.assertClean();
  });
  await test('character-data success survives a same-batch autosave overwrite', async () => {
    const h = harness();
    h.click(); h.characters(manual); h.characters(auto); h.flush();
    assert.equal(await h.wait.promise, manual); h.assertClean();
  });
  await test('old character-data values cannot masquerade as a new manual success', async () => {
    const h = harness(manual);
    h.click(); h.characters(auto); h.flush(); h.assertWatching(); h.expire();
    await assert.rejects(h.wait.promise, /Timed out/); h.assertClean();
  });
  await test('manual failure rejects and releases observer, click listener, and timer', async () => {
    const h = harness();
    h.click(); h.replace('⚠ Lưu nháp thất bại, hãy thử lại.'); h.flush();
    await assert.rejects(h.wait.promise, /Manual draft save failed/); h.assertClean();
  });
  await test('cancellation releases observer, click listener, and timer before a click', async () => {
    const h = harness();
    h.wait.cancel(); await assert.rejects(h.wait.promise, /cancelled/); h.assertClean();
    h.click(); h.replace(manual); h.flush(); h.assertClean();
  });
  console.log(`Manual draft-save helper: ${passed} passed, 0 failed (synthetic events only)`);
}

// The helper-only branch must precede all smoke-run setup, including mkdtemp.
if (process.argv.includes('--test-save-wait')) await testManualSaveWait();
else await runSmoke();

async function runSmoke() {
const root = fileURLToPath(new URL('../..', import.meta.url));
const fixturePath = path.join(root, 'backend/tests/workspace_output_fixture.php');
const php = 'C:/xampp/php/php.exe';
const chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const origin = 'http://127.0.0.1:8091';
const base = origin + '/AlgoJudge';
const cdpOrigin = 'http://127.0.0.1:9231';
const runDir = await mkdtemp(path.join(tmpdir(), 'algojudge-verify-workspace-'));
const schema = 'algojudge_test_workspace_' + new Date().toISOString().replace(/\D/g, '').slice(0, 17) + '_' + randomBytes(5).toString('hex');
const token = randomBytes(24).toString('hex');
const iniDir = path.join(runDir, 'php-conf');
const tempDir = path.join(runDir, 'temp');
const sessionDir = path.join(runDir, 'sessions');
const profileDir = path.join(runDir, 'chrome-profile');
const screenshotDir = path.join(runDir, 'screenshots');
for (const dir of [iniDir, tempDir, sessionDir, profileDir, screenshotDir]) await mkdir(dir);
const iniPath = path.join(iniDir, 'workspace-test.ini');
const phpErrorLog = path.join(runDir, 'php-errors.log');
const slash = value => value.replaceAll('\\', '/');
await writeFile(iniPath, [
  `auto_prepend_file="${slash(fixturePath)}"`,
  `sys_temp_dir="${slash(tempDir)}"`,
  `session.save_path="${slash(sessionDir)}"`,
  'log_errors=On', 'display_errors=On', 'error_reporting=32767',
  `error_log="${slash(phpErrorLog)}"`, '',
].join('\n'));
const env = { ...process.env, AJ_DB_HOST: '127.0.0.1', AJ_DB_PORT: '3307', AJ_DB_NAME: schema,
  AJ_DB_USER: 'root', AJ_DB_PASS: '', AJ_WORKSPACE_FIXTURE_TOKEN: token, PHP_INI_SCAN_DIR: iniDir,
  TEMP: tempDir, TMP: tempDir, PYTHONIOENCODING: 'utf-8' };
const report = { schema, runDir, screenshotDir, profileDir, phpErrorLog,
  driver: 'Native Node CDP fallback; chromium-cli absent; no installation/download',
  limitation: 'Monaco is not installed locally and its external CDN is blocked. An explicitly labeled textarea-backed Monaco API test double is injected only for editor setup. Production workspace.js, Alpine, API, real Python processes, judge, polling and result DOM are unchanged.',
  safety: { workingSchemaAccessed: false, envFileRead: false, externalNetworkAllowed: false, databaseDropped: false },
  passed: 0, failed: 0, checks: [], screenshots: [], commands: [], uncaughtJs: [], consoleErrors: [],
  httpErrors: [], blockedExternalRequests: [], phpErrors: [], layout: {}, processIds: {}, stoppedOwnedProcesses: [] };
let server, browser, ws, fixture;
let commandId = 0;
const pending = new Map(), eventWaiters = new Map();
let command, evaluate;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(ok, label, detail, diagnostic = false) {
  report[ok ? 'passed' : 'failed']++;
  report.checks.push({ ok: !!ok, label, category: diagnostic ? 'layout-diagnostic' : 'hard', ...(detail === undefined ? {} : { detail }) });
  console.log((ok ? 'PASS ' : 'FAIL ') + label + (detail === undefined ? '' : ' ' + JSON.stringify(detail)));
  return !!ok;
}
function ownedProcess(executable, args, logName, options = {}) {
  report.commands.push({ executable, args, env: { AJ_DB_HOST: env.AJ_DB_HOST, AJ_DB_PORT: env.AJ_DB_PORT, AJ_DB_NAME: env.AJ_DB_NAME, AJ_DB_USER: 'root', AJ_DB_PASS: '(empty synthetic test credential)', PHP_INI_SCAN_DIR: iniDir, TEMP: tempDir, TMP: tempDir }, cwd: root });
  const child = spawn(executable, args, { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'], ...options });
  child.stdout.on('data', data => appendFile(path.join(runDir, logName + '.stdout.log'), data).catch(() => {}));
  child.stderr.on('data', data => appendFile(path.join(runDir, logName + '.stderr.log'), data).catch(() => {}));
  return child;
}
async function execute(executable, args, logName) {
  const child = ownedProcess(executable, args, logName);
  let stdout = '', stderr = '';
  child.stdout.on('data', data => { stdout += data; });
  child.stderr.on('data', data => { stderr += data; });
  const code = await new Promise((resolve, reject) => { child.once('error', reject); child.once('exit', resolve); });
  assert.equal(code, 0, `${logName} exited ${code}: ${stderr}`);
  return stdout;
}
async function portIsFree(port) {
  // Bind both loopback families before starting; never stop another port's owner.
  for (const host of ['127.0.0.1', '::1']) {
    await new Promise((resolve, reject) => {
      const probe = createServer();
      probe.once('error', reject);
      probe.listen({ host, port, exclusive: true }, () => probe.close(resolve));
    });
  }
}
async function waitFor(fn, label, timeout = 20000) {
  const end = Date.now() + timeout;
  let last;
  while (Date.now() < end) {
    try { const result = await fn(); if (result) return result; } catch (error) { last = error.message; }
    await delay(100);
  }
  throw new Error(`Timed out waiting for ${label}${last ? ': ' + last : ''}`);
}
async function waitHttp(url) {
  return waitFor(async () => { const r = await fetch(url, { signal: AbortSignal.timeout(1500) }); return r.ok; }, url, 20000);
}
function event(name, timeout = 20000) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { eventWaiters.delete(name); reject(new Error('CDP event timeout: ' + name)); }, timeout);
    eventWaiters.set(name, params => { clearTimeout(timer); resolve(params); });
  });
}
async function settle() {
  await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
  await evaluate('Promise.all(document.getAnimations().map(a => a.finished.catch(() => {})))');
}
async function navigate(relative) {
  const loaded = event('Page.loadEventFired');
  const r = await command('Page.navigate', { url: base + relative });
  assert.ok(!r.errorText, r.errorText);
  await loaded;
  await settle();
}
async function click(selector, { navigates = false } = {}) {
  // Real pointer input, including visibility/occlusion checks, not invoking workspace handlers directly.
  const position = await evaluate(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (!e) throw new Error('Missing click target'); e.scrollIntoView({block:'nearest',inline:'nearest'}); const r=e.getBoundingClientRect(); const x=r.left+r.width/2,y=r.top+r.height/2; const hit=document.elementFromPoint(x,y); return {x,y,visible:!!hit&&(hit===e||e.contains(hit))}; })()`);
  assert.ok(position.visible, 'Pointer target is not visible: ' + selector);
  await command('Input.dispatchMouseEvent', { type: 'mousePressed', x: position.x, y: position.y, button: 'left', clickCount: 1 });
  await command('Input.dispatchMouseEvent', { type: 'mouseReleased', x: position.x, y: position.y, button: 'left', clickCount: 1 });
  if (!navigates) await settle();
}
async function fill(selector, text) {
  await evaluate(`(() => { const e=document.querySelector(${JSON.stringify(selector)}); e.value=${JSON.stringify(text)}; e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); })()`);
}
async function setSource(source) { await fill('#workspace-test-editor', source); }
async function saveSyntheticDraft(source, label) {
  await setSource(source);
  // Install before real pointer input; only new, click-gated manual text counts.
  await evaluate(`(() => {
    window.__workspaceManualSaveWait?.cancel();
    window.__workspaceManualSaveWait = (${observeManualSave.toString()})({
      status: document.querySelector('#draft-status'),
      button: document.querySelector(${JSON.stringify(saveButton)})
    });
  })()`);
  try {
    await click(saveButton);
    await evaluate('window.__workspaceManualSaveWait.promise');
    check(true, label + ': real save button reported successful draft save');
    const persisted = await evaluate(`fetch('/AlgoJudge/backend/api/get_draft.php?problem_id='+${Number(fixture.problem_id)}, {credentials:'same-origin', cache:'no-store'}).then(async r => ({status:r.status, ...await r.json()}))`);
    check(persisted.status === 200 && persisted.ok === true && persisted.drafts?.python === source,
      label + ': get_draft API retains exact saved source');
  } finally {
    await evaluate('window.__workspaceManualSaveWait?.cancel(); delete window.__workspaceManualSaveWait');
  }
}
async function viewport(width, height, mobile = false) {
  await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
  await settle();
  check(await evaluate(`Math.abs(innerWidth - ${width}) <= 1`), `${width}px viewport applied`);
}
async function screenshot(name, selector) {
  await settle();
  let options = { format: 'png', captureBeyondViewport: false };
  if (selector) {
    const r = await evaluate(`(() => {const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height};})()`);
    if (r.width > 0 && r.height > 0) options = { format: 'png', captureBeyondViewport: true, clip: { ...r, scale: 1 } };
  }
  const image = await command('Page.captureScreenshot', options);
  const file = path.join(screenshotDir, name + '.png');
  await writeFile(file, Buffer.from(image.data, 'base64'));
  report.screenshots.push(file);
  return file;
}
async function getStatus(id) {
  return evaluate(`fetch('/AlgoJudge/backend/api/submission_status.php?submission_id='+${Number(id)},{credentials:'same-origin'}).then(async r => ({status:r.status,...await r.json()}))`);
}
const runButton = '.ide-toolbar button[x-on\\:click="runCustomCode()"]';
const saveButton = '.ide-toolbar button[x-on\\:click="saveDraft(true)"]';
const submitButton = '.ide-toolbar button[x-on\\:click="submitSolution(false)"]';
async function customRun(source, stdin, label) {
  await setSource(source);
  await fill('#custom-stdin', stdin);
  await click(runButton);
  await waitFor(() => evaluate(`!['ĐANG CHẠY THỬ...','Chờ lệnh'].includes(document.querySelector('#console-badge').innerText)`), label + ' custom completion', 40000);
  const result = await evaluate(`({badge:document.querySelector('#console-badge').innerText,text:document.querySelector('#console-output').innerText,html:document.querySelector('#console-output').innerHTML})`);
  check(result.badge.startsWith('OK ('), label + ': real custom execution is OK, not an AC judgment', result.badge);
  return result;
}
async function submit(source, label, previousId = 0) {
  await setSource(source);
  await click(submitButton);
  await waitFor(() => evaluate(`(() => {const s=document.querySelector('.submission-result-summary strong');const id=Number(s?.textContent.match(/#(\\d+)/)?.[1]);return id>${Number(previousId)}&&!['QUEUED','RUNNING'].includes(document.querySelector('#console-badge').innerText);})()`), label + ' judged and rendered', 70000);
  const id = await evaluate(`Number(document.querySelector('.submission-result-summary strong').textContent.match(/#(\\d+)/)[1])`);
  const result = await getStatus(id);
  check(result.status === 200 && result.ok, label + ': authenticated real submission-status response');
  check(result.submission.source_code === source, label + ': API source matches exact submitted text');
  return result;
}
function checkPrivacy(result, label) {
  const hidden = result.active_run_cases.filter(tc => !tc.is_public);
  const hiddenInputs = ['0000000000000000000000000000', '0000000000000000000000000007', '0000000000000000000000000013'];
  check(hidden.length === 3 && hidden.every(tc => tc.stdin_preview === '[Dữ liệu chấm kín — Đã ẩn bảo mật]' && tc.expected_preview === '[Đáp án chấm kín — Đã ẩn bảo mật]' && tc.actual_preview === '[Đầu ra bị ẩn trên bộ test kín]' && tc.actual_truncated === false && !Object.hasOwn(tc, 'runtime_error')), label + ': API masks all three hidden cases and omits runtime errors');
  check(hiddenInputs.every(value => !JSON.stringify(result).includes(value)), label + ': unique hidden stdin absent from API payload');
}
async function publicComparison(label, expectedOutputs, actualOutputs, payload) {
  const blocks = await evaluate(`Array.from(document.querySelectorAll('#console-output .submission-case')).map(d=>({open:d.open,input:d.querySelector('.submission-output-text')?.textContent,expected:d.querySelector('.submission-output-expected pre')?.textContent,actual:d.querySelector('.submission-output-actual pre')?.textContent,labels:Array.from(d.querySelectorAll('.submission-output-label')).map(e=>e.textContent)}))`);
  check(blocks.length === 2, label + ': exactly two public comparison cards');
  check(JSON.stringify(blocks.map(b => b.input)) === JSON.stringify(['5\n', '6\n']), label + ': public stdin is literal and newline-preserving');
  check(JSON.stringify(blocks.map(b => b.expected)) === JSON.stringify(expectedOutputs), label + ': expected-output blocks show exact response text');
  const apiOutputs = payload.active_run_cases.filter(tc => tc.is_public).map(tc => tc.actual_preview);
  check(JSON.stringify(blocks.map(b => b.actual)) === JSON.stringify(apiOutputs), label + ': actual-output blocks match exact API response text including CRLF');
  check(JSON.stringify(apiOutputs.map(text => text.replaceAll('\r\n', '\n'))) === JSON.stringify(actualOutputs), label + ': actual execution output has expected literal content/newlines');
  check(blocks.every(b => JSON.stringify(b.labels) === JSON.stringify(['Đầu vào (stdin)', 'Đáp án mong đợi', 'Đầu ra của bạn (stdout)'])), label + ': input/expected/actual labels are distinct');
  check(await evaluate(`document.querySelectorAll('#console-output .submission-case-private').length===3&&Array.from(document.querySelectorAll('#console-output .submission-case-private')).every(e=>e.childElementCount===0&&e.textContent.endsWith('Dữ liệu đã ẩn.'))`), label + ': DOM renders only labels for three private cases');
  return blocks;
}
async function layout(label) {
  const result = await evaluate(`(() => { const box=e=>{const r=e.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}};const outer=document.querySelector('.ide-right'),left=document.querySelector('.ide-left');return {viewport:{width:innerWidth,height:innerHeight},pageWidth:document.documentElement.scrollWidth,clientWidth:document.documentElement.clientWidth,left:box(left),right:box(outer),toolbar:box(document.querySelector('.ide-toolbar')),console:box(document.querySelector('.ide-console')),output:box(document.querySelector('#console-output')),buttons:Array.from(document.querySelectorAll('.ide-toolbar button')).map(e=>({text:e.innerText,...box(e)})),comparisonWidth:document.querySelector('.submission-output-comparison')?.getBoundingClientRect().width};})()`);
  report.layout[label] = result;
  check(result.pageWidth <= result.clientWidth + 1, label + ': no horizontal document overflow', { pageWidth: result.pageWidth, clientWidth: result.clientWidth });
  check(result.buttons.every(b => b.x >= Math.max(0, result.right.x) - 1 && b.right <= Math.min(result.right.right, result.viewport.width) + 1 && b.y >= result.toolbar.y - 1 && b.bottom <= result.toolbar.bottom + 1), label + ': all toolbar buttons fit in unclipped editor panel and viewport width', result.buttons, true);
  check(result.right.width >= (result.viewport.width < 1200 ? 320 : 500), label + ': editor/results retain a usable panel width', { rightWidth: result.right.width }, true);
  if (result.viewport.width <= 900) {
    check(result.right.y >= result.left.bottom - 1 && result.left.width >= result.clientWidth - 1 && result.right.width >= result.clientWidth - 1, label + ': workspace is a full-width single column', { left: result.left, right: result.right }, true);
    check(result.buttons.every(b => b.height >= 44), label + ': all mobile toolbar controls have at least 44px touch height', result.buttons.map(b => b.height), true);
  }
  return result;
}
async function reachableToolbar(label) {
  const hits = await evaluate(`Array.from(document.querySelectorAll('.ide-toolbar button')).map(e=>{e.scrollIntoView({block:'center',inline:'nearest'});const r=e.getBoundingClientRect(),x=r.x+r.width/2,y=r.y+r.height/2,hit=document.elementFromPoint(x,y);return {text:e.innerText,visible:r.x>=0&&r.right<=innerWidth&&r.y>=0&&r.bottom<=innerHeight&&!!hit&&(hit===e||e.contains(hit))};})`);
  check(hits.every(h => h.visible), label + ': every toolbar button becomes visible and pointer-reachable after scrolling', hits, true);
  await evaluate('scrollTo(0,0)');
  await settle();
}

try {
  assert.match(schema, /^algojudge_test_workspace_[a-zA-Z0-9_]+$/);
  await portIsFree(8091);
  await portIsFree(9231);
  check(true, '8091 and 9231 have no pre-existing IPv4/IPv6 loopback listener');
  fixture = JSON.parse((await execute(php, [fixturePath, 'seed'], 'fixture-seed')).trim());
  assert.equal(fixture.schema, schema);
  report.fixture = { user_id: fixture.user_id, problem_id: fixture.problem_id, version_id: fixture.version_id, username: fixture.username };
  check(true, 'Created a NEW guarded synthetic schema: one learner, factorial problem, two public/three hidden cases and wrong-format draft');
  // Prove the seed refuses an existing schema; this command must fail before any write.
  const refusal = ownedProcess(php, [fixturePath, 'seed'], 'fixture-reseed-refusal');
  let refusalOutput = '';
  refusal.stderr.on('data', d => { refusalOutput += d; });
  refusal.stdout.on('data', d => { refusalOutput += d; });
  const refusalCode = await new Promise((resolve, reject) => { refusal.once('error', reject); refusal.once('exit', resolve); });
  check(refusalCode !== 0 && refusalOutput.includes('Refusing to overwrite existing schema'), 'Fixture refuses reuse of the existing schema before mutation');
  // The intentional refusal is logged separately from PHP app errors.
  try { const intentional = await readFile(phpErrorLog, 'utf8'); await writeFile(path.join(runDir, 'fixture-expected-refusal.log'), intentional); } catch {}
  // Keep the app's error log separate; do not delete the refusal evidence.
  await writeFile(iniPath, (await readFile(iniPath, 'utf8')).replace(slash(phpErrorLog), slash(path.join(runDir, 'php-app-errors.log'))));
  report.phpErrorLog = path.join(runDir, 'php-app-errors.log');
  server = ownedProcess(php, ['-S', '127.0.0.1:8091', '-t', 'C:/xampp/htdocs'], 'php-server');
  report.processIds.php = server.pid;
  server.once('error', error => { report.phpErrors.push(error.message); });
  await waitHttp(base + '/frontend/auth/login.php');
  browser = ownedProcess(chrome, ['--headless=new', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=9231', '--user-data-dir=' + profileDir,
    '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-component-update', '--disable-sync',
    '--disable-domain-reliability', '--metrics-recording-only', '--disable-extensions', '--disable-default-apps', '--no-pings',
    '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1', '--window-size=1440,1000', 'about:blank'], 'chrome');
  report.processIds.chrome = browser.pid;
  browser.once('error', error => { report.consoleErrors.push(error.message); });
  await waitHttp(cdpOrigin + '/json/version');
  const tabs = await (await fetch(cdpOrigin + '/json')).json();
  const tab = tabs.find(t => t.type === 'page');
  assert.ok(tab?.webSocketDebuggerUrl, 'Disposable Chrome has a page CDP target');
  ws = new WebSocket(tab.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  command = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++commandId;
    const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP command timeout: ' + method)); }, 75000);
    pending.set(id, { resolve: r => { clearTimeout(timer); resolve(r); }, reject: e => { clearTimeout(timer); reject(e); } });
    ws.send(JSON.stringify({ id, method, params }));
  });
  evaluate = async expression => {
    const r = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
    return r.result.value;
  };
  ws.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (message.id) {
      const item = pending.get(message.id);
      if (item) { pending.delete(message.id); message.error ? item.reject(new Error(JSON.stringify(message.error))) : item.resolve(message.result); }
    }
    const p = message.params;
    if (message.method === 'Runtime.exceptionThrown') report.uncaughtJs.push(p.exceptionDetails.exception?.description || p.exceptionDetails.text);
    if (message.method === 'Runtime.consoleAPICalled' && p.type === 'error') report.consoleErrors.push(p.args.map(a => a.value ?? a.description).join(' '));
    if (message.method === 'Network.responseReceived' && p.response.status >= 400) report.httpErrors.push({ status: p.response.status, url: p.response.url });
    if (message.method === 'Fetch.requestPaused') {
      const u = new URL(p.request.url);
      const external = ['http:', 'https:'].includes(u.protocol) && !['127.0.0.1', 'localhost', '[::1]'].includes(u.hostname);
      if (external) report.blockedExternalRequests.push(p.request.url);
      command(external ? 'Fetch.failRequest' : 'Fetch.continueRequest', external ? { requestId: p.requestId, errorReason: 'BlockedByClient' } : { requestId: p.requestId })
        .catch(error => report.consoleErrors.push('CDP interception: ' + error.message));
    }
    if (message.method && eventWaiters.has(message.method)) { eventWaiters.get(message.method)(p); eventWaiters.delete(message.method); }
  };
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
  // Only editor API compatibility is doubled. This never intercepts API responses or workspace modules.
  await command('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
    window.__stdoutExecuted = false;
    window.__workspaceEditorDouble = true;
    window.monaco = { editor: {
      create(container, options) {
        const note = document.createElement('div');
        note.textContent = 'Editor test double — Monaco CDN blocked';
        note.style.cssText='position:absolute;top:4px;left:12px;color:#f9e2af;font:11px system-ui;pointer-events:none';
        const input = document.createElement('textarea'); input.id='workspace-test-editor';
        input.setAttribute('aria-label','Synthetic textarea editor test double, not Monaco');
        input.spellcheck=false;input.wrap='off';input.value=options.value||'';
        input.style.cssText='box-sizing:border-box;width:100%;height:100%;min-height:300px;resize:none;border:0;background:#1e1e2e;color:#cdd6f4;padding:26px 12px 12px;font:14px/1.6 Consolas,monospace;outline:none;';
        container.style.position='relative';container.append(note,input);
        const listeners=[];input.addEventListener('input',()=>listeners.forEach(fn=>fn()));
        const model={language:options.language};
        const editor={getValue:()=>input.value,setValue:v=>{input.value=v;listeners.forEach(fn=>fn());},getModel:()=>model,onDidChangeModelContent:fn=>{listeners.push(fn);return{dispose(){}};},layout(){},focus(){input.focus();},dispose(){}};
        window.__workspaceTestEditor=editor;return editor;
      },setModelLanguage(model,lang){model.language=lang;}
    }};
  })();` });
  await viewport(1440, 1000);
  await navigate('/frontend/auth/login.php');
  await fill('[name=username]', fixture.username);
  await fill('[name=password]', fixture.password);
  const loggedIn = event('Page.loadEventFired');
  await click('form button[type=submit]', { navigates: true });
  await loggedIn;
  await settle();
  check((await evaluate('location.pathname')) === '/AlgoJudge/frontend/index.php', 'Real CSRF-protected login succeeds with synthetic credentials');
  await navigate('/frontend/workspace.php?id=' + fixture.problem_id);
  await waitFor(() => evaluate('!!window.__workspaceTestEditor'), 'workspace editor initialization');
  check(await evaluate(`document.querySelector('#lang-select').value==='python'&&document.querySelector('#workspace-test-editor').value===${JSON.stringify(fixture.wrong_source)}`), 'Workspace initializes Python with the seeded wrong-format draft');
  check(await evaluate(`!document.querySelector('.workspace-output-guide')&&!document.querySelector('#output-guide-title')&&!document.querySelector('#tab-desc').textContent.includes('Quy tắc nhập / xuất khi nộp bài')`), 'Workspace omits the redundant input/output guide');
  await screenshot('workspace-description-desktop');
  const trial = await customRun(fixture.wrong_source, '5\n', 'Wrong prompt/equation');
  check(trial.text === '[Đầu ra Stdout]:\nNhập N: 5! = 120\n\n', 'Custom run displays prompt/equation verbatim without claiming correct answer', trial.text);
  await screenshot('workspace-custom-desktop');
  const wrong = await submit(fixture.wrong_source, 'Wrong prompt/equation');
  report.wrongSubmission = wrong.submission;
  check(wrong.submission.final_verdict === 'WA' && wrong.submission.pass_count === 0 && wrong.submission.total_cases === 5 && wrong.submission.final_score === 0, 'Real judge: extra prompt/equation is WA, 0/5 cases, score 0');
  checkPrivacy(wrong, 'Wrong prompt/equation');
  const wrongBlocks = await publicComparison('WA', ['120\n', '720\n'], ['Nhập N: 5! = 120\n', 'Nhập N: 6! = 720\n'], wrong);
  check(wrongBlocks.every(b => b.open), 'WA public cards expand automatically');
  check(await evaluate(`document.querySelector('.submission-result-hint').textContent.includes('không in lời nhắc')&&document.querySelector('#console-badge').textContent==='WA (0/5 test)'`), 'WA summary, non-formatting hint, and 0/5 badge render');
  await layout('desktop-wa');
  await screenshot('workspace-wa-desktop');
  await evaluate(`document.querySelector('#console-output').scrollTop=document.querySelector('.submission-case').offsetTop-document.querySelector('#console-output').offsetTop`);
  await screenshot('workspace-wa-comparison-desktop', '#console-output');
  await evaluate(`document.querySelector('#console-output').scrollTop=document.querySelector('#console-output').scrollHeight`);
  await screenshot('workspace-hidden-labels-desktop', '#console-output');
  await viewport(1100, 900);
  await layout('narrow-desktop-wa');
  await reachableToolbar('narrow-desktop-wa');
  await screenshot('workspace-wa-narrow-desktop');
  await viewport(390, 844, true);
  await evaluate(`document.querySelector('#console-output').scrollTop=0`);
  await layout('mobile-wa');
  await reachableToolbar('mobile-wa');
  await screenshot('workspace-wa-mobile');
  await screenshot('workspace-wa-mobile-output', '#console-output');
  await evaluate(`document.querySelector('#console-output').scrollIntoView({block:'center'});document.querySelector('#console-output').scrollTop=0`);
  await screenshot('workspace-wa-mobile-console');
  await evaluate(`(() => {const o=document.querySelector('#console-output'),c=document.querySelector('.submission-case');o.scrollTop=c.offsetTop-o.offsetTop;})()`);
  await screenshot('workspace-wa-mobile-comparison', '#console-output');
  check(await evaluate(`getComputedStyle(document.querySelector('.submission-output-comparison')).gridTemplateColumns.split(' ').length===1`), 'Mobile WA comparison stacks expected and actual output in one column', undefined, true);
  await evaluate('scrollTo(0,0)');
  await viewport(1440, 1000);
  const corrected = fixture.wrong_source.replace('input("Nhập N: ")', 'input()').replace('print(f"{n}! = {factorial}")', 'print(factorial)');
  check(corrected.split('\n').slice(1, 4).join('\n') === fixture.wrong_source.split('\n').slice(1, 4).join('\n'), 'Correction preserves factorial algorithm; only two I/O lines change');
  await saveSyntheticDraft(corrected, 'corrected draft save');
  const accepted = await submit(corrected, 'Corrected reference', wrong.submission.id);
  report.acceptedSubmission = accepted.submission;
  check(accepted.submission.final_verdict === 'AC' && accepted.submission.pass_count === 5 && accepted.submission.total_cases === 5 && accepted.submission.final_score === 100, 'Real judge: corrected I/O reference is AC, 5/5 cases, score 100');
  checkPrivacy(accepted, 'Corrected reference');
  const acceptedBlocks = await publicComparison('AC', ['120\n', '720\n'], ['120\n', '720\n'], accepted);
  check(acceptedBlocks.every(b => !b.open), 'Accepted public comparison cards default collapsed');
  check(await evaluate(`!document.querySelector('.submission-result-hint')&&document.querySelector('#console-badge').textContent==='AC (5/5 test)'`), 'Accepted summary shows 5/5 and no WA-only hint');
  await screenshot('workspace-ac-desktop');
  await screenshot('workspace-ac-collapsed-desktop', '#console-output');
  await click('#console-output .submission-case summary');
  check(await evaluate(`document.querySelector('#console-output .submission-case').open`), 'Real pointer click expands an accepted public comparison card');
  await screenshot('workspace-ac-expanded-desktop', '#console-output');
  await viewport(390, 844, true);
  await layout('mobile-ac');
  await reachableToolbar('mobile-ac');
  await screenshot('workspace-ac-mobile');
  await evaluate(`document.querySelector('.submission-case').open=false;document.querySelector('#console-output').scrollTop=0;document.querySelector('#console-output').scrollIntoView({block:'center'})`);
  await screenshot('workspace-ac-mobile-console');
  await screenshot('workspace-ac-collapsed-mobile', '#console-output');
  await evaluate('scrollTo(0,0)');
  await viewport(1440, 1000);

  const literal = 'Tiếng Việt: <img src=x onerror="window.__stdoutExecuted=true"> & <script>window.__stdoutExecuted=true</script>';
  const literalLine2 = 'Dòng 2: <b>không phải HTML</b>';
  const multilineSource = corrected.replace('print(factorial)', `print(${JSON.stringify(literal)})\nprint(${JSON.stringify(literalLine2)})\nprint(factorial)`);
  const multilineTrial = await customRun(multilineSource, '5\n', 'Unicode/HTML-like multiline stdout');
  check(multilineTrial.text === '[Đầu ra Stdout]:\n' + literal + '\n' + literalLine2 + '\n120\n\n', 'Custom stdout preserves Unicode, literal markup, and multiple lines');
  check(await evaluate(`window.__stdoutExecuted===false&&!document.querySelector('#console-output img,#console-output script,#console-output b')`), 'HTML-like custom stdout cannot create elements or execute a handler');
  const multiline = await submit(multilineSource, 'Unicode/HTML-like multiline', accepted.submission.id);
  check(multiline.submission.final_verdict === 'WA' && multiline.submission.pass_count === 0 && multiline.submission.total_cases === 5, 'Real judge rejects literal extra Unicode/HTML-like multiline output, 0/5');
  checkPrivacy(multiline, 'Unicode/HTML-like multiline');
  await publicComparison('Unicode/multiline', ['120\n', '720\n'], [literal + '\n' + literalLine2 + '\n120\n', literal + '\n' + literalLine2 + '\n720\n'], multiline);
  check(await evaluate(`window.__stdoutExecuted===false&&!document.querySelector('#console-output img,#console-output script,#console-output b')`), 'Comparison stdout is text-only: no injected elements or executed handlers');
  await screenshot('workspace-literal-multiline-desktop', '#console-output');
  const longSource = corrected.replace('print(factorial)', `print(${JSON.stringify(literal)})\nprint(${JSON.stringify(literalLine2)})\nprint('X' * 420)\nprint(factorial)`);
  const longResult = await submit(longSource, 'Long stdout', multiline.submission.id);
  report.longSubmissionId = longResult.submission.id;
  check(longResult.submission.final_verdict === 'WA' && longResult.submission.pass_count === 0 && longResult.submission.total_cases === 5, 'Real judge rejects long extra output, 0/5');
  checkPrivacy(longResult, 'Long stdout');
  const longCases = longResult.active_run_cases.filter(tc => tc.is_public);
  check(longCases.length === 2 && longCases.every(tc => tc.actual_truncated && Array.from(tc.actual_preview).length === 300), 'Live API marks both public previews truncated at exactly 300 Unicode characters');
  check(await evaluate(`document.querySelectorAll('.submission-preview-note').length===2&&Array.from(document.querySelectorAll('.submission-preview-note')).every(e=>e.textContent.includes('300 ký tự đầu')&&e.textContent.includes('toàn bộ đầu ra'))`), 'Live DOM includes accurate 300-character preview/full-judge notice on both public cases');
  check(await evaluate(`document.querySelector('.submission-output-actual pre').textContent===${JSON.stringify(longCases[0].actual_preview)}&&window.__stdoutExecuted===false&&!document.querySelector('#console-output img,#console-output script,#console-output b')`), 'Truncated preview is exact and remains safe literal text');
  await evaluate(`(() => {const o=document.querySelector('#console-output'),n=document.querySelector('.submission-preview-note');o.scrollTop=n.offsetTop-o.offsetTop-110;})()`);
  await screenshot('workspace-truncation-desktop', '#console-output');
  // Restore only this synthetic learner's corrected draft; historical submissions are never edited.
  await saveSyntheticDraft(corrected, 'final corrected draft save');
  await navigate('/frontend/workspace.php?id=' + fixture.problem_id);
  await waitFor(() => evaluate('!!window.__workspaceTestEditor'), 'reloaded saved draft');
  check(await evaluate(`document.querySelector('#workspace-test-editor').value===${JSON.stringify(corrected)}`), 'Corrected draft survives a real page reload');
  const historical = await getStatus(wrong.submission.id);
  check(historical.submission.source_code === fixture.wrong_source && historical.submission.final_verdict === 'WA' && historical.submission.pass_count === 0 && historical.submission.total_cases === 5, 'Historical failed source/verdict remains unchanged after corrected draft and AC');
  checkPrivacy(historical, 'Historical failed submission');
  await navigate('/frontend/submissions.php?problem_id=' + fixture.problem_id);
  await waitFor(() => evaluate(`Array.from(document.querySelectorAll('tbody tr')).some(tr=>tr.querySelector('td')?.innerText===${JSON.stringify('#' + wrong.submission.id)})`), 'historical list');
  const historicalButton = await evaluate(`(() => {const row=Array.from(document.querySelectorAll('tbody tr')).find(tr=>tr.querySelector('td')?.innerText===${JSON.stringify('#' + wrong.submission.id)});row.id='workspace-historical-row';return !!row.querySelector('button');})()`);
  check(historicalButton, 'Real submissions history retains the original failed entry');
  await click('#workspace-historical-row button');
  await waitFor(() => evaluate(`document.querySelector('#code-modal').style.display==='flex'&&document.querySelector('#modal-code').textContent.length>0`), 'historical source modal');
  check(await evaluate(`document.querySelector('#modal-code').innerText===${JSON.stringify(fixture.wrong_source)}&&document.querySelector('#modal-cases').innerText.includes('WA')`), 'Actual history modal shows the original prompt/equation source and WA');
  await screenshot('historical-wa-source-desktop');
  const inspect = JSON.parse((await execute(php, [fixturePath, 'inspect'], 'fixture-inspect')).trim());
  report.databaseInspection = inspect;
  check(inspect.counts.users === 1 && inspect.counts.problems === 1 && inspect.counts.test_cases === 5, 'Final isolated schema still contains only one synthetic user/problem and five cases');
  check(inspect.submissions.length === 4 && inspect.submissions[0].source_code === fixture.wrong_source && inspect.submissions[0].final_verdict === 'WA' && inspect.submissions[1].source_code === corrected && inspect.submissions[1].final_verdict === 'AC', 'Database evidence: all four submitted versions retained, original WA and corrected AC immutable');
  check(inspect.drafts.length === 1 && inspect.drafts[0].source_code === corrected, 'Database evidence: only the synthetic draft now has corrected I/O');
  check(inspect.custom_runs.length === 2 && inspect.custom_runs.every(r => r.status === 'DONE' && r.verdict === 'OK'), 'Database evidence: both custom runs actually completed through the executor');
  check(report.uncaughtJs.length === 0, 'No uncaught JavaScript exceptions', report.uncaughtJs);
  check(report.consoleErrors.length === 0, 'No browser console.error calls', report.consoleErrors);
  check(report.httpErrors.length === 0, 'No HTTP 4xx/5xx responses from exercised app requests', report.httpErrors);
  check(report.blockedExternalRequests.length > 0 && report.blockedExternalRequests.every(u => u.startsWith('https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/')), 'CDP blocked all observed external requests (Monaco CDN only)', [...new Set(report.blockedExternalRequests)]);
} catch (error) {
  check(false, 'Fatal smoke-test step', error.stack || error.message);
  if (command && ws?.readyState === WebSocket.OPEN) {
    try { await screenshot('failure-state'); } catch (shotError) { report.screenshotError = shotError.message; }
  }
} finally {
  if (command && ws?.readyState === WebSocket.OPEN) {
    try { await command('Browser.close'); } catch {}
    try { ws.close(); } catch {}
  }
  for (const [label, child] of [['chrome', browser], ['php', server]]) {
    if (!child) continue;
    if (label === 'chrome') {
      await Promise.race([new Promise(resolve => child.exitCode !== null ? resolve() : child.once('exit', resolve)), delay(5000)]);
    }
    if (child.exitCode === null) child.kill(); // Only an owned child; never kill by port or executable name.
    await Promise.race([new Promise(resolve => child.exitCode !== null ? resolve() : child.once('exit', resolve)), delay(5000)]);
    report.stoppedOwnedProcesses.push({ label, pid: child.pid, stopped: child.exitCode !== null || child.signalCode !== null });
  }
  try {
    const phpText = await readFile(report.phpErrorLog, 'utf8');
    report.phpErrors.push(...phpText.split(/\r?\n/).filter(Boolean));
  } catch (error) { if (error.code !== 'ENOENT') report.phpErrors.push(error.message); }
  try {
    const text = await readFile(path.join(runDir, 'php-server.stderr.log'), 'utf8');
    const errors = text.split(/\r?\n/).filter(line => /PHP (?:Warning|Fatal error|Parse error|Notice|Deprecated)|Uncaught|\[500\]/i.test(line));
    report.phpErrors.push(...errors);
  } catch {}
  check(report.phpErrors.length === 0, 'No PHP application warnings/errors', report.phpErrors);
  check(report.stoppedOwnedProcesses.length === 2 && report.stoppedOwnedProcesses.every(p => p.stopped), 'Stopped only the PHP/Chrome processes owned by this smoke run', report.stoppedOwnedProcesses);
  // This report is test output, not a hand-written findings file; test DB/profile are deliberately retained.
  const resultPath = path.join(runDir, 'result.json');
  report.resultPath = resultPath;
  report.hardFailures = report.checks.filter(c => !c.ok && c.category === 'hard').length;
  report.layoutDiagnosticFailures = report.checks.filter(c => !c.ok && c.category === 'layout-diagnostic').length;
  await writeFile(resultPath, JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ passed: report.passed, failed: report.failed, hardFailures: report.hardFailures, layoutDiagnosticFailures: report.layoutDiagnosticFailures, schema, resultPath, screenshotDir, stoppedOwnedProcesses: report.stoppedOwnedProcesses }, null, 2));
  process.exitCode = report.failed ? 1 : 0;
}
}
