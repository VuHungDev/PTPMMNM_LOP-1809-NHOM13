<?php
/** Deterministic read-only query allowlist. The caller supplies the freshly authenticated Auth user. */
final class ChatbotQueries
{
    public const QUERIES = ['problems', 'recent_submissions', 'personal_progress', 'classes', 'class_students', 'class_progress', 'queue', 'admin_summary'];
    private const LIMIT = 20;
    private const VERDICTS = ['QUEUED', 'RUNNING', 'AC', 'WA', 'TLE', 'RE', 'CE', 'SYS_ERR'];

    /** Accent-insensitive intent matching only; the original text is retained for names and literals. */
    public static function normalize(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i','ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u','ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
        ]);
    }

    /** No SQL is selected by the model; these are the only supported query intentions. */
    public static function intent(array $user, string $text): ?array
    {
        $t = self::normalize($text);
        $f = self::extractFilters($user, $text, $t);
        $staff = in_array($user['role'] ?? '', ['teacher', 'admin'], true);
        $hasClass = preg_match('/\b(?:lop|classes?|class_id)\b/', $t);
        $personal = preg_match('/\b(?:cua toi|cua minh|ca nhan|toi da|toi co|my|mine|own)\b/', $t);
        $target = !empty($f['target_requested']);
        $ownTarget = (isset($f['user_id']) && (int)$f['user_id'] === (int)($user['id'] ?? 0))
            || (isset($f['username'], $user['username']) && mb_strtolower($f['username']) === mb_strtolower($user['username']));
        $isCount = (bool)preg_match('/\b(?:bao nhieu|may|so luong|tong so|count|how many)\b/', $t);
        $f['count'] = $isCount;
        $f['list'] = (bool)preg_match('/\b(?:danh sach|liet ke|list|tung|moi hoc vien)\b/', $t);
        $f['single_class'] = (bool)($hasClass && !preg_match('/\b(?:cac lop|moi lop|tat ca lop|all classes)\b/', $t));

        if (preg_match('/\b(?:hang doi|queue|cho cham|dang cham)\b/', $t)) {
            $f['self'] = !$staff || (bool)$personal || $ownTarget;
            return ['query' => 'queue', 'filters' => $f];
        }
        if (!$hasClass && !$target && preg_match('/\b(?:he thong|toan he thong|system|tong quan quan tri)\b/', $t)
            && preg_match('/\b(?:thong ke|tong quan|bao nhieu|so luong|tong|summary|count)\b/', $t)) {
            return ['query' => 'admin_summary', 'filters' => $f];
        }
        if ($hasClass && preg_match('/\b(?:bai nop|lan nop|lich su nop|submissions?)\b/', $t)) {
            $f['self'] = (bool)$personal && !$target;
            return ['query' => 'recent_submissions', 'filters' => $f];
        }
        if ($hasClass && preg_match('/\b(?:tien do|ty le|ti le|luot ac|lan ac|luot nop|ket qua|\bac\b)\b/', $t)) {
            $f['self'] = false;
            return ['query' => 'class_progress', 'filters' => $f];
        }
        if (preg_match('/\b(?:hoc vien|hoc sinh|students?)\b/', $t) && (!$personal || $hasClass) && !$target) {
            $f['self'] = false;
            return ['query' => 'class_students', 'filters' => $f];
        }
        if ($hasClass) return ['query' => 'classes', 'filters' => $f];
        if (preg_match('/\b(?:tien do|da giai|da hoan thanh|ty le ac|ti le ac|progress|solved|xp)\b/', $t)) {
            $f['self'] = !$target || $ownTarget;
            return ['query' => ($staff && $target && !$ownTarget ? 'class_progress' : 'personal_progress'), 'filters' => $f];
        }
        if (preg_match('/\b(?:bai nop|lan nop|luot nop|lich su nop|submissions?|nop gan nhat|ket qua.*(?:toi|minh)|toi.*ket qua)\b/', $t)
            || ($target && preg_match('/\b(?:ket qua|diem|results?|scores?)\b/', $t))) {
            $f['self'] = !$target || $ownTarget;
            return ['query' => 'recent_submissions', 'filters' => $f];
        }
        if (preg_match('/\b(?:bai tap|bai nao|bai ve|bai kho|bai de|tim bai|ten bai|danh sach bai|co bai|bao nhieu bai|problems?|exercises?|chu de|do kho)\b/', $t)) {
            return ['query' => 'problems', 'filters' => $f];
        }
        return null;
    }

    /** Role/target checks are shared by the pure router and the database entry point. */
    public static function denial(array $user, string $query, array $filters): ?array
    {
        $role = $user['role'] ?? '';
        if (!in_array($role, ['student', 'teacher', 'admin'], true)) return self::result('Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.');
        if (!in_array($query, self::QUERIES, true)) return self::result('Loại tra cứu không được hỗ trợ.');
        if ($query === 'admin_summary' && $role !== 'admin') return self::result('Số liệu tổng hợp toàn hệ thống chỉ dành cho quản trị viên. Tôi chỉ tra cứu dữ liệu trong quyền của bạn.');
        if ($role === 'student') {
            if (in_array($query, ['class_students', 'class_progress'], true)) return self::result('Học viên chỉ được tra cứu bài nộp và tiến độ của chính mình, không được tra cứu danh sách, bài nộp hay tiến độ của học viên khác. Bạn có thể xem các lớp mình tham gia tại Lớp học của tôi.', [['Lớp học của tôi', '/frontend/instructor/classes.php']]);
            if (($filters['visibility'] ?? 'active') !== 'active') return self::result('Học viên chỉ được tra cứu bài tập đang hiển thị, không được tra cứu bài ẩn.');
            if (isset($filters['user_id']) && (int)$filters['user_id'] !== (int)($user['id'] ?? 0)) return self::result('Bạn không được tra cứu bài nộp hoặc dữ liệu riêng của người dùng khác.');
            if (isset($filters['username']) && mb_strtolower((string)$filters['username']) !== mb_strtolower((string)($user['username'] ?? ''))) return self::result('Bạn không được tra cứu bài nộp hoặc dữ liệu riêng của người dùng khác.');
            if (!empty($filters['target_requested']) && empty($filters['user_id']) && empty($filters['username'])) return self::result('Bạn không được tra cứu bài nộp hoặc dữ liệu riêng của người dùng khác. Hãy hỏi về dữ liệu của chính mình.');
        }
        return null;
    }

    /**
     * @return ?array{answer:string,links:array,choices?:array}
     * null means no known query. Empty data, denied scope and database failure have different answers.
     * Filters are untrusted refinements; no caller-supplied SQL, ownership or role fields are used.
     */
    public static function answer(PDO $db, array $user, string $text, array $filters = []): ?array
    {
        $intent = self::intent($user, $text);
        $query = $filters['query'] ?? ($intent['query'] ?? null);
        if (!is_string($query) || !in_array($query, self::QUERIES, true)) return $query === null ? null : self::result('Loại tra cứu không được hỗ trợ.');
        // Explicit references in the text cannot be erased by empty client filters.
        $f = array_replace($filters, $intent['filters'] ?? []);
        $denial = self::denial($user, $query, $f);
        if ($denial !== null) return $denial;
        if ((int)($user['id'] ?? 0) < 1) return self::result('Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.');
        try {
            self::validateFilters($f);
            $dates = self::dateBounds($db, $f);
            $resolved = self::resolveClass($db, $user, $f, $text, in_array($query, ['class_students', 'class_progress', 'queue', 'recent_submissions'], true) && !empty($f['single_class']));
            if (isset($resolved['answer'])) return $resolved;
            if (isset($resolved['id'])) {
                $f['class_id'] = (int)$resolved['id'];
                $f['class_label'] = 'lớp ' . self::plain($resolved['name']) . ' (#' . (int)$resolved['id'] . ')';
            }
            if (!empty($f['target_requested']) && empty($f['username']) && empty($f['user_id'])) return self::result('Hãy chỉ rõ username hoặc user_id của học viên cần tra cứu. Tôi không suy đoán người dùng từ họ tên.');
            if (!empty($f['target_requested']) || isset($f['username']) || isset($f['user_id'])) {
                $target = self::resolveTarget($db, $user, $f);
                if (isset($target['answer'])) return $target;
                $f['user_id'] = (int)$target['id'];
                $f['target_label'] = self::plain($target['username']);
            }
            switch ($query) {
                case 'problems': return self::problems($db, $user, $f);
                case 'recent_submissions': return self::recentSubmissions($db, $user, $f, $dates);
                case 'personal_progress': return self::progress($db, $user, $f, $dates, true);
                case 'classes': return self::classes($db, $user, $f);
                case 'class_students': return self::students($db, $user, $f);
                case 'class_progress': return self::progress($db, $user, $f, $dates, false);
                case 'queue': return self::queue($db, $user, $f, $dates);
                case 'admin_summary': return self::adminSummary($db, $dates);
            }
        } catch (DomainException $e) {
            return self::result($e->getMessage());
        } catch (PDOException $e) {
            // Do not expose SQL, connection details or driver error text.
            return self::result('Không thể kết nối hoặc đọc dữ liệu tra cứu lúc này. Chưa có kết quả; hãy thử lại sau.');
        }
        return null;
    }

    private static function extractFilters(array $user, string $text, string $t): array
    {
        $f = [];
        if (preg_match('/\b(?:class_id|(?:lop|class)\s*(?:id|#))\s*[:=#]?\s*#?\s*([1-9]\d*)\b/', $t, $m)) $f['class_id'] = (int)$m[1];
        elseif (preg_match('/\b(?:lớp|lop|class)(?:\s+(?:tên|ten|named))?\s*[:：]?\s+(?:"([^"]+)"|“([^”]+)”|\'([^\']+)\')/iu', $text, $m)) $f['class_name'] = trim($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]));
        elseif (preg_match('/\b(?:lớp|lop|class)\s*[:：]?\s+([^\r\n?!,;:]+)/iu', $text, $m)) {
            $name = preg_split('/\s+(?:hôm nay|hom nay|hôm qua|hom qua|ngày|ngay|today|yesterday|\d{4}-\d{2}-\d{2}|có|co|đã|da|đang có|dang co|đang chờ|dang cho|đang chấm|dang cham|bao nhiêu|bao nhieu|gồm|gom|với|voi|thì|thi|đạt|dat|được|duoc|tiến độ|tien do|kết quả|ket qua|tỷ lệ|tỉ lệ|ty le|ti le|học viên|hoc vien|học sinh|hoc sinh|students?|progress|has|have|AC)\b/iu', trim($m[1]), 2)[0];
            $n = self::normalize($name);
            if ($name !== '' && !in_array($n, ['toi','minh','cua toi','cua minh','hoc','dang hoc','da tham gia','duoc phep','ban quan ly','quan ly','nao','nay','all','my','count','listing'], true)) $f['class_name'] = trim($name, " \t\n\r\0\x0B.：");
        }
        if (preg_match('/\b(?:user_id|student_id|(?:nguoi dung|hoc vien|user|student)\s*(?:id|#))\s*[:=#]?\s*([1-9]\d*)\b/', $t, $m)) {
            $f['user_id'] = (int)$m[1]; $f['target_requested'] = true;
        }
        if (preg_match('/(?:@|\busername\s*[:=]?\s*["\']?)([a-zA-Z0-9_]{1,50})\b/', $text, $m)) {
            $f['username'] = $m[1]; $f['target_requested'] = true;
        } elseif (preg_match('/\b(?:của|cua|for|of)\s+(?:bạn\s+|ban\s+)?["“\']?([^\r\n?!,;"”\']+)/iu', $text, $m)
            && preg_match('/\b(?:bai nop|lan nop|ket qua|tien do|submission|progress)\b/', $t)) {
            $target = preg_split('/\s+(?:hôm nay|hom nay|hôm qua|hom qua|ngày|ngay|gần nhất|gan nhat|mới nhất|moi nhat|thế nào|the nao|ra sao|today|yesterday|latest)\b/iu', trim($m[1]), 2)[0];
            $normalized = self::normalize($target);
            if (!preg_match('/^(?:toi|minh|chinh toi|lop\b|class\b|cac lop\b|he thong\b|myself|me|mine|my)\b/', $normalized)) {
                $f['target_requested'] = true;
                if (preg_match('/^[a-zA-Z0-9_]{1,50}$/', $target)) $f['username'] = $target;
            }
        } elseif (preg_match('/\b(?:học viên|hoc vien|học sinh|hoc sinh|người dùng|nguoi dung|user|student)\s+["\']?([\pL\pN_]{1,50})\b/iu', $text, $m)
            && preg_match('/\b(?:bai nop|lan nop|ket qua|tien do|diem|submission|progress|results?|scores?)\b/', $t)
            && !in_array(self::normalize($m[1]), ['co', 'trong', 'cua', 'da', 'bao', 'nao', 'active', 'dang', 'lop', 'thuoc', 'id'], true)) {
            $f['target_requested'] = true;
            if (preg_match('/^[a-zA-Z0-9_]{1,50}$/', $m[1])) $f['username'] = $m[1];
        }
        if (preg_match('/\b(?:bai nop|submission)\s*(?:id\s*)?[:=#]?\s*#?\s*([1-9]\d*)\b/', $t, $m)) $f['submission_id'] = (int)$m[1];
        if (preg_match('/\b(?:problem_id|(?:bai(?: tap)?|problem|exercise)\s*(?:id|#))\s*[:=#]?\s*([1-9]\d*)\b/', $t, $m)) $f['problem_id'] = (int)$m[1];
        if (preg_match('/\b(?:hom nay|today)\b/', $t)) $f['period'] = 'today';
        elseif (preg_match('/\b(?:hom qua|yesterday)\b/', $t)) $f['period'] = 'yesterday';
        elseif (preg_match('/\b(?:7 ngay|last 7 days)\b/', $t)) $f['period'] = 'last_7_days';
        elseif (preg_match('/\b(?:tuan nay|this week)\b/', $t)) $f['period'] = 'this_week';
        elseif (preg_match('/\b(?:ngay mai|tuan truoc|thang nay|thang truoc|nam nay|tomorrow|last week|this month|last month)\b/', $t)) $f['unsupported_date'] = true;
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\s*(?:den|toi|to|\.\.)\s*(\d{4}-\d{2}-\d{2})\b/', $t, $m)) { $f['date_from'] = $m[1]; $f['date_to'] = $m[2]; }
        elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $t, $m)) $f['date'] = $m[1];
        elseif (preg_match('/\bngay\s+\d{1,2}[\/.-]\d{1,2}/', $t)) $f['invalid_date'] = true;
        if (preg_match('/\b(queued|running|ac|wa|tle|re|ce|sys_err)\b/', $t, $m)) $f['verdict'] = strtoupper($m[1]);
        $topics = ['co ban'=>'Cơ bản','re nhanh'=>'Rẽ nhánh','vong lap'=>'Vòng lặp','so hoc'=>'Số học','quy hoach dong'=>'Quy hoạch động','mang'=>'Mảng','array'=>'Mảng','arrays'=>'Mảng','xu ly chuoi'=>'Xử lý chuỗi','chuoi'=>'Xử lý chuỗi','strings'=>'Xử lý chuỗi','string'=>'Xử lý chuỗi','tim kiem'=>'Tìm kiếm'];
        foreach ($topics as $key => $topic) if (preg_match('/\b' . preg_quote($key, '/') . '\b/', $t)) { $f['topic'] = $topic; break; }
        if (preg_match('/\b(?:easy|de)\b/', $t)) $f['difficulty'] = 'Easy';
        elseif (preg_match('/\b(?:medium|trung binh)\b/', $t)) $f['difficulty'] = 'Medium';
        elseif (preg_match('/\b(?:hard|kho)\b/', $t)) $f['difficulty'] = 'Hard';
        if (preg_match('/\b(?:bài|bai|problem|exercise)(?:\s+(?:tập|tap))?(?:\s+(?:tên|ten|named))?\s+["“\']([^"”\']+)["”\']/iu', $text, $m)) $f['title'] = trim($m[1]);
        elseif (preg_match('/\b(?:tên|ten|named)\s*[:：]?\s+([^\r\n?!,;]+)/iu', $text, $m) && !isset($f['class_name'])) $f['title'] = trim($m[1]);
        elseif (preg_match('/\b(?:tìm bài|tim bai|find problem)\s+([^\r\n?!,;]+)/iu', $text, $m) && !isset($f['problem_id']) && !isset($f['topic'])) $f['search'] = trim($m[1]);
        $f['visibility'] = preg_match('/\b(?:bai an|bai da an|dang an|hidden|inactive)\b/', $t) ? 'hidden' : (preg_match('/\b(?:tat ca bai|all problems)\b/', $t) ? 'all' : 'active');
        return $f;
    }

    private static function validateFilters(array $f): void
    {
        foreach (['class_id', 'user_id', 'submission_id', 'problem_id'] as $key) if (array_key_exists($key, $f)
            && (!is_scalar($f[$key]) || filter_var($f[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>2147483647]]) === false)) throw new DomainException('Bộ lọc ID không hợp lệ.');
        foreach (['username', 'class_name', 'title', 'search', 'topic'] as $key) if (isset($f[$key]) && (!is_string($f[$key]) || trim($f[$key]) === '' || mb_strlen($f[$key]) > 200)) throw new DomainException('Bộ lọc tên không hợp lệ hoặc quá dài.');
        if (isset($f['username']) && !preg_match('/^[a-zA-Z0-9_]{1,50}$/', $f['username'])) throw new DomainException('Hãy dùng username chính xác (chữ, số hoặc gạch dưới).');
        if (isset($f['difficulty']) && !in_array($f['difficulty'], ['Easy', 'Medium', 'Hard'], true)) throw new DomainException('Độ khó hợp lệ là Easy, Medium hoặc Hard.');
        if (isset($f['verdict']) && !in_array($f['verdict'], self::VERDICTS, true)) throw new DomainException('Bộ lọc kết quả chấm không hợp lệ.');
        if (!in_array($f['visibility'] ?? 'active', ['active', 'hidden', 'all'], true)) throw new DomainException('Bộ lọc trạng thái bài không hợp lệ.');
        if (!empty($f['invalid_date'])) throw new DomainException('Hãy nhập ngày theo định dạng YYYY-MM-DD để tránh nhầm ngày/tháng.');
        if (!empty($f['unsupported_date'])) throw new DomainException('Khoảng ngày này chưa được hỗ trợ. Hãy dùng hôm nay, hôm qua, tuần này, 7 ngày gần nhất hoặc YYYY-MM-DD đến YYYY-MM-DD.');
    }

    private static function classScope(array $user): array
    {
        if ($user['role'] === 'teacher') return ['c.owner_teacher_id = ?', [(int)$user['id']]];
        if ($user['role'] === 'student') return ["EXISTS (SELECT 1 FROM class_members own_cm WHERE own_cm.class_id = c.id AND own_cm.user_id = ? AND own_cm.status = 'active')", [(int)$user['id']]];
        return ['1=1', []];
    }

    /** Resolve only within authorized classes. Explicit unknown names never become an unfiltered query. */
    private static function resolveClass(PDO $db, array $user, array $f, string $text, bool $chooseOne): array
    {
        $explicit = isset($f['class_id']) || isset($f['class_name']);
        if (!$explicit && !$chooseOne) return [];
        if (isset($f['class_name']) && preg_match('/^(?:toi|minh|cua (?:toi|minh)|ban|cua ban|my|our)\b/', self::normalize($f['class_name']))) return self::result('Hãy chỉ rõ tên lớp hoặc ID lớp (ví dụ: Lớp #12). Chưa tra cứu số liệu vì mô tả lớp chưa xác định.');
        [$scope, $params] = self::classScope($user);
        if (isset($f['class_id'])) { $scope .= ' AND c.id = ?'; $params[] = (int)$f['class_id']; }
        if (isset($f['class_name'])) { $scope .= ' AND c.name = ?'; $params[] = $f['class_name']; }
        $rows = self::rows($db, "SELECT c.id, c.name FROM classes c WHERE $scope ORDER BY c.name, c.id LIMIT 21", $params);
        if (!$rows) return self::result($explicit ? 'Lớp được chỉ định không tồn tại hoặc bạn không có quyền xem. Chưa tra cứu số liệu; hãy chọn một lớp được phép.' : 'Bạn chưa có lớp nào trong phạm vi được phép xem.', [['Lớp học', '/frontend/instructor/classes.php']]);
        if (count($rows) === 1) return $rows[0];
        $choices = [];
        foreach (array_slice($rows, 0, self::LIMIT) as $row) $choices[] = ['label'=>self::plain($row['name']) . ' (#' . (int)$row['id'] . ')', 'text'=>'Lớp #' . (int)$row['id'] . ': ' . $text, 'class_id'=>(int)$row['id']];
        return self::result(($explicit ? 'Tên lớp không duy nhất.' : 'Bạn có nhiều lớp trong phạm vi được phép.') . ' Hãy chọn đúng lớp theo ID trước khi tra cứu (hiển thị tối đa 20 lựa chọn).', [['Lớp học', '/frontend/instructor/classes.php']], $choices);
    }

    /** EXISTS avoids duplicate people and submissions when a learner belongs to multiple classes. */
    private static function userScope(array $user, array $f, bool $studentsOnly = false): array
    {
        $params = [];
        if ($user['role'] === 'student' || !empty($f['self'])) { $scope = 'u.id = ?'; $params[] = (int)$user['id']; }
        elseif ($user['role'] === 'teacher') {
            $scope = "u.role = 'student' AND EXISTS (SELECT 1 FROM class_members scope_cm JOIN classes scope_c ON scope_c.id = scope_cm.class_id WHERE scope_cm.user_id = u.id AND scope_cm.status = 'active' AND scope_c.owner_teacher_id = ?)";
            $params[] = (int)$user['id'];
        } else $scope = $studentsOnly ? "u.role = 'student'" : '1=1';
        if ($studentsOnly) $scope .= " AND u.role = 'student'";
        if (isset($f['class_id'])) {
            $scope .= " AND EXISTS (SELECT 1 FROM class_members selected_cm WHERE selected_cm.user_id = u.id AND selected_cm.class_id = ? AND selected_cm.status = 'active')";
            $params[] = (int)$f['class_id'];
        }
        if (isset($f['user_id'])) { $scope .= ' AND u.id = ?'; $params[] = (int)$f['user_id']; }
        if (isset($f['username'])) { $scope .= ' AND u.username = ?'; $params[] = $f['username']; }
        return [$scope, $params];
    }

    private static function resolveTarget(PDO $db, array $user, array $f): array
    {
        // An explicit target is never silently replaced by the viewer's own submissions.
        $ownTarget = (isset($f['user_id']) && (int)$f['user_id'] === (int)$user['id'])
            || (isset($f['username'], $user['username']) && mb_strtolower($f['username']) === mb_strtolower($user['username']));
        if ($user['role'] !== 'student') $f['self'] = $ownTarget;
        [$scope, $params] = self::userScope($user, $f);
        $rows = self::rows($db, "SELECT u.id, u.username FROM users u WHERE $scope LIMIT 1", $params);
        return $rows[0] ?? self::result('Người dùng được chỉ định không tồn tại hoặc bạn không có quyền xem dữ liệu của họ. Chưa tra cứu bài nộp.');
    }

    /** Date words follow the database session calendar, the same clock used by TIMESTAMP columns. */
    private static function dateBounds(PDO $db, array $f): array
    {
        if (!isset($f['date']) && !isset($f['date_from']) && !isset($f['date_to']) && !isset($f['period'])) return ['from'=>null, 'to'=>null, 'label'=>'toàn thời gian'];
        foreach (['date', 'date_from', 'date_to'] as $key) if (isset($f[$key]) && (!is_string($f[$key]) || !self::validDate($f[$key]))) throw new DomainException('Ngày không hợp lệ. Hãy dùng ngày có thật theo định dạng YYYY-MM-DD.');
        if (isset($f['period']) && !in_array($f['period'], ['today', 'yesterday', 'last_7_days', 'this_week'], true)) throw new DomainException('Khoảng ngày không được hỗ trợ.');
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $clock = self::rows($db, $mysql ? 'SELECT CURRENT_TIMESTAMP AS now_at, @@session.time_zone AS session_timezone, @@system_time_zone AS system_timezone' : 'SELECT CURRENT_TIMESTAMP AS now_at', [])[0];
        $timezone = $mysql ? (($clock['session_timezone'] ?? 'SYSTEM') === 'SYSTEM' ? ($clock['system_timezone'] ?? 'SYSTEM') : $clock['session_timezone']) : 'UTC';
        // Calendar arithmetic only; comparison strings are interpreted in the DB session timezone.
        $today = new DateTimeImmutable(substr($clock['now_at'], 0, 10), new DateTimeZone('UTC'));
        if (isset($f['date'])) $from = $through = new DateTimeImmutable($f['date'], new DateTimeZone('UTC'));
        elseif (isset($f['date_from']) || isset($f['date_to'])) {
            if (!isset($f['date_from'], $f['date_to'])) throw new DomainException('Hãy cung cấp đủ ngày bắt đầu và kết thúc (YYYY-MM-DD).');
            $from = new DateTimeImmutable($f['date_from'], new DateTimeZone('UTC'));
            $through = new DateTimeImmutable($f['date_to'], new DateTimeZone('UTC'));
        } elseif ($f['period'] === 'yesterday') $from = $through = $today->modify('-1 day');
        elseif ($f['period'] === 'last_7_days') { $from = $today->modify('-6 days'); $through = $today; }
        elseif ($f['period'] === 'this_week') { $from = $today->modify('-' . ((int)$today->format('N') - 1) . ' days'); $through = $today; }
        else $from = $through = $today;
        if ($through < $from) throw new DomainException('Ngày kết thúc không được trước ngày bắt đầu.');
        return ['from'=>$from->format('Y-m-d 00:00:00'), 'to'=>$through->modify('+1 day')->format('Y-m-d 00:00:00'), 'label'=>$from->format('Y-m-d') . ($from != $through ? ' đến ' . $through->format('Y-m-d') : '') . ' (múi giờ database: ' . self::plain($timezone) . ')'];
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return false;
        return (int)$m[1] >= 1970 && (int)$m[1] <= 9998 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    private static function dateSql(array $dates, string $column): array
    {
        // Column names are literals from the allowlisted methods, never from filters/text.
        return $dates['from'] === null ? ['', []] : [" AND $column >= ? AND $column < ?", [$dates['from'], $dates['to']]];
    }

    private static function problems(PDO $db, array $user, array $f): array
    {
        $scope = ($f['visibility'] ?? 'active') === 'all' ? '1=1' : 'p.is_active = ?';
        $params = ($f['visibility'] ?? 'active') === 'all' ? [] : [(($f['visibility'] ?? 'active') === 'hidden') ? 0 : 1];
        foreach (['problem_id'=>'id','topic'=>'topic','difficulty'=>'difficulty','title'=>'title'] as $filter=>$column) if (isset($f[$filter])) { $scope .= " AND p.$column = ?"; $params[] = $f[$filter]; }
        if (isset($f['search'])) { $scope .= " AND p.title LIKE ? ESCAPE '!'"; $params[] = '%' . self::like($f['search']) . '%'; }
        $from = "FROM problems p JOIN problem_versions pv ON pv.id = p.current_version_id AND pv.problem_id = p.id WHERE $scope";
        $total = (int)self::scalar($db, 'SELECT COUNT(*) ' . $from, $params);
        $listQuery = http_build_query(array_intersect_key($f, array_flip(['topic', 'difficulty'])));
        $listLink = ($f['visibility'] ?? 'active') !== 'active' && $user['role'] !== 'student' ? '/frontend/instructor/problems.php' : '/frontend/index.php' . ($listQuery ? '?' . $listQuery : '');
        if (!$total) return self::result('Không có bài tập nào phù hợp bộ lọc trong phạm vi được phép xem.', [['Danh sách bài tập', $listLink]]);
        if (!empty($f['count'])) return self::result('Có ' . $total . ' bài tập phù hợp bộ lọc trong phạm vi được phép xem.', [['Danh sách bài tập', $listLink]]);
        $rows = self::rows($db, 'SELECT p.id, p.title, p.topic, p.difficulty, p.is_active ' . $from . ' ORDER BY p.id ASC LIMIT 20', $params);
        $links = [['Danh sách bài tập', $listLink]];
        $lines = ['Tìm thấy ' . $total . ' bài tập; hiển thị tối đa 20 bài:'];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $lines[] = '#' . $id . ' ' . self::plain($row['title']) . ' — ' . self::plain($row['topic']) . ', ' . $row['difficulty'] . ((int)$row['is_active'] === 0 ? ' (đang ẩn)' : '');
            $links[] = [self::plain($row['title']), '/frontend/workspace.php?id=' . $id];
        }
        if (isset($f['title']) && $total > 1) {
            $choices = [];
            foreach ($rows as $row) $choices[] = ['label'=>self::plain($row['title']) . ' (#' . (int)$row['id'] . ')', 'text'=>'Tìm bài #' . (int)$row['id']];
            return self::result("Tên bài không duy nhất. Hãy chọn ID phù hợp.\n" . implode("\n", $lines), $links, $choices);
        }
        return self::result(implode("\n", $lines), $links);
    }

    private static function recentSubmissions(PDO $db, array $user, array $f, array $dates): array
    {
        [$scope, $params] = self::userScope($user, $f);
        [$timeSql, $timeParams] = self::dateSql($dates, 's.created_at');
        $scope .= $timeSql; $params = array_merge($params, $timeParams);
        foreach (['submission_id'=>'id', 'problem_id'=>'problem_id', 'verdict'=>'final_verdict'] as $key=>$column) if (isset($f[$key])) { $scope .= " AND s.$column = ?"; $params[] = $f[$key]; }
        $from = "FROM submissions s JOIN users u ON u.id = s.user_id JOIN problems p ON p.id = s.problem_id WHERE $scope";
        $rows = self::rows($db, 'SELECT s.id, s.problem_id, s.language, s.final_verdict, s.final_score, s.pass_count, s.total_cases, s.created_at, p.title, u.username ' . $from . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 20', $params);
        $links = [['Lịch sử nộp bài', self::submissionLink($user, $f)]];
        if (!$rows) return self::result(isset($f['submission_id']) ? 'Bài nộp được chỉ định không tồn tại hoặc bạn không có quyền xem; chưa trả dữ liệu bài nộp.' : 'Chưa có bài nộp phù hợp trong phạm vi được phép và khoảng ' . $dates['label'] . '.', $links);
        if (!empty($f['count'])) return self::result('Có ' . (int)self::scalar($db, 'SELECT COUNT(*) ' . $from, $params) . ' lượt nộp phù hợp trong phạm vi được phép, ' . $dates['label'] . '.', $links);
        $lines = ['Bài nộp gần nhất ' . self::scopeLabel($user, $f) . ', ' . $dates['label'] . ' (tối đa 20; mới nhất trước):'];
        foreach ($rows as $row) {
            $lines[] = '#' . (int)$row['id'] . ' — ' . self::plain($row['title']) . ': ' . $row['final_verdict'] . ', ' . (int)$row['final_score'] . ' điểm, ' . (int)$row['pass_count'] . '/' . (int)$row['total_cases'] . ' ca đúng; ' . $row['language'] . '; ' . self::plain($row['created_at']);
            if (count($links) < 6) $links[] = ['Mở bài #' . (int)$row['problem_id'], '/frontend/workspace.php?id=' . (int)$row['problem_id']];
        }
        return self::result(implode("\n", $lines), $links);
    }

    private static function classes(PDO $db, array $user, array $f): array
    {
        [$scope, $params] = self::classScope($user);
        if (isset($f['class_id'])) { $scope .= ' AND c.id = ?'; $params[] = (int)$f['class_id']; }
        $total = (int)self::scalar($db, "SELECT COUNT(*) FROM classes c WHERE $scope", $params);
        $links = [['Lớp học', '/frontend/instructor/classes.php']];
        if (!$total) return self::result('Không có lớp nào trong phạm vi được phép xem.', $links);
        if (!empty($f['count'])) return self::result('Có ' . $total . ' lớp trong phạm vi được phép xem.', $links);
        $rows = self::rows($db, "SELECT c.id, c.name FROM classes c WHERE $scope ORDER BY c.name, c.id LIMIT 20", $params);
        $lines = ['Có ' . $total . ' lớp trong phạm vi được phép; hiển thị tối đa 20 lớp:'];
        foreach ($rows as $row) { $lines[] = '#' . (int)$row['id'] . ' — ' . self::plain($row['name']); $links[] = [self::plain($row['name']), '/frontend/instructor/classes.php?class_id=' . (int)$row['id']]; }
        return self::result(implode("\n", $lines), $links);
    }

    private static function students(PDO $db, array $user, array $f): array
    {
        $f['self'] = false;
        [$scope, $params] = self::userScope($user, $f, true);
        $total = (int)self::scalar($db, "SELECT COUNT(*) FROM users u WHERE $scope", $params);
        $lines = ['Có ' . $total . ' học viên ' . self::scopeLabel($user, $f) . '. Chỉ tính membership active; một người chỉ tính một lần.'];
        if (empty($f['count']) || !empty($f['list'])) {
            $rows = self::rows($db, "SELECT u.id, u.username FROM users u WHERE $scope ORDER BY u.username, u.id LIMIT 20", $params);
            $lines[] = 'Hiển thị tối đa 20 username:';
            foreach ($rows as $row) $lines[] = '#' . (int)$row['id'] . ' — ' . self::plain($row['username']);
        }
        return self::result(implode("\n", $lines), [['Tiến độ học viên', self::progressLink($f)], ['Lớp học', '/frontend/instructor/classes.php' . (isset($f['class_id']) ? '?class_id=' . (int)$f['class_id'] : '')]]);
    }

    private static function progress(PDO $db, array $user, array $f, array $dates, bool $personal): array
    {
        if ($personal) $f['self'] = true;
        [$scope, $params] = self::userScope($user, $f, !$personal);
        [$timeSql, $timeParams] = self::dateSql($dates, 's.created_at');
        $where = $scope . $timeSql;
        $subParams = array_merge($params, $timeParams);
        if (isset($f['problem_id'])) { $where .= ' AND s.problem_id = ?'; $subParams[] = (int)$f['problem_id']; }
        $row = self::rows($db, "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN s.final_verdict = 'AC' THEN 1 ELSE 0 END), 0) AS ac, COUNT(DISTINCT CASE WHEN s.final_verdict = 'AC' AND p.is_active = 1 THEN s.problem_id END) AS solved FROM submissions s JOIN users u ON u.id = s.user_id JOIN problems p ON p.id = s.problem_id WHERE $where", $subParams)[0];
        $total = (int)$row['total']; $ac = (int)$row['ac'];
        $rate = $total > 0 ? round(100 * $ac / $total, 1) . '%' : 'chưa có dữ liệu';
        $label = self::scopeLabel($user, $f);
        $lines = ['Tiến độ ' . $label . ', ' . $dates['label'] . ': ' . $total . ' lượt nộp, ' . $ac . ' lượt AC; tỷ lệ AC trên lượt nộp: ' . $rate . '.'];
        $visible = (int)self::scalar($db, 'SELECT COUNT(*) FROM problems WHERE is_active = ?', [1]);
        if ($personal) {
            $lines[] = 'Đã AC ' . (int)$row['solved'] . '/' . $visible . ' bài đang hiển thị trong khoảng đã chọn (mỗi bài chỉ tính một lần).';
            $xp = self::scalar($db, 'SELECT xp_points FROM users WHERE id = ?', [(int)$user['id']]);
            $lines[] = 'XP hiện tại của bạn: ' . (int)$xp . ' (không lọc theo ngày).';
            return self::result(implode("\n", $lines), [['Danh sách bài tập', '/frontend/index.php'], ['Lịch sử nộp bài', self::submissionLink($user, $f)], ['Hồ sơ', '/frontend/profile.php']]);
        }
        $members = (int)self::scalar($db, "SELECT COUNT(*) FROM users u WHERE $scope", $params);
        $lines[] = $members . ' học viên trong phạm vi hiện tại; chỉ tính membership active và không đếm trùng giữa các lớp. Số AC là lượt nộp, không phải số người.';
        if (!empty($f['verdict']) && $f['verdict'] !== 'AC') {
            $selected = (int)self::scalar($db, "SELECT COUNT(*) FROM submissions s JOIN users u ON u.id = s.user_id WHERE $where AND s.final_verdict = ?", array_merge($subParams, [$f['verdict']]));
            $lines[] = 'Trong đó ' . $selected . ' lượt ' . $f['verdict'] . '.';
        }
        if (!empty($f['list'])) {
            // Aggregate first by user; the authorization predicate remains in SQL, not post-filtered.
            $joinSql = $timeSql;
            $joinParams = $timeParams;
            if (isset($f['problem_id'])) { $joinSql .= ' AND s.problem_id = ?'; $joinParams[] = (int)$f['problem_id']; }
            $rows = self::rows($db, "SELECT u.id, u.username, COUNT(s.id) AS total, COALESCE(SUM(CASE WHEN s.final_verdict = 'AC' THEN 1 ELSE 0 END), 0) AS ac FROM users u LEFT JOIN submissions s ON s.user_id = u.id$joinSql WHERE $scope GROUP BY u.id, u.username ORDER BY u.username, u.id LIMIT 20", array_merge($joinParams, $params));
            $lines[] = 'Hiển thị tối đa 20 học viên: người — lượt nộp / lượt AC.';
            foreach ($rows as $r) $lines[] = self::plain($r['username']) . ' — ' . (int)$r['total'] . ' / ' . (int)$r['ac'];
        }
        return self::result(implode("\n", $lines), [['Tiến độ học viên', self::progressLink($f)], ['Lịch sử nộp bài', self::submissionLink($user, $f)]]);
    }

    private static function queue(PDO $db, array $user, array $f, array $dates): array
    {
        [$scope, $params] = self::userScope($user, $f);
        [$timeSql, $timeParams] = self::dateSql($dates, 'jr.created_at');
        $rows = self::rows($db, "SELECT jr.status, COUNT(*) AS total FROM judging_runs jr JOIN submissions s ON s.id = jr.submission_id JOIN users u ON u.id = s.user_id WHERE $scope$timeSql AND jr.status IN ('QUEUED', 'RUNNING', 'SYS_ERROR') GROUP BY jr.status", array_merge($params, $timeParams));
        $counts = ['QUEUED'=>0, 'RUNNING'=>0, 'SYS_ERROR'=>0];
        foreach ($rows as $row) $counts[$row['status']] = (int)$row['total'];
        $answer = 'Hàng đợi ' . self::scopeLabel($user, $f) . ', ' . $dates['label'] . ': ' . $counts['QUEUED'] . ' lượt chờ (QUEUED), ' . $counts['RUNNING'] . ' lượt đang chấm (RUNNING), ' . $counts['SYS_ERROR'] . ' lượt lỗi hệ thống (SYS_ERROR). Đếm lượt chấm, không phải người hay bài nộp; không thay đổi hàng đợi.';
        return self::result($answer, $user['role'] === 'student' || !empty($f['self']) ? [['Lịch sử nộp bài', self::submissionLink($user, $f)]] : [['Hàng đợi chấm', '/frontend/instructor/queue.php']]);
    }

    private static function adminSummary(PDO $db, array $dates): array
    {
        $users = self::rows($db, 'SELECT role, COUNT(*) AS total FROM users GROUP BY role', []);
        $roles = ['student'=>0, 'teacher'=>0, 'admin'=>0];
        foreach ($users as $r) $roles[$r['role']] = (int)$r['total'];
        $classes = (int)self::scalar($db, 'SELECT COUNT(*) FROM classes', []);
        $problems = self::rows($db, 'SELECT is_active, COUNT(*) AS total FROM problems GROUP BY is_active', []);
        $active = $hidden = 0;
        foreach ($problems as $p) { if ((int)$p['is_active'] === 1) $active += (int)$p['total']; else $hidden += (int)$p['total']; }
        [$timeSql, $params] = self::dateSql($dates, 's.created_at');
        $subs = self::rows($db, "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN s.final_verdict = 'AC' THEN 1 ELSE 0 END), 0) AS ac FROM submissions s WHERE 1=1$timeSql", $params)[0];
        return self::result('Tổng hợp quản trị: ' . $roles['student'] . ' tài khoản học viên, ' . $roles['teacher'] . ' giáo viên, ' . $roles['admin'] . ' admin; ' . $classes . ' lớp; ' . $active . ' bài đang hiển thị và ' . $hidden . ' bài ẩn (các tổng này không lọc ngày). Bài nộp ' . $dates['label'] . ': ' . (int)$subs['total'] . ' lượt, trong đó ' . (int)$subs['ac'] . ' lượt AC.', [['Quản lý tài khoản', '/frontend/admin/users.php'], ['Quản lý bài tập', '/frontend/instructor/problems.php'], ['Tiến độ học viên', '/frontend/instructor/progress.php']]);
    }

    private static function scopeLabel(array $user, array $f): string
    {
        if (isset($f['target_label'])) return 'của username ' . $f['target_label'] . (isset($f['class_label']) ? ' thuộc ' . $f['class_label'] : '');
        if ($user['role'] === 'student' || !empty($f['self'])) return 'của chính bạn';
        if (isset($f['class_label'])) return 'thuộc ' . $f['class_label'];
        return $user['role'] === 'teacher' ? 'của học viên active trong các lớp bạn sở hữu' : 'trong hệ thống';
    }

    private static function submissionLink(array $user, array $f): string
    {
        $q = [];
        foreach (['class_id', 'problem_id', 'verdict', 'username'] as $key) if (isset($f[$key])) $q[$key] = $f[$key];
        if (!empty($f['self']) && $user['role'] !== 'student' && isset($user['username'])) $q['username'] = $user['username'];
        return '/frontend/submissions.php' . ($q ? '?' . http_build_query($q) : '');
    }

    private static function progressLink(array $f): string
    {
        $q = [];
        if (isset($f['class_id'])) $q['class_id'] = (int)$f['class_id'];
        if (isset($f['username'])) $q['q'] = $f['username'];
        return '/frontend/instructor/progress.php' . ($q ? '?' . http_build_query($q) : '');
    }

    private static function like(string $value): string { return strtr($value, ['!'=>'!!', '%'=>'!%', '_'=>'!_']); }

    /** Bound values only; all SQL templates and identifiers originate inside this class. */
    private static function rows(PDO $db, string $sql, array $params): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function scalar(PDO $db, string $sql, array $params)
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private static function plain(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }

    private static function result(string $answer, array $links = [], array $choices = []): array
    {
        $result = ['answer'=>$answer, 'links'=>array_map(static fn(array $link): array => ['label'=>$link[0], 'url'=>$link[1]], $links)];
        if ($choices) $result['choices'] = $choices;
        return $result;
    }
}
