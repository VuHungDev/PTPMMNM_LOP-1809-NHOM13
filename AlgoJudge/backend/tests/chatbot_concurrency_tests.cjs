// Two real PHP processes share only a NEW synthetic DB and temporary session directory.
// Unlike Promise.all against one PHP dev server, this actually overlaps HTTP requests on Windows.
const assert=require('node:assert/strict');
const http=require('node:http');
const net=require('node:net');
const path=require('node:path');
const os=require('node:os');
const fs=require('node:fs');
const {spawn,execFileSync}=require('node:child_process');
const {consumeSSE}=require('../../frontend/assets/js/modules/chatbot.js');
const root=path.resolve(__dirname,'../..');
const php=process.env.PHP_BIN||(process.platform==='win32'?'C:/xampp/php/php.exe':'php');
assert.equal(process.env.AJ_DB_PORT,'3307','Explicit isolated database port required');
const database=`algojudge_test_ai_concurrency_${Date.now()}_${process.pid}`;
const sessionDir=fs.mkdtempSync(path.join(os.tmpdir(),'chatbot-session-test-'));
const env={...process.env,AJ_DB_HOST:'127.0.0.1',AJ_DB_PORT:'3307',AJ_DB_NAME:database,
  AI_PROVIDER:'openai',OPENAI_API_KEY:'local-concurrency-key',GEMINI_API_KEY:'local-unused',AI_MODEL:'local-model',AI_TIMEOUT:'0',
  MAIL_HOST:'',MAIL_USERNAME:'',MAIL_PASSWORD:'',MAIL_FROM_ADDRESS:''};
const fixture={title:'Tổng hai số',slug:'concurrency-sum',topic:'Cơ bản',difficulty:'Easy',suggested_lang:'cpp',
  description:'Cho hai số nguyên a và b thuộc [-100,100]. Tính tổng a+b.',input_desc:'Một dòng gồm hai số nguyên a và b cách nhau bởi dấu cách (-100 ≤ a,b ≤ 100).',
  output_desc:'In tổng a+b.',sample_input:'2 3',sample_output:'5',time_limit_ms:1000,assumptions:[],warnings:[],clarification:'',
  test_cases:[{stdin:'2 3',expected:'5',is_public:true,is_boundary:false},{stdin:'-100 -100',expected:'-200',is_public:false,is_boundary:true},{stdin:'-4 7',expected:'3',is_public:false,is_boundary:false}]};
let mode='ok',checks=0,providerCalls=0;
const apps=[],pending=[],logs=[];
const provider=http.createServer((req,res)=>{let raw='';req.on('data',d=>raw+=d);req.on('end',()=>{
  const body=JSON.parse(raw);assert.equal(body.stream,true);providerCalls++;
  res.writeHead(200,{'Content-Type':'text/event-stream'});
  if(mode==='gate'){
    const prefix=JSON.stringify(fixture).slice(0,180);
    res.write('data: '+JSON.stringify({choices:[{delta:{content:prefix},finish_reason:null}]})+'\n\n');
    pending.push({res,prefix});
  }else{
    res.end('data: '+JSON.stringify({choices:[{delta:{content:JSON.stringify(fixture)},finish_reason:null}]})+'\n\ndata: {"choices":[{"delta":{},"finish_reason":"stop"}]}\n\ndata: [DONE]\n\n');
  }
});});
function check(value,label){assert.ok(value,label);checks++;console.log('PASS '+label);}
function sql(query){return JSON.parse(execFileSync(php,['-r',`$n=getenv('AJ_DB_NAME');if(!preg_match('/^algojudge_test_ai_[a-zA-Z0-9_]+$/D',$n))exit(1);$p=new PDO('mysql:host=127.0.0.1;port='.getenv('AJ_DB_PORT').';dbname='.$n.';charset=utf8mb4',getenv('AJ_DB_USER')?:'root',getenv('AJ_DB_PASS')?:'');$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);echo json_encode($p->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));`,query],{cwd:root,env,encoding:'utf8',timeout:12000}));}
const counts=()=>sql('SELECT (SELECT COUNT(*) FROM problems) p,(SELECT COUNT(*) FROM problem_versions) v,(SELECT COUNT(*) FROM test_cases) t,(SELECT COUNT(*) FROM admin_activity_logs) a,(SELECT COUNT(*) FROM chatbot_creations) r')[0];
async function freePort(){const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const port=s.address().port;await new Promise(r=>s.close(r));return port;}
async function launch(){const port=await freePort();const child=spawn(php,['-d',`session.save_path=${sessionDir}`,'-S',`127.0.0.1:${port}`,'-t',path.dirname(root)],{cwd:root,env,stdio:['ignore','ignore','pipe']});apps.push(child);const index=logs.push('')-1;child.stderr.on('data',d=>{logs[index]+=d;});
  await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error(log)),10000);child.stderr.on('data',d=>{log+=d;if(log.includes('Development Server')){clearTimeout(timer);resolve();}});child.once('error',reject);child.once('exit',code=>{clearTimeout(timer);reject(Error('PHP exited '+code+' '+log));});});return `http://127.0.0.1:${port}/AlgoJudge`;}
