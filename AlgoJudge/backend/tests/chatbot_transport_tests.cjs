// Native new streaming transport + draft pipeline. Local provider only; no DB or .env.
const assert=require('node:assert/strict');
const http=require('node:http');
const path=require('node:path');
const {spawn}=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const php=process.env.PHP_BIN||(process.platform==='win32'?'C:/xampp/php/php.exe':'php');
const fixture={title:'Tổng hai số',slug:'tong-hai-so',topic:'Cơ bản',difficulty:'Easy',suggested_lang:'cpp',
  description:'Cho hai số nguyên a và b trong [-100,100]. Tính tổng a+b.',
  input_desc:'Một dòng gồm hai số nguyên a và b cách nhau bởi dấu cách (-100 ≤ a,b ≤ 100).',
  output_desc:'In tổng a+b.',sample_input:'2 3',sample_output:'5',time_limit_ms:1000,
  assumptions:[],warnings:[],clarification:'',test_cases:[
    {stdin:'2 3',expected:'5',is_public:true,is_boundary:false},
    {stdin:'-100 -100',expected:'-200',is_public:false,is_boundary:true},
    {stdin:'-4 7',expected:'3',is_public:false,is_boundary:false}]};
let mode='ok',requests=[],timers=new Set(),checks=0;
const provider=http.createServer((req,res)=>{let raw='';req.on('data',d=>raw+=d);req.on('end',()=>{
  const payload=JSON.parse(raw);requests.push(payload);
  assert.equal(payload.stream,true);assert.equal(req.url,'/v1/chat/completions');
  assert.equal(req.headers.authorization,'Bearer local-stream-secret');
  if(mode==='timeout')return;
  if(mode==='disconnect'){req.socket.destroy();return;}
  if(mode==='quota'){res.writeHead(429);res.end('local-stream-secret private-provider-body');return;}
  res.writeHead(200,{'Content-Type':'text/event-stream'});
  if(mode==='bad-sse'){res.end('data: not-json\n\n');return;}
  if(mode==='error-event'){res.end('data: {"error":{"message":"local-stream-secret private-provider-body"}}\n\n');return;}
  let output=structuredClone(fixture);
  if(mode==='noinput')Object.assign(output,{title:'In lời chào',description:'In nguyên văn chuỗi Hello World!',input_desc:'Không có dữ liệu đầu vào.',output_desc:'In một dòng Hello World!',sample_input:'',sample_output:'Hello World!',test_cases:[{stdin:'',expected:'Hello World!',is_public:true,is_boundary:false}]});
  if(mode==='literal'){
    output.sample_input=' 2 3\n';output.sample_output=' 5\n';
    output.test_cases[0].stdin=output.sample_input;output.test_cases[0].expected=output.sample_output;
  }
  if(mode==='clarify')output.clarification='Cần tính tổng hay tìm số lớn nhất?';
  if(mode==='conflict')output.test_cases.push({stdin:'2 3',expected:'6',is_public:false,is_boundary:false});
  if(mode==='repair'&&requests.length===1)output={title:fixture.title,description:fixture.description};
  const json=mode==='bad-json'?'{"title":"unterminated':JSON.stringify(output);
  let frames=': local heartbeat\r\n\r\n';
  for(let i=0;i<json.length;i+=31)frames+='data: '+JSON.stringify({choices:[{delta:{content:json.slice(i,i+31)},finish_reason:null}]})+'\r\n\r\n';
  if(mode!=='unfinished')frames+='data: '+JSON.stringify({choices:[{delta:{},finish_reason:mode==='cutoff'?'length':mode==='blocked'?'content_filter':'stop'}]})+'\r\n\r\ndata: [DONE]\r\n\r\n';
  const bytes=Buffer.from(frames);
  // Every byte boundary, including UTF-8 codepoints and CRLF, is fragmented.
  if(mode==='fragment'){
    let i=0;const send=()=>{if(res.destroyed)return;if(i===bytes.length)return res.end();res.write(bytes.subarray(i,i+1));i++;setImmediate(send);};send();
  }else res.end(bytes);
});});
function run(next,timeout='0'){
  mode=next;requests=[];
  return new Promise((resolve,reject)=>{
    const code=`require 'backend/lib/ChatbotStore.php'; require 'backend/lib/ChatbotDraft.php'; require 'backend/lib/AIStream.php'; $events=[]; try {$r=ChatbotDraft::generate('Given two integers a and b, calculate their sum.',null,'',static function($type,$data)use(&$events){$events[]=['type'=>$type,'data'=>$data];},static function(){}); echo json_encode(['ok'=>true,'result'=>$r,'events'=>$events,'php_limit'=>(int)ini_get('max_execution_time')],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(Throwable $e){echo json_encode(['ok'=>false,'code'=>$e instanceof AIRequestException?$e->reason():($e instanceof ChatbotError?$e->reason:'unexpected'),'status'=>$e instanceof AIRequestException?$e->httpStatus():($e instanceof ChatbotError?$e->status:500),'error'=>$e->getMessage(),'fields'=>$e instanceof ChatbotError?$e->fields:[]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}`;
    const child=spawn(php,['-d','max_execution_time=1','-r',code],{cwd:root,env:{...process.env,AI_PROVIDER:'openai',AI_MODEL:'offline-model',AI_BASE_URL:`http://127.0.0.1:${provider.address().port}/v1`,OPENAI_API_KEY:'local-stream-secret',AI_TIMEOUT:timeout},stdio:['ignore','pipe','pipe'],timeout:20000});
    let out='',err='';child.stdout.on('data',d=>out+=d);child.stderr.on('data',d=>err+=d);child.on('error',reject);child.on('close',c=>{if(c!==0)return reject(Error('PHP '+c+' '+err));try{resolve(JSON.parse(out));}catch(e){reject(Error('Invalid PHP response '+err+out,{cause:e}));}});
  });
}
function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS '+label);}
function failure(result,code,calls=1){check(!result.ok&&result.code===code&&requests.length===calls,mode+' uses '+code+' and '+calls+' provider call(s)');assert.doesNotMatch(JSON.stringify(result),/local-stream-secret|private-provider-body/);}
(async()=>{await new Promise(r=>provider.listen(0,'127.0.0.1',r));try{
  let r=await run('fragment');check(r.ok&&r.result.data.title===fixture.title&&r.events.filter(e=>e.type==='field').length>=8&&r.php_limit===0,'one-byte provider SSE preserves Vietnamese and streams complete fields with unlimited PHP wait');
  r=await run('literal');check(r.ok&&r.result.data.sample_input===' 2 3\n'&&r.result.data.sample_output===' 5\n'&&r.result.data.test_cases[0].stdin===' 2 3\n','literal sample/test whitespace survives normalization');
  r=await run('noinput');check(r.ok&&r.result.data.sample_input===''&&r.result.data.test_cases.length===1&&r.result.data.test_cases[0].stdin==='','no-input constant exercise needs no invented input or duplicate tests');
  r=await run('clarify');check(r.ok&&!!r.result.clarification&&!r.result.data,'core ambiguity returns a question, never a ready draft');
  r=await run('repair');check(r.ok&&requests.length===2&&JSON.parse(requests[1].messages[1].content).previous_draft.title===fixture.title&&JSON.parse(requests[1].messages[1].content).validation_issues.length>0,'single repair receives source, prior draft and concrete validation issues');
  failure(await run('bad-sse'),'ai_stream');
  failure(await run('error-event'),'ai_provider');
  failure(await run('bad-json'),'draft_validation',2);
  failure(await run('conflict'),'draft_validation',2);
  failure(await run('cutoff'),'ai_cutoff');
  failure(await run('blocked'),'ai_incomplete');
  failure(await run('unfinished'),'ai_stream');
  failure(await run('quota'),'ai_quota');
  failure(await run('disconnect'),'ai_connection');
  const started=Date.now();r=await run('timeout','5');failure(r,'ai_timeout');check(r.status===504&&Date.now()-started>=4500&&Date.now()-started<12000&&!r.error.includes('Thiếu'),'explicit positive timeout stays bounded and is not missing-field validation');
  console.log(`New stream transport checks: ${checks} passed.`);
}finally{for(const t of timers)clearTimeout(t);provider.closeAllConnections();provider.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
