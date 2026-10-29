<?php
// backend/database/seed_10_problems.php — nạp dữ liệu mẫu (5 user, 10 bài, 50 cases)
require_once __DIR__ . '/../../config/bootstrap.php';

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (['case_results', 'judging_runs', 'submissions', 'drafts', 'test_cases', 'problem_versions', 'problems', 'users', 'custom_runs'] as $tbl) {
    $pdo->exec("TRUNCATE TABLE $tbl");
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 1. Tạo tài khoản mẫu (Mật khẩu chung: 123456)
$pwd = password_hash('123456', PASSWORD_DEFAULT);
$users = [
    ['admin', 'admin@algojudge.local', $pwd, 'Quản Trị Viên Thực Thi', 'admin', 'ALL'],
    ['gv_cntt', 'giangvien@algojudge.local', $pwd, 'ThS. Nguyễn Văn Giảng', 'teacher', 'CNTT-18-02'],
    ['sv_son', 'son@algojudge.local', $pwd, 'Tạ Công Sơn (CNTT-18-02)', 'student', 'CNTT-18-02'],
    ['sv_phuong', 'phuong@algojudge.local', $pwd, 'Nguyễn Vũ Phương (CNTT-18-02)', 'student', 'CNTT-18-02'],
    ['sv_khaclop', 'khaclop@algojudge.local', $pwd, 'Học Viên Khác Lớp (CNTT-18-01)', 'student', 'CNTT-18-01'],
];
$stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, class_code) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($users as $u) {
    $stmtUser->execute($u);
}
$instructorId = 2;

