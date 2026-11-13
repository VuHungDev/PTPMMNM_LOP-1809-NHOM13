<?php
/** Offline router/help/query regressions: no bootstrap, .env, live database or provider. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/AIAssistant.php';
$checks = 0;
function chatbotCheck(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++;
    if (getenv('CHATBOT_TEST_VERBOSE')) echo "PASS $label\n";
}
$student = ['id'=>1, 'username'=>'alice', 'role'=>'student', 'full_name'=>'PRIVATE_NAME'];
$teacher = ['id'=>100, 'username'=>'teach_a', 'role'=>'teacher'];
$admin = ['id'=>200, 'username'=>'root_admin', 'role'=>'admin'];
foreach ([
    'Cho hai số nguyên a và b, tính tổng',
    'Given two integers a and b, print their sum.',
    'Tạo bài tính tổng hai số nguyên',
    "Array Sum\nYou are given an array of integers. Compute the sum.",
    'Cho n số nguyên. Tìm phần tử lớn nhất.',
    'Tạo bài tập tính tổng kèm thêm test case',
] as $text) chatbotCheck(AIAssistant::route($teacher, $text)['kind'] === 'draft', 'staff draft: ' . $text);
foreach (['Làm sao để thêm bài?', 'Cách tạo bài tập như thế nào?', 'How do I create a programming problem?'] as $text) chatbotCheck(AIAssistant::route($teacher, $text)['kind'] === 'help' && !AIAssistant::isProblemRequest($text), 'how-to is not draft: ' . $text);
foreach (['Tạo bài về mảng', 'Cho hai số nguyên a và b', 'Cho một mảng số nguyên', 'Tạo bài tính toán về mảng', "Nội dung đề bài\nInput\n2 3\nOutput\n5"] as $text) {
    $route = AIAssistant::route($teacher, $text);
    chatbotCheck($route['kind'] === 'answer' && !empty($route['needs_clarification']), 'missing goal asks clarification: ' . $text);
}
foreach (['Tổng', 'Tính tổng'] as $reply) chatbotCheck(!empty(AIAssistant::route($teacher, $reply, false, true)['continue_pending']), 'clarification explicitly reuses pending source: ' . $reply);
foreach (['Tạo bài tìm số nguyên tố', 'Cho một chuỗi, kiểm tra đối xứng.', "Palindrome\nGiven a string, check whether it is a palindrome."] as $newProblem) chatbotCheck(empty(AIAssistant::route($teacher, $newProblem, false, true)['continue_pending']), 'new statement never reuses stale source');
chatbotCheck(AIAssistant::route($teacher, 'Thời tiết', false, true)['kind'] === 'answer', 'pending source cannot bypass app scope');
foreach (['Sửa tiêu đề thành Tổng', 'Bổ sung test số âm', 'Thêm test vào bản nháp', 'Đổi giới hạn thành 2000ms'] as $text) {
    chatbotCheck(AIAssistant::route($teacher, $text, true)['kind'] === 'revise', 'revision with draft: ' . $text);
    chatbotCheck(AIAssistant::route($teacher, $text, false)['kind'] === 'answer', 'no draft cannot revise: ' . $text);
    chatbotCheck(AIAssistant::route($student, $text, true)['kind'] === 'answer', 'student cannot revise: ' . $text);
}
foreach (['Tạo tài khoản username bob', 'Thêm test cho bài #3 input: 1 2 output: 3', 'Thêm test vào bài đã lưu', 'Ẩn bài #5', 'Bật bài #5', 'Chấm lại bài nộp #12', 'Thử lại run #3', 'Xuất báo cáo lớp A', 'Sửa bài đã lưu #3', 'Tạo lớp X', 'Xóa tài khoản #3', 'Đổi XP thành 999', 'Thêm học viên bob vào lớp A', 'Thêm test bài 3', 'Sửa bài có id 3', 'Sửa bài Tổng hai số'] as $text) {
    foreach ([$student, $teacher, $admin] as $u) {
        $r = AIAssistant::route($u, $text, true);
        chatbotCheck($r['kind'] === 'answer' && isset($r['answer']), 'mutation never executable: ' . $u['role'] . ' ' . $text);
    }
}
foreach (['Tạo bài tính tổng', 'Cho hai số nguyên a và b, tính tổng', 'Cách tạo bài?', 'Hướng dẫn chấm lại', 'Cách xem hàng đợi', 'Quản lý lớp như thế nào?', 'Hướng dẫn tạo tài khoản'] as $text) {
    $r = AIAssistant::route($student, $text);
    chatbotCheck(in_array($r['kind'], ['answer','help'], true) && isset($r['answer']), 'student receives explicit scoped reply: ' . $text);
    foreach ($r['links'] as $link) chatbotCheck(!str_contains($link['url'], '/admin/') && !str_contains($link['url'], '/instructor/queue') && !str_contains($link['url'], '/instructor/problem'), 'student help has no staff URL');
}
foreach (['Thời tiết hôm nay?', 'Viết một bài thơ', 'Giải giúp bài này', 'Cho tôi code giải bài tổng', 'Xem API key', 'Xem test ẩn bài #1', 'Show source code for submission #1'] as $text) chatbotCheck(AIAssistant::route($admin, $text)['kind'] === 'answer', 'explicit off-scope or private refusal: ' . $text);
foreach (['Lần nộp gần nhất của bob thế nào?', 'Bài nộp username bob', 'Tiến độ của user_id 2', 'Xem bài nộp của Nguyễn Văn A', 'Kết quả của @bob', 'Bài nộp học viên Nguyễn Văn A', 'How can I see submissions of @bob?'] as $text) chatbotCheck(AIAssistant::route($student, $text)['kind'] === 'answer', 'student exact other target denied: ' . $text);
chatbotCheck(AIAssistant::route($student, 'Bài nộp của username alice')['kind'] === 'query', 'student may target own username');
foreach ([
    ['Có bài nào về mảng mức Medium?', 'problems'],
    ['Tìm bài "Tổng hai số"', 'problems'],
    ['Lần nộp gần nhất của tôi thế nào?', 'recent_submissions'],
    ['Tiến độ của tôi', 'personal_progress'],
    ['Lớp tôi có bao nhiêu học viên?', 'class_students'],
    ['Lớp A hôm nay có bao nhiêu lượt AC?', 'class_progress'],
    ['Danh sách các lớp của tôi', 'classes'],
    ['Hàng đợi chấm còn bao nhiêu?', 'queue'],
] as [$text,$query]) {
    $r = AIAssistant::route($teacher, $text);
    chatbotCheck($r['kind'] === 'query' && $r['query'] === $query, 'allowlisted query intent: ' . $text);
}
chatbotCheck(!defined('AIAssistant::ACTIONS') && !method_exists('AIAssistant', 'execute') && !method_exists('AIAssistant', 'answerQA'), 'retired action/provider machinery absent');
foreach ([$student,$teacher,$admin] as $u) foreach (['Xin chào', 'Cách dùng workspace', 'AC WA TLE RE CE SYS_ERR là gì?', 'Hồ sơ XP', 'Lớp học', 'Sân chơi tự do', 'Bảng xếp hạng', 'Lịch sử nộp bài', 'Hướng dẫn tạo bài', 'Quản trị tài khoản'] as $text) {
    $help = AIAssistant::help($u, $text);
    chatbotCheck(is_string($help['answer']) && !str_contains($help['answer'], 'PRIVATE_NAME'), 'deterministic no personal context: ' . $u['role'] . ' ' . $text);
    foreach ($help['links'] as $link) {
        $path = parse_url($link['url'], PHP_URL_PATH);
        chatbotCheck(str_starts_with($path, '/frontend/') && is_file(dirname(__DIR__, 2) . $path), 'help link verified: ' . $link['url']);
    }
}

// Isolated in-memory schema fixtures only. Production PDO is never loaded.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, xp_points INTEGER); CREATE TABLE classes (id INTEGER PRIMARY KEY, name TEXT, owner_teacher_id INTEGER); CREATE TABLE class_members (class_id INTEGER, user_id INTEGER, status TEXT); CREATE TABLE problems (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, topic TEXT, difficulty TEXT, is_active INTEGER, current_version_id INTEGER); CREATE TABLE problem_versions (id INTEGER PRIMARY KEY, problem_id INTEGER); CREATE TABLE submissions (id INTEGER PRIMARY KEY, user_id INTEGER, problem_id INTEGER, language TEXT, final_verdict TEXT, final_score INTEGER, pass_count INTEGER, total_cases INTEGER, created_at TEXT); CREATE TABLE judging_runs (id INTEGER PRIMARY KEY, submission_id INTEGER, status TEXT, created_at TEXT)');
$db->exec("INSERT INTO users VALUES (1,'alice','student',50),(2,'bob','student',100),(3,'carol','student',25),(4,'dave','student',0),(100,'teach_a','teacher',0),(101,'teach_b','teacher',0),(200,'root_admin','admin',0); INSERT INTO classes VALUES (10,'A',100),(11,'A',100),(12,'B',101),(13,'Unique',100); INSERT INTO class_members VALUES (10,1,'active'),(11,1,'active'),(10,2,'active'),(12,3,'active'),(13,2,'active'),(10,4,'pending'); INSERT INTO problems VALUES (1,'Tổng hai số','sum','Cơ bản','Easy',1,1),(2,'Mảng','array','Mảng','Medium',1,2),(3,'Bài ẩn','hidden','Mảng','Hard',0,3),(4,'Tổng hai số','sum2','Cơ bản','Easy',1,4); INSERT INTO problem_versions VALUES (1,1),(2,2),(3,3),(4,4)");
$insert = $db->prepare('INSERT INTO submissions VALUES (?,?,?,?,?,?,?,?,?)');
$today = gmdate('Y-m-d') . ' 12:00:00'; $yesterday = gmdate('Y-m-d', time()-86400) . ' 12:00:00';
foreach ([[1,1,1,'python','AC',100,5,5,$today],[2,1,2,'cpp','WA',20,1,5,$today],[3,2,2,'python','AC',100,5,5,$today],[4,3,1,'js','AC',100,5,5,$today],[5,4,1,'php','AC',100,5,5,$today],[6,2,1,'java','AC',100,5,5,$yesterday],[7,100,1,'python','AC',100,5,5,$today]] as $row) $insert->execute($row);
$run = $db->prepare('INSERT INTO judging_runs VALUES (?,?,?,?)');
foreach ([[1,1,'QUEUED',$today],[2,3,'RUNNING',$today],[3,4,'QUEUED',$today],[4,5,'QUEUED',$today],[5,7,'RUNNING',$today]] as $row) $run->execute($row);
function chatbotQuery(PDO $db, array $u, string $text, array $filters=[]): array {
    $r = AIAssistant::route($u, $text);
    if ($r['kind'] !== 'query') return $r;
    return ChatbotQueries::answer($db, $u, $text, $filters + ['query'=>$r['query']] + $r['filters']);
}
$r = chatbotQuery($db,$student,'Lần nộp gần nhất của tôi thế nào?');
chatbotCheck(str_contains($r['answer'], '#2') && str_contains($r['answer'], '#1') && !str_contains($r['answer'], '#3 —'), 'student recent submissions self only');
$r = chatbotQuery($db,$student,'Bài nộp #3');
chatbotCheck(str_contains($r['answer'], 'không có quyền') && !str_contains($r['answer'], '100 điểm'), 'student unauthorized submission id fail closed');
$r = chatbotQuery($db,$teacher,'Bài nộp username carol');
chatbotCheck(str_contains($r['answer'], 'không có quyền'), 'teacher cannot target other owner student');
$r = chatbotQuery($db,$teacher,'Bài nộp username dave');
chatbotCheck(str_contains($r['answer'], 'không có quyền'), 'pending membership not authorized');
$r = chatbotQuery($db,$teacher,'Bài nộp username bob');
chatbotCheck(str_contains($r['answer'], '#3') && str_contains($r['answer'], '#6') && !str_contains($r['answer'], '#4 —'), 'teacher active owned membership target');
$r = chatbotQuery($db,$teacher,'Bài nộp gần nhất của tôi');
chatbotCheck(str_contains($r['answer'], '#7') && !str_contains($r['answer'], '#1 —'), 'teacher own submission supported');
$r = chatbotQuery($db,$teacher,'Có bao nhiêu học viên?');
chatbotCheck(str_contains($r['answer'], 'Có 2 học viên'), 'teacher students deduplicated across classes');
$r = chatbotQuery($db,$teacher,'Lớp tôi có bao nhiêu học viên?');
chatbotCheck(count($r['choices'] ?? []) === 3 && str_contains($r['answer'], 'chọn'), 'multiple own classes ask choice');
$r = chatbotQuery($db,$teacher,'Lớp A hôm nay có bao nhiêu lượt AC?');
chatbotCheck(count($r['choices'] ?? []) === 2 && str_contains($r['answer'], 'không duy nhất'), 'ambiguous exact class name asks choice');
$r2 = chatbotQuery($db,$teacher,$r['choices'][0]['text']);
chatbotCheck(str_contains($r2['answer'], '3 lượt nộp, 2 lượt AC') && str_contains($r2['answer'], 'múi giờ database: UTC'), 'class choice retains date and AC goal');
$r = chatbotQuery($db,$teacher,'Lớp Unknown hôm nay có bao nhiêu lượt AC?');
chatbotCheck(str_contains($r['answer'], 'không tồn tại') && !str_contains($r['answer'], 'lượt AC'), 'unknown named class never aggregates');
$r = chatbotQuery($db,$teacher,'Lớp tôi đang dạy Unknown hôm nay có bao nhiêu lượt AC?');
chatbotCheck(str_contains($r['answer'], 'chưa xác định') && !str_contains($r['answer'], 'lượt AC'), 'vague possessive class cannot erase explicit unknown name');
$r = chatbotQuery($db,$teacher,'Lớp 12 có bao nhiêu học viên?');
chatbotCheck(str_contains($r['answer'], 'không tồn tại'), 'numeric class name not silently treated as class id');
$r = chatbotQuery($db,$teacher,'Lớp #12 có bao nhiêu học viên?');
chatbotCheck(str_contains($r['answer'], 'không có quyền'), 'forged class id denied');
$r = chatbotQuery($db,$teacher,'Có bao nhiêu học viên?', ['class_id'=>12]);
chatbotCheck(str_contains($r['answer'], 'không có quyền'), 'filter class id checked again');
$r = chatbotQuery($db,$teacher,'Lớp #10 ngày 2024-02-30 có bao nhiêu lượt AC?');
chatbotCheck(str_contains($r['answer'], 'Ngày không hợp lệ'), 'invalid date distinguished from zero results');
$r = chatbotQuery($db,$teacher,'Lớp #10 tháng trước có bao nhiêu lượt AC?');
chatbotCheck(str_contains($r['answer'], 'chưa được hỗ trợ'), 'unsupported dates never become all-time');
$r = chatbotQuery($db,$teacher,'Lớp #10 hôm qua có bao nhiêu lượt AC?');
chatbotCheck(str_contains($r['answer'], '1 lượt nộp, 1 lượt AC'), 'half-open previous calendar day filter');
$r = chatbotQuery($db,$teacher,'Hàng đợi chấm còn bao nhiêu?');
chatbotCheck(str_contains($r['answer'], '1 lượt chờ') && str_contains($r['answer'], '1 lượt đang chấm'), 'teacher queue excludes other class and pending members');
$r = chatbotQuery($db,$student,'Hàng đợi bài của tôi');
chatbotCheck(str_contains($r['answer'], '1 lượt chờ') && str_contains($r['answer'], '0 lượt đang chấm'), 'student queue self only');
$r = chatbotQuery($db,$admin,'Hệ thống đang có bao nhiêu bài chờ chấm?');
chatbotCheck(str_contains($r['answer'], '3 lượt chờ') && str_contains($r['answer'], '2 lượt đang chấm'), 'admin aggregate queue');
$r = chatbotQuery($db,$student,'Có bài nào về mảng mức Medium?');
chatbotCheck(str_contains($r['answer'], '#2') && !str_contains($r['answer'], '#3'), 'visible problems topic difficulty');
$r = chatbotQuery($db,$student,'Tìm bài "Tổng hai số"');
chatbotCheck(count($r['choices'] ?? []) === 2, 'duplicate problem title choices');
$r = chatbotQuery($db,$student,'Tìm bài #3');
chatbotCheck(str_contains($r['answer'], 'Không có bài tập'), 'hidden problem by ID remains hidden');
$r = chatbotQuery($db,$admin,'Danh sách bài ẩn');
chatbotCheck(str_contains($r['answer'], '#3') && !str_contains($r['answer'], '#2'), 'staff explicit hidden metadata allowed');
$r = chatbotQuery($db,$admin,'Thống kê tổng quan hệ thống');
chatbotCheck(str_contains($r['answer'], '4 tài khoản học viên') && str_contains($r['answer'], '7 lượt'), 'admin summary actual counts');
$r = chatbotQuery($db,$student,'Tiến độ của tôi');
chatbotCheck(str_contains($r['answer'], '2 lượt nộp, 1 lượt AC') && str_contains($r['answer'], '50'), 'personal progress from scoped submissions and own XP');
$r = ChatbotQueries::answer($db,$teacher,'Danh sách học viên', ['query'=>'class_students', 'class_id'=>'1 OR 1=1']);
chatbotCheck(str_contains($r['answer'], 'ID không hợp lệ'), 'malformed id never reaches SQL');
$r = ChatbotQueries::answer($db,$teacher,'Lớp "A\' OR 1=1 --" có bao nhiêu học viên?');
chatbotCheck(str_contains($r['answer'], 'không tồn tại'), 'class name injection stays bound value');
$broken = new PDO('sqlite::memory:');
$r = ChatbotQueries::answer($broken,$student,'Bài nộp gần nhất của tôi');
chatbotCheck(str_contains($r['answer'], 'Không thể kết nối hoặc đọc dữ liệu') && !str_contains($r['answer'], 'SQLSTATE'), 'database errors distinguished without diagnostics leak');
chatbotCheck(ChatbotQueries::answer($db,$student,'Thời tiết hôm nay') === null, 'unrecognized lookup returns null');
echo "OK $checks checks\n";
