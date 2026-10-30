<?php
// backend/api/ai_create_problem.php — Đẩy khung bài đã duyệt thành bài tập thật (giống problem_form.php).
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/AIHelper.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';

header('Content-Type: application/json; charset=utf-8');
$user = Auth::requireRole(['teacher', 'admin']);

try {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = trim((string)($b['title'] ?? ''));
    $desc = trim((string)($b['description'] ?? ''));
    $sampleIn = trim((string)($b['sample_input'] ?? ''));
    $sampleOut = trim((string)($b['sample_output'] ?? ''));
    if ($title === '' || $desc === '' || $sampleIn === '' || $sampleOut === '') {
        throw new Exception('Thiếu tiêu đề / mô tả / ví dụ mẫu — hãy bổ sung ở bước xem trước.');
    }
    $slug = trim((string)($b['slug'] ?? ''));
    if ($slug === '') $slug = AIHelper::slugify($title);
    if (!preg_match('/^[a-z0-9-]{3,100}$/', $slug)) {
        throw new Exception('Slug chưa đúng — chỉ gồm chữ thường, số, gạch ngang.');
    }
    $chk = $pdo->prepare("SELECT id FROM problems WHERE slug = ?");
    $chk->execute([$slug]);
    if ($chk->fetch()) {
        // Tự thêm hậu tố thay vì báo lỗi, để "đẩy lên luôn" không bị chặn
        $base = substr($slug, 0, 90);
        $slug = $base . '-' . substr(md5($title . microtime()), 0, 6);
    }
    $difficulty = in_array($b['difficulty'] ?? '', ['Easy', 'Medium', 'Hard'], true) ? $b['difficulty'] : 'Easy';
    $topic = trim((string)($b['topic'] ?? 'Cơ bản'));
    $timeLimit = max(100, (int)($b['time_limit_ms'] ?? 1000));

    $cases = [];
    foreach (($b['test_cases'] ?? []) as $c) {
        $sin = trim((string)($c['stdin'] ?? ''));
        $sexp = trim((string)($c['expected'] ?? ''));
        if ($sin === '' || $sexp === '') continue;
        $cases[] = [$sin, $sexp, !empty($c['is_public']) ? 1 : 0, 0];
    }
    if (!$cases) {
        throw new Exception('Cần ít nhất 1 test case có đủ Input + Output.');
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO problems (slug, title, topic, difficulty, suggested_lang, created_by) VALUES (?, ?, ?, ?, 'python', ?)")
        ->execute([$slug, $title, $topic, $difficulty, $user['id']]);
    $problemId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO problem_versions (problem_id, version_number, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, changelog, created_by) VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'Tạo bởi AI Chatbot', ?)")
        ->execute([$problemId, $desc, trim((string)($b['input_desc'] ?? '')), trim((string)($b['output_desc'] ?? '')), $sampleIn, $sampleOut, $timeLimit, $user['id']]);
    $verId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) VALUES (?, ?, ?, ?, ?, 0, 20)");
    foreach ($cases as $i => $c) {
        $ins->execute([$verId, $i + 1, $c[0], $c[1], $c[2]]);
    }
    $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$verId, $problemId]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'problem_id' => $problemId, 'slug' => $slug,
        'edit_url' => url('/frontend/instructor/problem_form.php?edit=' . $problemId)], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
