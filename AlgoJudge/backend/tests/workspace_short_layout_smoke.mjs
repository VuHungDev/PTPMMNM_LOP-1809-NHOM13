// Offline, dependency-free CSS regression smoke. No PHP, database, .env or app server.
// Run: node C:/xampp/htdocs/AlgoJudge/backend/tests/workspace_short_layout_smoke.mjs
// Only this new test and a new OS temp directory are written. Uses an owned Chrome on 9233.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { access, appendFile, mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';

const root = fileURLToPath(new URL('../..', import.meta.url));
const chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 9233;
const cdpOrigin = `http://127.0.0.1:${port}`;
const inputs = {
  css: path.join(root, 'frontend/assets/css/algojudge.css'),
  markup: path.join(root, 'frontend/workspace.php'),
  renderer: path.join(root, 'frontend/assets/js/modules/submission-result.js'),
};
const runDir = await mkdtemp(path.join(tmpdir(), 'algojudge-offline-short-layout-'));
const screenshotDir = path.join(runDir, 'screenshots');
const profileDir = path.join(runDir, 'chrome-profile');
await mkdir(screenshotDir);
await mkdir(profileDir);
const resultPath = path.join(runDir, 'result.json');
const fixturePath = path.join(runDir, 'workspace-offline.html');
const report = {
  runDir, resultPath, fixturePath, screenshotDir, profileDir, inputs,
  limitation: 'Offline layout verification only. Actual CSS and submission-result.js render a PHP-template-derived workspace with synthetic data. No PHP, database, login, judge, workspace.js, Alpine or Monaco runtime is exercised; the editor is the actual empty flex container with its CSS minimum height. External fonts are not loaded; Chrome uses local fallback fonts.',
  safety: { envFileRead: false, databaseAccessed: false, externalNetworkAllowed: false, appServerStarted: false, existingListenersStopped: false },
  passed: 0, failed: 0, checks: [], layouts: {}, screenshots: [], browserErrors: [], networkRequests: [], stoppedOwnedProcesses: [],
};
let browser, socket, command, evaluate, ownedSpawnError;
let nextId = 0;
const pending = new Map(), listeners = new Map();
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
function check(ok, label, detail) {
  report[ok ? 'passed' : 'failed']++;
  report.checks.push({ ok: !!ok, label, ...(detail === undefined ? {} : { detail }) });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${label}`);
}
async function sourceHashes() {
  return Object.fromEntries(await Promise.all(Object.entries(inputs).map(async ([name, file]) => [name, createHash('sha256').update(await readFile(file)).digest('hex')])));
}
async function portIsFree() {
  for (const host of ['127.0.0.1', '::1']) {
    await new Promise((resolve, reject) => {
      const probe = createServer();
      probe.once('error', reject);
      probe.listen({ host, port, exclusive: true }, () => probe.close(resolve));
    });
  }
}
async function waitUntil(fn, label, timeout = 15000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    if (ownedSpawnError) throw ownedSpawnError;
    if (await fn()) return;
    await delay(75);
  }
  throw new Error(`Timed out: ${label}`);
}
function event(name) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { listeners.delete(name); reject(new Error(`CDP event timeout: ${name}`)); }, 15000);
    listeners.set(name, params => { clearTimeout(timer); resolve(params); });
  });
}
async function settle() {
  await evaluate('new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))');
  await evaluate('Promise.all(document.getAnimations().map(a => a.finished.catch(() => {})))');
}
async function viewport(width, height, mobile = false) {
  await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
  await evaluate('window.scrollTo(0,0)');
  await settle();
  check(await evaluate(`innerWidth === ${width} && innerHeight === ${height}`), `${width}x${height}: exact viewport`);
}
async function screenshot(name) {
  await settle();
  const image = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  const file = path.join(screenshotDir, name + '.png');
  await writeFile(file, Buffer.from(image.data, 'base64'));
  report.screenshots.push(file);
  return file;
}
async function render(kind) {
  await evaluate(`window.renderFixture(${JSON.stringify(kind)})`);
  await settle();
}
async function scrollConsoleToEnd() {
  // These are deliberately the ONLY element scroll writes used for the desktop assertion.
  // Never scrollIntoView and never scroll an overflow:hidden ancestor to repair clipping.
  await evaluate(`(() => {
    const body = document.querySelector('.console-body');
    const output = document.querySelector('#console-output');
    body.scrollTop = body.scrollHeight;
    output.scrollTop = output.scrollHeight;
  })()`);
  await settle();
}
async function measure() {
  return evaluate(`(() => {
    const box = r => ({x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom});
    const metrics = el => ({...box(el.getBoundingClientRect()),clientHeight:el.clientHeight,scrollHeight:el.scrollHeight,scrollTop:el.scrollTop,overflowY:getComputedStyle(el).overflowY,flex:getComputedStyle(el).flex});
    const right = document.querySelector('.ide-right');
    const consoleEl = document.querySelector('.ide-console');
    const body = document.querySelector('.console-body');
    const output = document.querySelector('#console-output');
    const actual = [...output.querySelectorAll('.submission-output-actual pre')].at(-1);
    const text = actual.firstChild;
    const lastIndex = actual.textContent.trimEnd().length - 1;
    const range = document.createRange();
    range.setStart(text,lastIndex); range.setEnd(text,lastIndex+1);
    const glyph = range.getBoundingClientRect();
    const fullyInside = (r,c) => r.width>0 && r.height>0 && r.left>=c.left-0.5 && r.right<=c.right+0.5 && r.top>=c.top-0.5 && r.bottom<=c.bottom+0.5;
    const clientBox = el => {const r=el.getBoundingClientRect(); return {left:r.left+el.clientLeft,top:r.top+el.clientTop,right:r.left+el.clientLeft+el.clientWidth,bottom:r.top+el.clientTop+el.clientHeight};};
    const samples = [[0.5,0.5],[0.15,0.15],[0.85,0.15],[0.15,0.85],[0.85,0.85]].map(([fx,fy]) => {
      const x=glyph.left+glyph.width*fx,y=glyph.top+glyph.height*fy;
      const hit=document.elementFromPoint(x,y);
      return {x,y,unoccluded:!!hit && (hit===actual || actual.contains(hit)),hit:hit ? hit.tagName.toLowerCase()+(hit.id?'#'+hit.id:'')+(typeof hit.className==='string'?'.'+hit.className.replaceAll(' ','.'):'') : null};
    });
    const clippedAncestors = [];
    for(let e=actual.parentElement;e;e=e.parentElement){
      const style=getComputedStyle(e);
      if(['auto','scroll','hidden','clip'].includes(style.overflowY) || ['auto','scroll','hidden','clip'].includes(style.overflowX)) {
        clippedAncestors.push({tag:e.tagName,id:e.id,class:e.className,overflowX:style.overflowX,overflowY:style.overflowY,scrollTop:e.scrollTop,inside:fullyInside(glyph,clientBox(e)),...box(e.getBoundingClientRect())});
      }
    }
    const buttons = [...document.querySelectorAll('.ide-toolbar button')].map(el=>{
      const r=el.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2,hit=document.elementFromPoint(x,y);
      return {text:el.textContent.trim(),...box(r),inViewport:fullyInside(r,{left:0,top:0,right:innerWidth,bottom:innerHeight}),unoccluded:!!hit&&(hit===el||el.contains(hit))};
    });
    const select=document.querySelector('#lang-select'),sr=select.getBoundingClientRect(),sh=document.elementFromPoint(sr.left+sr.width/2,sr.top+sr.height/2);
    return {
      viewport:{width:innerWidth,height:innerHeight,scrollX,scrollY},
      page:{clientWidth:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight},
      appMain:{...metrics(document.querySelector('.app-main')),marginLeft:getComputedStyle(document.querySelector('.app-main')).marginLeft},
      sidebar:metrics(document.querySelector('.sb')),
      workspace:{...metrics(document.querySelector('.workspace-ide')),columns:getComputedStyle(document.querySelector('.workspace-ide')).gridTemplateColumns},
      left:metrics(document.querySelector('.ide-left')),right:metrics(right),toolbar:metrics(document.querySelector('.ide-toolbar')),
      editor:{...metrics(document.querySelector('#monaco-container')),minHeight:getComputedStyle(document.querySelector('#monaco-container')).minHeight},
      console:metrics(consoleEl),header:metrics(document.querySelector('.console-header')),body:metrics(body),output:metrics(output),
      rendered:{cases:output.querySelectorAll('.submission-case').length,expected:output.querySelector('.submission-output-expected pre').textContent,actual:actual.textContent},
      lastStdout:{glyph:box(glyph),char:actual.textContent[lastIndex],pre:box(actual.getBoundingClientRect()),insideRight:fullyInside(glyph,right.getBoundingClientRect()),insideOutput:fullyInside(glyph,clientBox(output)),insideBody:fullyInside(glyph,clientBox(body)),insideViewport:fullyInside(glyph,{left:0,top:0,right:innerWidth,bottom:innerHeight}),clippedAncestors,samples,visible:fullyInside(glyph,right.getBoundingClientRect())&&fullyInside(glyph,{left:0,top:0,right:innerWidth,bottom:innerHeight})&&clippedAncestors.every(a=>a.inside)&&samples.every(s=>s.unoccluded)},
      buttons,languageSelect:{...box(sr),unoccluded:!!sh&&(sh===select||select.contains(sh))}
    };
  })()`);
}
async function desktopChecks(width, height, kind) {
  const label = `${width}x${height}-${kind}`;
  await viewport(width, height);
  await render(kind);
  await scrollConsoleToEnd();
  const m = await measure();
  report.layouts[label] = m;
  check(m.appMain.x === 248 && m.appMain.marginLeft === '248px' && m.sidebar.width === 248, `${label}: expanded sidebar and real app-main margin`);
  check(m.page.scrollWidth <= m.page.clientWidth && m.viewport.scrollY === 0, `${label}: no page overflow/scroll workaround`);
  check(m.workspace.scrollTop === 0 && m.right.scrollTop === 0, `${label}: hidden ancestors remain unscrolled`);
  check(m.editor.height >= 220 && m.editor.minHeight === '220px', `${label}: actual editor minimum preserved`, m.editor);
  check(m.console.bottom <= m.right.bottom + 0.5 && m.console.bottom <= height + 0.5, `${label}: entire console fits right panel/viewport`, {console:m.console,right:m.right});
  check(m.console.flex === '0 1 300px' && m.header.flex === '0 0 auto', `${label}: console can shrink, header cannot`, {console:m.console.flex,header:m.header.flex});
  check(m.buttons.length === 3 && m.buttons.every(b=>b.inViewport && b.unoccluded) && m.languageSelect.unoccluded, `${label}: all core toolbar controls reachable`, m.buttons);
  check(m.output.scrollHeight > m.output.clientHeight && Math.abs(m.output.scrollTop-(m.output.scrollHeight-m.output.clientHeight)) <= 1, `${label}: output has a scrollbar and is at normal max scroll`, m.output);
  const bodyOverflow = m.body.scrollHeight - m.body.clientHeight;
  check(bodyOverflow <= 1 || m.body.scrollTop > 0 && Math.abs(m.body.scrollTop-bodyOverflow) <= 1, `${label}: outer body scroll reaches its real maximum`, m.body);
  if (height <= 560) check(bodyOverflow > 0 && m.body.scrollTop > 0 && m.console.height < 300, `${label}: short viewport genuinely exercises outer console scrolling/shrink`, m.body);
  check(m.lastStdout.visible, `${label}: last actual stdout glyph fully visible and unoccluded after ONLY two normal scrollTop writes`, m.lastStdout);
  await screenshot(label + '-scrolled');
  return m;
}
async function negativeControl(width, height) {
  const label = `${width}x${height}-old-css-control`;
  await viewport(width, height);
  await render('sample');
  await evaluate(`(() => {const s=document.createElement('style');s.id='old-css-control';s.textContent='.workspace-ide .ide-console{flex:0 0 300px}.workspace-ide .console-header{flex-shrink:1}';document.head.append(s);})()`);
  await settle();
  await scrollConsoleToEnd();
  const m = await measure();
  report.layouts[label] = m;
  check(!m.lastStdout.visible && m.console.bottom > m.right.bottom + 1 && m.right.scrollTop === 0 && m.workspace.scrollTop === 0, `${label}: old declarations reproduce clipping without a hidden-ancestor workaround`, {console:m.console,right:m.right,lastStdout:m.lastStdout});
  await screenshot(label);
  await evaluate(`document.querySelector('#old-css-control').remove()`);
  await settle();
}
async function scrollPageTo(selector) {
  // Mobile ONLY: ordinary document scrolling is required by the natural-height layout.
  await evaluate(`(() => {const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();window.scrollTo(0,Math.max(0,scrollY+r.top-24));})()`);
  await settle();
}
async function mobileChecks() {
  const label = '390x844-mobile';
  await viewport(390, 844, true);
  await render('stress');
  let m = await measure();
  report.layouts[label + '-top'] = m;
  check(m.appMain.marginLeft === '0px' && m.page.scrollWidth === m.page.clientWidth && m.page.clientWidth === 390, `${label}: no sidebar margin/horizontal page overflow`);
  check(m.workspace.overflowY === 'visible' && m.workspace.columns.split(' ').length === 1 && m.right.y >= m.left.bottom - 0.5 && m.right.width === 390, `${label}: natural-height full-width single column`, {workspace:m.workspace,left:m.left,right:m.right});
  check(m.editor.height === 360 && m.console.height > 300 && m.console.flex === '0 0 auto' && m.body.overflowY === 'visible' && m.output.height === 280 && m.page.scrollHeight > 844, `${label}: mobile editor/console retain natural heights`, {editor:m.editor,console:m.console,body:m.body,output:m.output});
  await screenshot(label + '-top');
  await scrollPageTo('.ide-toolbar');
  m = await measure();
  report.layouts[label + '-toolbar'] = m;
  check(m.buttons.every(b=>b.inViewport && b.unoccluded && b.height>=44) && m.languageSelect.unoccluded, `${label}: normal document scroll reaches language/run/save/submit controls`, m.buttons);
  await screenshot(label + '-toolbar');
  await scrollPageTo('.console-header');
  const headerReachable = await evaluate(`(() => {const e=document.querySelector('.console-header strong'),r=e.getBoundingClientRect(),h=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return r.top>=0 && r.bottom<=innerHeight && !!h && (h===e||e.contains(h));})()`);
  check(headerReachable, `${label}: console heading reachable with ordinary page scroll`);
  await scrollConsoleToEnd();
  await scrollPageTo('#console-output');
  m = await measure();
  report.layouts[label + '-stdout'] = m;
  check(m.workspace.scrollTop === 0 && m.right.scrollTop === 0 && m.body.scrollTop === 0, `${label}: no hidden-ancestor or outer-body scroll workaround`);
  check(m.lastStdout.visible && m.output.scrollTop > 0, `${label}: last stdout reachable by natural page + output scrolling`, m.lastStdout);
  await screenshot(label + '-stdout');
}

try {
  await access(chrome);
  report.sourceHashesBefore = await sourceHashes();
  const [css, template, renderer] = await Promise.all(Object.values(inputs).map(file=>readFile(file,'utf8')));
  assert.match(css, /\.workspace-ide \.ide-console\s*\{\s*flex: 0 1 300px;[^}]*min-height: 0;/);
  assert.match(css, /\.workspace-ide \.console-header\s*\{\s*flex-shrink: 0;/);
  assert.ok(!/@import|url\(/i.test(css), 'Fixture must not import external CSS/fonts/images');
  const normalizedTemplate = template.replaceAll('\r\n', '\n');
  let markup = normalizedTemplate.slice(normalizedTemplate.indexOf('<div class="ide-wrapper workspace-ide">'), normalizedTemplate.indexOf('\n<script>\nwindow.WS_CFG'));
  assert.ok(markup.startsWith('<div class="ide-wrapper workspace-ide">') && markup.endsWith('\n'));
  markup = markup.replace(/<\?php foreach \(\$publicCases as \$idx => \$tc\): \?>([\s\S]*?)<\?php endforeach; \?>/, (_, body) => body.replaceAll('<?= $idx + 1 ?>','1').replaceAll("<?= e($tc['stdin_data']) ?>",'5').replaceAll("<?= e($tc['expected_output']) ?>",'120'));
  markup = markup.replace(/<\?php foreach \(JudgeEngine::LANGUAGES as \$key => \$meta\): \?>([\s\S]*?)<\?php endforeach; \?>/, (_, body) => [
    ['c','C (gcc)'],['cpp','C++17 (g++)'],['java','Java (javac)'],['python','Python 3'],['php','PHP'],['js','JavaScript (Node)'],
  ].map(([key,label])=>body.replaceAll('<?= e($key) ?>',key).replaceAll("<?= e($meta['label']) ?>",label)).join(''));
  const replacements = {
    "<?= (int)$problem['time_limit_ms'] ?>": '1000',
    "<?= (int)($problem['memory_limit_kb'] / 1024) ?>": '256',
    "<?= nl2br(e($problem['description'])) ?>": 'Offline synthetic fixture: calculate N factorial. No real account, database, or application requests.',
    "<?= nl2br(e($problem['input_desc'])) ?>": 'One integer N.',
    "<?= nl2br(e($problem['output_desc'])) ?>": 'Print only the factorial: 120 for N = 5.',
    "<?= e($problem['sample_input']) ?>": '5',
  };
  for (const [from,to] of Object.entries(replacements)) markup=markup.replaceAll(from,to);
  assert.ok(!markup.includes('<?'), 'Every dynamic PHP value must be replaced without executing PHP');
  markup=markup.replace('id="tab-history"','id="tab-history" hidden').replace('class="ide-tab"','class="ide-tab active"');
  const html = `<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline' data:; connect-src 'none'; img-src data:; font-src 'none'; base-uri 'none'; form-action 'none'"><title>Offline Layout Fixture</title><style>${css}</style></head><body class="has-sb"><button type="button" class="sb-mobile-toggle" aria-label="Offline menu fixture"><span></span><span></span><span></span></button><aside class="sb" aria-label="Expanded sidebar placeholder"><div class="sb-top"><span class="sb-label">Offline layout fixture</span></div></aside><main class="app-main">${markup}</main><script type="module">
import { renderSubmissionConsole } from ${JSON.stringify('data:text/javascript;base64,'+Buffer.from(renderer).toString('base64'))};
const sample = {case_order:1,is_public:true,is_boundary:false,verdict:'WA',execution_time_ms:1,stdin_preview:'5',expected_preview:'120',actual_preview:'Nhập N: 5! = 120\\n'};
sample.actual_preview = sample.actual_preview.replace('\\\\n','\\n');
window.renderFixture = kind => {
  const cases=kind==='stress' ? Array.from({length:16},(_,i)=>({...sample,case_order:i+1,expected_preview:i===15?'120':Array.from({length:30},()=> '120').join('\\n')})) : [{...sample}];
  renderSubmissionConsole(document.querySelector('#console-output'),{submission:{id:1,version_number:1,final_verdict:'WA',final_score:0,pass_count:0,total_cases:cases.length},active_run_cases:cases});
  document.querySelector('#console-badge').textContent='WA (0/'+cases.length+' test)';
  document.querySelector('#custom-stdin').value='5';
  document.querySelector('#lang-select').value='python';
  document.querySelector('.console-body').scrollTop=0;
  document.querySelector('#console-output').scrollTop=0;
};
window.renderFixture('sample'); window.fixtureReady=true;
</script></body></html>`;
  await writeFile(fixturePath,html,'utf8');
  check(true,'Fixture embeds actual CSS and exact renderer module, with actual template hierarchy and synthetic PHP substitutions');
  await portIsFree();
  check(true,'9233 initially free on both loopback address families; no listener stopped');
  browser=spawn(chrome,[
    '--headless=new','--remote-debugging-address=127.0.0.1',`--remote-debugging-port=${port}`,'--user-data-dir='+profileDir,
    '--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-component-update','--disable-sync',
    '--disable-domain-reliability','--metrics-recording-only','--disable-extensions','--disable-default-apps','--no-pings',
    '--host-resolver-rules=MAP * ~NOTFOUND','--window-size=1440,1000','about:blank',
  ],{cwd:root,windowsHide:true,stdio:['ignore','pipe','pipe']});
  report.chromePid=browser.pid;
  browser.once('error',error=>{ownedSpawnError=error;});
  browser.stdout.on('data',data=>appendFile(path.join(runDir,'chrome.stdout.log'),data).catch(()=>{}));
  browser.stderr.on('data',data=>appendFile(path.join(runDir,'chrome.stderr.log'),data).catch(()=>{}));
  await waitUntil(async()=>{
    if(browser.exitCode!==null) throw new Error(`Owned Chrome exited ${browser.exitCode}`);
    try{return (await fetch(cdpOrigin+'/json/version',{signal:AbortSignal.timeout(500)})).ok;}catch{return false;}
  },'owned Chrome CDP startup');
  const tabs=await (await fetch(cdpOrigin+'/json')).json();
  const tab=tabs.find(t=>t.type==='page');
  assert.ok(tab?.webSocketDebuggerUrl);
  socket=new WebSocket(tab.webSocketDebuggerUrl);
  await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
  command=(method,params={})=>new Promise((resolve,reject)=>{
    const id=++nextId;
    const timer=setTimeout(()=>{pending.delete(id);reject(new Error('CDP timeout: '+method));},15000);
    pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});
    socket.send(JSON.stringify({id,method,params}));
  });
  evaluate=async expression=>{
    const r=await command('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
    if(r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);
    return r.result.value;
  };
  socket.onmessage=({data})=>{
    const m=JSON.parse(data);
    if(m.id){const p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(new Error(JSON.stringify(m.error))):p.resolve(m.result);}}
    if(m.method==='Runtime.exceptionThrown') report.browserErrors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
    if(m.method==='Network.requestWillBeSent') report.networkRequests.push(m.params.request.url);
    if(m.method==='Fetch.requestPaused'){
      const requestId=m.params.requestId,url=m.params.request.url;
      const allowed=/^(file:|data:|about:)/.test(url);
      command(allowed?'Fetch.continueRequest':'Fetch.failRequest',allowed?{requestId}:{requestId,errorReason:'BlockedByClient'}).catch(error=>report.browserErrors.push(error.message));
    }
    if(m.method&&listeners.has(m.method)){listeners.get(m.method)(m.params);listeners.delete(m.method);}
  };
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Network.setBlockedURLs',{urls:['http://*','https://*','ws://*','wss://*','ftp://*']});
  await command('Network.emulateNetworkConditions',{offline:true,latency:0,downloadThroughput:0,uploadThroughput:0});
  await command('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]});
  report.chromeVersion=await command('Browser.getVersion');
  const loaded=event('Page.loadEventFired');
  const navigation=await command('Page.navigate',{url:pathToFileURL(fixturePath).href});
  assert.ok(!navigation.errorText,navigation.errorText);
  await loaded;
  await waitUntil(()=>evaluate('window.fixtureReady===true'),'offline fixture module');
  await settle();
  check(await evaluate(`document.querySelector('.submission-output-actual pre').textContent === 'Nhập N: 5! = 120\\n'.replace('\\\\n','\\n')`),'Synthetic public WA renders literal expected 120 and actual prompt/equation');
  for(const [width,height] of [[1280,560],[1280,480],[1440,1000]]){
    for(const kind of ['sample','stress']) await desktopChecks(width,height,kind);
  }
  await negativeControl(1280,560);
  await negativeControl(1280,480);
  await mobileChecks();
  check(report.browserErrors.length===0,'No uncaught JavaScript/interception errors',report.browserErrors);
  check(report.networkRequests.every(url=>/^(file:|data:|about:)/.test(url)),'Only local file/data requests observed; external protocols blocked/offline',report.networkRequests.filter(url=>!url.startsWith('data:')));
}catch(error){
  check(false,'Smoke execution failed',error.stack||error.message);
  if(command&&socket?.readyState===WebSocket.OPEN){try{await screenshot('failure-state');}catch{}}
}finally{
  if(command&&socket?.readyState===WebSocket.OPEN){
    try{await command('Browser.close');}catch{}
    try{socket.close();}catch{}
  }
  if(browser){
    if(browser.exitCode===null&&browser.signalCode===null){await Promise.race([new Promise(resolve=>browser.once('exit',resolve)),delay(5000)]);}
    if(browser.exitCode===null&&browser.signalCode===null) browser.kill(); // Owned child only, never by port/name.
    if(browser.exitCode===null&&browser.signalCode===null){await Promise.race([new Promise(resolve=>browser.once('exit',resolve)),delay(3000)]);}
    report.stoppedOwnedProcesses.push({pid:browser.pid,stopped:browser.exitCode!==null||browser.signalCode!==null});
    check(report.stoppedOwnedProcesses.every(p=>p.stopped),'Stopped only the Chrome process owned by this run',report.stoppedOwnedProcesses);
  }
  report.sourceHashesAfter=await sourceHashes();
  check(JSON.stringify(report.sourceHashesBefore)===JSON.stringify(report.sourceHashesAfter),'CSS, workspace markup, and renderer unchanged by verification',report.sourceHashesAfter);
  await writeFile(resultPath,JSON.stringify(report,null,2),'utf8');
  console.log(JSON.stringify({passed:report.passed,failed:report.failed,resultPath,screenshotDir,fixturePath,chromePid:report.chromePid,stoppedOwnedProcesses:report.stoppedOwnedProcesses},null,2));
  process.exitCode=report.failed?1:0;
}
