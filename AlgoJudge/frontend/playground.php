<?php
// frontend/playground.php — Sân chơi tự do: luyện code tùy thích 6 ngôn ngữ, chạy có stdin,
// kết quả in ra ngay, báo đúng dòng lỗi + gợi ý khắc phục. Không chấm điểm, không lưu lịch sử chấm.
require_once __DIR__ . '/../backend/lib/Auth.php';
require_once __DIR__ . '/../backend/lib/JudgeEngine.php';
$user = Auth::requireLogin();

$samples = [
    'python' => "# Sân chơi tự do — Python 3\n# Thư viện chuẩn Python 3; gói ngoài chỉ khi có sẵn trong image Docker\nimport sys\n\ndef main():\n    name = input('Nhập tên của bạn: ').strip()\n    print(f'Xin chào, {name}!')\n    a = int(input('Nhập số a: '))\n    b = int(input('Nhập số b: '))\n    print(f'{a} + {b} = {a + b}')\n\nif __name__ == '__main__':\n    main()\n",
    'cpp'    => "// Sân chơi tự do — C++17\n#include <iostream>\n#include <string>\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    string name;\n    cout << \"Nhap ten: \";\n    if (!getline(cin, name)) return 0;\n    long long a, b;\n    cin >> a >> b;\n    cout << \"Xin chao, \" << name << \"! Tong = \" << (a + b) << \"\\n\";\n    return 0;\n}\n",
    'c'      => "// Sân chơi tự do — C11\n#include <stdio.h>\n\nint main(void) {\n    char name[64];\n    long long a, b;\n    printf(\"Nhap ten: \");\n    if (!fgets(name, sizeof(name), stdin)) return 0;\n    printf(\"Nhap a b: \");\n    if (scanf(\"%lld %lld\", &a, &b) == 2) {\n        printf(\"Xin chao! Tong = %lld\\n\", a + b);\n    }\n    return 0;\n}\n",
    'java'   => "// Sân chơi tự do — Java (lớp chính phải tên Main)\nimport java.util.*;\n\npublic class Main {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        System.out.print(\"Nhap ten: \");\n        String name = sc.nextLine();\n        System.out.print(\"Nhap a b: \");\n        long a = sc.nextLong(), b = sc.nextLong();\n        System.out.println(\"Xin chao, \" + name + \"! Tong = \" + (a + b));\n    }\n}\n",
    'php'    => '<' . '?php' . "\n// Sân chơi tự do — PHP (đọc từng dòng stdin)\n" . '$' . "lines = file('php://stdin', FILE_IGNORE_NEW_LINES);\n" . '$' . "name = trim(" . '$' . "lines[0] ?? 'bạn');\n" . '$' . "a = (int)(" . '$' . "lines[1] ?? 0);\necho \"Xin chào, \" . " . '$' . "name . \"! Tổng gấp đôi a = \" . (" . '$' . "a * 2) . PHP_EOL;\n",
    'js'     => "// Sân chơi tự do — Node.js (đọc từng dòng stdin)\nconst fs = require('fs');\nconst lines = fs.readFileSync(0, 'utf8').split(/\\r?\\n/);\nconst name = (lines[0] || 'bạn').trim();\nconst a = Number(lines[1] || 0);\nconsole.log('Xin chào, ' + name + '! Tổng gấp đôi a = ' + (a * 2));\n",
];

