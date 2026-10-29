<?php
// frontend/workspace.php — IDE: đề bài + Monaco Editor (6 ngôn ngữ) + console
require_once __DIR__ . '/../backend/lib/Auth.php';
require_once __DIR__ . '/../backend/lib/JudgeEngine.php';
$user = Auth::requireLogin();

$problemId = (int)($_GET['id'] ?? 1);
$stmt = $pdo->prepare("
    SELECT p.*, pv.id AS ver_id, pv.version_number, pv.description, pv.input_desc,
           pv.output_desc, pv.sample_input, pv.sample_output, pv.time_limit_ms, pv.memory_limit_kb
    FROM problems p
    JOIN problem_versions pv ON pv.id = p.current_version_id
    WHERE p.id = ?
");
$stmt->execute([$problemId]);
$problem = $stmt->fetch();
if (!$problem) {
    die('Không tìm thấy bài tập!');
}
$suggestLang = isset($problem['suggested_lang']) && JudgeEngine::isSupported($problem['suggested_lang'])
    ? $problem['suggested_lang'] : 'python';

$stmtPub = $pdo->prepare("SELECT * FROM test_cases WHERE problem_version_id = ? AND is_public = 1 ORDER BY case_order ASC");
$stmtPub->execute([$problem['ver_id']]);
$publicCases = $stmtPub->fetchAll();

$stmtDrafts = $pdo->prepare("SELECT language, source_code FROM drafts WHERE user_id = ? AND problem_id = ?");
$stmtDrafts->execute([$user['id'], $problemId]);
$savedDrafts = [];
foreach ($stmtDrafts->fetchAll() as $d) {
    $savedDrafts[$d['language']] = $d['source_code'];
}

$defaults = [
    'python' => "# Nhập dữ liệu từ stdin và in kết quả ra stdout\nimport sys\n\ndef solve():\n    data = sys.stdin.read().split()\n    if not data:\n        return\n    print(int(data[0]) + int(data[1]))\n\nif __name__ == '__main__':\n    solve()",
    'cpp'    => "#include <iostream>\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    long long a, b;\n    if (cin >> a >> b) {\n        cout << (a + b) << \"\\n\";\n    }\n    return 0;\n}",
    'c'      => "#include <stdio.h>\n\nint main(void) {\n    long long a, b;\n    if (scanf(\"%lld %lld\", &a, &b) == 2) {\n        printf(\"%lld\\n\", a + b);\n    }\n    return 0;\n}",
    'java'   => "import java.util.*;\n\npublic class Main {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        long a = sc.nextLong(), b = sc.nextLong();\n        System.out.println(a + b);\n    }\n}",
    'php'    => '<' . '?php' . "\n// Đọc stdin, ghi stdout\n" . '$' . "inp = preg_split('/\\\\s+/', trim(file_get_contents('php://stdin')));\nif (count(" . '$' . "inp) >= 2) {\n    echo ((int)" . '$' . "inp[0] + (int)" . '$' . "inp[1]) . PHP_EOL;\n}",
    'js'     => "// Đọc stdin, ghi stdout (Node.js)\nconst fs = require('fs');\nconst data = fs.readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nif (data.length >= 2) console.log(data[0] + data[1]);",
];

$pageTitle = $problem['title'];
$activeNav = 'train';
require FE_DIR . '/partials/layout_head.php';
?>
<div class="ide-wrapper">
    <div class="ide-left" x-data="{ leftTab: 'desc' }">
        <div class="ide-tabs">
            <div class="ide-tab" x-bind:class="{ 'active': leftTab === 'desc' }" x-on:click="leftTab = 'desc'">📄 Đề bài &amp; Ví dụ</div>
            <div class="ide-tab" x-bind:class="{ 'active': leftTab === 'history' }" x-on:click="leftTab = 'history'">🕒 Kết quả &amp; Lịch sử chấm</div>
        </div>

        <div id="tab-desc" class="ide-content" x-show="leftTab === 'desc'">
            <div style="display:flex; gap:16px; margin-bottom:16px; font-size:0.85rem; color:var(--cl-muted); background:#f8fafc; padding:10px 14px; border-radius:8px;">
                <span>⏱ Giới hạn: <strong><?= (int)$problem['time_limit_ms'] ?> ms</strong></span>
                <span>💾 Bộ nhớ: <strong><?= (int)($problem['memory_limit_kb'] / 1024) ?> MB</strong></span>
                <span>📥 stdin / 📤 stdout</span>
            </div>

            <h3 style="margin-bottom:8px;">Mô tả bài toán</h3>
            <p style="margin-bottom:18px;"><?= nl2br(e($problem['description'])) ?></p>

            <h4 style="margin-bottom:6px;">Đầu vào (Input)</h4>
            <p style="margin-bottom:16px;"><?= nl2br(e($problem['input_desc'])) ?></p>

            <h4 style="margin-bottom:6px;">Đầu ra (Output)</h4>
            <p style="margin-bottom:18px;"><?= nl2br(e($problem['output_desc'])) ?></p>

            <h4 style="margin-bottom:10px;">Ví dụ công khai (Public Sample Tests)</h4>
            <?php foreach ($publicCases as $idx => $tc): ?>
                <div style="background:#f8fafc; border:1px solid var(--cl-border); border-radius:8px; padding:12px; margin-bottom:12px;">
                    <div style="font-weight:700; font-size:0.85rem; color:var(--cl-primary); margin-bottom:6px;">Ví dụ #<?= $idx + 1 ?></div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-family:monospace; font-size:0.88rem;">
                        <div>
                            <div style="color:var(--cl-muted); font-size:0.78rem;">Đầu vào (Stdin):</div>
                            <pre style="background:#fff; padding:8px; border-radius:4px; border:1px solid #e2e8f0; margin-top:4px;"><?= e($tc['stdin_data']) ?></pre>
                        </div>
                        <div>
                            <div style="color:var(--cl-muted); font-size:0.78rem;">Đầu ra mẫu (Expected):</div>
                            <pre style="background:#fff; padding:8px; border-radius:4px; border:1px solid #e2e8f0; margin-top:4px;"><?= e($tc['expected_output']) ?></pre>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:8px; font-size:0.84rem; margin-top:16px;">
                ℹ️ <strong>Lưu ý:</strong> Hệ thống chấm tự động trên bộ dữ liệu thử nghiệm (gồm test công khai và test kín).
            </div>
        </div>

        <div id="tab-history" class="ide-content" x-show="leftTab === 'history'">
            <h3 style="margin-bottom:12px;">Chi tiết bài nộp &amp; Lịch sử các lần thực thi</h3>
            <div id="submission-detail-box" style="color:var(--cl-muted); font-size:0.92rem;">
                Hãy nhấn <strong>Nộp bài (Submit)</strong> ở khung bên phải để xem chi tiết từng ca kiểm thử và lịch sử các lần chấm (Runs).
            </div>
        </div>
    </div>

    <div class="ide-right" data-aj-cmp="workspace">
        <div class="ide-toolbar">
            <div style="display:flex; align-items:center; gap:12px;">
                <label style="font-size:0.85rem; color:#a6adc8;">Ngôn ngữ:</label>
                <select id="lang-select" x-on:change="changeLanguage()" style="background:#313244; color:#fff; border:1px solid #45475a; padding:6px 12px; border-radius:6px;">
                    <?php foreach (JudgeEngine::LANGUAGES as $key => $meta): ?>
                        <option value="<?= e($key) ?>"><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="draft-status" style="font-size:0.8rem; color:#a6adc8;">Đã sẵn sàng</span>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="btn btn-outline" x-on:click="saveDraft(true)">💾 Lưu nháp</button>
                <button class="btn btn-outline" x-on:click="runCustomCode()">▶ Chạy thử (Custom Input)</button>
                <button class="btn btn-success" x-on:click="submitSolution(false)">🚀 Nộp bài (Submit)</button>
            </div>
        </div>

        <div id="monaco-container"></div>

        <div class="ide-console">
            <div class="console-header">
                <div style="display:flex; gap:16px; align-items:center;">
                    <strong style="font-size:0.85rem; color:#89b4fa;">🖥 BẢNG ĐIỀU KHIỂN THỰC THI (CONSOLE)</strong>
                    <span id="console-badge" class="badge" style="background:#313244; color:#cdd6f4;">Chờ lệnh</span>
                </div>
                <div style="font-size:0.78rem; color:#a6adc8;">
                    Khóa Idempotency: <code id="idem-display" style="color:#f9e2af;">-</code>
                    <button class="btn btn-outline" style="padding:2px 8px; font-size:0.74rem; margin-left:6px;" x-on:click="submitSolution(true)" title="Mô phỏng mạng lag gửi lại cùng 1 request">Test Gửi Trùng</button>
                </div>
            </div>
            <div class="console-body" style="display:grid; grid-template-columns: 35% 65%; gap:14px;">
                <div>
                    <div style="font-size:0.78rem; color:#a6adc8; margin-bottom:4px;">Dữ liệu tự nhập (Custom Stdin):</div>
                    <textarea id="custom-stdin" style="width:100%; height:140px; background:#11111b; color:#cdd6f4; border:1px solid #313244; border-radius:6px; padding:8px; font-family:monospace;"><?= e($problem['sample_input']) ?></textarea>
                </div>
                <div>
                    <div style="font-size:0.78rem; color:#a6adc8; margin-bottom:4px;">Kết quả đầu ra / Tiến trình chấm:</div>
                    <div id="console-output" style="background:#11111b; border:1px solid #313244; border-radius:6px; padding:10px; height:140px; overflow-y:auto; white-space:pre-wrap;">Nhấn "Chạy thử" hoặc "Nộp bài".</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.WS_CFG = {
    problemId: <?= (int)$problem['id'] ?>,
    suggestLang: <?= json_encode($suggestLang) ?>,
    langMeta: <?= json_encode(array_map(fn($m) => $m['monaco'], JudgeEngine::LANGUAGES)) ?>,
    defaults: <?= json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    drafts: <?= json_encode($savedDrafts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<?php
$pageScripts = '<script type="module">import "' . url('/frontend/assets/js/pages/workspace.js') . '";</script>';
require FE_DIR . '/partials/layout_foot.php';
?>
