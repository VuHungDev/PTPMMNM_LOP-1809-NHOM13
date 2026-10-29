<?php
// backend/database/seed_60_problems.php — Bổ sung 50 bài mới (10 bài × 5 ngôn ngữ: c, cpp, java, php, js)
// Mỗi bài: 5 test cases (2 công khai + 3 kín/biên), 20đ/ca. Chạy sau migrate_v3.sql. Idempotent theo slug.
require_once __DIR__ . '/../../lib/JudgeEngine.php';

$instructorId = (int)$pdo->query("SELECT id FROM users WHERE username = 'gv_cntt'")->fetchColumn();
if (!$instructorId) {
    $instructorId = (int)$pdo->query("SELECT id FROM users WHERE role = 'teacher' ORDER BY id ASC LIMIT 1")->fetchColumn();
}

$problemsData = [
    // ================= C (10 bài) =================
    ['c-print-hello-n', 'C01: In N dòng chào', 'Cơ bản', 'Easy', 'c', 'In ra dòng chữ Hello đúng N lần, mỗi dòng một lần.',
        'Số nguyên N (1 <= N <= 100).', 'N dòng, mỗi dòng chữ Hello.',
        1000, [['3', "Hello\nHello\nHello", 1, 0], ['1', 'Hello', 1, 0], ['5', "Hello\nHello\nHello\nHello\nHello", 0, 0], ['100', str_repeat("Hello\n", 99) . 'Hello', 0, 1], ['2', "Hello\nHello", 0, 0]]],
    ['c-sum-1-to-n', 'C02: Tổng 1 đến N', 'Vòng lặp', 'Easy', 'c', 'Tính tổng S = 1 + 2 + ... + N.',
        'Số nguyên N (1 <= N <= 10^9). Dùng kiểu 64-bit.', 'Tổng S.',
        1000, [['5', '15', 1, 0], ['1', '1', 1, 0], ['1000000000', '500000000500000000', 0, 1], ['100', '5050', 0, 0], ['999999999', '499999999500000000', 0, 1]]],
    ['c-abs-diff', 'C03: Trị tuyệt đối hiệu', 'Rẽ nhánh', 'Easy', 'c', 'Cho A, B. In |A - B|.',
        'Hai số nguyên A, B (|A|,|B| <= 10^9).', 'Trị tuyệt đối của hiệu.',
        1000, [['3 10', '7', 1, 0], ['10 3', '7', 1, 0], ['-1000000000 1000000000', '2000000000', 0, 1], ['5 5', '0', 0, 1], ['0 -7', '7', 0, 0]]],
    ['c-mult-table', 'C04: Bảng cửu chương rút gọn', 'Vòng lặp', 'Easy', 'c', 'Cho N, in N dòng: dòng i là "N x i = tich".',
        'Số nguyên N (1 <= N <= 9).', 'N dòng theo mẫu.',
        1000, [['2', "2 x 1 = 2\n2 x 2 = 4", 1, 0], ['1', '1 x 1 = 1', 1, 0], ['9', "9 x 1 = 9\n9 x 2 = 18", 0, 0], ['5', "5 x 1 = 5\n5 x 2 = 10", 0, 1], ['3', "3 x 1 = 3\n3 x 2 = 6", 0, 0]]],
    ['c-count-even', 'C05: Đếm số chẵn', 'Mảng', 'Easy', 'c', 'Cho N và N số. Đếm số lượng số chẵn.',
        'Dòng 1: N (1 <= N <= 10^5). Dòng 2: N số nguyên.', 'Số lượng số chẵn.',
        1000, [["5\n1 2 3 4 5", '2', 1, 0], ["3\n2 4 6", '3', 1, 0], ["1\n7", '0', 0, 1], ["4\n-2 -3 0 1", '2', 0, 1], ["6\n11 13 15 17 19 21", '0', 0, 0]]],
    ['c-reverse-int', 'C06: Đảo ngược số nguyên', 'Số học', 'Medium', 'c', 'Cho số nguyên N, in số đảo ngược chữ số (giữ dấu âm).',
        'Số nguyên N (|N| <= 10^9).', 'Số đảo ngược.',
        1000, [['123', '321', 1, 0], ['-456', '-654', 1, 0], ['100', '1', 0, 1], ['0', '0', 0, 1], ['1000000000', '1', 0, 1]]],
    ['c-gcd-array', 'C07: GCD của mảng', 'Số học', 'Medium', 'c', 'Cho N số, tìm GCD của cả mảng.',
        'Dòng 1: N (1 <= N <= 10^5). Dòng 2: N số nguyên dương.', 'GCD của mảng.',
        1000, [["3\n12 18 24", '6', 1, 0], ["2\n7 13", '1', 1, 0], ["1\n999999937", '999999937', 0, 1], ["5\n100 200 300 400 500", '100', 0, 1], ["4\n48 180 12 36", '12', 0, 0]]],
    ['c-upper-lower', 'C08: Đổi hoa-thường', 'Xử lý chuỗi', 'Medium', 'c', 'Cho chuỗi (có thể chứa khoảng trắng). Đổi chữ hoa thành thường và ngược lại.',
        'Một dòng chuỗi (độ dài <= 10^5).', 'Chuỗi sau khi đổi.',
        1000, [['Hello World', 'hELLO wORLD', 1, 0], ['ABC xyz', 'abc XYZ', 1, 0], ['123!@#', '123!@#', 0, 1], ['a', 'A', 0, 1], ['Mixed CASE 99', 'mIXED case 99', 0, 0]]],
    ['c-prime-range', 'C09: Liệt kê số nguyên tố', 'Số học', 'Medium', 'c', 'Cho A, B (A <= B). In các số nguyên tố trong [A, B], mỗi số một dòng. Nếu không có, in 0.',
        'Hai số nguyên A, B (1 <= A <= B <= 10^5).', 'Các số nguyên tố hoặc 0.',
        1000, [['1 10', "2\n3\n5\n7", 1, 0], ['14 16', '0', 1, 0], ['2 2', '2', 0, 1], ['90 100', "97", 0, 1], ['20 30', "23\n29", 0, 0]]],
    ['c-matrix-sum', 'C10: Tổng ma trận', 'Mảng', 'Medium', 'c', 'Cho ma trận N×M. Tính tổng tất cả phần tử.',
        'Dòng 1: N M (1 <= N,M <= 100). N dòng tiếp theo, mỗi dòng M số.', 'Tổng các phần tử.',
        1000, [["2 3\n1 2 3\n4 5 6", '21', 1, 0], ["1 1\n-5", '-5', 1, 0], ["100 100\n" . trim(str_repeat('1 ', 10000)), '10000', 0, 1], ["3 3\n1 1 1\n1 1 1\n1 1 1", '9', 0, 0], ["2 2\n-1 -2\n-3 -4", '-10', 0, 1]]],
    // ================= C++ (10 bài) =================
    ['cpp-sort-asc', 'C++01: Sắp xếp tăng dần', 'Mảng', 'Easy', 'cpp', 'Cho N và N số. In ra dãy đã sắp xếp tăng dần, cách nhau bởi dấu cách.',
        'Dòng 1: N (1 <= N <= 10^5). Dòng 2: N số nguyên.', 'Dãy tăng dần.',
        1000, [["5\n3 1 4 1 5", '1 1 3 4 5', 1, 0], ["1\n42", '42', 1, 0], ["6\n5 4 3 2 1 0", '0 1 2 3 4 5', 0, 1], ["4\n-1 -5 3 0", '-5 -1 0 3', 0, 1], ["3\n7 7 7", '7 7 7', 0, 0]]],
    ['cpp-string-len', 'C++02: Độ dài từ dài nhất', 'Xử lý chuỗi', 'Easy', 'cpp', 'Cho dòng văn bản. In độ dài của từ dài nhất (từ = chuỗi không chứa khoảng trắng).',
        'Một dòng văn bản (độ dài <= 10^5).', 'Độ dài từ dài nhất.',
        1000, [['hello world', '5', 1, 0], ['a bb ccc dddd', '4', 1, 0], ['x', '1', 0, 1], ['  spaced   out  ', '6', 0, 1], ['12345 678', '5', 0, 0]]],
    ['cpp-second-max', 'C++03: Lớn thứ hai', 'Mảng', 'Easy', 'cpp', 'Cho N số phân biệt. Tìm giá trị lớn thứ hai.',
        'Dòng 1: N (2 <= N <= 10^5). Dòng 2: N số phân biệt.', 'Giá trị lớn thứ hai.',
        1000, [["5\n1 9 3 7 2", '7', 1, 0], ["2\n-5 -1", '-5', 1, 0], ["3\n100 200 150", '150', 0, 1], ["4\n-10 -20 -30 -5", '-10', 0, 1], ["6\n6 5 4 3 2 1", '5', 0, 0]]],
    ['cpp-sum-digits', 'C++04: Tổng chữ số', 'Số học', 'Easy', 'cpp', 'Cho số nguyên (có thể âm). Tính tổng các chữ số (bỏ dấu âm).',
        'Số nguyên N (|N| <= 10^18).', 'Tổng chữ số.',
        1000, [['12345', '15', 1, 0], ['-99', '18', 1, 0], ['0', '0', 0, 1], ['1000000000000000000', '1', 0, 1], ['-1001', '2', 0, 0]]],
    ['cpp-freq-char', 'C++05: Ký tự xuất hiện nhiều nhất', 'Xử lý chuỗi', 'Easy', 'cpp', 'Cho chuỗi lowercase. In ký tự xuất hiện nhiều nhất (nếu bằng nhau, lấy ký tự nhỏ nhất).',
        'Chuỗi lowercase (độ dài 1..10^5).', 'Ký tự frequent nhất.',
        1000, [['abracadabra', 'a', 1, 0], ['zzzxxx', 'x', 1, 0], ['q', 'q', 0, 1], ['bbaacc', 'a', 0, 1], ['hello', 'l', 0, 0]]],
    ['cpp-two-sum-idx', 'C++06: Two Sum (chỉ số)', 'Tìm kiếm', 'Medium', 'cpp', 'Cho mảng N số và K. Tìm 2 chỉ số i<j sao cho a[i]+a[j]=K. In i j (0-indexed). Đề đảm bảo có đúng 1 đáp án.',
        'Dòng 1: N K. Dòng 2: N số.', 'Hai chỉ số i j.',
        1000, [["4 9\n2 7 11 15", '0 1', 1, 0], ["3 6\n3 2 4", '1 2', 1, 0], ["2 -5\n-2 -3", '0 1', 0, 1], ["5 10\n1 2 3 7 8", '2 3', 0, 1], ["6 0\n-1 1 -2 2 -3 3", '0 1', 0, 0]]],
    ['cpp-stack-check', 'C++07: Ngoặc hợp lệ', 'Xử lý chuỗi', 'Medium', 'cpp', 'Cho chuỗi chỉ gồm ()[]{}. Kiểm tra ngoặc đóng mở hợp lệ. In YES/NO.',
        'Chuỗi độ dài 1..10^5.', 'YES hoặc NO.',
        1000, [['()[]{}', 'YES', 1, 0], ['([)]', 'NO', 1, 0], ['(', 'NO', 0, 1], ['{[()]}', 'YES', 0, 1], ['', 'YES', 0, 1]]],
    ['cpp-prefix-sum', 'C++08: Tổng đoạn (prefix)', 'Mảng', 'Medium', 'cpp', 'Cho mảng N số và Q truy vấn. Mỗi truy vấn L R (1-indexed), in tổng a[L..R].',
        'Dòng 1: N Q. Dòng 2: N số. Q dòng: L R.', 'Mỗi truy vấn một dòng đáp án.',
        1000, [["5 2\n1 2 3 4 5\n1 3\n2 5", "6\n14", 1, 0], ["1 1\n7\n1 1", '7', 1, 0], ["3 3\n-1 -2 -3\n1 1\n1 3\n2 2", "-1\n-6\n-2", 0, 1], ["4 1\n10 20 30 40\n1 4", '100', 0, 0], ["5 2\n5 5 5 5 5\n3 3\n1 5", "5\n25", 0, 1]]],
    ['cpp-unique-count', 'C++09: Đếm phần tử phân biệt', 'Mảng', 'Medium', 'cpp', 'Cho N số. Đếm có bao nhiêu giá trị phân biệt.',
        'Dòng 1: N (1 <= N <= 10^5). Dòng 2: N số.', 'Số giá trị phân biệt.',
        1000, [["5\n1 2 2 3 1", '3', 1, 0], ["1\n99", '1', 1, 0], ["6\n5 5 5 5 5 5", '1', 0, 1], ["7\n-1 0 -1 2 0 3 2", '4', 0, 1], ["4\n10 20 30 40", '4', 0, 0]]],
    ['cpp-gcd-lcm', 'C++10: GCD và LCM', 'Số học', 'Medium', 'cpp', 'Cho A, B. In GCD và LCM trên 2 dòng.',
        'Hai số nguyên dương (<= 10^9).', 'Dòng 1: GCD. Dòng 2: LCM.',
        1000, [['12 18', "6\n36", 1, 0], ['7 13', "1\n91", 1, 0], ['1 1', "1\n1", 0, 1], ['1000000000 999999999', "1\n999999999000000000", 0, 1], ['48 180', "12\n720", 0, 0]]],
    // ================= Java (10 bài) =================
    ['java-hello-name', 'Java01: Chào theo tên', 'Cơ bản', 'Easy', 'java', 'Cho tên (một từ). In ra "Hello, <ten>!".',
        'Một từ (độ dài <= 50).', 'Lời chào.',
        1000, [['An', 'Hello, An!', 1, 0], ['Binh', 'Hello, Binh!', 1, 0], ['X', 'Hello, X!', 0, 1], ['Java', 'Hello, Java!', 0, 0], ['Z9', 'Hello, Z9!', 0, 1]]],
    ['java-max-three', 'Java02: Lớn nhất 3 số', 'Rẽ nhánh', 'Easy', 'java', 'Cho 3 số nguyên. In số lớn nhất.',
        'Ba số nguyên cách nhau bởi khoảng trắng.', 'Số lớn nhất.',
        1000, [['3 7 5', '7', 1, 0], ['-1 -2 -3', '-1', 1, 0], ['5 5 5', '5', 0, 1], ['-10 0 10', '10', 0, 1], ['100 99 101', '101', 0, 0]]],
    ['java-even-sum', 'Java03: Tổng số chẵn 1..N', 'Vòng lặp', 'Easy', 'java', 'Tính tổng các số chẵn từ 1 đến N.',
        'Số nguyên N (1 <= N <= 10^9).', 'Tổng các số chẵn.',
        1000, [['10', '30', 1, 0], ['1', '0', 1, 0], ['1000000000', '250000000500000000', 0, 1], ['7', '12', 0, 0], ['100', '2550', 0, 1]]],
    ['java-vowel-count', 'Java04: Đếm nguyên âm', 'Xử lý chuỗi', 'Easy', 'java', 'Đếm nguyên âm (a,e,i,o,u, không phân biệt hoa thường) trong chuỗi.',
        'Một dòng chuỗi.', 'Số nguyên âm.',
        1000, [['Hello', '2', 1, 0], ['XYZ', '0', 1, 0], ['AEIOUaeiou', '10', 0, 1], ['Java Programming', '5', 0, 0], ['bcdfg', '0', 0, 1]]],
    ['java-array-sum', 'Java05: Tổng mảng', 'Mảng', 'Easy', 'java', 'Cho N và N số. Tính tổng (dùng long).',
        'Dòng 1: N (1 <= N <= 10^5). Dòng 2: N số.', 'Tổng.',
        1000, [["4\n1 2 3 4", '10', 1, 0], ["1\n-100", '-100', 1, 0], ["3\n1000000000 1000000000 1000000000", '3000000000', 0, 1], ["2\n-5 5", '0', 0, 0], ["5\n0 0 0 0 0", '0', 0, 1]]],
    ['java-fizzbuzz', 'Java06: FizzBuzz', 'Vòng lặp', 'Medium', 'java', 'Cho N. Với i từ 1..N, in Fizz nếu chia hết 3, Buzz nếu chia hết 5, FizzBuzz nếu cả hai, ngược lại in i. Mỗi kết quả một dòng.',
        'Số nguyên N (1 <= N <= 100).', 'N dòng kết quả.',
        1000, [['3', "1\n2\nFizz", 1, 0], ['5', "1\n2\nFizz\n4\nBuzz", 1, 0], ['1', '1', 0, 1], ['15', "1\n2\nFizz\n4\nBuzz\nFizz\n7\n8\nFizz\nBuzz\n11\nFizz\n13\n14\nFizzBuzz", 0, 1], ['6', "1\n2\nFizz\n4\nBuzz\nFizz", 0, 0]]],
    ['java-palindrome-num', 'Java07: Số đối xứng', 'Số học', 'Medium', 'java', 'Kiểm tra số nguyên dương N có đọc xuôi ngược giống nhau không. In YES/NO.',
        'Số nguyên dương N (1 <= N <= 10^18).', 'YES hoặc NO.',
        1000, [['121', 'YES', 1, 0], ['123', 'NO', 1, 0], ['7', 'YES', 0, 1], ['1221', 'YES', 0, 1], ['100', 'NO', 0, 0]]],
    ['java-word-count', 'Java08: Đếm từ', 'Xử lý chuỗi', 'Medium', 'java', 'Cho dòng văn bản. Đếm số từ (tách bởi khoảng trắng, bỏ từ rỗng).',
        'Một dòng văn bản.', 'Số từ.',
        1000, [['hello world', '2', 1, 0], ['  a  b   c ', '3', 1, 0], ['', '0', 0, 1], ['single', '1', 0, 1], ['Java is fun', '3', 0, 0]]],
    ['java-binary-search', 'Java09: Tìm kiếm nhị phân', 'Tìm kiếm', 'Medium', 'java', 'Mảng tăng dần N số và X. In chỉ số của X (0-indexed) hoặc -1.',
        'Dòng 1: N X. Dòng 2: N số tăng dần.', 'Chỉ số hoặc -1.',
        1000, [["5 7\n1 3 5 7 9", '3', 1, 0], ["5 4\n1 3 5 7 9", '-1', 1, 0], ["1 10\n10", '0', 0, 1], ["6 6\n1 2 3 4 5 6", '5', 0, 1], ["6 1\n1 2 3 4 5 6", '0', 0, 0]]],
    ['java-matrix-diag', 'Java10: Tổng đường chéo', 'Mảng', 'Medium', 'java', 'Cho ma trận vuông N×N. Tính tổng đường chéo chính.',
        'Dòng 1: N (1 <= N <= 100). N dòng tiếp theo.', 'Tổng đường chéo chính.',
        1000, [["3\n1 2 3\n4 5 6\n7 8 9", '15', 1, 0], ["1\n42", '42', 1, 0], ["2\n-1 -2\n-3 -4", '-5', 0, 1], ["4\n1 0 0 0\n0 2 0 0\n0 0 3 0\n0 0 0 4", '10', 0, 1], ["2\n5 5\n5 5", '10', 0, 0]]],
    // ================= PHP (10 bài) =================
    ['php-hello-repeat', 'PHP01: Lặp chuỗi', 'Cơ bản', 'Easy', 'php', 'Cho chuỗi S và N. In S lặp lại N lần, mỗi lần một dòng.',
        'Dòng 1: chuỗi S. Dòng 2: số N (1 <= N <= 100).', 'N dòng S.',
        1000, [["abc\n3", "abc\nabc\nabc", 1, 0], ["x\n1", 'x', 1, 0], ["Hi\n2", "Hi\nHi", 0, 0], ["9\n5", "9\n9\n9\n9\n9", 0, 1], ["ok\n4", "ok\nok\nok\nok", 0, 1]]],
    ['php-sum-range', 'PHP02: Tổng đoạn', 'Vòng lặp', 'Easy', 'php', 'Cho A, B (A <= B). Tính tổng từ A đến B.',
        'Hai số A B (|A|,|B| <= 10^9).', 'Tổng đoạn.',
        1000, [['1 5', '15', 1, 0], ['-3 3', '0', 1, 0], ['1 1000000000', '500000000500000000', 0, 1], ['5 5', '5', 0, 1], ['-5 -1', '-15', 0, 0]]],
    ['php-odd-count', 'PHP03: Đếm số lẻ', 'Mảng', 'Easy', 'php', 'Cho N và N số. Đếm số lượng số lẻ.',
        'Dòng 1: N. Dòng 2: N số.', 'Số lượng số lẻ.',
        1000, [["5\n1 2 3 4 5", '3', 1, 0], ["3\n2 4 6", '0', 1, 0], ["1\n-7", '1', 0, 1], ["4\n0 0 0 1", '1', 0, 1], ["6\n11 22 33 44 55 66", '3', 0, 0]]],
    ['php-strrev', 'PHP04: Đảo chuỗi', 'Xử lý chuỗi', 'Easy', 'php', 'Cho chuỗi S (một dòng). In chuỗi đảo ngược.',
        'Một dòng chuỗi.', 'Chuỗi đảo ngược.',
        1000, [['hello', 'olleh', 1, 0], ['a', 'a', 1, 0], ['12345', '54321', 0, 1], ['', '', 0, 1], ['Ab C', 'C bA', 0, 0]]],
    ['php-max-digit', 'PHP05: Chữ số lớn nhất', 'Số học', 'Easy', 'php', 'Cho số nguyên dương. Tìm chữ số lớn nhất.',
        'Số nguyên N (0 <= N <= 10^18).', 'Chữ số lớn nhất.',
        1000, [['93842', '9', 1, 0], ['5', '5', 1, 0], ['0', '0', 0, 1], ['1000009', '9', 0, 1], ['5723', '7', 0, 0]]],
    ['php-sort-desc', 'PHP06: Sắp xếp giảm dần', 'Mảng', 'Medium', 'php', 'Cho N số. Sắp xếp giảm dần, cách nhau bởi dấu cách.',
        'Dòng 1: N. Dòng 2: N số.', 'Dãy giảm dần.',
        1000, [["5\n3 1 4 1 5", '5 4 3 1 1', 1, 0], ["1\n7", '7', 1, 0], ["4\n-1 -5 3 0", '3 0 -1 -5', 0, 1], ["3\n2 2 2", '2 2 2', 0, 0], ["6\n0 9 8 7 6 5", '9 8 7 6 5 0', 0, 1]]],
    ['php-char-freq', 'PHP07: Tần suất ký tự', 'Xử lý chuỗi', 'Medium', 'php', 'Cho chuỗi lowercase. In "ky_tu so_lan" của ký tự xuất hiện nhiều nhất (bằng nhau lấy nhỏ nhất).',
        'Chuỗi lowercase.', 'Ký tự và số lần.',
        1000, [['abracadabra', 'a 5', 1, 0], ['hello', 'l 2', 1, 0], ['q', 'q 1', 0, 1], ['bbaacc', 'a 2', 0, 1], ['mississippi', 'i 4', 0, 0]]],
    ['php-subarray-max', 'PHP08: Tổng đoạn lớn nhất', 'Mảng', 'Medium', 'php', 'Cho N số. Tìm tổng lớn nhất của đoạn con liên tiếp (Kadane).',
        'Dòng 1: N. Dòng 2: N số.', 'Tổng lớn nhất.',
        1000, [["5\n-2 1 -3 4 -1", '4', 1, 0], ["3\n-5 -1 -8", '-1', 1, 0], ["1\n42", '42', 0, 1], ["8\n-2 -3 4 -1 -2 1 5 -3", '7', 0, 1], ["4\n1 2 3 4", '10', 0, 0]]],
    ['php-prime-sum', 'PHP09: Tổng số nguyên tố', 'Số học', 'Medium', 'php', 'Cho N. Tính tổng các số nguyên tố <= N.',
        'Số nguyên N (1 <= N <= 10^5).', 'Tổng.',
        1000, [['10', '17', 1, 0], ['1', '0', 1, 0], ['2', '2', 0, 1], ['100', '1060', 0, 1], ['20', '77', 0, 0]]],
    ['php-json-sum', 'PHP10: Tổng trong chuỗi số', 'Xử lý chuỗi', 'Medium', 'php', 'Cho dòng gồm số và dấu phẩy/khoảng trắng. Tính tổng các số.',
        'Một dòng (VD: "1, 2, 3").', 'Tổng.',
        1000, [['1, 2, 3', '6', 1, 0], ['10', '10', 1, 0], ['-1, -2, 3', '0', 0, 1], ['0,0,0', '0', 0, 1], ['5,10,15,20', '50', 0, 0]]],
    // ================= JS (10 bài) =================
    ['js-hello-repeat', 'JS01: Lặp lời chào', 'Cơ bản', 'Easy', 'js', 'Cho N. In N dòng "Hello, JS!".',
        'Số nguyên N (1 <= N <= 100).', 'N dòng chào.',
        1000, [['3', "Hello, JS!\nHello, JS!\nHello, JS!", 1, 0], ['1', 'Hello, JS!', 1, 0], ['2', "Hello, JS!\nHello, JS!", 0, 0], ['5', "Hello, JS!\nHello, JS!\nHello, JS!\nHello, JS!\nHello, JS!", 0, 1], ['4', "Hello, JS!\nHello, JS!\nHello, JS!\nHello, JS!", 0, 0]]],
    ['js-fizzbuzz-lite', 'JS02: FizzBuzz rút gọn', 'Vòng lặp', 'Easy', 'js', 'Cho N. Với i=1..N in Fizz (3), Buzz (5), FizzBuzz (cả hai), ngược lại in i.',
        'Số nguyên N (1 <= N <= 100).', 'N dòng.',
        1000, [['3', "1\n2\nFizz", 1, 0], ['5', "1\n2\nFizz\n4\nBuzz", 1, 0], ['1', '1', 0, 1], ['15', "1\n2\nFizz\n4\nBuzz\nFizz\n7\n8\nFizz\nBuzz\n11\nFizz\n13\n14\nFizzBuzz", 0, 1], ['6', "1\n2\nFizz\n4\nBuzz\nFizz", 0, 0]]],
    ['js-array-sum', 'JS03: Tổng mảng', 'Mảng', 'Easy', 'js', 'Cho N và N số. Tính tổng.',
        'Dòng 1: N. Dòng 2: N số.', 'Tổng.',
        1000, [["4\n1 2 3 4", '10', 1, 0], ["1\n-5", '-5', 1, 0], ["3\n1000000000 1000000000 1000000000", '3000000000', 0, 1], ["2\n0 0", '0', 0, 0], ["5\n-1 1 -2 2 0", '0', 0, 1]]],
    ['js-vowel-count', 'JS04: Đếm nguyên âm', 'Xử lý chuỗi', 'Easy', 'js', 'Đếm nguyên âm (a,e,i,o,u, không phân biệt hoa thường).',
        'Một dòng chuỗi.', 'Số nguyên âm.',
        1000, [['Hello World', '3', 1, 0], ['xyz', '0', 1, 0], ['AEIOU', '5', 0, 1], ['JavaScript', '3', 0, 0], ['', '0', 0, 1]]],
    ['js-max-min', 'JS05: Min Max', 'Mảng', 'Easy', 'js', 'Cho N số. In "min max" trên một dòng.',
        'Dòng 1: N. Dòng 2: N số.', 'min max.',
        1000, [["5\n3 1 4 1 5", '1 5', 1, 0], ["1\n9", '9 9', 1, 0], ["3\n-5 -2 -9", '-9 -2', 0, 1], ["4\n0 0 0 0", '0 0', 0, 0], ["2\n100 -100", '-100 100', 0, 1]]],
    ['js-two-sum', 'JS06: Two Sum', 'Tìm kiếm', 'Medium', 'js', 'Cho mảng N số và K. Tìm i<j sao cho a[i]+a[j]=K (đúng 1 đáp án).',
        'Dòng 1: N K. Dòng 2: N số.', 'Hai chỉ số i j.',
        1000, [["4 9\n2 7 11 15", '0 1', 1, 0], ["3 6\n3 2 4", '1 2', 1, 0], ["2 0\n-1 1", '0 1', 0, 1], ["5 8\n1 2 3 4 5", '2 4', 0, 1], ["6 7\n1 2 3 4 5 6", '0 5', 0, 0]]],
    ['js-unique-sorted', 'JS07: Loại trùng + sắp xếp', 'Mảng', 'Medium', 'js', 'Cho N số. In các giá trị phân biệt đã sắp xếp tăng dần.',
        'Dòng 1: N. Dòng 2: N số.', 'Dãy phân biệt tăng dần.',
        1000, [["5\n3 1 3 2 1", '1 2 3', 1, 0], ["1\n7", '7', 1, 0], ["6\n5 5 5 5 5 5", '5', 0, 1], ["4\n-1 0 -1 2", '-1 0 2', 0, 1], ["7\n4 4 3 3 2 2 1", '1 2 3 4', 0, 0]]],
    ['js-palindrome-str', 'JS08: Chuỗi đối xứng', 'Xử lý chuỗi', 'Medium', 'js', 'Cho chuỗi (có thể chứa khoảng trắng). Kiểm tra đối xứng chính xác (phân biệt hoa thường). In YES/NO.',
        'Một dòng chuỗi.', 'YES hoặc NO.',
        1000, [['racecar', 'YES', 1, 0], ['hello', 'NO', 1, 0], ['a', 'YES', 0, 1], ['ab ba', 'YES', 0, 1], ['AbA', 'NO', 0, 0]]],
    ['js-climb-stairs', 'JS09: Bậc thang', 'Quy hoạch động', 'Medium', 'js', 'Leo N bậc, mỗi bước 1 hoặc 2 bậc. Đếm số cách.',
        'Số nguyên N (1 <= N <= 30).', 'Số cách.',
        1000, [['3', '3', 1, 0], ['1', '1', 1, 0], ['2', '2', 0, 1], ['10', '89', 0, 1], ['5', '8', 0, 0]]],
    ['js-missing-number', 'JS10: Số còn thiếu', 'Mảng', 'Medium', 'js', 'Cho N và N-1 số phân biệt từ 1..N. Tìm số còn thiếu.',
        'Dòng 1: N (2 <= N <= 10^5). Dòng 2: N-1 số.', 'Số còn thiếu.',
        1000, [["5\n1 2 4 5", '3', 1, 0], ["2\n1", '2', 1, 0], ["3\n2 3", '1', 0, 1], ["10\n1 2 3 4 5 6 7 8 10", '9', 0, 1], ["4\n4 3 1", '2', 0, 0]]],
];

