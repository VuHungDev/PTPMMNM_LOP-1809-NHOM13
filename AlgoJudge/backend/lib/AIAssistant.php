<?php
// backend/lib/AIAssistant.php — Bộ não chatbot full-app: định tuyến ý định + tra cứu số liệu + thao tác có xác nhận.
// Nguyên tắc: đọc thoải mái; mọi thao tác GHI đều phải qua confirm_token (2 bước).

class AIAssistant
{
    /** Danh sách thao tác GHI được phép qua chatbot (mỗi việc một hàm, không SQL tự do). */
    const ACTIONS = [
        'create_user' => 'Tạo tài khoản mới',
        'add_test_case' => 'Thêm test case cho bài tập',
        'toggle_problem' => 'Bật/tắt hiển thị bài tập',
        'rejudge' => 'Chấm lại một bài nộp',
        'retry_run' => 'Thử lại một lần chấm lỗi',
        'export_progress' => 'Xuất báo cáo tiến độ lớp',
    ];

    /**
     * Định tuyến tin nhắn → ['kind' => 'parse_problem'|'qa'|'stats'|'confirm'|'execute', ...].
     * Heuristic cục bộ (không tốn API) cho các ý định rõ; còn lại nhờ AI phân loại.
     */
    public static function route(array $user, string $text): array
    {
        $text = trim($text);
        if ($text === '') throw new Exception('Hãy nhập nội dung trước.');
        if (mb_strlen($text) > 12000) throw new Exception('Tin nhắn quá dài (tối đa ~12000 ký tự).');
        $isStaff = in_array($user['role'], ['teacher', 'admin'], true);

        // Học viên: chỉ hỏi đáp + tra cứu của mình — không tạo bài, không thao tác giảng viên
        if (!$isStaff) {
            $lines = count(preg_split('/\r?\n/', $text));
            if ($lines >= 3 && preg_match('/(input|output|ví dụ|đầu vào|đầu ra)/iu', $text)) {
                return ['kind' => 'qa', 'answer' => 'Tạo bài tập là chức năng của giảng viên. Bạn có thể hỏi tôi về đề bài, cách nộp bài, xem điểm ở đâu... (tôi chỉ gợi ý hướng, không đưa code giải).'];
            }
            if (preg_match('/tạo\s+(tài khoản|user)|thêm\s+test|chấm\s*lại|thử\s*lại|bật|tắt.*bài|xuất.*báo cáo/u', mb_strtolower($text))) {
                return ['kind' => 'qa', 'answer' => 'Chức năng này dành cho giảng viên. Bạn cần giúp gì về luyện tập, nộp bài hay xem điểm?'];
            }
            return ['kind' => 'qa'];
        }

        // 1. Đề bài dài (nhiều dòng + có Input/Output) → tạo bài (giữ luồng cũ)
        $lines = count(preg_split('/\r?\n/', $text));
        if ($lines >= 3 && preg_match('/(input|output|ví dụ|đầu vào|đầu ra|stdin|test)/iu', $text)) {
            return ['kind' => 'parse_problem'];
        }

        // 2. Ý định thao tác GHI — bắt bằng từ khóa tiếng Việt (không cần AI)
        $t = mb_strtolower($text);
        if (preg_match('/tạo\s+(tài khoản|tài khoan|user|người dùng|hoc vien|học viên|giáo viên|giao vien)/u', $t)) {
            return self::needConfirm('create_user', self::extractUserFields($text), $user);
        }
        if (preg_match('/thêm\s+test/u', $t)) {
            return self::needConfirm('add_test_case', self::extractTestFields($text), $user);
        }
        if (preg_match('/(bật|tat|tắt|ẩn|hiện|an|hien).*bài/u', $t) || preg_match('/bài.*(bật|tat|tắt|ẩn|hiện)/u', $t)) {
            return self::needConfirm('toggle_problem', self::extractProblemRef($text), $user);
        }
        if (preg_match('/chấm\s*lại/u', $t)) {
            return self::needConfirm('rejudge', self::extractSubmissionRef($text), $user);
        }
        if (preg_match('/thử\s*lại/u', $t) && preg_match('/run|chấm|lần/u', $t)) {
            return self::needConfirm('retry_run', self::extractRunRef($text), $user);
        }
        if (preg_match('/xuất|bao cao|báo cáo|excel|thống kê|thong ke/u', $t)) {
            return self::needConfirm('export_progress', self::extractClassRef($text), $user);
        }

        // 3. Câu hỏi tra cứu số liệu — bắt bằng từ khóa, truy vấn DB thật
        $stat = self::tryStats($user, $text);
        if ($stat !== null) return $stat;

        // 4. Còn lại → hỏi đáp tự do qua AI (có ngữ cảnh vai trò + bản đồ tính năng)
        return ['kind' => 'qa'];
    }

