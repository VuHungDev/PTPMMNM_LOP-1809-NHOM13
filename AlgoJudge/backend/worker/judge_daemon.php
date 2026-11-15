<?php
// backend/worker/judge_daemon.php — CHỈ chạy từ dòng lệnh (CLI), tách khỏi tiến trình Web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Chỉ được chạy Judge Worker Daemon từ dòng lệnh (CLI) để tách biệt khỏi tiến trình Web!');
}

require_once __DIR__ . '/../lib/JudgeQueue.php';

echo "=====================================================\n";
echo " [AlgoJudge] Judge Worker Daemon (CLI) — 6 ngôn ngữ\n";
echo " Đang lắng nghe hàng đợi chấm bài (judging_runs & custom_runs)...\n";
echo "=====================================================\n";

$once = in_array('--once', $argv, true);

function daemonHeartbeat(PDO $pdo): void
{
    try {
        $pdo->prepare("INSERT INTO daemon_heartbeat (name, pid, note) VALUES ('judge_daemon', ?, 'Worker chấm bài cách ly')
            ON DUPLICATE KEY UPDATE last_beat = NOW(), pid = VALUES(pid)")
            ->execute([function_exists('getmypid') ? getmypid() : null]);
    } catch (Exception $e) {
    }
}

while (true) {
    daemonHeartbeat($pdo);
    $stmtCustom = $pdo->query("SELECT * FROM custom_runs WHERE status = 'QUEUED' ORDER BY id ASC LIMIT 1");
    $custom = $stmtCustom->fetch();
    if ($custom) {
        JudgeQueue::processCustomRun($pdo, (int)$custom['id']);
        echo '[CUSTOM RUN #' . $custom['id'] . "] Hoàn tất.\n";
    }

    $stmtRun = $pdo->query("SELECT id, submission_id FROM judging_runs WHERE status = 'QUEUED' ORDER BY id ASC LIMIT 1");
    $queuedRun = $stmtRun->fetch();

    if ($queuedRun) {
        echo '[JUDGING RUN #' . $queuedRun['id'] . '] Đang chấm Submission #' . $queuedRun['submission_id'] . "...\n";
        $res = JudgeEngine::processJudgingRun($pdo, (int)$queuedRun['id']);
        echo '[JUDGING RUN #' . $queuedRun['id'] . '] Kết quả: ' . $res['verdict'] . "\n";
    }

    if ($once) {
        break;
    }
    usleep(250000);
}
