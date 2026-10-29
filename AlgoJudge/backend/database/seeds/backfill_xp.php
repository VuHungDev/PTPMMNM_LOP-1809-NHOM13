<?php
// backend/database/backfill_xp.php — Cộng bù XP cho các bài đã AC trước khi có tính năng XP (chạy 1 lần)
require_once __DIR__ . '/../../lib/JudgeEngine.php';

$subs = $pdo->query("
    SELECT s.id FROM submissions s
    WHERE s.final_verdict = 'AC'
    GROUP BY s.user_id, s.problem_id
")->fetchAll();

$total = 0;
foreach ($subs as $row) {
    $first = $pdo->prepare("SELECT id FROM submissions WHERE user_id = (SELECT user_id FROM submissions WHERE id = ?) AND problem_id = (SELECT problem_id FROM submissions WHERE id = ?) AND final_verdict = 'AC' ORDER BY id ASC LIMIT 1");
    $first->execute([$row['id'], $row['id']]);
    $firstId = (int)$first->fetchColumn();
    $total += JudgeEngine::awardFirstSolveXp($pdo, $firstId);
}

$pdo->query("UPDATE users u SET xp_points = 0 WHERE NOT EXISTS (SELECT 1 FROM submissions s WHERE s.user_id = u.id AND s.final_verdict = 'AC') AND xp_points <> 0");
echo "[SUCCESS] Đã cộng bù tổng $total XP cho các bài đã giải trước đó.\n";
