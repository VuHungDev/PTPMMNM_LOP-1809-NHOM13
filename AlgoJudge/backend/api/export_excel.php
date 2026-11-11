<?php
require_once __DIR__ . '/../lib/Auth.php';

$viewer = Auth::user();
if (!$viewer) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    die('Hết phiên đăng nhập. Vui lòng đăng nhập lại rồi xuất Excel.');
}
$type = (string)($_GET['type'] ?? 'submissions');
$fProblem = max(0, (int)($_GET['problem_id'] ?? 0));
$fVerdict = (string)($_GET['verdict'] ?? '');
$fClass = filter_var($_GET['class_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$fUser = trim((string)($_GET['username'] ?? ''));
$fSearch = trim((string)($_GET['q'] ?? ''));
$allowedVerdicts = ['QUEUED', 'RUNNING', 'AC', 'WA', 'TLE', 'RE', 'CE', 'SYS_ERR'];
if (!in_array($fVerdict, $allowedVerdicts, true)) $fVerdict = '';
$date = date('Ymd_Hi');
$classNamesSql = Auth::classNamesSql($viewer);
if ($viewer['role'] === 'teacher' && $fClass > 0 && !Auth::teacherOwnsClass((int)$viewer['id'], $fClass)) {
    http_response_code(403);
    die('Bạn không quản lý lớp này.');
}

function xlsCell($value): string
{
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=+@\-\t\r]/', $value)) $value = "'" . $value;
    return '<td>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
}

function xlsStart(string $filename, array $headers): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"UTF-8\"></head><body><table border=\"1\"><tr>";
    foreach ($headers as $header) echo '<th>' . htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
    echo '</tr>';
}

function xlsRow(array $values): void
{
    echo '<tr>';
    foreach ($values as $value) echo xlsCell($value);
    echo '</tr>';
}

function xlsEnd(): void
{
    echo '</table></body></html>';
    exit;
}

function exportTeacherClassPredicate(array $viewer, int $classId, array &$params, string $alias = 'u'): string
{
    if ($viewer['role'] === 'admin') {
        if ($classId > 0) {
            $params[] = $classId;
            return "EXISTS (SELECT 1 FROM class_members cm_scope WHERE cm_scope.user_id = $alias.id AND cm_scope.class_id = ? AND cm_scope.status = 'active')";
        }
        return '1=1';
    }
    if ($classId > 0) {
        $params[] = $classId;
        $params[] = (int)$viewer['id'];
        return "($alias.role = 'student' AND EXISTS (SELECT 1 FROM class_members cm_scope JOIN classes c_scope ON c_scope.id = cm_scope.class_id WHERE cm_scope.user_id = $alias.id AND cm_scope.class_id = ? AND cm_scope.status = 'active' AND c_scope.owner_teacher_id = ?))";
    }
    $params[] = (int)$viewer['id'];
    return Auth::teacherStudentScope($alias);
}