    // ---------- Tra cứu số liệu (Nhóm 1) ----------

    private static function tryStats(array $user, string $text): ?array
    {
        global $pdo;
        $t = mb_strtolower($text);
        $isTeacher = in_array($user['role'], ['teacher', 'admin'], true);
        if (!$isTeacher) return null;
        $scope = ($user['role'] === 'teacher' && $user['class_code'] !== 'ALL')
            ? " AND u.class_code = " . $pdo->quote($user['class_code']) : '';

        // Bao nhiêu học viên / bài tập / bài nộp...
        if (preg_match('/(bao nhiêu|mấy|thống kê|tổng|tong).*(học viên|hoc vien|học sinh|user|tài khoản)/u', $t)
            || preg_match('/(học viên|hoc vien).*(bao nhiêu|mấy|danh sách|liệt kê)/u', $t)) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.role = 'student'$scope")->fetchColumn();
            $cls = $user['role'] === 'teacher' && $user['class_code'] !== 'ALL' ? " lớp {$user['class_code']}" : '';
            return ['kind' => 'stats', 'answer' => "Hiện có **$n học viên**$cls."];
        }
        if (preg_match('/(bao nhiêu|mấy|tổng).*(bài tập|bai tap|bài|problem)/u', $t)
            || preg_match('/(bài tập|bài).*(bao nhiêu|mấy|danh sách)/u', $t)) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM problems WHERE is_active = 1")->fetchColumn();
            return ['kind' => 'stats', 'answer' => "Hiện có **$n bài tập** đang hiển thị. Xem chi tiết tại trang Danh sách bài tập."];
        }
        if (preg_match('/(hàng đợi|hang doi|queue|đang chấm|dang cham|chờ chấm|cho cham)/u', $t)) {
            $q = (int)$pdo->query("SELECT COUNT(*) FROM judging_runs WHERE status IN ('QUEUED','RUNNING')")->fetchColumn();
            $msg = $q > 0 ? "Hàng đợi chấm hiện có **$q lượt** đang chờ/xử lý." : "Hàng đợi chấm đang trống — mọi bài đã chấm xong.";
            return ['kind' => 'stats', 'answer' => $msg];
        }
        if (preg_match('/(bài.*(sai|khó|rớt|rot|wa nhiều)|wa nhiều|khó nhất|ty le|tỉ lệ)/u', $t)) {
            $rows = $pdo->query("
                SELECT p.id, p.title,
                       SUM(s.final_verdict = 'AC') AS ac, COUNT(*) AS total
                FROM submissions s JOIN problems p ON p.id = s.problem_id
                GROUP BY p.id HAVING total >= 3 ORDER BY ac / total ASC LIMIT 5
            ")->fetchAll();
            if (!$rows) return ['kind' => 'stats', 'answer' => 'Chưa đủ dữ liệu nộp bài để đánh giá bài khó nhất.'];
            $out = "Top bài tỉ lệ AC thấp nhất:\n";
            foreach ($rows as $r) {
                $rate = $r['total'] ? round($r['ac'] / $r['total'] * 100, 1) : 0;
                $out .= "- #{$r['id']} {$r['title']}: {$rate}% AC ({$r['ac']}/{$r['total']})\n";
            }
            return ['kind' => 'stats', 'answer' => $out];
        }
        if (preg_match('/(tiến độ|tien do|điểm|diem|kết quả|ket qua).*(lớp|lop|class)/u', $t)
            || preg_match('/(lớp|lop).*(tiến độ|điểm|thế nào|ra sao)/u', $t)) {
            $rows = $pdo->query("
                SELECT COUNT(*) AS n,
                       SUM(s.final_verdict = 'AC') AS ac, COUNT(*) AS total
                FROM submissions s JOIN users u ON u.id = s.user_id WHERE 1=1$scope
            ")->fetch();
            $total = (int)($rows['total'] ?? 0);
            $ac = (int)($rows['ac'] ?? 0);
            $rate = $total ? round($ac / $total * 100, 1) : 0;
            return ['kind' => 'stats', 'answer' => "Lớp: $total lượt nộp, $ac lượt AC (**$rate%**). Muốn chi tiết từng bạn, bấm Xuất báo cáo hoặc vào trang Tiến độ học viên."];
        }
        return null;
    }

    // ---------- Trích tham số từ câu tự nhiên ----------

    private static function extractUserFields(string $text): array
    {
        $f = ['full_name' => '', 'username' => '', 'email' => '', 'role' => 'student', 'class_code' => ''];
        if (preg_match('/tên\s+(là\s+)?([^,\n]+)/iu', $text, $m)) $f['full_name'] = trim($m[2]);
        if (preg_match('/username\s*(là\s+)?([a-zA-Z0-9_]{3,50})/i', $text, $m)) $f['username'] = $m[2];
        if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $text, $m)) $f['email'] = $m[0];
        if (preg_match('/giáo viên|giao vien|teacher/u', mb_strtolower($text))) $f['role'] = 'teacher';
        if (preg_match('/lớp\s+([A-Za-z0-9-]+)/iu', $text, $m)) $f['class_code'] = strtoupper($m[1]);
        return $f;
    }

    private static function extractProblemRef(string $text): array
    {
        $f = ['ref' => ''];
        if (preg_match('/bài\s*(số\s*)?#?(\d+)/iu', $text, $m)) $f['ref'] = $m[2];
        elseif (preg_match('/["“](.+?)["”]/u', $text, $m)) $f['ref'] = trim($m[1]);
        return $f;
    }

    private static function extractTestFields(string $text): array
    {
        $f = self::extractProblemRef($text);
        $f += ['stdin' => '', 'expected' => '', 'is_public' => false];
        if (preg_match('/input\s*[:：]\s*([^\n;]+)/iu', $text, $m)) $f['stdin'] = trim($m[1]);
        if (preg_match('/output\s*[:：]\s*([^\n;]+)/iu', $text, $m)) $f['expected'] = trim($m[1]);
        if (preg_match('/công khai|cong khai|public/u', mb_strtolower($text))) $f['is_public'] = true;
        return $f;
    }

    private static function extractSubmissionRef(string $text): array
    {
        $f = ['submission_id' => 0];
        if (preg_match('/(nộp|submit|bài nộp)[^0-9]*#?(\d+)/iu', $text, $m)) $f['submission_id'] = (int)$m[2];
        elseif (preg_match('/#(\d+)/', $text, $m)) $f['submission_id'] = (int)$m[1];
        return $f;
    }

    private static function extractRunRef(string $text): array
    {
        $f = ['run_id' => 0];
        if (preg_match('/run[^0-9]*#?(\d+)/iu', $text, $m)) $f['run_id'] = (int)$m[1];
        elseif (preg_match('/(lần chấm|lần)[^0-9]*#?(\d+)/iu', $text, $m)) $f['run_id'] = (int)$m[2];
        return $f;
    }

    private static function extractClassRef(string $text): array
    {
        $f = ['class_code' => ''];
        if (preg_match('/lớp\s+([A-Za-z0-9-]+)/iu', $text, $m)) $f['class_code'] = strtoupper($m[1]);
        return $f;
    }

    // ---------- Xác nhận 2 bước ----------

    /** Tạo confirm_token lưu session, trả về tóm tắt để bot hỏi lại. */
    private static function needConfirm(string $action, array $params, array $user): array
    {
        if (!isset(self::ACTIONS[$action])) throw new Exception('Thao tác không được phép.');
        // Phân quyền: chỉ admin được tạo tài khoản
        if ($action === 'create_user' && $user['role'] !== 'admin') {
            return ['kind' => 'qa', 'answer' => 'Chỉ quản trị viên mới được tạo tài khoản. Thầy/cô liên hệ admin nhé.'];
        }
        $missing = self::validateParams($action, $params, $user);
        if ($missing !== '') {
            return ['kind' => 'qa', 'answer' => $missing]; // thiếu info → hỏi bổ sung, chưa tạo token
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['ai_pending'][$token] = ['action' => $action, 'params' => $params, 'at' => time()];
        return ['kind' => 'confirm', 'token' => $token, 'action' => $action,
                'summary' => self::summarize($action, $params)];
    }

    /** Kiểm tra tham số đủ chưa; trả '' nếu đủ, ngược lại trả câu hỏi bổ sung. */
    public static function validateParams(string $action, array $params, array $user): string
    {
        switch ($action) {
            case 'create_user':
                if (empty($params['full_name'])) return 'Cho tôi biết **họ tên** của tài khoản mới nhé. (VD: tạo tài khoản Nguyễn Văn A, username avan, email a@x.vn, lớp 10A1)';
                if (empty($params['username'])) return 'Cho tôi biết **username** (chữ/số/gạch dưới, 3–50 ký tự) nhé.';
                if (empty($params['email']) || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) return 'Cho tôi biết **email** hợp lệ nhé.';
                return '';
            case 'add_test_case':
                if (empty($params['ref'])) return 'Thêm test cho **bài nào**? (VD: thêm test cho bài #3, input: 1 2, output: 3)';
                if (($params['stdin'] ?? '') === '' || ($params['expected'] ?? '') === '') return 'Cho tôi biết **input và output** của test nhé. (VD: input: 2 3, output: 5)';
                return '';
            case 'toggle_problem':
                if (empty($params['ref'])) return 'Bật/tắt **bài nào**? Cho tôi số ID hoặc tên bài nhé. (VD: ẩn bài #5)';
                return '';
            case 'rejudge':
                if (empty($params['submission_id'])) return 'Chấm lại **bài nộp nào**? Cho tôi ID bài nộp nhé. (VD: chấm lại bài nộp #12)';
                return '';
            case 'retry_run':
                if (empty($params['run_id'])) return 'Thử lại **lần chấm (run) nào**? Cho tôi ID run nhé.';
                return '';
            case 'export_progress':
                return ''; // lớp để trống = phạm vi của mình
        }
        return '';
    }

    public static function summarize(string $action, array $params): string
    {
        switch ($action) {
            case 'create_user':
                $role = $params['role'] === 'teacher' ? 'giáo viên' : 'học viên';
                $cls = $params['class_code'] !== '' ? $params['class_code'] : '(theo lớp của bạn)';
                return "Tạo tài khoản **{$params['full_name']}** (@{$params['username']}, {$params['email']}, vai trò $role, lớp $cls, mật khẩu mặc định 123456).";
            case 'add_test_case':
                $pub = !empty($params['is_public']) ? 'công khai' : 'kín';
                return "Thêm test case ($pub) cho bài **{$params['ref']}**: input `{$params['stdin']}` → output `{$params['expected']}` (tạo phiên bản đề mới).";
            case 'toggle_problem':
                return "Đổi trạng thái hiển thị (bật ↔ tắt) của bài **{$params['ref']}**.";
            case 'rejudge':
                return "Chấm lại bài nộp **#{$params['submission_id']}** trên bộ test mới nhất (giữ lịch sử cũ).";
            case 'retry_run':
                return "Thử lại lần chấm (run) **#{$params['run_id']}** (chỉ được với run lỗi/kẹt).";
            case 'export_progress':
                $cls = $params['class_code'] !== '' ? " lớp {$params['class_code']}" : '';
                return "Xuất báo cáo tiến độ$cls ra file Excel.";
        }
        return $action;
    }

    /** Thực thi thao tác đã xác nhận. Chỉ chạy khi token hợp lệ. */
    public static function execute(array $user, string $token): array
    {
        $p = $_SESSION['ai_pending'][$token] ?? null;
        if (!$p) throw new Exception('Yêu cầu đã hết hạn — hãy nói lại từ đầu nhé.');
        if (time() - $p['at'] > 600) {
            unset($_SESSION['ai_pending'][$token]);
            throw new Exception('Đã quá 10 phút — hãy nói lại từ đầu để tôi làm lại nhé.');
        }
        unset($_SESSION['ai_pending'][$token]);
        global $pdo;
        $action = $p['action'];
        $params = $p['params'];
        // Thao tác GHI chỉ dành cho giảng viên/admin
        if (!in_array($user['role'], ['teacher', 'admin'], true)) {
            throw new Exception('Chức năng này dành cho giảng viên.');
        }
        $missing = self::validateParams($action, $params, $user);
        if ($missing !== '') throw new Exception($missing);

        switch ($action) {
            case 'create_user': {
                if ($user['role'] !== 'admin') throw new Exception('Chỉ admin được tạo tài khoản.');
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $chk->execute([$params['username'], $params['email']]);
                if ($chk->fetch()) throw new Exception('Username hoặc email đã tồn tại.');
                $cls = $params['class_code'] !== '' ? $params['class_code']
                    : ($user['class_code'] !== 'ALL' ? $user['class_code'] : 'CNTT-18-02');
                $hash = password_hash('123456', PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role, class_code) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$params['username'], $params['email'], $hash, $params['full_name'], $params['role'], $cls]);
                return ['ok' => true, 'answer' => "Đã tạo tài khoản **{$params['full_name']}** (@{$params['username']}) — mật khẩu mặc định 123456."];
            }
            case 'add_test_case': {
                $pr = self::findProblem($params['ref']);
                if (!$pr) throw new Exception("Không tìm thấy bài '{$params['ref']}'.");
                $pdo->beginTransaction();
                try {
                    $verNum = (int)$pdo->query("SELECT COALESCE(MAX(version_number),0)+1 FROM problem_versions WHERE problem_id = {$pr['id']}")->fetchColumn();
                    $pdo->prepare("INSERT INTO problem_versions (problem_id, version_number, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, changelog, created_by) SELECT ?, ?, description, input_desc, output_desc, sample_input, sample_output, time_limit_ms, 'AI chatbot: thêm test case', ? FROM problem_versions WHERE id = ?")
                        ->execute([$pr['id'], $verNum, $user['id'], $pr['current_version_id']]);
                    $verId = (int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) SELECT ?, case_order, stdin_data, expected_output, is_public, is_boundary, points FROM test_cases WHERE problem_version_id = ? ORDER BY case_order")
                        ->execute([$verId, $pr['current_version_id']]);
                    $ord = (int)$pdo->query("SELECT COALESCE(MAX(case_order),0)+1 FROM test_cases WHERE problem_version_id = $verId")->fetchColumn();
                    $pdo->prepare("INSERT INTO test_cases (problem_version_id, case_order, stdin_data, expected_output, is_public, is_boundary, points) VALUES (?, ?, ?, ?, ?, 0, 20)")
                        ->execute([$verId, $ord, $params['stdin'], $params['expected'], !empty($params['is_public']) ? 1 : 0]);
                    $pdo->prepare("UPDATE problems SET current_version_id = ? WHERE id = ?")->execute([$verId, $pr['id']]);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                return ['ok' => true, 'answer' => "Đã thêm test case cho bài **{$pr['title']}** (phiên bản mới v$verNum)."];
            }
            case 'toggle_problem': {
                $pr = self::findProblem($params['ref']);
                if (!$pr) throw new Exception("Không tìm thấy bài '{$params['ref']}'.");
                $pdo->prepare("UPDATE problems SET is_active = 1 - is_active WHERE id = ?")->execute([$pr['id']]);
                $new = $pdo->query("SELECT is_active FROM problems WHERE id = {$pr['id']}")->fetchColumn() ? 'hiển thị' : 'đang ẩn';
                return ['ok' => true, 'answer' => "Bài **{$pr['title']}** giờ $new."];
            }
            case 'rejudge': {
                $sid = (int)$params['submission_id'];
                $sub = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
                $sub->execute([$sid]);
                if (!$sub->fetch()) throw new Exception("Không tìm thấy bài nộp #$sid.");
                require_once __DIR__ . '/JudgeEngine.php';
                $dup = $pdo->prepare("SELECT id FROM judging_runs WHERE submission_id = ? AND status IN ('QUEUED','RUNNING') LIMIT 1");
                $dup->execute([$sid]);
                if ($dup->fetch()) throw new Exception("Bài nộp #$sid đang có lượt chấm chờ/xử lý — đợi xong rồi thử lại.");
                $verId = (int)$pdo->query("SELECT current_version_id FROM problems WHERE id = (SELECT problem_id FROM submissions WHERE id = $sid)")->fetchColumn();
                $runNum = (int)$pdo->query("SELECT COALESCE(MAX(run_number),0)+1 FROM judging_runs WHERE submission_id = $sid")->fetchColumn();
                $pdo->prepare("INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict) VALUES (?, ?, ?, 'REJUDGE', ?, 'QUEUED', 'PENDING')")
                    ->execute([$sid, $verId, $runNum, $user['id']]);
                $runId = (int)$pdo->lastInsertId();
                $res = JudgeEngine::processJudgingRun($pdo, $runId);
                return ['ok' => true, 'answer' => "Đã chấm lại bài nộp #$sid: **{$res['verdict']}** ({$res['score']} điểm)."];
            }
            case 'retry_run': {
                $rid = (int)$params['run_id'];
                $run = $pdo->prepare("SELECT jr.*, s.problem_id FROM judging_runs jr JOIN submissions s ON s.id = jr.submission_id WHERE jr.id = ?");
                $run->execute([$rid]);
                $r = $run->fetch();
                if (!$r) throw new Exception("Không tìm thấy run #$rid.");
                if (!in_array($r['status'], ['SYS_ERROR', 'QUEUED', 'RUNNING'], true)) throw new Exception("Run #$rid đã xong bình thường — chỉ thử lại được run lỗi/kẹt.");
                require_once __DIR__ . '/JudgeEngine.php';
                $runNum = (int)$pdo->query("SELECT COALESCE(MAX(run_number),0)+1 FROM judging_runs WHERE submission_id = {$r['submission_id']}")->fetchColumn();
                $pdo->prepare("INSERT INTO judging_runs (submission_id, problem_version_id, run_number, trigger_type, triggered_by, status, verdict) VALUES (?, ?, ?, 'SYS_RETRY', ?, 'QUEUED', 'PENDING')")
                    ->execute([$r['submission_id'], $r['problem_version_id'], $runNum, $user['id']]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE judging_runs SET status='SYS_ERROR', verdict='SYS_ERR', finished_at=COALESCE(finished_at,NOW()) WHERE id = ? AND status <> 'COMPLETED'")->execute([$rid]);
                $res = JudgeEngine::processJudgingRun($pdo, $newId);
                return ['ok' => true, 'answer' => "Đã thử lại run #$rid → run mới #$newId: **{$res['verdict']}** ({$res['score']}đ)."];
            }
            case 'export_progress': {
                $cls = $params['class_code'] !== '' ? $params['class_code']
                    : ($user['role'] === 'teacher' && $user['class_code'] !== 'ALL' ? $user['class_code'] : '');
                if ($user['role'] === 'teacher' && $user['class_code'] !== 'ALL') $cls = $user['class_code'];
                $q = http_build_query(array_filter(['type' => 'progress', 'class_code' => $cls ?: null]));
                $link = url('/backend/api/export_excel.php?' . $q);
                return ['ok' => true, 'answer' => "Bấm để tải báo cáo tiến độ" . ($cls ? " lớp $cls" : '') . ": [Tải Excel]($link)"];
            }
        }
        throw new Exception('Thao tác không được phép.');
    }

    private static function findProblem(string $ref): ?array
    {
        global $pdo;
        $ref = trim($ref);
        if ($ref === '') return null;
        if (ctype_digit($ref)) {
            $st = $pdo->prepare("SELECT * FROM problems WHERE id = ?");
            $st->execute([(int)$ref]);
            $r = $st->fetch();
            if ($r) return $r;
        }
        $st = $pdo->prepare("SELECT * FROM problems WHERE title LIKE ? OR slug = ? LIMIT 1");
        $st->execute(["%$ref%", $ref]);
        return $st->fetch() ?: null;
    }

    // ---------- Hỏi đáp tự do (Nhóm 1) ----------

    public static function answerQA(array $user, string $text): string
    {
        $isTeacher = in_array($user['role'], ['teacher', 'admin'], true);
        $system = "Bạn là trợ lý của hệ thống chấm bài AlgoJudge (tiếng Việt, xưng hô thầy/cô với giảng viên, bạn với học viên). "
            . "Người hỏi: {$user['full_name']} (vai trò {$user['role']}" . ($isTeacher ? ", lớp {$user['class_code']}" : '') . ").\n"
            . "BẢN ĐỒ TÍNH NĂNG:\n"
            . "- Luyện tập: Danh sách bài tập (/frontend/index.php), Sân chơi tự do (/frontend/playground.php), Bảng xếp hạng (/frontend/leaderboard.php), Lịch sử nộp bài (/frontend/submissions.php), Hồ sơ & XP (/frontend/profile.php).\n"
            . "- Giảng dạy: Danh sách bài tập (/frontend/instructor/problems.php), Thêm bài (/frontend/instructor/problem_form.php), Bộ test & phiên bản (/frontend/instructor/dataset_versions.php), Chấm lại (/frontend/instructor/rejudge.php), Hàng đợi chấm (/frontend/instructor/queue.php), Tiến độ học viên (/frontend/instructor/progress.php).\n"
            . "- Quản trị: Danh sách tài khoản (/frontend/admin/users.php), Tạo tài khoản (/frontend/admin/user_form.php).\n"
            . "QUY TẮC:\n"
            . "- Chỉ trả lời trong phạm vi các tính năng trên; việc ngoài phạm vi thì nói không làm được.\n"
            . "- TUYỆT ĐỐI không hướng dẫn: xóa dữ liệu, sửa điểm/XP, đổi vai trò, xem mật khẩu, giải hộ bài tập (với học viên chỉ gợi ý hướng, không đưa code).\n"
            . "- Trả lời ngắn gọn (dưới 150 từ), kèm link trang liên quan khi có. Dùng Markdown đơn giản.";
        if (!AIHelper::hasKey()) {
            return self::localQA($user, $text);
        }
        try {
            return AIHelper::chat($system, $text);
        } catch (Exception $e) {
            return self::localQA($user, $text);
        }
    }

    /** Trả lời cục bộ khi không có AI: gợi ý trang phù hợp theo từ khóa. */
    private static function localQA(array $user, string $text): string
    {
        $t = mb_strtolower($text);
        $links = [
            'tiến độ' => 'Tiến độ học viên: /frontend/instructor/progress.php',
            'chấm lại' => 'Chấm lại: /frontend/instructor/rejudge.php',
            'hàng đợi' => 'Hàng đợi chấm: /frontend/instructor/queue.php',
            'tài khoản' => 'Tạo tài khoản: /frontend/admin/user_form.php (chỉ admin)',
            'thêm bài' => 'Thêm bài tập: /frontend/instructor/problem_form.php',
            'bảng xếp hạng' => 'Bảng xếp hạng: /frontend/leaderboard.php',
            'lịch sử' => 'Lịch sử nộp bài: /frontend/submissions.php',
            'sân chơi' => 'Sân chơi tự do: /frontend/playground.php',
        ];
        foreach ($links as $k => $v) {
            if (str_contains($t, $k)) return "Bạn có thể dùng: $v";
        }
        return 'Tôi chưa hiểu ý. Bạn có thể hỏi: "lớp tôi có bao nhiêu học viên?", "hàng đợi chấm còn bao nhiêu?", "chấm lại bài nộp #12", "tạo tài khoản ...", hoặc dán đề bài để tôi tạo bài tập.';
    }
}
