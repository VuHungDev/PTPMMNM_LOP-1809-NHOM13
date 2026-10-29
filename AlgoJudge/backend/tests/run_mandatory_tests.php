<?php
// backend/tests/run_mandatory_tests.php — 5 phép thử bắt buộc (chạy từ CLI)
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/JudgeEngine.php';

echo "====================================================================\n";
echo " KIỂM THỬ TỰ ĐỘNG 5 PHÉP THỬ BẮT BUỘC — 6 NGÔN NGỮ\n";
echo "====================================================================\n\n";

$problemId = 1;
$stmtVer = $pdo->prepare("SELECT current_version_id FROM problems WHERE id = ?");
$stmtVer->execute([$problemId]);
$versionId = (int)$stmtVer->fetchColumn();
$studentA = 3;
$studentB = 4;

function createAndRunTestSubmission(PDO $pdo, int $userId, int $probId, int $verId, string $lang, string $code, string $idemKey, bool $simDown = false): array
{
    $chk = $pdo->prepare("SELECT id, latest_run_id FROM submissions WHERE idempotency_key = ?");
    $chk->execute([$idemKey]);
    $ex = $chk->fetch();
    if ($ex) {
        return ['submission_id' => (int)$ex['id'], 'run_id' => (int)$ex['latest_run_id'], 'duplicate' => true];
    }

    $pdo->prepare("INSERT INTO submissions (idempotency_key, user_id, problem_id, problem_version_id, language, source_code) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$idemKey, $userId, $probId, $verId, $lang, $code]);
    $subId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by) VALUES (?, ?, 1, 'INITIAL', ?)")
        ->execute([$subId, $verId, $userId]);
    $runId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE submissions SET latest_run_id = ? WHERE id = ?")->execute([$runId, $subId]);

    $res = JudgeEngine::processJudgingRun($pdo, $runId, $simDown);
    return ['submission_id' => $subId, 'run_id' => $runId, 'verdict' => $res['verdict'], 'duplicate' => false];
}

echo "[PHÉP THỬ 1] Phân loại 5 trạng thái (AC, WA biên, CE, RE, TLE) — Python:\n";
$codes = [
    'AC'  => "import sys\na, b = map(int, sys.stdin.read().split())\nprint(a + b)",
    'WA'  => "import sys\na, b = map(int, sys.stdin.read().split())\nprint(a + b if abs(a) < 1000 else 0)",
    'CE'  => "def broken_syntax(:\n    print(1)",
    'RE'  => "print(1 / 0)",
    'TLE' => "while True:\n    pass",
];
foreach ($codes as $expected => $src) {
    $r = createAndRunTestSubmission($pdo, $studentA, $problemId, $versionId, 'python', $src, 'test1_' . $expected . '_' . uniqid());
    $pass = ($r['verdict'] === $expected) ? 'PASS' : 'FAIL';
    echo "  - Mã thử [$expected] -> [{$r['verdict']}] => $pass\n";
}

echo "\n[PHÉP THỬ 1b] Chấm đúng (AC) trên 5 ngôn ngữ còn lại:\n";
$acCodes = [
    'c'      => "#include <stdio.h>\nint main(){long long a,b; if(scanf(\"%lld %lld\",&a,&b)==2) printf(\"%lld\\n\",a+b); return 0;}",
    'cpp'    => "#include <iostream>\nusing namespace std;\nint main(){long long a,b; if(cin>>a>>b) cout<<(a+b)<<\"\\n\"; return 0;}",
    'java'   => "import java.util.*; public class Main { public static void main(String[] a){ Scanner s=new Scanner(System.in); long x=s.nextLong(), y=s.nextLong(); System.out.println(x+y);} }",
    'php'    => "<?php \$d=preg_split('/\\s+/',trim(file_get_contents('php://stdin'))); echo ((int)\$d[0]+(int)\$d[1]).PHP_EOL;",
    'js'     => "const fs=require('fs'); const p=fs.readFileSync(0,'utf8').trim().split(/\\s+/).map(Number); console.log(p[0]+p[1]);",
];
foreach ($acCodes as $lang => $src) {
    $r = createAndRunTestSubmission($pdo, $studentA, $problemId, $versionId, $lang, $src, 'test1b_' . $lang . '_' . uniqid());
    $pass = (($r['verdict'] ?? '') === 'AC') ? 'PASS' : 'FAIL';
    echo "  - $lang -> [" . ($r['verdict'] ?? '?') . "] => $pass\n";
}

