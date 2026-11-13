<?php
/** Pure, role-aware routing and verified application help. No provider or mutation entry points. */
require_once __DIR__ . '/ChatbotQueries.php';

final class AIAssistant
{
    /** @return array{kind:string,answer?:string,links?:array,query?:string,filters?:array} */
    public static function route(array $user, string $text, bool $hasDraft = false, bool $clarifying = false): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 12000) {
            return self::answered('Hãy nhập tin nhắn từ 1 đến 12000 ký tự.');
        }
        if (!self::knownRole($user)) return self::answered('Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.');
        $t = ChatbotQueries::normalize($text);
        $staff = in_array($user['role'], ['teacher', 'admin'], true);
        if (self::requestsPrivateData($t)) return self::privateDataRefusal();
        if (self::requestsSolution($t)) return self::solutionRefusal();
        $intent = ChatbotQueries::intent($user, $text);
        if ($intent !== null) {
            $denial = ChatbotQueries::denial($user, $intent['query'], $intent['filters']);
            if ($denial !== null) return ['kind' => 'answer'] + $denial;
        }

        // A question about a screen is not permission to perform that screen's operation.
        if (self::isGuide($t)) {
            $help = self::knowledge($user, $t);
            return $help !== null ? ['kind' => 'help'] + $help : self::answered(self::outsideScope());
        }

        // A new statement can mention tests; that is different from adding tests to a saved problem.
        if (self::isProblemRequest($text) && self::startsCreation($t) && !self::savedTarget($t)) {
            return self::draftRoute($staff, $t);
        }
        if (self::isMutation($t) || (self::savedTarget($t) && preg_match('/\b(sua|doi|them|xoa|cap nhat|edit|change|add|delete|update)\b/', $t))
            || (!preg_match('/\b(?:ban nhap|draft)\b/', $t) && preg_match('/^(?:(?:hay|vui long|giup toi|giup minh|please)\s+)*(?:sua|doi|xoa|cap nhat|edit|change|delete|update)\s+(?:bai(?:\s+tap)?|problem|exercise)\b/', $t))) {
            return self::answered('Chatbot không thực hiện thay đổi tài khoản, lớp, bài đã lưu, bộ test, điểm/XP, chấm lại, thử lại hàng đợi hoặc xuất báo cáo. Bạn có thể hỏi cách dùng màn hình phù hợp với quyền của mình. Bài mới chỉ được lưu bằng nút Thêm bài trên bản nháp đã duyệt.');
        }
        if (self::isRevision($t)) {
            if (!$staff) return self::answered('Học viên không được tạo hoặc chỉnh sửa bản nháp bài tập. Tôi có thể hướng dẫn luyện tập, nộp bài và tra cứu dữ liệu của chính bạn.');
            if (!$hasDraft) return self::answered('Chưa có bản nháp để chỉnh sửa. Hãy gửi đề bài với yêu cầu tính toán rõ ràng để tạo bản nháp trước; chatbot không sửa bài đã lưu.');
            return ['kind' => 'revise'];
        }
        if (self::isProblemRequest($text)) {
            $route = self::draftRoute($staff, $t);
            // A short answer such as “Tính tổng” may complete the pending goal.
            // A newly pasted statement (Cho/Given/...) always starts its own source.
            if ($clarifying && $staff && $route['kind'] === 'draft'
                && !preg_match('/^(?:(?:de bai|bai toan|problem(?: statement)?)\s*:\s*)?(?:cho\b|(?:you are\s+)?given\b|viet chuong trinh\b|write\s+(?:a\s+)?program\b)/', $t)
                && !str_contains($t, "\n")) $route['continue_pending'] = true;
            return $route;
        }

        if ($intent !== null) return ['kind' => 'query'] + $intent;
        $help = self::knowledge($user, $t);
        if ($help !== null) return ['kind' => 'help'] + $help;
        // The endpoint owns the pending source. This flag cannot bypass role or safety checks above.
        if ($clarifying && $staff && preg_match('/\b(?:tong|hieu|tich|thuong|lon nhat|nho nhat|nguyen to|doi xung|dem|sap xep|sum|max|min|prime|sort|count|yes|no)\b/', $t)) return ['kind' => 'draft', 'continue_pending' => true];
        return self::answered(self::outsideScope());
    }

    /** Existing consumers may inspect intent, but this never grants permission or calls AI. */
    public static function isProblemRequest(string $text): bool
    {
        $t = ChatbotQueries::normalize(trim($text));
        if ($t === '' || self::isGuide($t)) return false;
        if (preg_match('/^(?:tim|xem|tra cuu|liet ke|find|search|show)\s+(?:bai(?:\s+tap)?|problem|exercise|lop|class|bai nop|submission)\b/', $t)) return false;
        if (preg_match('/^(?:(?:hay|vui long|giup toi|giup minh|please)\s+)?(?:them\s+test|add\s+(?:a\s+)?test|cham\s*lai|thu\s*lai|(?:bat|tat|an|hien)\s+bai|tao\s+(?:tai khoan|user|nguoi dung))\b/', $t)) return false;
        if (preg_match('/\b(?:tao|them|soan|sinh|viet|chuan hoa|chat loc|boc tach)\s+(?:(?:giup|cho)\s+(?:toi|minh)\s+)?(?:mot\s+|1\s+)?(?:bai(?:\s*tap)?|de(?:\s*bai)?|problem)\b|\b(?:create|generate|draft)\s+(?:an?\s+)?(?:programming\s+)?(?:problem|exercise)\b|\b(?:dich|translate).*?\b(?:de|bai|problem)\b/', $t)) return true;
        if (count(preg_split('/\r\n|\r|\n/', $t)) >= 3 && preg_match('/\b(input|output|stdin|dau vao|dau ra|vi du)\b/', $t)) return true;
        return (bool)preg_match('/(?:^|[\r\n.!?:]\s*)(?:(?:de bai|bai toan)\s*:\s*)?(?:cho\s+(?:hai|ba|mot|so|mang|day|chuoi|do thi|cay|n\b|[a-z]\b)|(?:you are\s+)?given\s+|(?:hay\s+)?(?:tinh|tim|kiem tra)\s+|viet\s+chuong trinh|write\s+(?:a\s+)?program|your task is\s+|(?:calculate|compute|find)\s+(?:the\s+)?)/', $t);
    }

    /** Deterministic Vietnamese help. Links are verified, app-relative routes, not provider output. */
    public static function help(array $user, string $text): array
    {
        if (!self::knownRole($user)) return self::response('Phiên đăng nhập không hợp lệ. Vui lòng đăng nhập lại.');
        $t = ChatbotQueries::normalize($text);
        if (self::requestsPrivateData($t)) return self::response(self::privateDataRefusal()['answer']);
        if (self::requestsSolution($t)) return self::response(self::solutionRefusal()['answer']);
        $intent = ChatbotQueries::intent($user, $text);
        if ($intent !== null && ($denial = ChatbotQueries::denial($user, $intent['query'], $intent['filters'])) !== null) return $denial;
        return self::knowledge($user, $t) ?? self::response(self::outsideScope());
    }

    private static function knownRole(array $user): bool
    {
        return in_array($user['role'] ?? '', ['student', 'teacher', 'admin'], true);
    }

    private static function isGuide(string $t): bool
    {
        return (bool)preg_match('/\b(?:lam sao|lam the nao|cach\s+(?:nao|dung|su dung|tao|them|sua|doi|xoa|cham|thu|xuat|xem|nop|chay|tham gia|quan ly|khoa|mo)|huong dan|o dau|la gi|nghia la gi|how\s+(?:do|can|to)|where\s+(?:do|can|is)|what\s+(?:is|are|does)|quan ly\s+(?:lop|bai|tai khoan))\b/', $t);
    }

    private static function startsCreation(string $t): bool
    {
        return (bool)preg_match('/^(?:(?:hay|vui long|giup toi|giup minh|toi muon|please|can you)\s+)*(?:tao|them|soan|sinh|viet|chuan hoa|chat loc|boc tach|dich|create|generate|draft|translate)\b/', $t);
    }

    private static function savedTarget(string $t): bool
    {
        return (bool)preg_match('/\b(?:bai|problem|exercise)\s*(?:(?:tap|nop)\s*)?(?:#\s*\d+|(?:co\s+)?id\s*[:=#]?\s*\d+|\d+\b|da luu|da co|hien co)|\b(?:saved|existing)\s+(?:problem|exercise)|\bphien ban\s+(?:moi|thu|so)|\bnew\s+version\b/', $t);
    }

    private static function isMutation(string $t): bool
    {
        if (preg_match('/\b(?:cham\s*lai|rejudge|retry|thu\s*lai\s+(?:run|lan|cham)|(?:tao|them|xoa|khoa|mo khoa|doi quyen|doi vai tro|reset)\s+(?:tai khoan|user|nguoi dung|hoc vien|giao vien)|(?:xuat|export).*\b(?:bao cao|excel|report)|(?:bat|tat|an|hien|xoa)\s+(?:hien thi\s+)?bai|(?:sua|doi|cap nhat)\s+(?:diem|xp|bai da luu)|(?:tao|xoa|doi ten)\s+lop|(?:duyet|tu choi|xoa|them)\s+(?:thanh vien|hoc vien)|(?:create|delete|lock|unlock)\s+(?:user|account|class)|(?:change|set)\s+(?:role|score|xp))\b/', $t)) return true;
        // Adding a test to a named/saved exercise is retired even while another draft is open.
        return (bool)preg_match('/\b(?:them|bo sung|add)\s+(?:a\s+)?(?:test|ca kiem thu).*\b(?:cho bai|vao bai|to (?:the )?(?:problem|exercise))\b(?!\s*(?:nhap|draft))/', $t)
            && !preg_match('/\b(?:ban nhap|draft)\b/', $t);
    }

    private static function isRevision(string $t): bool
    {
        return (bool)preg_match('/^(?:(?:hay|vui long|giup toi|giup minh|please)\s+)*(?:sua|doi|chinh|cap nhat|bo sung|them|xoa|edit|revise|change|update|add|remove)\b.*\b(?:ban nhap|draft|tieu de|title|slug|mo ta|description|dau vao|dau ra|input|output|vi du|sample|test|ca kiem thu|gioi han|thoi gian|time limit|do kho|difficulty|ngon ngu|language|chu de|topic|rang buoc|so am|truong hop bien)\b/', $t);
    }

    private static function draftRoute(bool $staff, string $t): array
    {
        if (!$staff) return self::answered('Tạo và chỉnh sửa bản nháp bài tập chỉ dành cho giáo viên hoặc quản trị viên. Học viên có thể hỏi cách dùng ứng dụng và tra cứu bài tập, bài nộp, tiến độ của chính mình; tôi không giải hộ bài.');
        // Missing formatting can be completed later; a missing computational goal must not be invented.
        $t = preg_replace('/\btinh toan\b|\b(?:tinh|tim|kiem tra|dem)\s+(?:gi do|gi|cai gi)\b|\b(?:sample\s+)?(?:input|output|stdin|stdout)\s*[:：]?[^\r\n]*/', '', $t);
        if (!preg_match('/\b(?:tinh|tim|kiem tra|dem|sap xep|in\s+(?:ra|chuoi|dong|so|hello|loi)|xuat|tong|hieu|tich|thuong|lon nhat|nho nhat|nguyen to|doi xung|phan tu|giai phuong trinh|calculate|compute|find|check|count|sort|print|output\s+\w+|sum|maximum|minimum|prime|palindrome|return\s+\w+)\b/', $t)) {
            return self::answered('Bạn muốn bài toán yêu cầu tính, tìm, kiểm tra hoặc in kết quả gì? Hãy nêu mục tiêu cốt lõi (ví dụ: tính tổng các phần tử của mảng). Tôi có thể bổ sung định dạng Input/Output, ví dụ và test sau khi mục tiêu rõ ràng.') + ['needs_clarification' => true];
        }
        return ['kind' => 'draft'];
    }

    private static function requestsPrivateData(string $t): bool
    {
        return (bool)preg_match('/\b(?:api[_ ]?key|password[_ ]?hash|otp[_ ]?hash|session[_ ]?(?:token|id)|confirm_token|system_error_detail|error_detail|\.env|bi mat he thong)\b|\b(?:xem|lay|hien|tiet lo|doc|show|reveal|read|give).*\b(?:test\s+(?:an|kin)|hidden\s+tests?|ma nguon|source[_ ]?code|email\s+(?:cua|nguoi|hoc vien)|mat khau\s+(?:cua|nguoi|hoc vien))\b/', $t);
    }

    private static function requestsSolution(string $t): bool
    {
        return (bool)preg_match('/\b(?:giai ho|giai giup|loi giai|code giai|viet code|write\s+(?:the\s+)?code|give\s+(?:me\s+)?(?:the\s+)?(?:code|solution)|cho (?:toi|minh)\s+(?:code|ma nguon)|goi y thuat toan|sua code|debug\s+(?:my\s+)?code|solution code|solve\s+(?:this|the|my)\s+(?:problem|exercise))\b/', $t);
    }

    private static function privateDataRefusal(): array
    {
        return self::answered('Chatbot không cung cấp bí mật hệ thống, test ẩn, mã nguồn, thông tin lỗi nội bộ hoặc thông tin riêng như email của người khác. Tôi chỉ tra cứu thông tin tổng hợp và kết quả được phép xem.');
    }

    private static function solutionRefusal(): array
    {
        return self::answered('Tôi không cung cấp lời giải, code giải hộ, gợi ý thuật toán hoặc sửa code. Tôi có thể giải thích trạng thái chấm, hướng dẫn chạy thử/nộp bài và giúp tra cứu trong AlgoJudge.');
    }

    private static function knowledge(array $user, string $t): ?array
    {
        $role = $user['role'];
        $staff = in_array($role, ['teacher', 'admin'], true);
        $adminFeature = preg_match('/\b(?:quan tri|admin|quan ly tai khoan|tao tai khoan|them tai khoan|doi quyen|doi vai tro|khoa tai khoan|xoa tai khoan|reset mat khau|dat lai mat khau.*nguoi)\b/', $t);
        if ($adminFeature && $role !== 'admin') return self::response('Chức năng quản trị tài khoản chỉ dành cho quản trị viên. Tôi không hướng dẫn thao tác quản trị ngoài quyền của bạn. Với tài khoản của mình, bạn có thể dùng Hồ sơ hoặc Quên mật khẩu.', [['Hồ sơ', '/frontend/profile.php']]);
        $staffFeature = preg_match('/\b(?:tao bai|them bai|soan de|ban nhap|draft|quan ly bai|bo test|bo du lieu|phien ban|cham lai|rejudge|thu lai run|tien do hoc vien|quan ly lop|duyet.*(?:lop|thanh vien)|tao lop|them hoc vien|xuat bao cao|hang doi|queue)\b/', $t);
        if ($staffFeature && !$staff) return self::response('Học viên không có quyền sử dụng nghiệp vụ giảng dạy hoặc quản trị. Tôi có thể hướng dẫn danh sách bài, workspace, chạy thử, nộp bài, lịch sử, lớp học và tiến độ của chính bạn.', [['Danh sách bài tập', '/frontend/index.php'], ['Lớp học của tôi', '/frontend/instructor/classes.php']]);

        if ($adminFeature) return self::response('Mở Quản lý tài khoản để lọc theo vai trò hoặc tìm tài khoản. Dùng trang Thêm tài khoản để tạo tài khoản; các thao tác đổi quyền, khóa, đặt lại mật khẩu hoặc xóa được thực hiện trên màn hình quản trị với kiểm tra quyền. Quản lý lớp dùng màn hình riêng. Chatbot chỉ hướng dẫn, không thực hiện các thay đổi này.', [['Quản lý tài khoản', '/frontend/admin/users.php'], ['Thêm tài khoản', '/frontend/admin/user_form.php'], ['Quản lý lớp', '/frontend/instructor/classes.php']]);
        if ($staff && preg_match('/\b(?:tao bai|them bai|soan de|ban nhap|draft|create.*problem|add.*problem)\b/', $t)) return self::response('Dán đề bài có mục tiêu rõ ràng vào chat để biên soạn bản nháp. Kiểm tra và sửa mô tả, Input/Output, ví dụ, giới hạn và test ngay trong bản xem trước. Chỉ nút Thêm bài trên bản nháp mới nhất đã duyệt mới lưu bài thật; nhắn OK hoặc “thêm luôn” không lưu. Bạn vẫn có thể tạo bài thủ công trên trang Thêm bài.', [['Thêm bài thủ công', '/frontend/instructor/problem_form.php']]);
        if ($staff && preg_match('/\b(?:bo test|test case|bo du lieu|phien ban|dataset|version)\b/', $t)) return self::response('Mở Bộ Test & Phiên bản, chọn bài, xem phiên bản hiện có rồi nhập giới hạn/changelog và dữ liệu test bổ sung khi cần. Màn hình tạo phiên bản mới và giữ lịch sử cũ; chấm lại là thao tác ở màn hình riêng. Chatbot không sửa test hoặc tạo phiên bản cho bài đã lưu.', [['Bộ Test & Phiên bản', '/frontend/instructor/dataset_versions.php'], ['Chấm lại & đối chiếu', '/frontend/instructor/rejudge.php']]);
        if ($staff && preg_match('/\b(?:cham lai|rejudge|doi chieu)\b/', $t)) return self::response('Mở Chấm lại & đối chiếu, lọc bài và chọn bài nộp trong phạm vi được phép. Thao tác trên màn hình tạo lượt chấm mới với bộ test hiện hành và giữ các kết quả cũ để so sánh. Giáo viên chỉ xem học viên có membership active trong lớp mình sở hữu. Chatbot không chấm lại.', [['Chấm lại & đối chiếu', '/frontend/instructor/rejudge.php']]);
        if ($staff && preg_match('/\b(?:hang doi|queue|thu lai|run ket)\b/', $t)) return self::response('Hàng đợi chấm hiển thị lượt QUEUED, RUNNING và SYS_ERROR trong phạm vi được phép. Có thể xem lượt lỗi/kẹt và dùng nút Thử lại trên màn hình khi đủ điều kiện. Thử lại tạo lượt mới, không xóa lịch sử; chatbot không điều khiển hàng đợi.', [['Hàng đợi chấm', '/frontend/instructor/queue.php']]);
        if ($staff && preg_match('/\b(?:tien do|hoc vien|bao cao|excel)\b/', $t) && !preg_match('/\b(?:lop|tham gia)\b/', $t)) return self::response('Mở Tiến độ học viên, chọn lớp được phép quản lý và lọc username khi cần. Trang hiển thị số lượt nộp, bài đang hiển thị đã AC và lần nộp gần nhất; nút Xuất Excel xuất theo bộ lọc của màn hình. Học viên thuộc nhiều lớp không được đếm lặp khi xem phạm vi chung. Chatbot không thực thi xuất báo cáo.', [['Tiến độ học viên', '/frontend/instructor/progress.php']]);
        if (preg_match('/\b(?:lop|membership|ma moi|join code|class)\b/', $t)) {
            if (!$staff) return self::response('Mở Lớp học của tôi để xem các lớp bạn đang là thành viên active. Mục Tham gia lớp cho nhập mã mời do giáo viên cung cấp; theo dõi trạng thái tại Yêu cầu của tôi và chờ được duyệt. Tham gia lớp không cấp quyền xem bài nộp riêng của người khác.', [['Lớp học của tôi', '/frontend/instructor/classes.php'], ['Tham gia lớp', '/frontend/instructor/classes.php?view=join'], ['Yêu cầu của tôi', '/frontend/instructor/classes.php?view=requests']]);
            if (preg_match('/\b(?:tien do|bao cao|excel)\b/', $t)) return self::response('Mở Tiến độ học viên và chọn đúng lớp theo ID; giáo viên chỉ dùng lớp mình sở hữu và học viên có membership active. Xem tổng lượt nộp, số bài đã AC, lần nộp cuối; xuất Excel bằng nút trên màn hình nếu cần. Chatbot chỉ tra cứu và hướng dẫn, không xuất báo cáo thay bạn.', [['Tiến độ học viên', '/frontend/instructor/progress.php'], ['Quản lý lớp', '/frontend/instructor/classes.php']]);
            return self::response('Mở Quản lý lớp, chọn lớp thuộc quyền để xem thành viên/yêu cầu. Trên màn hình có thể tạo lớp, chia sẻ/kích hoạt/đổi mã mời, thêm học viên theo username, duyệt/từ chối yêu cầu hoặc đưa học viên ra khỏi lớp. Giáo viên chỉ quản lý lớp mình sở hữu; admin có thể gán chủ quản. Các thay đổi không được thực hiện bằng lệnh chat.', [['Quản lý lớp', '/frontend/instructor/classes.php']]);
        }
        if ($staff && preg_match('/\b(?:quan ly bai|an bai|hien bai|tat bai|bat bai|xoa bai|sua bai)\b/', $t)) return self::response('Mở Quản lý bài tập để xem các bài, bật/tắt hiển thị hoặc mở form chỉnh sửa. Xóa bài chỉ dành cho admin trên màn hình này. Thay đổi bài hoặc bộ test đã lưu không được thực hiện qua chat; bản nháp bài mới là luồng riêng.', [['Quản lý bài tập', '/frontend/instructor/problems.php']]);
        if (preg_match('/\b(?:ac|wa|tle|re|ce|sys_err|queued|running)\b/', $t)) return self::response('AC: vượt qua bộ test. WA: kết quả không đúng. TLE: vượt giới hạn thời gian. RE: lỗi khi chạy. CE: biên dịch không thành công. SYS_ERR: lỗi hệ thống chấm, không đồng nghĩa bài sai. QUEUED/RUNNING: đang chờ/đang chấm. Xem kết quả và lịch sử tại Lịch sử nộp bài hoặc trong workspace; không cần nộp dồn khi đang chờ.', [['Lịch sử nộp bài', '/frontend/submissions.php']]);
        if (preg_match('/\b(?:san choi|playground)\b/', $t)) return self::response('Sân chơi tự do cho viết và chạy thử chương trình với dữ liệu tự nhập, không cần chọn bài tập. Chọn ngôn ngữ, nhập chương trình và input rồi chạy; đây không phải nộp bài để nhận AC cho một bài tập.', [['Sân chơi tự do', '/frontend/playground.php']]);
        if (preg_match('/\b(?:bang xep hang|leaderboard|thu hang)\b/', $t)) return self::response('Bảng xếp hạng hiển thị XP của học viên và thống kê luyện tập. Bạn có thể chọn bộ lọc lớp được phép xem; giáo viên chỉ xem các lớp mình quản lý.', [['Bảng xếp hạng', '/frontend/leaderboard.php']]);
        if (preg_match('/\b(?:ho so|xp|mat khau|profile|quen mat khau|email)\b/', $t)) return self::response('Hồ sơ hiển thị thông tin cá nhân, XP và thống kê của tài khoản. Đổi email/mật khẩu qua form trong Hồ sơ với xác thực hiện có và OTP khi được cấu hình. Nếu không đăng nhập được, dùng Quên mật khẩu. Không gửi mật khẩu hoặc OTP vào chat.', [['Hồ sơ', '/frontend/profile.php'], ['Quên mật khẩu', '/frontend/auth/forgot_password.php']]);
        if (preg_match('/\b(?:lich su|bai nop|lan nop|ket qua|diem|submissions?)\b/', $t)) return self::response('Mở Lịch sử nộp bài để lọc theo bài hoặc kết quả chấm và xem trạng thái, điểm, số ca đúng. Học viên chỉ xem bài nộp của chính mình. Trong workspace, tab Kết quả & Lịch sử chấm cho theo dõi bài vừa nộp và các lần chấm.', [['Lịch sử nộp bài', '/frontend/submissions.php'], ['Danh sách bài tập', '/frontend/index.php']]);
        if (preg_match('/\b(?:workspace|chay thu|nop bai|ngon ngu|c\+\+|python|java|javascript|php|stdin|stdout)\b/', $t)) return self::response('Từ Danh sách bài tập, chọn bài để mở workspace. Đọc đề và ví dụ, chọn C/C++/Java/Python/PHP/JS, nhập chương trình. Chạy thử dùng Custom Input của bạn; Nộp bài gửi chương trình vào hàng đợi chấm với bộ test của bài. Theo dõi kết quả trong workspace hoặc Lịch sử nộp bài.', [['Danh sách bài tập', '/frontend/index.php'], ['Lịch sử nộp bài', '/frontend/submissions.php']]);
        if (preg_match('/\b(?:danh sach bai|bai tap|luyen tap|chu de|do kho|problem list)\b/', $t)) return self::response('Danh sách bài tập cho lọc theo chủ đề, độ khó và ngôn ngữ gợi ý. Chọn tên bài hoặc nút Làm bài/Xem để mở workspace. Học viên thấy tiến độ hoàn thành các bài đang hiển thị.', [['Danh sách bài tập', '/frontend/index.php']]);
        if (preg_match('/^(?:xin chao|chao|hello|hi|help|tro giup|ban (?:lam|giup) duoc gi|chatbot|algojudge)[!?.\s]*$/', $t) || preg_match('/\b(?:tinh nang|su dung algojudge|ve ung dung)\b/', $t)) {
            $links = [['Danh sách bài tập', '/frontend/index.php'], ['Lịch sử nộp bài', '/frontend/submissions.php'], ['Lớp học', '/frontend/instructor/classes.php']];
            if ($staff) $links[] = ['Tiến độ học viên', '/frontend/instructor/progress.php'];
            if ($role === 'admin') $links[] = ['Quản lý tài khoản', '/frontend/admin/users.php'];
            return self::response('Tôi hỗ trợ cách dùng AlgoJudge và tra cứu dữ liệu trong quyền của bạn: bài tập, bài nộp, tiến độ và lớp học.' . ($staff ? ' Giáo viên/admin có thể dán đề rõ mục tiêu để tạo và sửa bản nháp; chỉ bấm Thêm bài mới lưu bài thật.' : '') . ' Tôi không giải hộ bài hoặc thực hiện các thao tác thay đổi dữ liệu bằng lệnh chat.', $links);
        }
        return null;
    }

    private static function outsideScope(): string
    {
        return 'Tôi chỉ hỗ trợ cách dùng và tra cứu dữ liệu AlgoJudge theo quyền đăng nhập, không trả lời kiến thức ngoài ứng dụng hoặc giải hộ bài. Bạn có thể hỏi cách nộp bài, ý nghĩa AC/WA, tìm bài theo chủ đề, bài nộp gần nhất hoặc tiến độ của mình.';
    }

    private static function answered(string $answer): array
    {
        return ['kind' => 'answer'] + self::response($answer);
    }

    /** Internal links are literal known routes; never accept a URL from chat or model output. */
    private static function response(string $answer, array $links = []): array
    {
        return ['answer' => $answer, 'links' => array_map(static fn(array $link): array => ['label' => $link[0], 'url' => $link[1]], $links)];
    }
}