// 2. Danh sách 10 bài tập chuẩn (Mỗi bài >= 5 test cases, có biên & công khai)
$problemsData = [
    [
        'slug' => 'sum-two-integers',
        'title' => 'Bài 1: Tính tổng hai số nguyên A và B',
        'topic' => 'Cơ bản',
        'difficulty' => 'Easy',
        'desc' => 'Cho hai số nguyên A và B cách nhau bởi một khoảng trắng. Hãy tính và in ra tổng A + B. (Nguồn: Public Domain Classic)',
        'in_desc' => 'Một dòng duy nhất chứa hai số nguyên A, B (-10^9 <= A, B <= 10^9).',
        'out_desc' => 'Một số nguyên duy nhất là tổng A + B.',
        'time' => 1000,
        'cases' => [
            ['3 5', '8', 1, 0],
            ['-10 25', '15', 1, 0],
            ['0 0', '0', 0, 1],
            ['1000000000 1000000000', '2000000000', 0, 1],
            ['-999999999 -1', '-1000000000', 0, 1],
        ]
    ],
    [
        'slug' => 'check-leap-year',
        'title' => 'Bài 2: Kiểm tra năm nhuận',
        'topic' => 'Rẽ nhánh',
        'difficulty' => 'Easy',
        'desc' => 'Một năm là năm nhuận nếu chia hết cho 400, hoặc chia hết cho 4 nhưng không chia hết cho 100. Cho số nguyên dương N, in ra YES nếu là năm nhuận, ngược lại in NO.',
        'in_desc' => 'Một số nguyên dương N (1 <= N <= 10000).',
        'out_desc' => 'Chuỗi YES hoặc NO.',
        'time' => 1000,
        'cases' => [
            ['2024', 'YES', 1, 0],
            ['2023', 'NO', 1, 0],
            ['2000', 'YES', 0, 1],
            ['1900', 'NO', 0, 1],
            ['4', 'YES', 0, 1],
        ]
    ],
    [
        'slug' => 'factorial-n',
        'title' => 'Bài 3: Tính giai thừa N!',
        'topic' => 'Vòng lặp',
        'difficulty' => 'Easy',
        'desc' => 'Cho số nguyên không âm N (0 <= N <= 20). Hãy tính N! = 1 * 2 * ... * N (quy ước 0! = 1).',
        'in_desc' => 'Số nguyên N (0 <= N <= 20).',
        'out_desc' => 'Giá trị của N!.',
        'time' => 1000,
        'cases' => [
            ['5', '120', 1, 0],
            ['3', '6', 1, 0],
            ['0', '1', 0, 1],
            ['1', '1', 0, 1],
            ['12', '479001600', 0, 1],
        ]
    ],
    [
        'slug' => 'gcd-two-numbers',
        'title' => 'Bài 4: Ước chung lớn nhất (GCD)',
        'topic' => 'Số học',
        'difficulty' => 'Easy',
        'desc' => 'Cho hai số nguyên dương A và B. Hãy tìm ước số chung lớn nhất của A và B bằng thuật toán Euclid.',
        'in_desc' => 'Hai số nguyên dương A, B (1 <= A, B <= 10^9).',
        'out_desc' => 'Ước chung lớn nhất của A và B.',
        'time' => 1000,
        'cases' => [
            ['12 18', '6', 1, 0],
            ['7 13', '1', 1, 0],
            ['1 1000000000', '1', 0, 1],
            ['1000000000 500000000', '500000000', 0, 1],
            ['270 192', '6', 0, 0],
        ]
    ],
    [
        'slug' => 'prime-check',
        'title' => 'Bài 5: Kiểm tra số nguyên tố',
        'topic' => 'Số học',
        'difficulty' => 'Easy',
        'desc' => 'Cho số nguyên N. Kiểm tra xem N có phải là số nguyên tố hay không. In ra TRUE nếu là số nguyên tố, ngược lại in FALSE.',
        'in_desc' => 'Số nguyên N (-10^6 <= N <= 10^9).',
        'out_desc' => 'TRUE hoặc FALSE.',
        'time' => 1000,
        'cases' => [
            ['17', 'TRUE', 1, 0],
            ['15', 'FALSE', 1, 0],
            ['2', 'TRUE', 0, 1],
            ['1', 'FALSE', 0, 1],
            ['-7', 'FALSE', 0, 1],
        ]
    ],
    [
        'slug' => 'fibonacci-nth',
        'title' => 'Bài 6: Số Fibonacci thứ N',
        'topic' => 'Quy hoạch động',
        'difficulty' => 'Medium',
        'desc' => 'Dãy Fibonacci được định nghĩa: F_0 = 0, F_1 = 1, và F_n = F_{n-1} + F_{n-2} với n >= 2. Cho N, hãy in ra F_N.',
        'in_desc' => 'Số nguyên N (0 <= N <= 70).',
        'out_desc' => 'Giá trị F_N.',
        'time' => 1000,
        'cases' => [
            ['6', '8', 1, 0],
            ['10', '55', 1, 0],
            ['0', '0', 0, 1],
            ['1', '1', 0, 1],
            ['50', '12586269025', 0, 1],
        ]
    ],
    [
        'slug' => 'array-max-element',
        'title' => 'Bài 7: Tìm phần tử lớn nhất trong mảng',
        'topic' => 'Mảng',
        'difficulty' => 'Easy',
        'desc' => 'Cho mảng gồm N số nguyên. Hãy tìm giá trị lớn nhất trong mảng.',
        'in_desc' => 'Dòng 1 chứa số nguyên dương N (1 <= N <= 10^5). Dòng 2 chứa N số nguyên cách nhau bởi dấu cách.',
        'out_desc' => 'Giá trị lớn nhất trong mảng.',
        'time' => 1000,
        'cases' => [
            ["5\n1 9 3 7 2", '9', 1, 0],
            ["4\n-5 -2 -10 -1", '-1', 1, 0],
            ["1\n42", '42', 0, 1],
            ["5\n-1000000 -1000000 -1000000 -1000000 -1000000", '-1000000', 0, 1],
            ["6\n10 20 30 40 50 60", '60', 0, 0],
        ]
    ],
    [
        'slug' => 'palindrome-string',
        'title' => 'Bài 8: Chuỗi đối xứng (Palindrome)',
        'topic' => 'Xử lý chuỗi',
        'difficulty' => 'Medium',
        'desc' => 'Cho một chuỗi ký tự không chứa khoảng trắng S. Kiểm tra xem S đọc xuôi hay đọc ngược có giống nhau không. In YES hoặc NO.',
        'in_desc' => 'Một dòng chứa chuỗi S độ dài từ 1 đến 10^5.',
        'out_desc' => 'YES hoặc NO.',
        'time' => 1000,
        'cases' => [
            ['racecar', 'YES', 1, 0],
            ['codelearn', 'NO', 1, 0],
            ['a', 'YES', 0, 1],
            ['abba', 'YES', 0, 1],
            ['abcba1', 'NO', 0, 0],
        ]
    ],
    [
        'slug' => 'count-vowels',
        'title' => 'Bài 9: Đếm số lượng nguyên âm',
        'topic' => 'Xử lý chuỗi',
        'difficulty' => 'Easy',
        'desc' => 'Cho chuỗi ký tự S. Hãy đếm số lượng chữ cái nguyên âm (a, e, i, o, u không phân biệt hoa thường) trong chuỗi.',
        'in_desc' => 'Một dòng chứa chuỗi S.',
        'out_desc' => 'Số nguyên duy nhất là số nguyên âm đếm được.',
        'time' => 1000,
        'cases' => [
            ['CodeLearn', '4', 1, 0],
            ['HELLO WORLD', '3', 1, 0],
            ['bcdfg', '0', 0, 1],
            ['AEIOUaeiou', '10', 0, 1],
            ['PythonProgramming', '4', 0, 0],
        ]
    ],
    [
        'slug' => 'binary-search-index',
        'title' => 'Bài 10: Tìm kiếm nhị phân trên mảng tăng dần',
        'topic' => 'Tìm kiếm',
        'difficulty' => 'Medium',
        'desc' => 'Cho mảng N số nguyên đã sắp xếp tăng dần và số nguyên X. Tìm chỉ số (0-indexed) của X trong mảng, nếu không có in -1.',
        'in_desc' => 'Dòng 1 chứa N và X. Dòng 2 chứa N số nguyên tăng dần.',
        'out_desc' => 'Chỉ số của X (từ 0 đến N-1) hoặc -1.',
        'time' => 1000,
        'cases' => [
            ["5 7\n1 3 5 7 9", '3', 1, 0],
            ["5 4\n1 3 5 7 9", '-1', 1, 0],
            ["1 10\n10", '0', 0, 1],
            ["6 1\n1 2 3 4 5 6", '0', 0, 1],
            ["6 6\n1 2 3 4 5 6", '5', 0, 1],
        ]
    ]
];

