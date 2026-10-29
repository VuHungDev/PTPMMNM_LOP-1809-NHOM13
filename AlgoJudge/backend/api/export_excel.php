<?php
// backend/api/export_excel.php — Xuất báo cáo Excel (.xls mở trực tiếp bằng Excel)
// type=submissions | progress. Phạm vi dữ liệu theo vai trò:
// student: chỉ bài của mình / chỉ dòng tiến độ của mình
// teacher: lớp mình quản lý (hoặc ALL) | admin: toàn bộ + lọc theo lớp
require_once __DIR__ . '/../lib/Auth.php';

// Xuất file tải về: hết phiên thì trả 401 text thuần (không redirect HTML
// để trình duyệt khỏi tải về file rác).
$viewer = Auth::user();
if (!$viewer) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    die('Hết phiên đăng nhập. Vui lòng đăng nhập lại rồi xuất Excel.');
}
$type = $_GET['type'] ?? 'submissions';
$fProblem = (int)($_GET['problem_id'] ?? 0);
$fVerdict = $_GET['verdict'] ?? '';
$fClass = trim($_GET['class_code'] ?? '');

$allowedVerdicts = ['QUEUED', 'RUNNING', 'AC', 'WA', 'TLE', 'RE', 'CE', 'SYS_ERR'];
if ($fVerdict !== '' && !in_array($fVerdict, $allowedVerdicts, true)) {
    $fVerdict = '';
}

// Điều kiện phạm vi theo vai trò
$scopeSql = '';
$scopeParams = [];
if ($viewer['role'] === 'student') {
    $scopeSql = 's.user_id = ?';
    $scopeParams[] = $viewer['id'];
} elseif ($viewer['role'] === 'teacher') {
    if ($viewer['class_code'] !== 'ALL') {
        $scopeSql = 'u.class_code = ?';
        $scopeParams[] = $viewer['class_code'];
    } else {
        $scopeSql = '1=1';
    }
} else {
    $scopeSql = '1=1';
    if ($fClass !== '') {
        $scopeSql = 'u.class_code = ?';
        $scopeParams[] = $fClass;
    }
}

function xlsCell($v): string
{
    return '<td>' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '</td>';
}

$date = date('Ymd_Hi');
if ($type === 'progress') {
    if ($viewer['role'] === 'student') {
        http_response_code(403);
        die('Báo cáo tiến độ lớp chỉ dành cho giảng viên và quản trị viên.');
    }
    $stmt = $pdo->prepare("
        SELECT u.username, u.full_name, u.class_code,
               (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS sub_total,
               (SELECT COUNT(DISTINCT s.problem_id) FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AS solved,
               (SELECT MAX(s.created_at) FROM submissions s WHERE s.user_id = u.id) AS last_submit
        FROM users u
        WHERE u.role = 'student' AND (" . ($viewer['role'] === 'teacher' && $viewer['class_code'] !== 'ALL' ? 'u.class_code = ?' : '1=1') . ")
          " . ($viewer['role'] === 'admin' && $fClass !== '' ? 'AND u.class_code = ?' : '') . "
        ORDER BY solved DESC, sub_total DESC
    ");
    $pp = [];
    if ($viewer['role'] === 'teacher' && $viewer['class_code'] !== 'ALL') {
        $pp[] = $viewer['class_code'];
    }
    if ($viewer['role'] === 'admin' && $fClass !== '') {
        $pp[] = $fClass;
    }
    $stmt->execute($pp);
    $rows = $stmt->fetchAll();
    $totalProblems = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1")->fetchColumn();

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"algojudge_tiendo_{$date}.xls\"");
    echo "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"UTF-8\"></head><body>";
    echo '<table border="1"><tr><th>Username</th><th>Họ tên</th><th>Lớp</th><th>Bài đã giải (AC)</th><th>Tổng số bài (' . $totalProblems . ')</th><th>Tổng lượt nộp</th><th>Tỉ lệ AC</th><th>Lần nộp cuối</th></tr>';
    foreach ($rows as $r) {
        $rate = $totalProblems > 0 ? round((int)$r['solved'] / $totalProblems * 100, 1) . '%' : '0%';
        echo '<tr>' . xlsCell($r['username']) . xlsCell($r['full_name']) . xlsCell($r['class_code'])
            . xlsCell($r['solved']) . xlsCell($totalProblems) . xlsCell($r['sub_total'])
            . xlsCell($rate) . xlsCell($r['last_submit'] ?? '') . '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

// type = submissions
$sql = "
    SELECT s.id, u.username, u.full_name, u.class_code, p.title AS problem_title,
           s.language, pv.version_number, s.final_verdict, s.final_score,
           s.pass_count, s.total_cases, s.created_at
    FROM submissions s
    JOIN users u ON u.id = s.user_id
    JOIN problems p ON p.id = s.problem_id
    JOIN problem_versions pv ON pv.id = s.problem_version_id
    WHERE ($scopeSql)
";
$params = $scopeParams;
if ($fProblem > 0) {
    $sql .= ' AND s.problem_id = ?';
    $params[] = $fProblem;
}
if ($fVerdict !== '') {
    $sql .= ' AND s.final_verdict = ?';
    $params[] = $fVerdict;
}
$sql .= ' ORDER BY s.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"algojudge_bainop_{$date}.xls\"");
echo "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\"><head><meta charset=\"UTF-8\"></head><body>";
echo '<table border="1"><tr><th>ID</th><th>Username</th><th>Họ tên</th><th>Lớp</th><th>Bài tập</th><th>Ngôn ngữ</th><th>Phiên bản đề</th><th>Kết quả</th><th>Điểm</th><th>Số ca đúng</th><th>Tổng ca</th><th>Ngày nộp</th></tr>';
foreach ($rows as $r) {
    echo '<tr>' . xlsCell($r['id']) . xlsCell($r['username']) . xlsCell($r['full_name']) . xlsCell($r['class_code'])
        . xlsCell($r['problem_title']) . xlsCell($r['language']) . xlsCell('v' . $r['version_number'] . '.0')
        . xlsCell($r['final_verdict']) . xlsCell($r['final_score'])
        . xlsCell($r['pass_count']) . xlsCell($r['total_cases']) . xlsCell($r['created_at']) . '</tr>';
}
echo '</table></body></html>';
