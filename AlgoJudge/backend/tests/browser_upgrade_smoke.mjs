// Uses Chrome DevTools on a disposable profile; no npm/browser download required.
import assert from 'node:assert/strict';
import {writeFile} from 'node:fs/promises';
const out = process.env.AJ_TEST_SCREENSHOT_DIR;
assert.ok(out && out.includes('algojudge-verify-'), 'Use a disposable screenshot directory');
const tabs = await (await fetch('http://127.0.0.1:9228/json')).json();
const ws = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
await new Promise((resolve,reject) => { ws.onopen = resolve; ws.onerror = reject; });
let seq = 0;
const pending = new Map(), listeners = new Map(), errors = [];
ws.onmessage = ({data}) => {
  const m = JSON.parse(data);
  if (m.id) { const item = pending.get(m.id); if (item) { pending.delete(m.id); m.error ? item.reject(m.error) : item.resolve(m.result); } }
  if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
  if (m.method && listeners.has(m.method)) { listeners.get(m.method)(m.params); listeners.delete(m.method); }
};
function cmd(method, params = {}) { return new Promise((resolve,reject) => { const id = ++seq; pending.set(id,{resolve,reject}); ws.send(JSON.stringify({id,method,params})); }); }
function event(name) { return new Promise((resolve,reject) => { const timeout = setTimeout(() => {listeners.delete(name);reject(new Error('Timeout '+name));},15000); listeners.set(name,p=>{clearTimeout(timeout);resolve(p);}); }); }
async function evaluate(expression) { const r = await cmd('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); if(r.exceptionDetails) throw new Error(r.exceptionDetails.text); return r.result.value; }
await cmd('Page.enable'); await cmd('Runtime.enable');
await cmd('Emulation.setDeviceMetricsOverride',{width:1365,height:1000,deviceScaleFactor:1,mobile:false});
// Avoid analytics/CDNs while exercising this local UI.
await cmd('Network.enable'); await cmd('Network.setBlockedURLs',{urls:['*cdnjs.cloudflare.com*','*fonts.googleapis.com*','*fonts.gstatic.com*']});
async function settle() {
  // Wait for responsive layout and sidebar transitions, not just the network load event.
  await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
  await evaluate('Promise.all(document.getAnimations().map(a => a.finished.catch(() => {})))');
}
async function nav(path) { const loaded = event('Page.loadEventFired'); const result = await cmd('Page.navigate',{url:'http://127.0.0.1:8088/AlgoJudge'+path}); assert.ok(!result.errorText, result.errorText); await loaded; await settle(); }
async function viewport(width,height,mobile) {
  await cmd('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile});
  await settle();
  assert.ok(Math.abs((await evaluate('innerWidth')) - width) <= 1, 'viewport applied');
}
async function shot(name) {
  await settle();
  assert.ok(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'), name + ': no horizontal page overflow');
  const r = await cmd('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
  await writeFile(out+'/'+name+'.png',Buffer.from(r.data,'base64'));
}
async function login(username,password) {
  await nav('/frontend/auth/login.php');
  const loaded = event('Page.loadEventFired');
  await evaluate(`document.querySelector('[name="username"]').value=${JSON.stringify(username)}; document.querySelector('[name="password"]').value=${JSON.stringify(password)}; document.querySelector('form').requestSubmit();`);
  await loaded;
  assert.ok(!(await evaluate('location.pathname')).endsWith('login.php'),'login redirects');
}
await login('test_teacher1','Test-pass-2026');
await nav('/frontend/instructor/classes.php');
assert.ok(await evaluate('document.body.innerText.includes("HTTP test class")'),'classes rendered');
await shot('classes-desktop');
await nav('/frontend/profile.php');
assert.ok(!(await evaluate('document.body.innerText.includes("Tiến độ từng bài")')),'teacher progress hidden');
await shot('teacher-profile-desktop');
// Submit a locally answered statistics question; this never calls Gemini.
await evaluate(`new Promise((resolve,reject) => {
  const button = document.querySelector('#ai-send');
  const timeout = setTimeout(() => { observer.disconnect(); reject(new Error('Chat request timed out')); }, 10000);
  const observer = new MutationObserver(() => { if (!button.disabled) { clearTimeout(timeout); observer.disconnect(); resolve(); } });
  observer.observe(button, {attributes:true,attributeFilter:['disabled']});
  document.querySelector('#ai-fab').click();
  document.querySelector('#ai-input').value = 'bao nhiêu học viên';
  button.click();
})`);
assert.ok(await evaluate('document.querySelector("#ai-msgs").innerText.includes("học viên")'), 'local chat answered');
const historyKey = await evaluate('Object.keys(sessionStorage).find(k => k.startsWith("ai-chat-history:"))');
assert.ok(historyKey, 'chat history stored in login scope');
await nav('/frontend/instructor/classes.php');
assert.ok(await evaluate('document.querySelector("#ai-msgs").innerText.includes("bao nhiêu học viên")'), 'chat history survives page navigation');
await nav('/frontend/auth/logout.php');
await login('test_teacher1','Test-pass-2026');
assert.ok(!(await evaluate('document.querySelector("#ai-msgs").innerText.includes("bao nhiêu học viên")')), 'fresh login does not restore old chat');
assert.equal(await evaluate(`sessionStorage.getItem(${JSON.stringify(historyKey)})`), null, 'old login history purged');
await nav('/frontend/instructor/ai_chat.php');
assert.equal(await evaluate('document.querySelectorAll("#ai-send").length'), 1, 'floating send control unique on AI page');
assert.equal(await evaluate('document.querySelectorAll("#problem-ai-send").length'), 1, 'problem generator send control unique');
await evaluate(`new Promise((resolve,reject) => {
  const button = document.querySelector('#ai-send');
  const timeout = setTimeout(() => { observer.disconnect(); reject(new Error('Chat request timed out')); }, 10000);
  const observer = new MutationObserver(() => { if (!button.disabled) { clearTimeout(timeout); observer.disconnect(); resolve(); } });
  observer.observe(button, {attributes:true,attributeFilter:['disabled']});
  document.querySelector('#ai-fab').click();
  document.querySelector('#ai-input').value = 'bao nhiêu học viên';
  button.click();
})`);
assert.ok(await evaluate('document.querySelector("#ai-panel #ai-msgs").innerText.includes("Hiện có")'), 'floating chat answers in its own panel on AI page');
assert.ok(!(await evaluate('document.querySelector("#problem-ai-msgs").innerText.includes("Hiện có")')), 'problem generator is unchanged by floating chat');
await viewport(390,844,true);
await nav('/frontend/instructor/classes.php');
await shot('classes-mobile');
const rosterPath = await evaluate('new URL(document.querySelector(".class-table a").href).pathname.replace("/AlgoJudge", "") + new URL(document.querySelector(".class-table a").href).search');
await nav(rosterPath);
await shot('class-roster-mobile');
await nav('/frontend/profile.php');
await shot('teacher-profile-mobile');
await login('test_student1','New-pass-2026');
await nav('/frontend/profile.php?page=2');
assert.ok(await evaluate('document.body.innerText.includes("Test problem 11")'),'student page two renders');
await shot('student-profile-mobile');
await viewport(1365,1000,false);
assert.ok(await evaluate('document.querySelector(".app-main").getBoundingClientRect().left >= document.querySelector(".sb").getBoundingClientRect().right - 1'), 'desktop sidebar does not cover content after resize');
await shot('student-profile-desktop');
// New student views: drive the actual buttons, not only direct page URLs.
async function clickNavigation(selector) {
  const loaded = event('Page.loadEventFired');
  await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
  await loaded; await settle();
}
await nav('/frontend/instructor/classes.php');
assert.ok(await evaluate('!document.querySelector("[name=join_code]") && document.querySelectorAll(".class-actions a").length === 2'), 'home contains only joined classes and two actions');
await shot('student-classes-desktop');
await clickNavigation('.class-actions a[href*="view=join"]');
assert.ok(await evaluate('!!document.querySelector("#join-code")'), 'join button opens labeled form');
assert.ok(await evaluate('(() => { const a=document.querySelector(".btn-back"); a.focus(); const s=getComputedStyle(a); return s.borderStyle === "solid" && parseFloat(s.borderWidth)>0 && a.getBoundingClientRect().height>=44 && document.activeElement===a; })()'), 'return button framed, focusable and touch-sized');
await shot('student-join-desktop');
await clickNavigation('.btn-back');
await clickNavigation('.class-actions a[href*="view=requests"]');
assert.ok(await evaluate('document.body.innerText.includes("Yêu cầu của tôi")'), 'requests action opens own statuses');
await viewport(390,844,true);
await shot('student-requests-mobile');
await clickNavigation('.btn-back');
await shot('student-classes-mobile');
await clickNavigation('.class-joined-list a');
assert.ok(await evaluate('document.body.innerText.includes("Danh sách chỉ đọc") && !document.querySelector("[name=student_id]")'), 'joined roster is read-only');
await shot('student-roster-mobile');
await nav('/frontend/auth/logout.php');
await login('test_admin','Test-pass-2026');
await nav('/frontend/profile.php');
assert.ok(await evaluate('document.body.innerText.includes("Lịch sử hoạt động") && !document.querySelector("[name=new_password]") && !document.body.innerText.includes("10 bài nộp gần nhất")'), 'admin profile shows only identity and audit');
await shot('admin-profile-mobile');
await viewport(1365,1000,false);
await shot('admin-profile-desktop');
await nav('/frontend/instructor/problem_form.php');
assert.ok(await evaluate('document.querySelector("#pf-back").hidden && getComputedStyle(document.querySelector("#pf-back")).display === "none"'), 'new button CSS preserves hidden wizard previous button');
await evaluate(`document.querySelectorAll('#problem-form [data-pane="1"] [required]').forEach(el => { if (!el.value) el.value = el.name === 'slug' ? 'browser-only-no-save' : 'Browser only'; }); document.querySelector('#pf-next').click();`);
await settle();
assert.ok(await evaluate('!document.querySelector("#pf-back").hidden && getComputedStyle(document.querySelector("#pf-back")).display !== "none"'), 'wizard previous button appears on step two');
await evaluate('document.querySelector("#pf-back").click()');
assert.ok(await evaluate('document.querySelector("#pf-back").hidden'), 'wizard previous button returns to first step');
assert.deepEqual(errors, [], 'no uncaught browser JavaScript exceptions');
console.log('Browser: staff/student navigation, read-only rosters, framed return buttons, OTP/profile layouts, admin audit, chat isolation and 14 screenshots passed; no uncaught JS errors.');
ws.close();
