// HTTP mutations + DB assertions only on the explicitly isolated test deployment.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const base=process.env.AJ_TEST_BASE_URL, db=process.env.AJ_DB_NAME;
assert.equal(base,'http://127.0.0.1:8088/AlgoJudge');
assert.match(db||'',/^algojudge_test_[a-zA-Z0-9_]+$/);
assert.equal(process.env.AJ_DB_PORT,'3307');
const mysql='C:/xampp/mysql/bin/mysql.exe';
const sql=q=>execFileSync(mysql,['--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=3307','--user=root','--batch','--skip-column-names','--database='+db,'--execute='+q],{encoding:'utf8'}).trim();
let count=0;
const check=(v,label)=>{assert.ok(v,label);count++;console.log('PASS '+label);};
class Client {
  cookie='';
  async request(path,form=null,json=null){
    const headers={Cookie:this.cookie};let body;
    if(form){headers['Content-Type']='application/x-www-form-urlencoded';body=new URLSearchParams(form);}
    if(json){headers['Content-Type']='application/json';headers['X-CSRF-Token']=this.csrf;body=JSON.stringify(json);}
    const r=await fetch(base+path,{method:body?'POST':'GET',headers,body,redirect:'manual'});
    for(const c of r.headers.getSetCookie())if(c.startsWith('PHPSESSID='))this.cookie=c.split(';')[0];
    const text=await r.text();assert.ok(!/Fatal error|Warning:|Parse error|Uncaught/.test(text),path);
    this.csrf=text.match(/<meta name="csrf-token" content="([a-f0-9]+)"/)?.[1]||this.csrf;
    return {text,status:r.status,location:r.headers.get('location')};
  }
  async login(name){await this.request('/frontend/auth/login.php');assert.equal((await this.request('/frontend/auth/login.php',{username:name,password:'Test-pass-2026',csrf_token:this.csrf})).status,302);await this.request('/frontend/profile.php');}
  post(path,fields){return this.request(path,{...fields,csrf_token:this.csrf});}
}
const admin=new Client(), teacher=new Client();await admin.login('test_admin');await teacher.login('test_teacher1');
const actor=Number(sql("SELECT id FROM users WHERE username='test_admin'"));
const total=()=>Number(sql('SELECT COUNT(*) FROM admin_activity_logs WHERE actor_id='+actor));
const latest=()=>sql('SELECT action FROM admin_activity_logs WHERE actor_id='+actor+' ORDER BY id DESC LIMIT 1');
const prefix='ha_'+Date.now();let n=total();
await admin.post('/frontend/admin/user_form.php',{t_username:prefix,t_email:prefix+'@example.test',t_fullname:'Audit HTTP test',t_role:'student',t_password:'Audit-pass-2026',t_password_confirm:'Audit-pass-2026'});
const uid=Number(sql("SELECT id FROM users WHERE username='"+prefix+"'"));
check(uid>0 && total()===++n && latest()==='user.create','account create emits one event');
await admin.post('/frontend/admin/users.php',{action:'set_role',target_id:uid,role:'student'});
check(total()===n,'same role no-op emits no event');
await admin.post('/frontend/admin/users.php',{action:'set_role',target_id:uid,role:'teacher'});
check(total()===++n && latest()==='user.role_change','role change emits one event');
for(const action of ['toggle_lock','toggle_lock','reset_pwd']){
  await admin.post('/frontend/admin/users.php',{action,target_id:uid,new_password:'Changed-audit-2026'});
  check(total()===++n,'admin '+action+' emits one event');
}
await admin.post('/frontend/admin/users.php',{action:'delete_user',target_id:uid});
check(sql('SELECT COUNT(*) FROM users WHERE id='+uid)==='0' && total()===++n && latest()==='user.delete','deleted account keeps immutable event');
await admin.post('/frontend/admin/users.php',{action:'delete_user',target_id:uid});check(total()===n,'absent account delete not recorded');
// The chatbot no longer accepts raw create payloads. Audit this existing manual
// form without provider access; the dedicated stream suite covers chatbot save.
const slug='audit-'+prefix.replaceAll('_','-');
const result=await admin.post('/frontend/instructor/problem_form.php',{action:'create',title:'Audit '+prefix,slug,topic:'Cơ bản',difficulty:'Easy',suggested_lang:'cpp',description:'Disposable audit test',input_desc:'Một số nguyên.',output_desc:'In lại số đã nhập.',sample_input:'1',sample_output:'1',time_limit_ms:'1000','test_stdin[0]':'1','test_expected[0]':'1','test_public[0]':'1','test_boundary[0]':'0'});
assert.equal(result.status,302);
const pid=Number(sql("SELECT id FROM problems WHERE slug='"+slug+"'"));check(pid>0 && total()===++n && latest()==='problem.create','manual problem save emits one event without provider request');
await teacher.post('/frontend/instructor/problems.php',{action:'toggle',problem_id:pid});check(total()===n,'teacher toggle not attributed to admin');
await admin.post('/frontend/instructor/problems.php',{action:'toggle',problem_id:pid});check(total()===++n && latest()==='problem.enable','admin toggle action matches resulting state');
await admin.post('/frontend/instructor/dataset_versions.php',{action:'create_version',problem_id:pid,time_limit_ms:1000,changelog:'Test audit',extra_stdin:'2',extra_expected:'2'});
check(total()===++n && latest()==='dataset.create','dataset version and event committed');
const edit=await admin.request('/frontend/instructor/problem_form.php?edit='+pid);
check(edit.text.includes('name="problem_id" value="'+pid+'"'),'edit uses problem id after version ids diverge');
await admin.post('/frontend/instructor/problems.php',{action:'delete',problem_id:pid});check(total()===++n && latest()==='problem.delete' && sql('SELECT COUNT(*) FROM problems WHERE id='+pid)==='0','problem deletion keeps event');
await admin.post('/frontend/instructor/problems.php',{action:'delete',problem_id:pid});check(total()===n,'absent problem deletion not logged');
const teacherId=Number(sql("SELECT id FROM users WHERE username='test_teacher1'"));
const cls=await admin.post('/frontend/instructor/classes.php',{action:'create_class',name:'Audit '+prefix,owner_teacher_id:teacherId});
const classId=Number(new URL(cls.location,base).searchParams.get('class_id'));
check(classId>0 && total()===++n && latest()==='class.create','admin class creation audited through shared service');
await admin.post('/frontend/instructor/classes.php?class_id='+classId,{action:'assign_owner',class_id:classId,owner_teacher_id:teacherId});check(total()===n,'same class owner creates no duplicate event');
// Simulate audit storage failure in ONLY the isolated schema and restore it immediately.
const trigger='audit_fail_'+Date.now();
sql(`CREATE TRIGGER ${trigger} BEFORE INSERT ON admin_activity_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated audit write failure'`);
try{
  const name=prefix+'_rollback';
  await admin.post('/frontend/admin/user_form.php',{t_username:name,t_email:name+'@example.test',t_fullname:'Rollback test',t_role:'student',t_password:'Audit-pass-2026',t_password_confirm:'Audit-pass-2026'});
  check(sql("SELECT COUNT(*) FROM users WHERE username='"+name+"'")==='0' && total()===n,'audit storage failure rolls back actual HTTP account creation');
}finally{sql('DROP TRIGGER '+trigger);}
const payloads=sql('SELECT metadata FROM admin_activity_logs WHERE actor_id='+actor);
check(!/Audit-pass|Changed-audit|@example|password_hash|otp_hash/.test(payloads),'audit metadata excludes credentials and email');
const page=await admin.request('/frontend/profile.php');check(page.text.includes('Tạo tài khoản') && page.text.includes('Tạo lớp học') && !page.text.includes('name="new_password"'),'admin profile displays genuine mutations without password control');
console.log(`HTTP audit checks: ${count} passed.`);