$stmtProb = $pdo->prepare("INSERT INTO problems (slug, title, topic, difficulty, suggested_lang, created_by) VALUES (?, ?, ?, ?, ?, ?)");
$stmtVer = $pdo->prepare("INSERT INTO problem_versions (problem_id, version_number, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, changelog, created_by) VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'Phiên bản v1.0 ban đầu', ?)");
$stmtUpd = $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?");
$stmtCase = $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) VALUES (?, ?, ?, ?, ?, ?, 20)");
$stmtChk = $pdo->prepare("SELECT id FROM problems WHERE slug = ?");

$added = 0;
$skipped = 0;
foreach ($problemsData as $p) {
    $stmtChk->execute([$p[0]]);
    if ($stmtChk->fetch()) {
        $skipped++;
        continue;
    }
    [$slug, $title, $topic, $diff, $lang, $desc, $inD, $outD, $time, $cases] = $p;
    $stmtProb->execute([$slug, $title, $topic, $diff, $lang, $instructorId]);
    $probId = (int)$pdo->lastInsertId();
    $stmtVer->execute([$probId, $desc, $inD, $outD, $cases[0][0], $cases[0][1], $time, $instructorId]);
    $verId = (int)$pdo->lastInsertId();
    $stmtUpd->execute([$verId, $probId]);
    foreach ($cases as $idx => $c) {
        $stmtCase->execute([$verId, $idx + 1, $c[0], $c[1], $c[2], $c[3]]);
    }
    $added++;
}

echo "[SUCCESS] Thêm mới $added bài, bỏ qua $skipped bài đã tồn tại.\n";
$count = $pdo->query("SELECT COUNT(*) FROM problems")->fetchColumn();
echo "Tổng số bài hiện tại: $count\n";
