<?php
// frontend/index.php — Danh sách bài tập + tiến độ
require_once __DIR__ . '/../backend/lib/Auth.php';
require_once __DIR__ . '/../backend/lib/JudgeEngine.php';
$user = Auth::requireLogin();

$topicFilter = $_GET['topic'] ?? '';
$diffFilter  = $_GET['difficulty'] ?? '';
$langFilter  = $_GET['lang'] ?? '';
if ($langFilter !== '' && !JudgeEngine::isSupported($langFilter)) {
    $langFilter = '';
}

$sql = "
    SELECT p.*, pv.version_number, pv.time_limit_ms,
           (SELECT final_verdict FROM submissions s WHERE s.problem_id = p.id AND s.user_id = :uid AND s.final_verdict = 'AC' LIMIT 1) AS solved_status,
           (SELECT COUNT(DISTINCT s2.user_id) FROM submissions s2 WHERE s2.problem_id = p.id AND s2.final_verdict = 'AC') AS ac_users,
           (SELECT COUNT(DISTINCT s3.user_id) FROM submissions s3 WHERE s3.problem_id = p.id) AS tried_users
    FROM problems p
    JOIN problem_versions pv ON pv.id = p.current_version_id
    WHERE p.is_active = 1
";
$params = [':uid' => $user['id']];
if ($topicFilter !== '') {
    $sql .= " AND p.topic = :topic";
    $params[':topic'] = $topicFilter;
}
if ($diffFilter !== '') {
    $sql .= " AND p.difficulty = :diff";
    $params[':diff'] = $diffFilter;
}
if ($langFilter !== '') {
    $sql .= " AND p.suggested_lang = :lang";
    $params[':lang'] = $langFilter;
}
$sql .= " ORDER BY p.id ASC";

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM problems p WHERE p.is_active = 1"
    . ($topicFilter !== '' ? " AND p.topic = :topic" : '')
    . ($diffFilter !== '' ? " AND p.difficulty = :diff" : '')
    . ($langFilter !== '' ? " AND p.suggested_lang = :lang" : ''));
foreach (array_filter([':topic' => $topicFilter ?: null, ':diff' => $diffFilter ?: null, ':lang' => $langFilter ?: null]) as $k => $v) {
    $stmtCnt->bindValue($k, $v);
}
$stmtCnt->execute();
$totalRows = (int)$stmtCnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql .= " LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$problems = $stmt->fetchAll();
$baseQuery = array_filter(['topic' => $topicFilter ?: null, 'difficulty' => $diffFilter ?: null, 'lang' => $langFilter ?: null]);

$stmtSolved = $pdo->prepare("SELECT COUNT(DISTINCT problem_id) FROM submissions WHERE user_id = ? AND final_verdict = 'AC'");
$stmtSolved->execute([$user['id']]);
$solvedCount = (int)$stmtSolved->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1")->fetchColumn();

$pageTitle = 'Luyện tập lập trình';
$activeNav = 'train';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="background:linear-gradient(135deg, #1e22a8 0%, #0f172a 100%); color:#fff; padding:32px 40px;">
    <div style="max-width:1280px; margin:0 auto; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h1 style="font-size:1.85rem; margin-bottom:8px;">Hệ thống Luyện tập &amp; Chấm bài Tự động</h1>
            <p style="opacity:0.85;">Viết mã C / C++ / Java / Python / PHP / JS trực tiếp trên trình duyệt, chạy thử và nhận kết quả chấm tức thì.</p>
        </div>
        <div style="background:rgba(255,255,255,0.1); padding:16px 24px; border-radius:12px; text-align:center;">
            <div style="font-size:0.85rem; opacity:0.8;">TIẾN ĐỘ HOÀN THÀNH</div>
            <div style="font-size:1.8rem; font-weight:800; color:#38bdf8;"><?= $solvedCount ?> / <?= $totalCount ?> bài</div>
        </div>
    </div>
</div>