class Client{
  cookie='';csrf='';
  async request(base,route,body,form=false){const r=await fetch(base+route,{method:body?'POST':'GET',redirect:'manual',signal:AbortSignal.timeout(18000),headers:{Cookie:this.cookie,...(body?{'Content-Type':form?'application/x-www-form-urlencoded':'application/json','X-CSRF-Token':this.csrf}:{})},body:body?(form?new URLSearchParams({...body,csrf_token:this.csrf}):JSON.stringify(body)):undefined});for(const c of r.headers.getSetCookie())if(c.startsWith('PHPSESSID='))this.cookie=c.split(';')[0];return r;}
  async page(base,route,body,form=false){const r=await this.request(base,route,body,form),text=await r.text();assert.doesNotMatch(text,/Fatal error|Warning:|Notice:|Parse error/);this.csrf=text.match(/name="csrf_token" value="([a-f0-9]+)"/)?.[1]||text.match(/name="csrf-token" content="([a-f0-9]+)"/)?.[1]||this.csrf;return {status:r.status,text};}
  async login(base,role='teacher'){await this.page(base,'/frontend/auth/login.php');assert.equal((await this.page(base,'/frontend/auth/login.php',{username:'ai_test_'+role,password:'Ai-test-only-2026'},true)).status,302);await this.page(base,'/frontend/index.php');}
  async json(base,route,body){const r=await this.request(base,route,body);return {status:r.status,...await r.json()};}
  async chat(base,text,extra={}){const r=await this.request(base,'/backend/api/ai_chat.php',{text,...extra});assert.match(r.headers.get('content-type'),/text\/event-stream/);let events=[];const done=await consumeSSE(r,(name,data)=>events.push({name,data}));return {events,done,draft:events.findLast(e=>e.name==='draft')?.data.draft};}
}
async function staged(client,base,draft){
  // Return a holder: returning the promise directly would await the whole gated stream.
  let firstResolve,firstReject;const first=new Promise((r,j)=>{firstResolve=r;firstReject=j;});
  const timer=setTimeout(()=>firstReject(Error('No staged field arrived')),8000);
  const completion=(async()=>{try{const response=await client.request(base,'/backend/api/ai_chat.php',{text:'Sửa tiêu đề cho rõ hơn',draft_id:draft.id,revision:draft.revision});let events=[];const done=await consumeSSE(response,(name,data)=>{events.push({name,data});if(name==='field'){clearTimeout(timer);firstResolve();}});return {events,done};}catch(error){firstReject(error);throw error;}finally{clearTimeout(timer);}})();completion.catch(()=>{});await first;return {completion};
}
function flushPending(){for(const item of pending.splice(0)){if(item.res.destroyed)continue;item.res.end('data: '+JSON.stringify({choices:[{delta:{content:JSON.stringify(fixture).slice(item.prefix.length)},finish_reason:null}]})+'\n\ndata: {"choices":[{"delta":{},"finish_reason":"stop"}]}\n\ndata: [DONE]\n\n');}}
(async()=>{try{
  console.log(execFileSync(php,['backend/tests/ai_http_fixture.php'],{cwd:root,env,encoding:'utf8'}).trim());
  await new Promise(r=>provider.listen(0,'127.0.0.1',r));env.AI_BASE_URL=`http://127.0.0.1:${provider.address().port}/v1`;
  const a=await launch(),b=await launch(),teacher=new Client();await teacher.login(a);
  let draft=(await teacher.chat(a,'Cho hai số nguyên a và b, tính tổng.')).draft;assert.ok(draft);
  mode='gate';let stream=await staged(teacher,a,draft);
  const start=Date.now();let state=await teacher.json(b,'/backend/api/ai_state.php');
  check(state.processing&&Date.now()-start<2500,'second process can read the same PHP session while provider waits');
  const busy=await teacher.json(b,'/backend/api/ai_chat.php',{text:'Xin chào'});
  check(busy.status===409&&busy.code==='chat_busy','second simultaneous chat is rejected by the server lease');
  const uid=Number(sql("SELECT id FROM users WHERE username='ai_test_teacher'")[0].id);
  sql('UPDATE users SET xp_points=xp_points WHERE id='+uid);
  check(true,'provider wait holds no users row transaction lock');
  const stopped=await teacher.json(b,'/backend/api/ai_draft.php',{action:'stop'});assert.equal(stopped.ok,true);
  let result=await stream.completion;
  check(!result.done.ok&&result.events.some(e=>e.name==='error'&&e.data.code==='cancelled')&&!result.events.some(e=>e.name==='draft'),'midstream stop prevents late final draft');
  state=await teacher.json(b,'/backend/api/ai_state.php');
  check(!state.processing&&state.draft.revision===draft.revision&&state.draft.id===draft.id,'stop retains the previous reviewed draft');
  flushPending();
  // Changing auth_version in the database is observed before any accepted draft.
  stream=await staged(teacher,a,draft);sql('UPDATE users SET auth_version=auth_version+1 WHERE id='+uid);
  result=await stream.completion;
  check(!result.done.ok&&result.events.some(e=>e.name==='error'&&e.data.code==='auth_expired')&&!result.events.some(e=>e.name==='draft'),'midstream auth-version revocation blocks the final result');
  flushPending();
  await teacher.login(b);mode='ok';draft=(await teacher.chat(a,'Cho hai số nguyên a và b, tính tổng.')).draft;
  mode='gate';stream=await staged(teacher,a,draft);sql("UPDATE users SET role='student' WHERE id="+uid);
  result=await stream.completion;
  check(!result.done.ok&&result.events.some(e=>e.name==='error'&&e.data.code==='auth_expired')&&!result.events.some(e=>e.name==='draft'),'midstream teacher downgrade blocks the final result');
  flushPending();sql("UPDATE users SET role='teacher' WHERE id="+uid);
  await teacher.login(b);mode='ok';
  draft=(await teacher.chat(a,'Cho hai số nguyên a và b, tính tổng.')).draft;
  mode='gate';stream=await staged(teacher,a,draft);
  const logout=await teacher.page(b,'/frontend/auth/logout.php');assert.equal(logout.status,302);
  result=await stream.completion;
  check(!result.done.ok&&result.events.some(e=>e.name==='error'&&e.data.code==='auth_expired')&&!result.events.some(e=>e.name==='draft'),'logout from another process revokes an active stream without a session-lock stall');
  flushPending();
  // Failure after all exercise writes (the audit insert) must roll everything back.
  const admin=new Client();await admin.login(b,'admin');mode='ok';const ad=(await admin.chat(a,'Cho hai số nguyên a và b, tính tổng.')).draft;
  const payload={draft_id:ad.id,revision:ad.revision,idempotency_key:ad.save_key};
  const initial=counts();const trigger='chatbot_test_audit_failure';
  sql(`CREATE TRIGGER ${trigger} BEFORE INSERT ON admin_activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='local audit failure'`);
  try{
    const failed=await admin.json(b,'/backend/api/ai_create_problem.php',payload);
    check(failed.status===500&&failed.code==='server_error'&&!JSON.stringify(failed).includes('local audit failure'),'save failure is sanitized rather than leaking database details');
    check(JSON.stringify(counts())===JSON.stringify(initial),'audit failure rolls back problem, version, tests and idempotency receipt');
    state=await admin.json(b,'/backend/api/ai_state.php');
    check(state.draft.status==='review'&&state.draft.id===ad.id&&state.draft.revision===ad.revision,'failed save keeps the exact draft for a safe retry');
  }finally{sql('DROP TRIGGER '+trigger);}
  // Hold the target row, then dispatch to both PHP processes. Both HTTP saves
  // are waiting concurrently, not merely serialized by a single dev server.
  const lockCode=`$p=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.getenv('AJ_DB_NAME').';charset=utf8mb4',getenv('AJ_DB_USER')?:'root',getenv('AJ_DB_PASS')?:'');$p->beginTransaction();$p->query(\"SELECT id FROM users WHERE username='ai_test_admin' FOR UPDATE\")->fetch();fwrite(STDOUT,\"locked\\n\");fflush(STDOUT);fgets(STDIN);$p->commit();`;
  const locker=spawn(php,['-r',lockCode],{cwd:root,env,stdio:['pipe','pipe','pipe']});
  await new Promise((resolve,reject)=>{locker.stdout.once('data',resolve);locker.once('error',reject);locker.once('exit',c=>{if(c!==0)reject(Error('Lock helper '+c));});});
  const left=admin.json(a,'/backend/api/ai_create_problem.php',payload),right=admin.json(b,'/backend/api/ai_create_problem.php',payload);
  await new Promise(r=>setTimeout(r,200));locker.stdin.end('release\n');
  const saved=await Promise.all([left,right]);
  check(saved.every(x=>x.ok)&&saved[0].problem_id===saved[1].problem_id&&Number(counts().p)===1&&Number(counts().v)===1&&Number(counts().t)===3&&Number(counts().a)===1&&Number(counts().r)===1,'two real PHP processes confirm concurrently and create exactly one complete audited problem');
  check((await admin.json(b,'/backend/api/ai_create_problem.php',payload)).problem_id===saved[0].problem_id,'retry after concurrent save returns the same receipt');
  check(logs.every(log=>!/PHP (?:Warning|Fatal|Notice|Parse)/.test(log)),'no PHP session warnings or runtime errors in either concurrent server');
  console.log(`Chatbot concurrency checks: ${checks} passed. Local provider calls: ${providerCalls}. Synthetic schema retained: ${database}. Session directory retained: ${sessionDir}`);
}catch(error){
  for(const [index,log] of logs.entries())console.error('PHP test log '+index+'\n'+log.slice(-6000));
  throw error;
}finally{
  for(const app of apps)if(!app.killed)app.kill();
  provider.closeAllConnections();provider.close();
}})().catch(error=>{console.error(error);process.exitCode=1;});
