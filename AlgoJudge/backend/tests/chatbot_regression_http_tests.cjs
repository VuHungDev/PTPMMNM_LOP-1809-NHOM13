// Existing management/manual-form regressions against a NEW, retained schema only.
// No SMTP or remote provider: both are disabled/redirected before bootstrap can read .env.
const assert = require('node:assert/strict');
const http = require('node:http');
const path = require('node:path');
const {spawn} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BIN || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
assert.equal(process.env.AJ_DB_PORT, '3307', 'Explicit isolated database port required');
const database = `algojudge_test_chat_regression_${Date.now()}_${process.pid}`;
const env = {...process.env, AJ_DB_HOST:'127.0.0.1', AJ_DB_PORT:'3307', AJ_DB_NAME:database,
  AJ_TEST_BASE_URL:'http://127.0.0.1:8088/AlgoJudge', AI_PROVIDER:'openai', AI_TIMEOUT:'5',
  OPENAI_API_KEY:'local-regression-key', GEMINI_API_KEY:'local-unused', AI_MODEL:'local-model',
  MAIL_HOST:'', MAIL_USERNAME:'', MAIL_PASSWORD:'', MAIL_FROM_ADDRESS:''};
let app, providerCalls = 0;
const provider = http.createServer((req,res) => {
  providerCalls++;
  req.resume();
  res.writeHead(502, {'Content-Type':'application/json'});
  res.end('{"error":"Unexpected provider call in application regression test"}');
});
function run(command, args) {
  return new Promise((resolve,reject) => {
    const child = spawn(command,args,{cwd:root,env,stdio:['ignore','pipe','pipe']});
    child.stdout.on('data',d=>process.stdout.write(d));
    child.stderr.on('data',d=>process.stderr.write(d));
    child.on('error',reject);
    child.on('close',code=>code===0?resolve():reject(new Error(`${command} exited ${code}`)));
  });
}
(async()=>{try {
  await new Promise(r=>provider.listen(0,'127.0.0.1',r));
  env.AI_BASE_URL=`http://127.0.0.1:${provider.address().port}/v1`;
  // Fail rather than talk to a possibly unrelated service occupying the fixed test port.
  const probe = http.createServer();
  await new Promise((resolve,reject)=>{probe.once('error',reject);probe.listen(8088,'127.0.0.1',resolve);});
  await new Promise(r=>probe.close(r));
  await run(php,['backend/tests/run_upgrade_tests.php']);
  app=spawn(php,['-S','127.0.0.1:8088','-t',path.dirname(root)],{cwd:root,env,stdio:['ignore','ignore','pipe']});
  await new Promise((resolve,reject)=>{
    let log='';const timer=setTimeout(()=>reject(new Error('PHP startup: '+log)),10000);
    app.stderr.on('data',d=>{log+=d;if(log.includes('Development Server')){clearTimeout(timer);resolve();}});
    app.once('error',reject);app.once('exit',code=>{clearTimeout(timer);reject(new Error('PHP startup exited '+code+' '+log));});
  });
  await run(process.execPath,['backend/tests/http_upgrade_tests.mjs']);
  await run(process.execPath,['backend/tests/http_audit_tests.mjs']);
  assert.equal(providerCalls,0,'Management regressions must not call AI');
  console.log('No provider calls. Synthetic schema retained: '+database);
} finally {
  if(app&&!app.killed)app.kill();
  provider.closeAllConnections();provider.close();
}})().catch(error=>{console.error(error);process.exitCode=1;});
