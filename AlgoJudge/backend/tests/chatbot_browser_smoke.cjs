// Optional real Chrome smoke; uses the ALREADY isolated app from chatbot_stream_tests.cjs.
// No external packages, downloads or service. Native Node WebSocket speaks local Chrome CDP.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const {spawn}=require('node:child_process');
module.exports=async function browserSmoke({base}){
  assert.match(base,/^http:\/\/127\.0\.0\.1:\d+\/AlgoJudge$/);
  const bin=process.env.CHROME_BIN||'C:/Program Files/Google/Chrome/Application/chrome.exe';
  assert.ok(fs.existsSync(bin),'Set CHROME_BIN to an installed browser');
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'chatbot-chrome-'));
  const chrome=spawn(bin,['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,
    '--no-first-run','--no-default-browser-check','--disable-extensions','--disable-background-networking',
    '--disable-component-update','--disable-sync','--metrics-recording-only','about:blank'],{stdio:['ignore','ignore','pipe']});
  let ws,id=0;const pending=new Map(),exceptions=[];
  try{
    const socket=await new Promise((resolve,reject)=>{let out='';const timer=setTimeout(()=>reject(Error('Chrome readiness: '+out)),15000);
      chrome.stderr.on('data',d=>{out+=d;const m=out.match(/DevTools listening on (ws:\/\/127\.0\.0\.1:\d+\/devtools\/browser\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});chrome.once('error',reject);chrome.once('exit',c=>reject(Error('Chrome exit '+c)));});
    const host=new URL(socket).host;
    const target=await (await fetch('http://'+host+'/json/new?about:blank',{method:'PUT'})).json();
    ws=new WebSocket(target.webSocketDebuggerUrl);await new Promise((r,j)=>{ws.addEventListener('open',r,{once:true});ws.addEventListener('error',j,{once:true});});
    ws.addEventListener('message',e=>{const data=JSON.parse(e.data);if(data.id){const p=pending.get(data.id);if(p){pending.delete(data.id);data.error?p.reject(Error(JSON.stringify(data.error))):p.resolve(data.result);}}if(data.method==='Runtime.exceptionThrown')exceptions.push(data.params.exceptionDetails);});
    const call=(method,params={})=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});ws.send(JSON.stringify({id:n,method,params}));});
    const evaluate=async(expression,awaitPromise=true)=>{const r=await call('Runtime.evaluate',{expression,awaitPromise,returnByValue:true,userGesture:true});if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;};
    await call('Runtime.enable');await call('Page.enable');
    await call('Emulation.setDeviceMetricsOverride',{width:1365,height:980,deviceScaleFactor:1,mobile:false});
    await call('Page.navigate',{url:base+'/frontend/auth/login.php'});
    async function until(expression){const deadline=Date.now()+20000;let last;while(Date.now()<deadline){try{if(await evaluate('!!('+expression+')'))return;}catch(e){last=e;}await new Promise(r=>setTimeout(r,50));}throw Error('Browser condition timeout: '+expression+(last?' / '+last.message:''));}
    await until("document.querySelector('input[name=username]')");
    await evaluate(`document.querySelector('[name=username]').value='ai_test_teacher';document.querySelector('[name=password]').value='Ai-test-only-2026';document.querySelector('form').requestSubmit();`);
    await until("location.pathname.endsWith('/frontend/index.php') || location.pathname.endsWith('/frontend/instructor/problems.php')");
    await call('Page.navigate',{url:base+'/frontend/instructor/ai_chat.php'});
    await until("document.querySelector('[data-chatbot]')?.chatbot");
    await evaluate("document.querySelector('[data-chatbot]').chatbot.ready");
    await evaluate(`document.querySelector('[data-chatbot-input]').value='Cho hai số nguyên a và b, tính tổng.';document.querySelector('[data-chatbot-send]').click();`);
    await until("document.querySelector('[data-chatbot-staging]')?.hidden === false && document.querySelector('[data-chatbot-save]').disabled");
    await until("document.querySelector('[data-draft-field=title]') && !document.querySelector('[data-chatbot-save]').disabled");
    assert.equal(await evaluate("document.querySelector('[data-draft-field=suggested_lang]').value"),'cpp');
    // Pure metadata edit then sync uses server revision and must retain every case flag.
    await evaluate(`const el=document.querySelector('[data-draft-field=title]');el.value='Tổng kiểm tra trình duyệt';el.dispatchEvent(new Event('input',{bubbles:true}));const slug=document.querySelector('[data-draft-field=slug]');slug.value='chrome-smoke-'+Date.now();slug.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-chatbot-sync]').click();`);
    await until("!document.querySelector('[data-chatbot]').chatbot.state.operation && !document.querySelector('[data-chatbot]').chatbot.state.dirty");
    const before=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});
    const desktop=path.join(profile,'chatbot-preview.png');fs.writeFileSync(desktop,Buffer.from(before.data,'base64'));
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'no horizontal overflow on phone');
    const mobile=path.join(profile,'chatbot-mobile.png');fs.writeFileSync(mobile,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
    await call('Emulation.setDeviceMetricsOverride',{width:1365,height:980,deviceScaleFactor:1,mobile:false});
    await evaluate("document.querySelector('[data-chatbot-input]').value='Câu hỏi chưa gửi sau khi lưu';document.querySelector('[data-chatbot-save]').click()");
    await until("!document.querySelector('[data-chatbot]').chatbot.state.operation && document.querySelector('[data-chatbot-messages]').textContent.includes('Đã tạo bài #')");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden"),true,'saved preview is hidden');
    assert.equal(await evaluate("document.querySelector('[data-chatbot]').chatbot.state.draft"),null,'saved draft is not active');
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft-editor]').children.length"),0,'saved editor is retired');
    assert.equal(await evaluate("document.querySelector('[data-chatbot-input]').value"),'Câu hỏi chưa gửi sau khi lưu','save preserves unsent text');
    assert.equal(await evaluate("document.activeElement === document.querySelector('[data-chatbot-input]')"),true,'composer focused after save');
    assert.equal(await evaluate("document.querySelector('[data-chatbot-send]').disabled"),false,'composer ready after save');
    assert.equal(await evaluate("!!document.querySelector('[data-chatbot-messages] a[href*=\"problem_form.php?edit=\"]')"),true,'success link remains in chat');
    await evaluate("document.querySelector('[data-chatbot]').chatbot.restore(false)");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden && document.querySelector('[data-chatbot]').chatbot.state.draft === null"),true,'saved restore stays hidden');
    assert.equal(await evaluate("document.querySelector('[data-chatbot-input]').value"),'Câu hỏi chưa gửi sau khi lưu','restore preserves unsent text');
    const savedShot=path.join(profile,'chatbot-saved.png');
    fs.writeFileSync(savedShot,Buffer.from((await call('Page.captureScreenshot',{format:'png'})).data,'base64'));
    await evaluate("document.querySelector('[data-chatbot-input]').value='Hướng dẫn nộp bài';document.querySelector('[data-chatbot-send]').click()");
    await until("!document.querySelector('[data-chatbot]').chatbot.state.operation");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden && document.querySelector('[data-chatbot-messages]').textContent.includes('Lịch sử')"),true,'question after save keeps completed form hidden');
    await evaluate("document.querySelector('[data-chatbot-input]').value='Given two integers a and b, print their sum.';document.querySelector('[data-chatbot-send]').click()");
    await until("document.querySelector('[data-draft-field=title]') && !document.querySelector('[data-chatbot-save]').disabled");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden"),false,'second source opens a fresh preview');
    await evaluate("document.querySelector('[data-draft-field=slug]').value='chrome-next-'+Date.now();document.querySelector('[data-draft-field=slug]').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-chatbot-save]').click()");
    await until("!document.querySelector('[data-chatbot]').chatbot.state.operation && document.querySelector('[data-chatbot]').chatbot.state.draft === null");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden"),true,'second completed preview also retires');
    // The same controller runs in the floating widget; saved restore must stay closed there too.
    await call('Page.navigate',{url:base+'/frontend/index.php'});
    await until("document.querySelector('[data-chatbot-launcher]')");
    await evaluate("document.querySelector('[data-chatbot-launcher]').click();document.querySelector('[data-chatbot]').chatbot.ready");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden && document.querySelector('[data-chatbot]').chatbot.state.draft === null"),true,'floating saved restore has no active preview');
    await evaluate("document.querySelector('[data-chatbot-input]').value='Cho hai số nguyên a và b, tính tổng.';document.querySelector('[data-chatbot-send]').click()");
    await until("document.querySelector('[data-draft-field=title]') && !document.querySelector('[data-chatbot-save]').disabled");
    await evaluate("document.querySelector('[data-draft-field=slug]').value='chrome-widget-'+Date.now();document.querySelector('[data-draft-field=slug]').dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-chatbot-save]').click()");
    await until("!document.querySelector('[data-chatbot]').chatbot.state.operation && document.querySelector('[data-chatbot]').chatbot.state.draft === null");
    assert.equal(await evaluate("document.querySelector('[data-chatbot-draft]').hidden && !document.querySelector('[data-chatbot-send]').disabled"),true,'floating save retires preview and readies chat');
    assert.equal(exceptions.length,0,'no browser Runtime exceptions: '+JSON.stringify(exceptions));
    console.log('PASS real Chrome: streamed preview/edit/save/retire, preserved composer/focus, saved restore, next question, sequential problems, floating widget, responsive phone, no runtime exceptions');
    console.log('Browser screenshots: '+desktop+' ; '+mobile+' ; '+savedShot);
    return {desktop,mobile,savedShot};
  }finally{if(ws)ws.close();if(!chrome.killed)chrome.kill();}
};