<div style="max-width:1280px; margin:28px auto; padding:0 24px;">
    <form method="GET" style="display:flex; gap:12px; margin-bottom:20px; background:#fff; padding:16px; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
        <select name="topic" class="form-control" style="max-width:220px;">
            <option value="">-- Tất cả chủ đề --</option>
            <?php foreach (['Cơ bản', 'Rẽ nhánh', 'Vòng lặp', 'Số học', 'Quy hoạch động', 'Mảng', 'Xử lý chuỗi', 'Tìm kiếm'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $topicFilter === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="difficulty" class="form-control" style="max-width:160px;">
            <option value="">-- Mọi mức độ --</option>
            <option value="Easy" <?= $diffFilter === 'Easy' ? 'selected' : '' ?>>Easy (Dễ)</option>
            <option value="Medium" <?= $diffFilter === 'Medium' ? 'selected' : '' ?>>Medium (Trung bình)</option>
            <option value="Hard" <?= $diffFilter === 'Hard' ? 'selected' : '' ?>>Hard (Khó)</option>
        </select>
        <select name="lang" class="form-control" style="max-width:190px;">
            <option value="">-- Mọi ngôn ngữ --</option>
            <?php foreach (JudgeEngine::LANGUAGES as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= $langFilter === $key ? 'selected' : '' ?>><?= e($meta['label']) ?> (<?= e($key) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Lọc bài tập</button>
        <a href="<?= url('/frontend/index.php') ?>" class="btn" style="background:#e2e8f0; color:#334155;">Đặt lại</a>
    </form>

    <div style="background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; text-align:left;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:1px solid var(--cl-border); font-size:0.85rem; color:var(--cl-muted); text-transform:uppercase;">
                    <th style="padding:14px 20px;">Trạng thái</th>
                    <th style="padding:14px 20px;">Tên bài tập</th>
                    <th style="padding:14px 20px;">Chủ đề</th>
                    <th style="padding:14px 20px;">Độ khó</th>
                    <th style="padding:14px 20px;">Ngôn ngữ gợi ý</th>
                    <th style="padding:14px 20px;">Phiên bản Test</th>
                    <th style="padding:14px 20px;">Giới hạn chạy</th>
                    <th style="padding:14px 20px;">Tỉ lệ AC</th>
                    <th style="padding:14px 20px; text-align:right;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($problems as $p): ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:14px 20px;">
                        <?php if ($p['solved_status'] === 'AC'): ?>
                            <span class="badge verdict-AC">✔ Đã giải (AC)</span>
                        <?php else: ?>
                            <span class="badge" style="background:#f1f5f9; color:#64748b;">Chưa giải</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:14px 20px; font-weight:700;">
                        <a href="<?= url('/frontend/workspace.php?id=' . (int)$p['id']) ?>" style="color:var(--cl-text); text-decoration:none;">
                            <?= e($p['title']) ?>
                        </a>
                    </td>
                    <td style="padding:14px 20px;"><span class="badge" style="background:#e0e7ff; color:#3730a3;"><?= e($p['topic']) ?></span></td>
                    <td style="padding:14px 20px;"><span class="badge badge-<?= e($p['difficulty']) ?>"><?= e($p['difficulty']) ?></span></td>
                    <td style="padding:14px 20px;"><code style="background:#f1f5f9; padding:2px 8px; border-radius:6px;"><?= e($p['suggested_lang'] ?? 'python') ?></code></td>
                    <td style="padding:14px 20px; font-family:monospace;">v<?= (int)$p['version_number'] ?>.0</td>
                    <td style="padding:14px 20px; color:var(--cl-muted); font-size:0.9rem;"><?= (int)$p['time_limit_ms'] ?> ms</td>
                    <td style="padding:14px 20px; font-size:0.9rem;" title="<?= (int)$p['ac_users'] ?> / <?= (int)$p['tried_users'] ?> người giải đúng"><?= (int)$p['tried_users'] > 0 ? round((int)$p['ac_users'] / (int)$p['tried_users'] * 100) . '%' : '—' ?></td>
                    <td style="padding:14px 20px; text-align:right;">
                        <a href="<?= url('/frontend/workspace.php?id=' . (int)$p['id']) ?>" class="btn btn-primary" style="padding:6px 14px;">Làm bài →</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php require FE_DIR . '/partials/pagination.php'; ?>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
