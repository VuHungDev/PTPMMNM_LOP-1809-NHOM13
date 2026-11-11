// Read-only HTTP regression checks against freshly created run_upgrade_tests.php fixtures.
// No real accounts, emails, judge jobs, or production database writes.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const base = process.env.AJ_TEST_BASE_URL;
const database = process.env.AJ_DB_NAME || '';
assert.equal(base, 'http://127.0.0.1:8089/AlgoJudge', 'Use the isolated PHP server on 8089');
assert.match(database, /^algojudge_test_[a-zA-Z0-9_]+$/);
assert.equal(process.env.AJ_DB_PORT, '3307');
function sql(query) {
  return execFileSync('C:/xampp/mysql/bin/mysql.exe', ['--no-defaults', '--protocol=TCP', '--host=127.0.0.1', '--port=3307', '--user=root', '--batch', '--skip-column-names', database, '-e', query], {encoding:'utf8'}).trim();
}
assert.equal(sql('SELECT DATABASE()'), database);
assert.equal(sql('SELECT COUNT(*) FROM submissions'), '35', 'Use fresh baseline fixtures');
const classes = Object.fromEntries(sql("SELECT name,id FROM classes WHERE name IN ('Test class one','Test class two')").split('\n').map(line => line.trim().split('\t')));
const ownClass = Number(classes['Test class one']);
const foreignClass = Number(classes['Test class two']);
assert.ok(ownClass > 0 && foreignClass > 0);
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; console.log('PASS ' + label); }
class Client {
  cookie = '';
  async get(path, form = null) {
    const headers = this.cookie ? {Cookie:this.cookie} : {};
    if (form) headers['Content-Type'] = 'application/x-www-form-urlencoded';
    const response = await fetch(base + path, {method:form ? 'POST' : 'GET', headers, body:form ? new URLSearchParams(form) : undefined, redirect:'manual'});
    for (const cookie of response.headers.getSetCookie()) if (cookie.startsWith('PHPSESSID=')) this.cookie = cookie.split(';')[0];
    const text = await response.text();
    assert.ok(!/Fatal error|Warning:|Parse error|Uncaught/.test(text), 'No PHP error at ' + path);
    return {status:response.status, text};
  }
  async login(name, password = 'Test-pass-2026') {
    const page = await this.get('/frontend/auth/login.php');
    const token = page.text.match(/name="csrf-token" content="([a-f0-9]+)"/)?.[1];
    assert.ok(token);
    const response = await this.get('/frontend/auth/login.php', {username:name, password, csrf_token:token});
    check(response.status === 302, 'login ' + name);
  }
}
function rows(page) { return [...page.text.matchAll(/<tr[\s>][\s\S]*?<\/tr>/g)].map(m => m[0]).filter(row => row.includes('<td') && !row.includes('colspan=')); }
function count(page) { return Number(page.text.match(/\((\d+) bản\)/)?.[1]); }
function listIds(page) { return rows(page).map(row => Number(row.match(/>#(\d+)<\/td>/)?.[1])); }
const list = '/frontend/submissions.php';
const exportPath = '/backend/api/export_excel.php?type=submissions';
const details = '/backend/api/submission_status.php?submission_id=';
const teacher = new Client(), other = new Client(), admin = new Client(), student = new Client();
await teacher.login('test_teacher1');
await other.login('test_teacher2');
await admin.login('test_admin');
await student.login('test_student1', 'New-pass-2026');
const home = await teacher.get(list);
check(home.status === 200 && count(home) === 18, 'teacher sees 18 managed-student submissions, not all 35');
check(home.text.includes('Lớp tôi quản lý') && home.text.includes('Chỉ hiển thị bài nộp'), 'teacher scope clearly described');
check(home.text.includes('Test class one') && !home.text.includes('Test class two'), 'class filter and labels hide classes owned by another teacher');
const pageTwo = await teacher.get(list + '?page=2');
const ids = [...listIds(home), ...listIds(pageTwo)].sort((a,b) => a-b);
check(JSON.stringify(ids) === JSON.stringify(Array.from({length:18}, (_,i) => 2*i+1)), 'pagination and total use the same owned-class scope without duplicates');
check(listIds(await teacher.get(list + '?page=999')).length === 3, 'out-of-range page clamps within teacher scope');
const own = await teacher.get(list + '?class_id=' + ownClass);
check(own.status === 200 && count(own) === 18, 'selected owned class stays scoped');
check(count(await teacher.get(list + '?username=test_student2')) === 0, 'username filter cannot broaden teacher scope');
check(count(await teacher.get(list + '?problem_id=2')) === 0, 'problem filter cannot expose a foreign student');
check(count(await teacher.get(list + '?verdict=AC')) === 18, 'verdict filter preserves teacher scope');
check((await teacher.get(list + '?class_id=' + foreignClass)).status === 403, 'forged foreign-class list denied');
check((await teacher.get(exportPath + '&class_id=' + foreignClass)).status === 403, 'forged foreign-class export denied');
for (const invalid of ['0','-1','2abc']) {
  check(count(await teacher.get(list + '?class_id=' + invalid)) === 18, 'invalid class filter cannot widen scope: ' + invalid);
}
const exported = await teacher.get(exportPath);
check(exported.status === 200 && rows(exported).length === 18, 'export includes all managed submissions and no others');
check(!exported.text.includes('test_student2') && !exported.text.includes('Test class two'), 'export hides foreign student and class labels');
check(rows(await teacher.get(exportPath + '&username=test_student2')).length === 0, 'export search cannot widen scope');
check(rows(await teacher.get(exportPath + '&class_id=' + ownClass)).length === 18, 'export selected class matches list');
const detail = await teacher.get(details + '1');
check(detail.status === 200 && JSON.parse(detail.text).ok, 'managed student source detail allowed');
check(!detail.text.includes('Test class two'), 'source detail hides other teachers class labels');
const denied = await teacher.get(details + '2');
check(denied.status === 403 && !denied.text.includes('source_code'), 'direct foreign submission ID denied without source');
check(count(await other.get(list)) === 35, 'second teacher sees both students enrolled in their own classes');
check(count(await other.get(list + '?class_id=' + foreignClass)) === 18, 'second teacher selected class only includes its members');
check((await other.get(list + '?class_id=' + ownClass)).status === 403, 'reverse teacher isolation');
check(count(await admin.get(list)) === 35 && rows(await admin.get(exportPath)).length === 35, 'admin retains system-wide list and export');
check(count(await student.get(list)) === 18 && rows(await student.get(exportPath)).length === 18, 'student retains own submissions only');
check((await student.get(details + '2')).status === 403, 'student cannot read another students source');
// Anonymous requests must not accidentally reveal a cached authenticated list or export.
const anonymous = new Client();
check((await anonymous.get(list)).status === 302 && (await anonymous.get(exportPath)).status === 401, 'history and export require login');
console.log(`Submission scope HTTP checks: ${checks} passed.`);