if ($type === 'progress') {
    if (!in_array($viewer['role'], ['teacher', 'admin'], true)) {
        http_response_code(403);
        die('Báo cáo tiến độ chỉ dành cho giảng viên và quản trị viên.');
    }
    $params = [];
    $scope = exportTeacherClassPredicate($viewer, $fClass, $params);
    $sql = "SELECT u.username, u.full_name,
                   $classNamesSql AS class_names,
                   (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_total,
                   (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s JOIN problems p ON p.id = s.problem_id WHERE s.user_id = u.id AND s.final_verdict = 'AC' AND p.is_active = 1) AS solved,
                   (SELECT MAX(s.created_at) FROM submissions s WHERE s.user_id = u.id) AS last_submit
            FROM users u WHERE u.role = 'student' AND $scope";
    if ($fSearch !== '') {
        $sql .= ' AND (u.username LIKE ? OR u.full_name LIKE ?)';
        $params[] = '%' . $fSearch . '%';
        $params[] = '%' . $fSearch . '%';
    }
    $sql .= ' ORDER BY solved DESC, sub_total DESC, u.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $totalProblems = (int)$pdo->query('SELECT COUNT(*) FROM problems WHERE is_active = 1')->fetchColumn();
    xlsStart("algojudge_tiendo_$date.xls", ['Username', 'Họ tên', 'Lớp', 'Bài đã giải (AC)', 'Tổng bài', 'Tổng lượt nộp', 'Tỉ lệ AC', 'Lần nộp cuối']);
    foreach ($rows as $r) {
        $rate = $totalProblems > 0 ? round((int)$r['solved'] / $totalProblems * 100, 1) . '%' : '0%';
        xlsRow([$r['username'], $r['full_name'], $r['class_names'] ?: '—', $r['solved'], $totalProblems, $r['sub_total'], $rate, $r['last_submit'] ?? '']);
    }
    xlsEnd();
}

if ($type === 'submissions') {
    $where = [];
    $params = [];
    if ($viewer['role'] === 'student') {
        $where[] = 's.user_id = ?';
        $params[] = (int)$viewer['id'];
    } elseif ($viewer['role'] === 'teacher') {
        $where[] = exportTeacherClassPredicate($viewer, $fClass, $params);
    } elseif ($fClass > 0) {
        $where[] = "EXISTS (SELECT 1 FROM class_members cm WHERE cm.user_id = u.id AND cm.class_id = ? AND cm.status = 'active')";
        $params[] = $fClass;
    }
    if ($fProblem > 0) { $where[] = 's.problem_id = ?'; $params[] = $fProblem; }
    if ($fVerdict !== '') { $where[] = 's.final_verdict = ?'; $params[] = $fVerdict; }
    if ($fUser !== '') { $where[] = 'u.username LIKE ?'; $params[] = '%' . $fUser . '%'; }
    $sql = "SELECT s.id, u.username, u.full_name,
                   $classNamesSql AS class_names,
                   p.title AS problem_title, s.language, pv.version_number, s.final_verdict, s.final_score, s.pass_count, s.total_cases, s.created_at
            FROM submissions s JOIN users u ON u.id = s.user_id JOIN problems p ON p.id = s.problem_id JOIN problem_versions pv ON pv.id = s.problem_version_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    xlsStart("algojudge_bainop_$date.xls", ['ID', 'Username', 'Họ tên', 'Lớp', 'Bài tập', 'Ngôn ngữ', 'Phiên bản', 'Kết quả', 'Điểm', 'Số ca đúng', 'Tổng ca', 'Ngày nộp']);
    foreach ($stmt->fetchAll() as $r) xlsRow([$r['id'], $r['username'], $r['full_name'], $r['class_names'] ?: '—', $r['problem_title'], $r['language'], 'v' . $r['version_number'] . '.0', $r['final_verdict'], $r['final_score'], $r['pass_count'], $r['total_cases'], $r['created_at']]);
    xlsEnd();
}

if ($type === 'users') {
    if ($viewer['role'] !== 'admin') { http_response_code(403); die('Chỉ quản trị viên được xuất danh sách tài khoản.'); }
    $where = [];
    $params = [];
    $role = (string)($_GET['role'] ?? '');
    if (in_array($role, ['student', 'teacher', 'admin'], true)) { $where[] = 'u.role = ?'; $params[] = $role; }
    if ($fSearch !== '') { $where[] = '(u.username LIKE ? OR u.full_name LIKE ?)'; array_push($params, '%' . $fSearch . '%', '%' . $fSearch . '%'); }
    $sql = "SELECT u.username, u.full_name, u.email, u.role, u.is_active,
                   $classNamesSql AS class_names
            FROM users u" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY u.id';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    xlsStart("algojudge_taikhoan_$date.xls", ['Username', 'Họ tên', 'Email', 'Vai trò', 'Lớp', 'Trạng thái']);
    foreach ($stmt->fetchAll() as $r) xlsRow([$r['username'], $r['full_name'], $r['email'], $r['role'], $r['class_names'] ?: '—', (int)$r['is_active'] ? 'Hoạt động' : 'Bị khóa']);
    xlsEnd();
}

if ($type === 'roster') {
    if (!in_array($viewer['role'], ['teacher', 'admin'], true)) { http_response_code(403); die('Chỉ giảng viên và quản trị viên được xuất danh sách lớp.'); }
    if ($fClass < 1 || ($viewer['role'] === 'teacher' && !Auth::teacherOwnsClass((int)$viewer['id'], $fClass))) { http_response_code(403); die('Lớp không hợp lệ hoặc bạn không quản lý lớp này.'); }
    $stmt = $pdo->prepare("SELECT c.name AS class_name, u.username, u.full_name, u.email, cm.status, cm.requested_at FROM class_members cm JOIN classes c ON c.id = cm.class_id JOIN users u ON u.id = cm.user_id WHERE cm.class_id = ? ORDER BY FIELD(cm.status, 'pending', 'active', 'rejected', 'removed'), u.username");
    $stmt->execute([$fClass]);
    xlsStart("algojudge_lop_$date.xls", ['Lớp', 'Username', 'Họ tên', 'Email', 'Trạng thái', 'Ngày yêu cầu']);
    foreach ($stmt->fetchAll() as $r) xlsRow([$r['class_name'], $r['username'], $r['full_name'], $r['email'], $r['status'], $r['requested_at']]);
    xlsEnd();
}

if ($type === 'leaderboard') {
    if (!in_array($viewer['role'], ['teacher', 'admin'], true)) { http_response_code(403); die('Chỉ giảng viên và quản trị viên được xuất bảng xếp hạng.'); }
    $params = [];
    $scope = exportTeacherClassPredicate($viewer, $fClass, $params);
    $stmt = $pdo->prepare("SELECT u.username, u.full_name, u.xp_points,
           $classNamesSql AS class_names,
           (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS solved,
           (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_total
           FROM users u WHERE u.role = 'student' AND $scope ORDER BY u.xp_points DESC, solved DESC, u.id ASC");
    $stmt->execute($params);
    xlsStart("algojudge_xep_hang_$date.xls", ['Hạng', 'Username', 'Họ tên', 'Lớp', 'XP', 'Bài đã giải', 'Lượt nộp']);
    $rank = 0;
    foreach ($stmt->fetchAll() as $r) xlsRow([++$rank, $r['username'], $r['full_name'], $r['class_names'] ?: '—', $r['xp_points'], $r['solved'], $r['sub_total']]);
    xlsEnd();
}

if ($type === 'problems') {
    if (!in_array($viewer['role'], ['teacher', 'admin'], true)) { http_response_code(403); die('Chỉ giảng viên và quản trị viên được xuất danh sách bài tập.'); }
    $rows = $pdo->query("SELECT p.id, p.title, p.slug, p.topic, p.difficulty, p.is_active, pv.version_number, pv.time_limit_ms,
       (SELECT COUNT(*) FROM test_cases tc WHERE tc.problem_version_id = pv.id) AS case_count
       FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id ORDER BY p.id")->fetchAll();
    xlsStart("algojudge_baitap_$date.xls", ['ID', 'Tiêu đề', 'Slug', 'Chủ đề', 'Độ khó', 'Trạng thái', 'Phiên bản', 'Giới hạn (ms)', 'Số test']);
    foreach ($rows as $r) xlsRow([$r['id'], $r['title'], $r['slug'], $r['topic'], $r['difficulty'], (int)$r['is_active'] ? 'Hiện' : 'Ẩn', 'v' . $r['version_number'] . '.0', $r['time_limit_ms'], $r['case_count']]);
    xlsEnd();
}

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');
die('Loại báo cáo không hợp lệ.');