echo "\n[PHÉP THỬ 2] Mô phỏng ngắt bộ thực thi:\n";
$r2 = createAndRunTestSubmission($pdo, $studentA, $problemId, $versionId, 'python', $codes['AC'], 'test2_' . uniqid(), true);
echo '  - Kết quả khi ngắt bộ thực thi: [' . $r2['verdict'] . '] => ' . ($r2['verdict'] === 'SYS_ERR' ? "PASS\n" : "FAIL\n");

echo "\n[PHÉP THỬ 3] Chống nộp trùng (Idempotency Key):\n";
$sharedKey = 'idem_key_fixed_' . uniqid();
$firstSub  = createAndRunTestSubmission($pdo, $studentA, $problemId, $versionId, 'python', $codes['AC'], $sharedKey);
$retrySub  = createAndRunTestSubmission($pdo, $studentA, $problemId, $versionId, 'python', $codes['AC'], $sharedKey);
$newIntent = createAndRunTestSubmission($pdo, $studentA, $problemId, $versionId, 'python', $codes['AC'], 'idem_key_new_' . uniqid());
echo "  - Gửi 1: #{$firstSub['submission_id']} | Gửi lại: #{$retrySub['submission_id']} | Nộp mới: #{$newIntent['submission_id']}\n";
echo '  => ' . (($firstSub['submission_id'] === $retrySub['submission_id'] && $firstSub['submission_id'] !== $newIntent['submission_id']) ? "PASS\n" : "FAIL\n");

echo "\n[PHÉP THỬ 4] Kết quả cũ về muộn không ghi đè kết quả mới:\n";
$subId4 = $newIntent['submission_id'];
$run1Id = $newIntent['run_id'];
$pdo->prepare("INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict) VALUES (?, ?, 2, 'REJUDGE', 2, 'COMPLETED', 'AC')")
    ->execute([$subId4, $versionId]);
$run2Id = (int)$pdo->lastInsertId();
JudgeEngine::syncLatestRunToSubmission($pdo, $subId4, $run2Id, 'AC', 100, 5, 5);
$overwritten = JudgeEngine::syncLatestRunToSubmission($pdo, $subId4, $run1Id, 'WA', 20, 1, 5);
$stmtCurSub = $pdo->prepare("SELECT latest_run_id, final_verdict FROM submissions WHERE id = ?");
$stmtCurSub->execute([$subId4]);
$curState = $stmtCurSub->fetch();
echo "  - Run cũ (#$run1Id) cố ghi đè 'WA': Bị chặn = " . (!$overwritten ? 'TRUE' : 'FALSE') . ", giữ = [{$curState['final_verdict']}] => " . ($curState['final_verdict'] === 'AC' ? "PASS\n" : "FAIL\n");

echo "\n[PHÉP THỬ 5] Phân quyền đọc mã riêng (ACL) & bảo vệ test kín:\n";
$viewerA = ['id' => $studentA, 'role' => 'student', 'class_code' => 'CNTT-18-02'];
$ownerB  = ['id' => $studentB, 'role' => 'student', 'class_code' => 'CNTT-18-02'];
$canRead = Auth::canViewSubmission($viewerA, $ownerB);
echo '  - Học viên A đọc mã của B: ' . ($canRead ? "CHO PHÉP (FAIL)" : "BỊ CHẶN 403 (PASS)") . "\n";
