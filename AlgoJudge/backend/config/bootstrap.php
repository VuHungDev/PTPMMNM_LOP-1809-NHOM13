<?php
// backend/config/bootstrap.php — Điểm khởi tạo chung cho toàn bộ BE và FE.
// Mọi trang frontend và mọi endpoint api đều require file này ĐẦU TIÊN.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// URL gốc của ứng dụng khi chạy trên XAMPP (http://localhost/AlgoJudge)
define('BASE_URL', '/AlgoJudge');
define('BE_DIR', dirname(__DIR__));            // .../AlgoJudge/backend
define('FE_DIR', dirname(BE_DIR) . '/frontend'); // .../AlgoJudge/frontend

// --- Kết nối MySQL (MariaDB của XAMPP, ưu tiên cổng 3307) ---
define('DB_HOST', '127.0.0.1');
define('DB_PORTS', [3307, 3306]); // thử 3307 trước, rồi 3306
define('DB_NAME', 'algojudge');
define('DB_USER', 'root');
define('DB_PASS', '');

// --- Công cụ chấm bài cách ly (chạy trên máy chấm, không phải tiến trình Web) ---
// Bật TRUE để mô phỏng ngắt bộ thực thi (kiểm thử Phép thử số 2)
define('JUDGE_SANDBOX_SIMULATE_DOWN', false);

$__isWin = (PHP_OS_FAMILY === 'Windows');
// PHP_BINARY khi chạy qua Apache (mod_php) là httpd.exe, không thực thi được mã PHP.
// Suy ra php.exe từ php.ini (cùng thư mục cài PHP) để chấm bài PHP đúng.
$__phpBin = PHP_BINARY;
if ($__isWin && stripos(basename($__phpBin), 'httpd') !== false && ($__ini = php_ini_loaded_file()) !== false) {
    $__phpFromIni = dirname($__ini) . DIRECTORY_SEPARATOR . 'php.exe';
    if (is_file($__phpFromIni)) {
        $__phpBin = $__phpFromIni;
    }
}
define('PYTHON_BIN', $__isWin ? 'python' : 'python3');
define('PHP_BIN', $__phpBin); // trình thông dịch PHP để chấm bài PHP
unset($__phpBin, $__ini, $__phpFromIni);
define('NODE_BIN', 'node');
// Sandbox npm riêng của máy chấm (lodash, mathjs, dayjs, big.js, seedrandom...).
// Code JS được require() các gói này nhờ NODE_PATH; không đụng global npm của máy.
define('NODE_SANDBOX_DIR', BE_DIR . DIRECTORY_SEPARATOR . 'sandbox' . DIRECTORY_SEPARATOR . 'nodejs');
// Toolchain C/C++: ưu tiên MSYS2 ucrt64 cài sẵn trên máy chấm (đường dẫn tuyệt đối
// để Apache/mod_php vẫn thấy dù PATH của service khác PATH người dùng), fallback gcc/g++ trong PATH.
$__winlibsBin = getenv('LOCALAPPDATA') . '\Microsoft\WinGet\Packages\BrechtSanders.WinLibs.POSIX.UCRT_Microsoft.Winget.Source_8wekyb3d8bbwe\mingw64\bin';
$__gccCands = [$__winlibsBin . '\gcc', 'D:/msys64/ucrt64/bin/gcc', 'D:/msys64/mingw64/bin/gcc'];
$__gppCands = [$__winlibsBin . '\g++', 'D:/msys64/ucrt64/bin/g++', 'D:/msys64/mingw64/bin/g++'];
$__gccFound = 'gcc';
foreach ($__gccCands as $__c) {
    if (is_file($__c . '.exe')) { $__gccFound = $__c; break; }
}
$__gppFound = 'g++';
foreach ($__gppCands as $__c) {
    if (is_file($__c . '.exe')) { $__gppFound = $__c; break; }
}
unset($__winlibsBin);
define('GCC_BIN', $__gccFound);
define('GPP_BIN', $__gppFound);
unset($__gccCands, $__gppCands, $__gccFound, $__gppFound, $__c);
// JDK (Microsoft OpenJDK) cài ở Program Files nhưng PATH của Apache service
// thường không có, nên dò thư mục cài đặt trước rồi mới fallback PATH.
$__jdkCands = glob('C:/Program Files/Microsoft/jdk-*/bin/javac.exe') ?: [];
$__jdkBin = 'javac';
foreach ($__jdkCands as $__j) {
    $__jdkBin = substr($__j, 0, -4);
    break;
}
define('JAVAC_BIN', $__jdkBin);
$__javaBin = 'java';
if ($__jdkBin !== 'javac') {
    $__javaC = str_replace('/', DIRECTORY_SEPARATOR, dirname($__jdkBin)) . '\java.exe';
    if (is_file($__javaC)) { $__javaBin = substr($__javaC, 0, -4); }
}
define('JAVA_BIN', $__javaBin);
unset($__jdkCands, $__jdkBin, $__javaBin, $__javaC, $__j);
unset($__isWin);

$pdo = null;
$__lastErr = '';
foreach (DB_PORTS as $__port) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . $__port . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        define('DB_PORT', $__port);
        break;
    } catch (PDOException $e) {
        $__lastErr = $e->getMessage();
    }
}
unset($__port);
if ($pdo === null) {
    http_response_code(500);
    die('Không kết nối được MySQL. Hãy khởi động MySQL (XAMPP) rồi tải lại trang.');
}

// Escape HTML — dùng cho mọi dữ liệu in ra trình duyệt
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Đường dẫn URL tuyệt đối từ gốc ứng dụng
function url(string $path): string
{
    return BASE_URL . $path;
}
