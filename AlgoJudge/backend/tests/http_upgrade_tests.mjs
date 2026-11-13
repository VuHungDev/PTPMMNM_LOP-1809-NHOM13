// Drive actual HTTP routes using disposable fixtures from run_upgrade_tests.php.
// Never point at a working deployment; this runner is fixed to the isolated server.
import assert from 'node:assert/strict';
const base = process.env.AJ_TEST_BASE_URL;
assert.equal(base, 'http://127.0.0.1:8088/AlgoJudge', 'Start the isolated PHP server on 8088 first');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log(`PASS ${label}`); }
class Browser {
  cookie = '';
  async request(path, form = null, {json, csrf} = {}) {
    const headers = this.cookie ? {Cookie: this.cookie} : {};
    let body;
    if (form) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; body = new URLSearchParams(form); }
    if (json) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    if (csrf) headers['X-CSRF-Token'] = csrf;
    const response = await fetch(base + path, {method: body ? 'POST' : 'GET', body, headers, redirect: 'manual'});
    for (const cookie of response.headers.getSetCookie()) if (cookie.startsWith('PHPSESSID=')) this.cookie = cookie.split(';')[0];
    const text = await response.text();
    assert.ok(!/Fatal error|Warning:|Parse error|Uncaught/.test(text), `PHP error in ${path}: ${text.slice(0,500)}`);
    return {status: response.status, text, location: response.headers.get('location'), headers: response.headers};
  }
  async login(username, password = 'Test-pass-2026') {
    const page = await this.request('/frontend/auth/login.php');
    const result = await this.request('/frontend/auth/login.php', {username, password, csrf_token: token(page)});
    check(result.status === 302, `login ${username}`);
  }
}
function token(page) { const match = page.text.match(/<meta name="csrf-token" content="([a-f0-9]+)"/); assert.ok(match, 'CSRF meta exists'); return match[1]; }
function tableRows(page) { return [...page.text.matchAll(/<tr[\s>][\s\S]*?<\/tr>/g)].filter(r => r[0].includes('<td')); }
const student = new Browser(), teacher = new Browser(), admin = new Browser(), stranger = new Browser();
await student.login('test_student1', 'New-pass-2026');
await teacher.login('test_teacher1');
await admin.login('test_admin');
await stranger.login('test_teacher2');
for (const [browser, label] of [[teacher,'teacher'], [admin,'admin']]) {
  for (const path of ['/frontend/index.php', '/frontend/profile.php', '/frontend/submissions.php', '/frontend/instructor/classes.php', '/frontend/instructor/progress.php', '/frontend/instructor/rejudge.php', '/frontend/instructor/queue.php', '/frontend/instructor/dataset_versions.php', '/frontend/instructor/problems.php', '/frontend/leaderboard.php']) {
    check((await browser.request(path)).status === 200, `${label} opens ${path}`);
  }
  const profile = await browser.request('/frontend/profile.php');
  check(!profile.text.includes('Tiến độ từng bài') && !profile.text.includes('Hồ sơ &amp; điểm XP'), `${label} personal progress hidden`);
  check(label === 'admin' ? !profile.text.includes('10 bài nộp gần nhất') && profile.text.includes('Lịch sử hoạt động') && !profile.text.includes('name="new_password"') : profile.text.includes('10 bài nộp gần nhất') && profile.text.includes('OTP'), `${label} role-specific profile sections`);
  check(!(await browser.request('/frontend/index.php')).text.includes('TIẾN ĐỘ HOÀN THÀNH'), `${label} home hides own progress`);
}
const zeroProgress = await admin.request('/frontend/instructor/progress.php?q=0');
check(zeroProgress.text.includes('type=progress&q=0'), 'progress export link preserves search zero');
const zeroSubmissions = await admin.request('/frontend/submissions.php?username=0');
check(zeroSubmissions.text.includes('type=submissions&username=0'), 'submission export link preserves search zero');
for (const [path,type] of [['/frontend/instructor/progress.php','progress'],['/frontend/submissions.php','submissions'],['/frontend/leaderboard.php','leaderboard']]) {
  for (const value of ['0','2abc','-1']) {
    check((await teacher.request(path+'?class_id='+value)).status === 200 && (await teacher.request('/backend/api/export_excel.php?type='+type+'&class_id='+value)).status === 200, `${type}: invalid class filter handled consistently (${value})`);
  }
}
const aiPage = await teacher.request('/frontend/instructor/ai_chat.php');
check((aiPage.text.match(/data-chatbot data-staff=/g) || []).length === 1 && !aiPage.text.includes('data-chatbot-launcher'), 'dedicated chatbot uses one shared component without duplicate floating widget');
check(!aiPage.text.includes('Đẩy lên luôn'), 'problem AI uses requested add label');
const studentProfile = await student.request('/frontend/profile.php?page=2');
check(studentProfile.text.includes('Test problem 11') && !studentProfile.text.includes('>Test problem 1</a>'), 'student per-problem pagination');
const classHome = await student.request('/frontend/instructor/classes.php');
check(classHome.text.includes('Tham gia lớp học') && classHome.text.includes('Yêu cầu của tôi') && !classHome.text.includes('name="join_code"'), 'student home shows action buttons, not join form');
check((await student.request('/frontend/instructor/classes.php?view=join')).text.includes('name="join_code"'), 'join button destination contains invitation form');
check((await student.request('/frontend/instructor/classes.php?view=requests')).status === 200, 'own requests view opens');
check((await student.request('/frontend/leaderboard.php?page=2')).status === 200, 'student leaderboard paginated');
check((await admin.request('/frontend/admin/users.php?page=2')).text.includes('test_extra_'), 'admin users pagination');
const versions = await admin.request('/frontend/instructor/dataset_versions.php?page=3');
check(versions.status === 200 && versions.text.includes('Test problem 35'), 'version history beyond 30 rows');
const queue = await admin.request('/frontend/instructor/queue.php?page=2');
check(queue.status === 200 && queue.text.includes('page=1'), 'queue pagination beyond first 20 runs');
check((await teacher.request('/backend/api/submission_status.php?submission_id=2')).status === 403, 'teacher cannot read outside-class submission');
check((await student.request('/backend/api/submission_status.php?submission_id=1&run_id=2')).status === 404, 'run must belong to authorized submission');
check((await teacher.request('/backend/api/rejudge.php')).status === 405, 'rejudge rejects GET');
check((await teacher.request('/backend/api/rejudge.php', null, {json:{submission_id:2}})).status === 403, 'rejudge rejects missing CSRF');
const staffToken = token(await teacher.request('/frontend/profile.php'));
check((await teacher.request('/backend/api/rejudge.php', null, {json:{submission_id:2},csrf:staffToken})).status === 403, 'rejudge denies foreign student even with valid CSRF');
check((await teacher.request('/backend/api/rejudge.php', null, {json:{submission_id:1,problem_version_id:2},csrf:staffToken})).status === 400, 'rejudge rejects version from another problem');
check((await teacher.request('/backend/api/retry_run.php', null, {json:{run_id:2},csrf:staffToken})).status === 403, 'retry denies foreign student');
const create = await teacher.request('/frontend/instructor/classes.php', {action:'create_class',name:'HTTP test class',csrf_token:staffToken});
check(create.status === 303 && create.location.includes('class_id='), 'teacher creates class over HTTP');
const classId = new URL(create.location,base).searchParams.get('class_id');
const detailPath = '/frontend/instructor/classes.php?class_id=' + classId;
let detail = await teacher.request(detailPath);
const joinCode = detail.text.match(/<code[^>]*>([A-F0-9]{16})<\/code>/)?.[1];
check(!!joinCode, 'class invitation rendered');
check((await stranger.request(detailPath)).status === 403, 'other teacher denied class detail');
check((await stranger.request('/backend/api/export_excel.php?type=roster&class_id='+classId)).status === 403, 'other teacher denied roster export');
const enroll = await teacher.request(detailPath, {action:'add_student',class_id:classId,identifier:'test_student1',csrf_token:token(detail)});
check(enroll.status === 303 && (await teacher.request(detailPath)).text.includes('test_student1'), 'teacher adds existing student');
const learner = new Browser(); await learner.login('test_fresh');
const learnerClasses = await learner.request('/frontend/instructor/classes.php');
await learner.request('/frontend/instructor/classes.php', {action:'request_join',join_code:joinCode,csrf_token:token(learnerClasses)});
detail = await teacher.request(detailPath);
check(detail.text.includes('test_fresh') && detail.text.includes('Chờ duyệt'), 'join request waits for approval');
check((await learner.request(detailPath)).status === 404, 'pending student cannot read class roster');
check(!(await learner.request('/frontend/instructor/classes.php')).text.includes('HTTP test class'), 'pending class excluded from joined home');
const pendingRow = tableRows(detail).find(r => r[0].includes('test_fresh'))?.[0];
const pendingId = pendingRow?.match(/name="student_id" value="(\d+)"/)?.[1];
check(!!pendingId, 'pending request includes approval action');
await teacher.request(detailPath, {action:'approve_member',class_id:classId,student_id:pendingId,csrf_token:token(detail)});
check((await learner.request('/frontend/instructor/classes.php?view=requests')).text.includes('Đã duyệt'), 'student sees approved request status');
const studentRoster = await learner.request(detailPath);
check(studentRoster.status === 200 && studentRoster.text.includes('test_student1') && studentRoster.text.includes('test_teacher1'), 'approved student reads teacher and classmates');
check(!studentRoster.text.includes(joinCode) && !studentRoster.text.includes('name="student_id"') && !studentRoster.text.includes('test_student1@example.test'), 'student roster has no invitation, management controls or peer email');
check((await learner.request('/backend/api/export_excel.php?type=roster&class_id='+classId)).status === 403, 'student roster remains non-exportable');
const selected = await teacher.request('/backend/api/export_excel.php?type=submissions&class_id='+classId+'&username=test_student1');
check(selected.status === 200 && selected.headers.get('content-disposition')?.includes('.xls') && tableRows(selected).length === 18, 'submissions export obeys selected class and username');
check(!selected.text.includes('Test class two'), 'teacher export hides other teachers class names');
check(selected.text.includes('&#039;=SUM(1+1)'), 'Excel formula-like names are neutralized');
check(tableRows(await teacher.request('/backend/api/export_excel.php?type=submissions&class_id='+classId+'&username=test_student2')).length === 0, 'export intersects username with class scope');
for (const type of ['progress','leaderboard','problems']) check((await teacher.request('/backend/api/export_excel.php?type='+type)).status === 200, `teacher ${type} export`);
check((await student.request('/backend/api/export_excel.php?type=users')).status === 403, 'student cannot export users');
const usersExport = await admin.request('/backend/api/export_excel.php?type=users&role=student&q=test_extra_1');
check(tableRows(usersExport).length === 11, 'users export includes full filtered result, not current page');
check(tableRows(await admin.request('/backend/api/export_excel.php?type=users&q=example.test')).length === 0, 'users export q matches screen, not email');
// All chatbot account mutations are retired, including for administrators.
// These local refusals must not call a provider or produce confirmation tokens.
const aiText = 'tạo tài khoản tên Test AI Learner, username test_ai_created, email test_ai_created@example.test';
const aiDenied = await teacher.request('/backend/api/ai_chat.php', null, {json:{text:aiText},csrf:staffToken});
check(aiDenied.headers.get('content-type').includes('text/event-stream') && aiDenied.text.includes('event: answer') && !aiDenied.text.includes('event: draft'), 'teacher gets streamed guidance rather than account mutation');
const adminToken = token(await admin.request('/frontend/profile.php'));
const adminRefusal = await admin.request('/backend/api/ai_chat.php', null, {json:{text:aiText},csrf:adminToken});
check(adminRefusal.text.includes('event: answer') && !adminRefusal.text.includes('confirm_token'), 'admin chatbot account mutations are also retired');
check((await admin.request('/backend/api/ai_chat.php', null, {json:{confirm_token:'retired-test-token'},csrf:adminToken})).status === 410, 'old confirmation tokens are permanently retired');
const oldSession = new Browser(); await oldSession.login('test_fresh');
const changeProfile = await learner.request('/frontend/profile.php');
const deniedEmail = await learner.request('/frontend/profile.php', {full_name:'HTTP Learner', email:'changed@example.test',current_password:'Test-pass-2026',new_password:'Changed-pass-2026',confirm_password:'Changed-pass-2026',csrf_token:token(changeProfile)});
check(deniedEmail.status === 303 && (await learner.request('/frontend/profile.php')).text.includes('Yêu cầu không hợp lệ'), 'legacy direct password/email update cannot bypass OTP');
const namePage = await learner.request('/frontend/profile.php');
const changedProfile = await learner.request('/frontend/profile.php', {action:'save_name',full_name:'HTTP Learner',csrf_token:token(namePage)});
check(changedProfile.status === 303 && (await learner.request('/frontend/profile.php')).text.includes('HTTP Learner'), 'non-sensitive name edit still works');
check(JSON.parse((await oldSession.request('/backend/api/session_status.php')).text).valid === true, 'name-only update does not revoke unrelated session');
await (new Browser()).login('test_fresh');
const unavailable = await learner.request('/frontend/profile.php', {action:'request_password',current_password:'Test-pass-2026',csrf_token:token(await learner.request('/frontend/profile.php'))});
check(unavailable.status === 303 && (await learner.request('/frontend/profile.php')).text.includes('chưa sẵn sàng'), 'profile OTP fails closed without SMTP');
const forgedAdmin = await admin.request('/frontend/profile.php', {action:'request_password',current_password:'Test-pass-2026',csrf_token:adminToken});
check(forgedAdmin.status === 303 && (await admin.request('/frontend/profile.php')).text.includes('chỉ được xem'), 'admin cannot forge profile credential changes');
check((await admin.request('/frontend/profile.php')).text.includes('Lịch sử hoạt động'), 'admin history remains available after chatbot mutation retirement');
for (const browser of [teacher,admin]) {
  check(!Object.hasOwn(JSON.parse((await browser.request('/backend/api/session_status.php')).text).user,'xp_points'), 'staff session API hides own XP');
}
check(Object.hasOwn(JSON.parse((await student.request('/backend/api/session_status.php')).text).user,'xp_points'), 'student session API retains XP');
const forgot = await (new Browser()).request('/frontend/auth/forgot_password.php');
check(forgot.status === 200 && forgot.text.includes('chưa được cấu hình'), 'missing SMTP fails safely');
console.log(`HTTP checks: ${checks} passed.`);