$pageTitle = 'Sân chơi tự do';
$activeNav = 'play';
require FE_DIR . '/partials/layout_head.php';
?>
<div class="ide-wrapper">
    <div class="ide-left" style="grid-template-rows:auto 1fr; display:grid;" x-data="{ pgTab: 'stdin' }">
        <div style="padding:16px 20px 0;">
            <h2 style="margin-bottom:6px;">🎮 Sân chơi tự do</h2>
            <p style="color:var(--cl-muted); font-size:0.88rem; margin-bottom:12px;">
                Viết code tùy thích với 6 ngôn ngữ, có ô nhập liệu riêng. Bấm <strong>Chạy</strong> là thấy kết quả ngay —
                sai dòng nào báo đúng dòng đó kèm gợi ý sửa. Không chấm điểm, không lưu lịch sử.
            </p>
            <div class="ide-tabs" style="border:1px solid var(--cl-border); border-radius:8px 8px 0 0;">
                <div class="ide-tab" x-bind:class="{ 'active': pgTab === 'stdin' }" x-on:click="pgTab = 'stdin'">⌨️ Dữ liệu nhập (stdin)</div>
                <div class="ide-tab" x-bind:class="{ 'active': pgTab === 'libs' }" x-on:click="pgTab = 'libs'">📦 Thư viện có sẵn</div>
            </div>
        </div>
        <div class="ide-content" style="padding-top:0;">
            <div id="pg-tab-stdin" x-show="pgTab === 'stdin'">
                <p style="font-size:0.82rem; color:var(--cl-muted); margin:12px 0 6px;">
                    Mỗi lần chương trình gọi <code>input()</code> / đọc stdin sẽ lấy 1 dòng ở đây theo thứ tự.
                    Với ví dụ mẫu Python: dòng 1 = tên, dòng 2 = số a, dòng 3 = số b.
                </p>
                <textarea id="pg-stdin" class="form-control" rows="8" style="font-family:monospace; resize:vertical;"
                    placeholder="Nhập mỗi giá trị trên 1 dòng, ví dụ:&#10;An&#10;3&#10;5">An&#10;3&#10;5</textarea>
            </div>
            <div id="pg-tab-libs" x-show="pgTab === 'libs'">
                <div style="font-size:0.86rem; line-height:1.7; padding-top:12px;">
                    <p>Chương trình được biên dịch và chạy trong <strong>Docker Linux cách ly</strong>, không dùng thư viện hay tệp cài trên máy chủ. Mỗi lần chạy nhận toàn bộ stdin từ ô bên cạnh, không nhập tương tác trong console.</p>
                    <p><strong>Python 3:</strong> dùng thư viện chuẩn (<code>math, re, json, itertools, collections, heapq, statistics, fractions, decimal...</code>). Gói ngoài chỉ dùng được nếu quản trị viên đã đóng sẵn trong image Docker; không tự cài gói khi chạy.</p>
                    <p><strong>Node.js 22:</strong> module chuẩn <code>fs, path, readline, util, events, crypto...</code>; đọc stdin bằng <code>fs.readFileSync(0, 'utf8')</code>. Gói npm ngoài chỉ dùng được nếu đã đóng sẵn trong image.</p>
                    <p><strong>PHP 8.2 CLI:</strong> chạy với <code>-n</code> (không nạp php.ini của máy chủ), chỉ dùng hàm và extension tích hợp sẵn trong image; đọc dữ liệu từ <code>php://stdin</code>.</p>
                    <p><strong>Java 17:</strong> thư viện chuẩn JDK, lớp chính phải tên <code>Main</code>.</p>
                    <p><strong>C11 / C++17:</strong> thư viện chuẩn C (<code>stdio, stdlib, string, math...</code>) và C++ STL (<code>vector, map, set, algorithm, queue, stack, string...</code>).</p>
                    <p><strong>Giới hạn:</strong> mã nguồn tối đa 256 KiB, stdin tối đa 60.000 byte, thời gian chạy 10 giây. Không truy cập internet, mạng hoặc MySQL/dịch vụ trên máy chủ. Phiên bản chi tiết và thư viện bổ sung phụ thuộc image Docker đã được quản trị viên kiểm duyệt.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="ide-right" data-aj-cmp="playground">
        <div class="ide-toolbar">
            <div style="display:flex; align-items:center; gap:12px;">
                <label style="font-size:0.85rem; color:#a6adc8;">Ngôn ngữ:</label>
                <select id="pg-lang" x-on:change="changeLang()" style="background:#313244; color:#fff; border:1px solid #45475a; padding:6px 12px; border-radius:6px;">
                    <?php foreach (JudgeEngine::LANGUAGES as $key => $meta): ?>
                        <option value="<?= e($key) ?>"><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="aj-icon-action aj-icon-action--delete aj-icon-action--dark" aria-label="Xóa kết quả" data-action-label="Xóa kết quả" title="Xóa kết quả" x-on:click="clear()"><?= aj_action_icon('delete') ?></button>
                <button class="btn btn-success" id="pg-run-btn" x-on:click="run()">▶ Chạy (Ctrl+Enter)</button>
            </div>
        </div>

        <div id="pg-editor" style="flex:1; min-height:280px;"></div>

        <div class="ide-console" style="height:280px;">
            <div class="console-header">
                <div style="display:flex; gap:16px; align-items:center;">
                    <strong style="font-size:0.85rem; color:#89b4fa;">🖥 KẾT QUẢ</strong>
                    <span id="pg-badge" class="badge" style="background:#313244; color:#cdd6f4;">Chờ lệnh</span>
                </div>
                <div id="pg-time" style="font-size:0.78rem; color:#a6adc8;"></div>
            </div>
            <div class="console-body" style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div>
                    <div style="font-size:0.78rem; color:#a6adc8; margin-bottom:4px;">📤 Đầu ra chương trình (stdout):</div>
                    <div id="pg-stdout" style="background:#11111b; border:1px solid #313244; border-radius:6px; padding:10px; height:170px; overflow-y:auto; white-space:pre-wrap;">Bấm "Chạy" để thực thi.</div>
                </div>
                <div>
                    <div style="font-size:0.78rem; color:#a6adc8; margin-bottom:4px;">⚠️ Lỗi &amp; gợi ý (dòng sai):</div>
                    <div id="pg-stderr" style="background:#11111b; border:1px solid #313244; border-radius:6px; padding:10px; height:170px; overflow-y:auto; white-space:pre-wrap; color:#f9a8d4;">Chưa có lỗi.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.PG_LANG_META = <?= json_encode(array_map(fn($m) => $m['monaco'], JudgeEngine::LANGUAGES), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.PG_SAMPLES = <?= json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<style>
.pg-error-line { background: rgba(239, 68, 68, 0.22) !important; }
.pg-error-glyph { background: #ef4444; width: 5px !important; margin-left: 3px; border-radius: 2px; }
</style>
<?php
$pageScripts = '<script type="module">import "' . url('/frontend/assets/js/pages/playground.js') . '?v=20261115-playground-5";</script>';
require FE_DIR . '/partials/layout_foot.php';
?>