$stmtProb = $pdo->prepare("INSERT INTO problems (slug, title, topic, difficulty, created_by) VALUES (?, ?, ?, ?, ?)");
$stmtVer  = $pdo->prepare("INSERT INTO problem_versions (problem_id, version_number, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, changelog, created_by) VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'Phiên bản v1.0 ban đầu', ?)");
$stmtUpd  = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
$stmtCase = $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) VALUES (?, ?, ?, ?, ?, ?, 20)");

foreach ($problemsData as $p) {
    $stmtProb->execute([$p['slug'], $p['title'], $p['topic'], $p['difficulty'], $instructorId]);
    $probId = $pdo->lastInsertId();

    $sampleIn = $p['cases'][0][0];
    $sampleOut = $p['cases'][0][1];

    $stmtVer->execute([$probId, $p['desc'], $p['in_desc'], $p['out_desc'], $sampleIn, $sampleOut, $p['time'], $instructorId]);
    $verId = $pdo->lastInsertId();

    $stmtUpd->execute([$verId, $probId]);

    foreach ($p['cases'] as $idx => $c) {
        $stmtCase->execute([$verId, $idx + 1, $c[0], $c[1], $c[2], $c[3]]);
    }
}

echo "[SUCCESS] Đã khởi tạo 5 tài khoản mẫu và 10 bài tập (50 test cases) thành công!\n";
