<?php
// Lưu đúng bản nháp đang sửa; tab cũ không được ghi đè thay đổi từ tab khác.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';

csrf_verify_api();
$user = Auth::requireApiLogin();
$raw = file_get_contents('php://input', false, null, 0, 2097153);
$data = is_string($raw) && strlen($raw) <= 2097152 ? json_decode($raw, true) : null;
$problemId = $data['problem_id'] ?? null;
$language = $data['language'] ?? null;
$sourceCode = $data['source_code'] ?? null;
$baseHash = $data['base_hash'] ?? null;

if (!is_array($data) || !is_int($problemId) || $problemId <= 0 || $problemId > 2147483647
    || !is_string($language) || !JudgeEngine::isSupported($language)
    || !is_string($sourceCode) || strlen($sourceCode) > DockerSandbox::MAX_SOURCE_BYTES
    || !is_string($baseHash) || ($baseHash !== 'missing' && !preg_match('/^[a-f0-9]{64}$/D', $baseHash))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dữ liệu bản nháp không hợp lệ (mã nguồn tối đa 256 KiB; cần mã đối chiếu base_hash).']);
    exit;
}

$ownsTransaction = false;
try {
    if ($pdo->inTransaction()) throw new LogicException('Draft request requires its own transaction.');
    $pdo->beginTransaction();
    $ownsTransaction = true;
    // Khóa tài khoản trước: tuần tự hóa cả trường hợp chưa có dòng draft để khóa.
    $stmtActor = $pdo->prepare('SELECT role, is_active, auth_version FROM users WHERE id = ? FOR UPDATE');
    $stmtActor->execute([(int)$user['id']]);
    $actor = $stmtActor->fetch();
    if (!$actor || (int)$actor['is_active'] !== 1 || !isset($user['auth_version'])
        || (int)$actor['auth_version'] !== (int)$user['auth_version']) {
        throw new DomainException('Quyền tài khoản đã thay đổi. Hãy sao chép mã rồi đăng nhập lại.', 403);
    }
    // Khóa đề/phiên bản hiện tại cùng chính sách hiển thị, không sửa lịch sử bài nộp.
    $stmtProblem = $pdo->prepare('SELECT p.current_version_id, p.is_active FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id AND pv.problem_id = p.id WHERE p.id = ? FOR UPDATE');
    $stmtProblem->execute([$problemId]);
    $problem = $stmtProblem->fetch();
    if (!$problem || ((int)$problem['is_active'] !== 1 && !in_array($actor['role'], ['teacher', 'admin'], true))) {
        throw new DomainException('Không tìm thấy bài tập hoặc phiên bản bộ dữ liệu.', 404);
    }
    $stmtDraft = $pdo->prepare('SELECT source_code FROM drafts WHERE user_id = ? AND problem_id = ? AND language = ? FOR UPDATE');
    $stmtDraft->execute([(int)$user['id'], $problemId, $language]);
    $draft = $stmtDraft->fetch();
    $currentHash = $draft ? hash('sha256', $draft['source_code']) : 'missing';
    // ACK có thể mất sau COMMIT: cùng nguồn hiện tại là retry an toàn, không cập nhật timestamp.
    if ((!$draft || $draft['source_code'] !== $sourceCode) && !hash_equals($currentHash, $baseHash)) {
        throw new DomainException('Bản nháp đã thay đổi ở tab khác. Mã cục bộ chưa được lưu; hãy sao chép mã trước khi tải lại trang. Không tự động ghi đè.', 409);
    }
    if ($draft) {
        if ($draft['source_code'] !== $sourceCode) {
            $pdo->prepare('UPDATE drafts SET source_code = ?, updated_at = NOW() WHERE user_id = ? AND problem_id = ? AND language = ?')
                ->execute([$sourceCode, (int)$user['id'], $problemId, $language]);
        }
    } else {
        $pdo->prepare('INSERT INTO drafts (user_id, problem_id, language, source_code) VALUES (?, ?, ?, ?)')
            ->execute([(int)$user['id'], $problemId, $language, $sourceCode]);
    }
    $pdo->commit();
    $ownsTransaction = false;
} catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    $status = $e instanceof DomainException && in_array($e->getCode(), [403, 404, 409], true) ? $e->getCode() : 500;
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $e instanceof DomainException ? $e->getMessage() : 'Không thể lưu nháp. Mã cục bộ vẫn được giữ; hãy thử lại.']);
    exit;
}

echo json_encode(['ok' => true, 'base_hash' => hash('sha256', $sourceCode), 'saved_at' => date('H:i:s')]);
