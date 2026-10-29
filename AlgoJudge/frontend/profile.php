<?php
// frontend/profile.php — Hồ sơ cá nhân: XP, hạng, tiến độ từng bài, lịch sử nộp gần đây
require_once __DIR__ . '/../backend/lib/Auth.php';
$user = Auth::requireLogin();
$uid = (int)$user['id'];

// Đọc lại số liệu mới nhất từ DB
$stmtU = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtU->execute([$uid]);
$me = $stmtU->fetch();

$stmtR = $pdo->prepare("SELECT COUNT(*) + 1 FROM users WHERE role = 'student' AND xp_points > (SELECT xp_points FROM users WHERE id = ?)");
$stmtR->execute([$uid]);
$myRank = $me['role'] === 'student' ? (int)$stmtR->fetchColumn() : null;

$totalProblems = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1")->fetchColumn();
$stmtS = $pdo->prepare("SELECT COUNT(DISTINCT problem_id) FROM submissions WHERE user_id = ? AND final_verdict = 'AC'");
$stmtS->execute([$uid]);
$solved = (int)$stmtS->fetchColumn();
$pct = $totalProblems > 0 ? round($solved / $totalProblems * 100) : 0;

// Tiến độ từng bài: verdict tốt nhất của user cho mỗi bài
$stmtP = $pdo->prepare("
    SELECT p.id, p.title, p.difficulty,
           (SELECT s.final_verdict FROM submissions s WHERE s.user_id = ? AND s.problem_id = p.id ORDER BY CASE s.final_verdict WHEN 'AC' THEN 0 ELSE 1 END, s.id DESC LIMIT 1) AS best,
           (SELECT COUNT(*) FROM submissions s WHERE s.user_id = ? AND s.problem_id = p.id) AS tries
    FROM problems p WHERE p.is_active = 1 ORDER BY p.id ASC
");
$stmtP->execute([$uid, $uid]);
$perProblem = $stmtP->fetchAll();

// 10 bài nộp gần nhất
$stmtL = $pdo->prepare("
    SELECT s.id, s.language, s.final_verdict, s.final_score, s.pass_count, s.total_cases, s.created_at, p.title
    FROM submissions s JOIN problems p ON p.id = s.problem_id
    WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 10
");
$stmtL->execute([$uid]);
$recent = $stmtL->fetchAll();

$pageTitle = 'Hồ sơ của tôi';
$activeNav = 'profile';
require FE_DIR . '/partials/layout_head.php';
?>
<div style="max-width:1100px; margin:28px auto; padding:0 24px;">
    <div style="background:#fff; border-radius:12px; padding:24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px; display:flex; gap:24px; align-items:center;">
        <div style="width:72px; height:72px; border-radius:50%; background:linear-gradient(135deg, #2c31cf, #0ea5e9); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.8rem; font-weight:800;">
            <?= e(mb_strtoupper(mb_substr($me['username'], 0, 1))) ?>
        </div>
        <div style="flex:1;">
            <h2 style="margin-bottom:2px;"><?= e($me['full_name']) ?></h2>
            <div style="color:var(--cl-muted); font-size:0.9rem;"><code><?= e($me['username']) ?></code> · <?= e($me['role']) ?> · Lớp <?= e($me['class_code']) ?></div>
        </div>
        <div style="display:flex; gap:16px; text-align:center;">
            <div><div style="font-size:1.5rem; font-weight:800; color:var(--cl-primary);"><?= (int)$me['xp_points'] ?></div><div style="font-size:0.8rem; color:var(--cl-muted);">XP</div></div>
            <?php if ($myRank): ?><div><div style="font-size:1.5rem; font-weight:800;">#<?= $myRank ?></div><div style="font-size:0.8rem; color:var(--cl-muted);">Xếp hạng</div></div><?php endif; ?>
            <div><div style="font-size:1.5rem; font-weight:800;"><?= $solved ?>/<?= $totalProblems ?></div><div style="font-size:0.8rem; color:var(--cl-muted);">Bài đã giải</div></div>
        </div>
    </div>

    <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <strong>Tiến độ hoàn thành</strong><span><?= $pct ?>%</span>
        </div>
        <div style="background:#f1f5f9; border-radius:999px; height:14px; overflow:hidden;">
            <div style="background:linear-gradient(90deg, #2c31cf, #38bdf8); height:14px; width:<?= $pct ?>%;"></div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
        <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="margin-bottom:12px;">Tiến độ từng bài</h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <?php foreach ($perProblem as $p): ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:8px 4px;"><a href="<?= url('/frontend/workspace.php?id=' . (int)$p['id']) ?>" style="color:var(--cl-text); font-weight:600;"><?= e($p['title']) ?></a></td>
                    <td style="padding:8px 4px; text-align:right;">
                        <?php if ($p['best'] === null): ?>
                            <span class="badge" style="background:#f1f5f9; color:#64748b;">Chưa làm</span>
                        <?php elseif ($p['best'] === 'AC'): ?>
                            <span class="badge verdict-AC">✔ AC (<?= (int)$p['tries'] ?> lần nộp)</span>
                        <?php else: ?>
                            <span class="badge verdict-<?= e($p['best']) ?>"><?= e($p['best']) ?> (<?= (int)$p['tries'] ?> lần nộp)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div style="background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 2px 8px rgba(0,0,0,0.05); align-self:start;">
            <h3 style="margin-bottom:12px;">10 bài nộp gần nhất</h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                <?php foreach ($recent as $s): ?>
                <tr style="border-bottom:1px solid var(--cl-border);">
                    <td style="padding:8px 4px; font-weight:700;">#<?= (int)$s['id'] ?></td>
                    <td style="padding:8px 4px;"><?= e($s['title']) ?><br><small style="color:var(--cl-muted);"><code><?= e($s['language']) ?></code> · <?= e($s['created_at']) ?></small></td>
                    <td style="padding:8px 4px; text-align:right;"><span class="badge verdict-<?= e($s['final_verdict']) ?>"><?= e($s['final_verdict']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recent): ?>
                <tr><td style="padding:16px; color:var(--cl-muted);">Chưa có bài nộp nào.</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?php require FE_DIR . '/partials/layout_foot.php'; ?>
