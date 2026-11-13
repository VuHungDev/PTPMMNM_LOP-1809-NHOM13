// Real local app + streamed fake provider. Never uses a remote model or working DB.
const assert = require('node:assert/strict');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const {spawn, execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BIN || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
assert.match(process.env.AJ_DB_PORT || '', /^\d+$/, 'Explicit AJ_DB_PORT required');
const dbName = `algojudge_test_ai_stream_${Date.now()}_${process.pid}`;
const env = {...process.env, AJ_DB_NAME:dbName, AI_PROVIDER:'openai', OPENAI_API_KEY:'offline-stream-key',
  GEMINI_API_KEY:'offline-unused', AI_MODEL:'offline-model', AI_TIMEOUT:'0'};
const fixture = {title:'Tổng hai số',slug:'tong-hai-so-stream',topic:'Cơ bản',difficulty:'Easy',suggested_lang:'cpp',
  description:'Cho hai số nguyên a và b. Tính tổng a + b. Mỗi số thuộc đoạn [-100, 100].',
  input_desc:'Một dòng chứa hai số nguyên a và b, cách nhau bởi dấu cách (-100 ≤ a,b ≤ 100).',
  output_desc:'In một số nguyên duy nhất là tổng a + b.',sample_input:'2 3',sample_output:'5',time_limit_ms:1700,
  assumptions:['Bổ sung giới hạn mỗi số thuộc đoạn [-100, 100].'],clarification:'',
  test_cases:[{stdin:'2 3',expected:'5',is_public:true,is_boundary:false},
    {stdin:'-100 -100',expected:'-200',is_public:false,is_boundary:true},
    {stdin:'-4 7',expected:'3',is_public:false,is_boundary:false}]};
let mode='ok',calls=[], app, base, assertions=0;
function check(condition,label){assert.ok(condition,label);assertions++;console.log('PASS '+label);}
const provider=http.createServer((req,res)=>{let raw='';req.on('data',d=>raw+=d);req.on('end',()=>{
  const payload=JSON.parse(raw);calls.push(payload);
  assert.equal(payload.stream,true);assert.equal(req.headers.authorization,'Bearer offline-stream-key');
  if(mode==='quota'){res.writeHead(429);res.end('{"error":"offline-stream-key private-error"}');return;}
  res.writeHead(200,{'Content-Type':'text/event-stream'});
  let output={...fixture};
  if(mode==='clarify')output={...fixture,clarification:'Bạn muốn tính tổng hay tìm phần tử lớn nhất?'};
  if(mode==='partial') output={title:fixture.title,description:fixture.description};
  if(mode==='repair' && !payload.messages[1].content.includes('validation_issues'))output={title:fixture.title,description:fixture.description};
  if(mode==='revise'){
    const context=JSON.parse(payload.messages[1].content);
    assert.equal(context.current_draft.title,'Tiêu đề tự sửa');
    assert.equal(context.original_statement,'Cho hai số nguyên a và b, tính tổng.');
    output={...context.current_draft,title:'Tổng đã chỉnh'};
  }
  const json=JSON.stringify(output), fragments=[];
  for(let i=0;i<json.length;i+=55)fragments.push(json.slice(i,i+55));
  function write(i){if(res.destroyed)return;if(i===fragments.length){res.end('data: {"choices":[{"delta":{},"finish_reason":"stop"}]}\r\n\r\ndata: [DONE]\r\n\r\n');return;}
    const event='data: '+JSON.stringify({choices:[{delta:{content:fragments[i]},finish_reason:null}]})+'\r\n\r\n';
    // Force a split inside UTF8 and between CRLF to exercise both decoders.
    const bytes=Buffer.from(event);res.write(bytes.subarray(0,bytes.length-3));res.write(bytes.subarray(bytes.length-3));
    setTimeout(()=>write(i+1),mode==='slow'?450:8);
  }write(0);
});});
function sql(query){return JSON.parse(execFileSync(php,['-r',`$p=new PDO('mysql:host=127.0.0.1;port='.getenv('AJ_DB_PORT').';dbname='.getenv('AJ_DB_NAME').';charset=utf8mb4',getenv('AJ_DB_USER')?:'root',getenv('AJ_DB_PASS')?:'');echo json_encode($p->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));`,query],{cwd:root,env,encoding:'utf8'}));}
class Client{
  cookie='';csrf='';
  async request(route,body,form=false){
    const response=await fetch(base+route,{method:body?'POST':'GET',redirect:'manual',headers:{Cookie:this.cookie,...(body?{'Content-Type':form?'application/x-www-form-urlencoded':'application/json','X-CSRF-Token':this.csrf}:{})},body:body?(form?new URLSearchParams({...body,csrf_token:this.csrf}):JSON.stringify(body)):undefined});
    for(const c of response.headers.getSetCookie())if(c.startsWith('PHPSESSID='))this.cookie=c.split(';')[0];
    return response;
  }
  async page(route,body,form=false){const r=await this.request(route,body,form),text=await r.text();assert.doesNotMatch(text,/Fatal error|Warning:|Parse error/);this.csrf=text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1]||text.match(/name="csrf-token" content="([a-f0-9]+)"/)?.[1]||this.csrf;return {r,text};}
  async login(role){await this.page('/frontend/auth/login.php');const {r}=await this.page('/frontend/auth/login.php',{username:'ai_test_'+role,password:'Ai-test-only-2026'},true);assert.equal(r.status,302);await this.page('/frontend/index.php');}
  async json(route,body){const r=await this.request(route,body);const text=await r.text();assert.doesNotMatch(text,/Fatal error|Warning:|Parse error/);return {status:r.status,...JSON.parse(text)};}
  async chat(text,extra={},onEvent){const r=await this.request('/backend/api/ai_chat.php',{text,...extra});const type=r.headers.get('content-type');if(!type.includes('text/event-stream'))return {status:r.status,json:await r.json(),events:[]};
    let buffer='',events=[],firstAt=0,all='';const decoder=new TextDecoder(),start=Date.now();
    for await(const chunk of r.body){buffer+=decoder.decode(chunk,{stream:true}).replace(/\r\n/g,'\n');let end;while((end=buffer.indexOf('\n\n'))>=0){const block=buffer.slice(0,end);buffer=buffer.slice(end+2);if(block.startsWith(':'))continue;const name=block.match(/^event: (.+)$/m)?.[1];const data=block.split('\n').filter(l=>l.startsWith('data: ')).map(l=>l.slice(6)).join('\n');if(!name||!data)continue;const event={name,data:JSON.parse(data),ms:Date.now()-start};events.push(event);if(!firstAt&&name==='field')firstAt=event.ms;onEvent?.(event);}}
    return {status:r.status,events,firstAt,elapsed:Date.now()-start,draft:events.findLast(e=>e.name==='draft')?.data.draft,answer:events.findLast(e=>e.name==='answer')?.data.answer};
  }
}
async function freePort(){const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
(async()=>{try{
  console.log(execFileSync(php,['backend/tests/ai_http_fixture.php'],{cwd:root,env,encoding:'utf8'}).trim());
  await new Promise(r=>provider.listen(0,'127.0.0.1',r));env.AI_BASE_URL=`http://127.0.0.1:${provider.address().port}/v1`;
  const port=await freePort();base=`http://127.0.0.1:${port}/AlgoJudge`;
  app=spawn(php,['-d','max_execution_time=1','-S',`127.0.0.1:${port}`,'-t',path.dirname(root)],{cwd:root,env,stdio:['ignore','ignore','pipe']});
  await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(new Error(log)),10000);app.stderr.on('data',d=>{log+=d;if(log.includes('Development Server')){clearTimeout(timer);resolve();}});app.on('error',reject);});
  const teacher=new Client(),student=new Client(),admin=new Client();await teacher.login('teacher');await student.login('student');await admin.login('admin');
  let r=await teacher.chat('Hướng dẫn tạo bài');check(!r.draft&&r.answer&&calls.length===0,'how-to streams grounded local help without provider');
  const retired=await teacher.json('/backend/api/ai_chat.php',{confirm_token:'old-token'});check(retired.status===410,'legacy confirm token permanently retired');
  r=await student.chat('Tạo bài tính tổng hai số nguyên');check(!r.draft&&calls.length===0,'student cannot route to generation/provider');
  const denied=await student.json('/backend/api/ai_draft.php',{action:'update',draft_id:'x',revision:1,data:fixture});check(denied.status===403,'direct staff draft mutation denied to student');
  const bare=await teacher.json('/backend/api/ai_create_problem.php',fixture);check(bare.status===422,'legacy raw payload cannot bypass draft confirmation');
  mode='slow';let countAtField=-1;
  r=await teacher.chat('Cho hai số nguyên a và b, tính tổng.',{},event=>{if(event.name==='field'&&countAtField<0)countAtField=Number(sql('SELECT COUNT(*) n FROM problems')[0].n);});
  check(r.draft&&r.firstAt<r.elapsed-500&&r.elapsed>5000,'actual provider fields arrive before final draft, exceeding PHP timer');
  check(countAtField===0&&Number(sql('SELECT COUNT(*) n FROM problems')[0].n)===0,'streaming preview never inserts a problem');
  let draft=r.draft;check(draft.data.suggested_lang==='cpp'&&draft.data.time_limit_ms===1700,'full form fields in server draft');
  const state=await teacher.json('/backend/api/ai_state.php');check(state.draft.id===draft.id&&state.messages.length>0&&!state.processing,'server history and owned draft restore');
  const alien=await admin.json('/backend/api/ai_draft.php',{action:'cancel',draft_id:draft.id,revision:draft.revision});check(alien.status===404,'another staff session cannot access draft by ID');
  r=await teacher.chat('OK',{draft_id:draft.id,revision:draft.revision});check(Number(sql('SELECT COUNT(*) n FROM problems')[0].n)===0,'OK text does not create a problem');
  const updated=await teacher.json('/backend/api/ai_draft.php',{action:'update',draft_id:draft.id,revision:draft.revision,data:{...draft.data,title:'Tiêu đề tự sửa'}});check(updated.ok,'direct title edits are synchronized');draft=updated.draft;
  mode='revise';r=await teacher.chat('Đổi tiêu đề cho rõ hơn',{draft_id:draft.id,revision:draft.revision});check(r.draft?.data.title==='Tổng đã chỉnh'&&r.draft.data.suggested_lang==='cpp','revision AI sees source plus latest manually edited draft');draft=r.draft;
  const conflict=await teacher.json('/backend/api/ai_draft.php',{action:'update',draft_id:draft.id,revision:draft.revision-1,data:draft.data});check(conflict.status===409,'stale revision cannot overwrite new draft');
  let u=await teacher.json('/backend/api/ai_draft.php',{action:'update',draft_id:draft.id,revision:draft.revision,data:{...draft.data,description:draft.data.description+' Không làm tròn.'}});draft=u.draft;
  check(draft.semantic_review,'semantic edit requires explicit re-review');
  let saved=await teacher.json('/backend/api/ai_create_problem.php',{draft_id:draft.id,revision:draft.revision,idempotency_key:draft.save_key});check(saved.status===422,'unreviewed semantic edits cannot save');
  u=await teacher.json('/backend/api/ai_draft.php',{action:'review',draft_id:draft.id,revision:draft.revision});check(u.ok&&!u.draft.semantic_review,'review clears gate only after server validation');draft=u.draft;
  const payload={draft_id:draft.id,revision:draft.revision,idempotency_key:draft.save_key};
  const results=await Promise.all([teacher.json('/backend/api/ai_create_problem.php',payload),teacher.json('/backend/api/ai_create_problem.php',payload)]);
  check(results.every(x=>x.ok)&&results[0].problem_id===results[1].problem_id&&Number(sql('SELECT COUNT(*) n FROM problems')[0].n)===1,'concurrent confirmation and retry are idempotent');
  const persisted=sql('SELECT p.suggested_lang,v.input_desc,v.output_desc,v.time_limit_ms FROM problems p JOIN problem_versions v ON v.id=p.current_version_id')[0];
  check(persisted.suggested_lang==='cpp'&&Number(persisted.time_limit_ms)===1700&&persisted.input_desc===fixture.input_desc&&persisted.output_desc===fixture.output_desc,'save preserves all reviewed form fields');
  const cases=sql('SELECT is_public,is_boundary,expected_output FROM test_cases ORDER BY case_order');check(cases.length===3&&Number(cases[1].is_boundary)===1&&Number(cases[1].is_public)===0,'save preserves hidden and boundary flags');
  mode='repair';const before=calls.length;r=await teacher.chat('Cho hai số nguyên a và b, tính tổng.');check(r.draft&&calls.length===before+2,'partial streamed draft gets one contextual repair');draft=r.draft;
  const oldRetry=await teacher.json('/backend/api/ai_create_problem.php',payload);check(oldRetry.ok&&oldRetry.problem_id===results[0].problem_id,'idempotency receipt survives replacing current draft');
  mode='quota';r=await teacher.chat('Sửa giới hạn bài',{draft_id:draft.id,revision:draft.revision});const error=r.events.find(e=>e.name==='error');check(error?.data.code==='ai_quota'&&!JSON.stringify(r).includes('offline-stream-key'),'streamed provider failures are typed and sanitized');
  const retained=await teacher.json('/backend/api/ai_state.php');check(retained.draft.revision===draft.revision&&retained.draft.data.title===draft.data.title,'failed AI revision retains current draft');
  mode='partial';r=await admin.chat('Cho hai số nguyên a và b, tính tổng.');check(!r.draft&&r.events.some(e=>e.name==='error'),'repeated incomplete streams never produce a valid blank preview');
  check((await admin.json('/backend/api/ai_state.php')).draft===null,'failed generation writes no staff draft');
  mode='ok';
  const samplePage=await teacher.page('/frontend/instructor/ai_chat.php');check(samplePage.text.includes('data-chatbot')&&!samplePage.text.includes('id="ai-fab"'),'dedicated page uses shared new interface without duplicate widget');
  // Core clarification context must not contaminate a newly pasted problem.
  await admin.chat('Cho hai số nguyên a và b');
  r=await admin.chat('Tính tổng');
  let context=JSON.parse(calls.at(-1).messages[1].content);
  check(r.draft&&context.original_statement==='Cho hai số nguyên a và b\nLàm rõ của người dùng: Tính tổng','short goal continuation retains the original pending statement');
  await admin.chat('Tạo bài về mảng');
  const newStatement='Cho một chuỗi, kiểm tra đối xứng.';
  r=await admin.chat(newStatement);
  context=JSON.parse(calls.at(-1).messages[1].content);
  check(r.draft&&context.original_statement===newStatement&&!context.current_draft,'new explicit statement never inherits a stale clarification');
  await admin.chat('Cho một mảng số nguyên');
  const beforeHelp=calls.length;
  await admin.chat('Hướng dẫn nộp bài');
  await admin.chat('Tổng');
  check(calls.length===beforeHelp,'intervening help clears pending clarification instead of resuming an old task');
  mode='clarify';
  const revisionRequest='Sửa giới hạn bài';
  const retainedBefore=(await teacher.json('/backend/api/ai_state.php')).draft;
  r=await teacher.chat(revisionRequest,{draft_id:retainedBefore.id,revision:retainedBefore.revision});
  check(!r.draft&&(await teacher.json('/backend/api/ai_state.php')).draft.id===retainedBefore.id,'AI clarification during revision keeps the existing draft');
  mode='ok';
  r=await teacher.chat('Tổng',{draft_id:retainedBefore.id,revision:retainedBefore.revision});
  context=JSON.parse(calls.at(-1).messages[1].content);
  check(r.draft?.id===retainedBefore.id&&context.current_draft.title===retainedBefore.data.title&&context.original_statement===retainedBefore.source&&context.revision_request===revisionRequest+'\nLàm rõ của người dùng: Tổng','revision clarification preserves source, latest draft and unresolved instruction');
  if (process.env.AJ_BROWSER === '1') {
    mode='slow';
    await require('./chatbot_browser_smoke.cjs')({base});
  }
  console.log(`Chatbot stream HTTP checks: ${assertions} passed. Synthetic schema retained: ${dbName}`);
}finally{if(app&&!app.killed)app.kill();provider.closeAllConnections();provider.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
