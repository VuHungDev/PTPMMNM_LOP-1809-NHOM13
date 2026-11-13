<?php
/** Private login-scoped state. Short row transactions only; never held across an AI call. */
final class ChatbotError extends RuntimeException
{
    public string $reason;
    public int $status;
    public array $fields;
    public function __construct(string $message, string $reason = 'invalid_request', int $status = 400, array $fields = [])
    { parent::__construct($message); $this->reason = $reason; $this->status = $status; $this->fields = $fields; }
}

final class ChatbotStore
{
    private PDO $db;
    private array $actor;
    private string $id;
    public function __construct(PDO $db, array $actor, string $scope)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $scope)) throw new ChatbotError('Hãy đăng nhập lại để mở chatbot.', 'auth_expired', 401);
        $this->db = $db; $this->actor = $actor;
        $this->id = hash('sha256', $actor['id'] . ':' . $scope);
        try {
            $q = $db->prepare('INSERT IGNORE INTO chatbot_sessions (id,user_id,auth_version,role,messages) VALUES (?,?,?,?,?)');
            $q->execute([$this->id, $actor['id'], $actor['auth_version'], $actor['role'], '[]']);
            // Bounded retention. No other application data is ever removed.
            $db->exec('DELETE FROM chatbot_sessions WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 50');
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1146) throw new ChatbotError('Chatbot chưa được khởi tạo. Quản trị viên cần chạy migration migrate_v10_chatbot.sql cho database ứng dụng.', 'chatbot_setup', 503);
            throw $e;
        }
    }

    private function actorLock(): void
    {
        $q = $this->db->prepare('SELECT role,auth_version,is_active FROM users WHERE id=? FOR UPDATE');
        $q->execute([$this->actor['id']]); $u = $q->fetch(PDO::FETCH_ASSOC);
        if (!$u || !(int)$u['is_active'] || $u['role'] !== $this->actor['role'] || (int)$u['auth_version'] !== (int)$this->actor['auth_version']) {
            throw new ChatbotError('Quyền hoặc phiên đăng nhập đã thay đổi. Hãy đăng nhập lại.', 'auth_expired', 401);
        }
    }
    private function transaction(callable $fn)
    {
        $this->db->beginTransaction();
        try {
            $this->actorLock();
            $q = $this->db->prepare('SELECT * FROM chatbot_sessions WHERE id=? AND user_id=? FOR UPDATE');
            $q->execute([$this->id, $this->actor['id']]); $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['role'] !== $this->actor['role'] || (int)$row['auth_version'] !== (int)$this->actor['auth_version']) throw new ChatbotError('Phiên chatbot không còn hợp lệ. Hãy đăng nhập lại.', 'auth_expired', 401);
            $row['messages'] = json_decode($row['messages'], true) ?: [];
            $row['draft'] = $row['draft'] ? json_decode($row['draft'], true) : null;
            $pending = $row['pending_source'] ? json_decode($row['pending_source'], true) : null;
            $row['pending_source'] = is_array($pending) && is_string($pending['source'] ?? null)
                ? $pending : ($row['pending_source'] ? ['source' => $row['pending_source']] : null);
            $result = $fn($row);
            $q = $this->db->prepare('UPDATE chatbot_sessions SET messages=?,draft=?,pending_source=?,lease_token=?,lease_heartbeat=?,rate_started=?,rate_count=?,updated_at=NOW() WHERE id=?');
            $q->execute([json_encode(array_slice($row['messages'], -40), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $row['draft'] ? json_encode($row['draft'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                $row['pending_source'] ? json_encode($row['pending_source'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                $row['lease_token'], $row['lease_heartbeat'], $row['rate_started'], $row['rate_count'], $this->id]);
            $this->db->commit(); return $result;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private static function busy(array $row): bool
    { return !empty($row['lease_token']) && (int)$row['lease_heartbeat'] >= time() - 60; }
    private static function checkDraft(array $row, string $id, int $revision): array
    {
        $d = $row['draft'];
        if (!$d || !hash_equals($d['id'], $id)) throw new ChatbotError('Không tìm thấy bản nháp trong phiên của bạn.', 'draft_not_found', 404);
        if ($d['revision'] !== $revision) throw new ChatbotError('Bản nháp đã thay đổi ở tab khác. Khôi phục bản mới nhất trước khi tiếp tục; nội dung đang sửa được giữ nguyên.', 'draft_conflict', 409);
        return $d;
    }
    public function state(): array
    {
        return $this->transaction(static function (&$row) {
            if ($row['draft'] && $row['draft']['status'] === 'review' && !in_array($row['role'], ['teacher', 'admin'], true)) $row['draft'] = null;
            return ['messages' => $row['messages'], 'draft' => $row['draft'], 'processing' => self::busy($row)];
        });
    }
    public function begin(string $text, string $draftId, int $revision): array
    {
        return $this->transaction(static function (&$row) use ($text, $draftId, $revision) {
            if (self::busy($row)) throw new ChatbotError('Một yêu cầu đang được xử lý. Hãy chờ hoặc bấm Dừng chờ.', 'chat_busy', 409);
            if ((int)$row['rate_started'] < time() - 60) { $row['rate_started'] = time(); $row['rate_count'] = 0; }
            if ((int)$row['rate_count'] >= 12) throw new ChatbotError('Bạn gửi quá nhiều yêu cầu. Hãy chờ một phút rồi thử lại.', 'rate_limit', 429);
            $row['rate_count']++;
            if ($draftId !== '') self::checkDraft($row, $draftId, $revision);
            $row['lease_token'] = bin2hex(random_bytes(16)); $row['lease_heartbeat'] = time();
            $row['messages'][] = ['role' => 'user', 'text' => $text];
            return ['token' => $row['lease_token'], 'draft' => $row['draft'], 'pending_source' => $row['pending_source']];
        });
    }
    public function heartbeat(string $token): void
    {
        $q = $this->db->prepare('UPDATE chatbot_sessions SET lease_heartbeat=?,updated_at=NOW() WHERE id=? AND lease_token=?');
        $q->execute([time(), $this->id, $token]);
        if ($q->rowCount() === 0) {
            // MySQL affected rows can be zero when a heartbeat has the same second.
            $q = $this->db->prepare('SELECT lease_token FROM chatbot_sessions WHERE id=?'); $q->execute([$this->id]);
            if ($q->fetchColumn() !== $token) throw new ChatbotError('Đã dừng yêu cầu. Bản nháp trước được giữ nguyên.', 'cancelled', 409);
        }
    }
    public function finish(string $token, string $answer, array $links = [], ?array $newDraft = null, ?array $expected = null, ?string $pendingSource = null): ?array
    {
        return $this->transaction(static function (&$row) use ($token, $answer, $links, $newDraft, $expected, $pendingSource) {
            if ($row['lease_token'] !== $token) throw new ChatbotError('Yêu cầu đã bị hủy hoặc thay thế.', 'cancelled', 409);
            if ($expected) self::checkDraft($row, $expected['id'], $expected['revision']);
            if ($newDraft !== null) $row['draft'] = $newDraft;
            // A question may preserve the draft, but must not leave an unrelated
            // old clarification attached to the next newly pasted exercise.
            $row['pending_source'] = $newDraft === null && $pendingSource !== null
                ? ['source' => $pendingSource, 'draft_id' => $expected['id'] ?? null, 'revision' => $expected['revision'] ?? null] : null;
            $row['messages'][] = ['role' => 'assistant', 'text' => $answer, 'links' => $links];
            $row['lease_token'] = null; $row['lease_heartbeat'] = null;
            return $row['draft'];
        });
    }
    public function fail(string $token): void
    {
        $q = $this->db->prepare('UPDATE chatbot_sessions SET lease_token=NULL,lease_heartbeat=NULL WHERE id=? AND lease_token=?');
        $q->execute([$this->id, $token]);
    }
    public function stop(): void
    { $q = $this->db->prepare('UPDATE chatbot_sessions SET lease_token=NULL,lease_heartbeat=NULL WHERE id=?'); $q->execute([$this->id]); }
    public function mutate(string $id, int $revision, callable $fn): array
    {
        return $this->transaction(static function (&$row) use ($id, $revision, $fn) {
            if (self::busy($row)) throw new ChatbotError('Hãy chờ yêu cầu hiện tại hoàn thành hoặc dừng chờ trước khi sửa.', 'chat_busy', 409);
            $d = self::checkDraft($row, $id, $revision);
            if ($d['status'] !== 'review') throw new ChatbotError('Bản nháp này đã được tạo hoặc hủy.', 'draft_closed', 409);
            $row['draft'] = $fn($d); return $row['draft'];
        });
    }
    /** Creation and idempotency marker share the SAME transaction and row lock. */
    public function save(string $id, int $revision, string $key): array
    {
        return $this->transaction(function (&$row) use ($id, $revision, $key) {
            $saved = $this->db->prepare('SELECT envelope FROM chatbot_creations WHERE session_id=? AND user_id=? AND draft_id=? AND revision=? AND save_key=?');
            $saved->execute([$this->id, $this->actor['id'], $id, $revision, $key]);
            $savedEnvelope = $saved->fetchColumn();
            if ($savedEnvelope) return json_decode($savedEnvelope, true, 64, JSON_THROW_ON_ERROR);
            if (self::busy($row)) throw new ChatbotError('Bản nháp đang được xử lý, chưa thể thêm bài.', 'chat_busy', 409);
            $d = self::checkDraft($row, $id, $revision);
            if (!hash_equals($d['save_key'], $key)) throw new ChatbotError('Khóa xác nhận bản nháp không hợp lệ.', 'invalid_save_key', 409);
            if ($d['status'] === 'saved') return $d;
            if ($d['status'] !== 'review') throw new ChatbotError('Bản nháp đã bị hủy.', 'draft_closed', 409);
            $issues = ChatbotDraft::issues($d['data']);
            if ($issues || $d['semantic_review']) throw new ChatbotError('Hãy kiểm tra và duyệt lại các mục trước khi thêm bài.', 'draft_validation', 422, $issues ?: ['Cần duyệt lại ví dụ/test sau khi chỉnh sửa.']);
            $b = $d['data'];
            $q = $this->db->prepare('SELECT id FROM problems WHERE slug=?'); $q->execute([$b['slug']]);
            if ($q->fetchColumn()) throw new ChatbotError('Slug đã tồn tại. Hãy sửa slug rồi duyệt lại.', 'draft_validation', 422, ['Slug đã được sử dụng.']);
            $this->db->prepare('INSERT INTO problems (slug,title,topic,difficulty,suggested_lang,created_by) VALUES (?,?,?,?,?,?)')
                ->execute([$b['slug'], $b['title'], $b['topic'], $b['difficulty'], $b['suggested_lang'], $this->actor['id']]);
            $problemId = (int)$this->db->lastInsertId();
            $this->db->prepare("INSERT INTO problem_versions (problem_id,version_number,description,input_desc,output_desc,sample_input,sample_output,time_limit_ms,changelog,created_by) VALUES (?,1,?,?,?,?,?,?, 'Tạo từ bản nháp chatbot đã duyệt', ?)")
                ->execute([$problemId, $b['description'], $b['input_desc'], $b['output_desc'], $b['sample_input'], $b['sample_output'], $b['time_limit_ms'], $this->actor['id']]);
            $versionId = (int)$this->db->lastInsertId();
            $q = $this->db->prepare('INSERT INTO test_cases (problem_version_id,case_order,stdin_data,expected_output,is_public,is_boundary) VALUES (?,?,?,?,?,?)');
            foreach ($b['test_cases'] as $i => $c) $q->execute([$versionId, $i + 1, $c['stdin'], $c['expected'], (int)$c['is_public'], (int)$c['is_boundary']]);
            $this->db->prepare('UPDATE problems SET current_version_id=? WHERE id=?')->execute([$versionId, $problemId]);
            AuditLog::record($this->db, $this->actor, 'problem.create', 'problem', $problemId, ['version_number' => 1, 'source' => 'ai']);
            $d['status'] = 'saved'; $d['problem_id'] = $problemId; $d['edit_url'] = url('/frontend/instructor/problem_form.php?edit=' . $problemId);
            $this->db->prepare('INSERT INTO chatbot_creations (session_id,user_id,draft_id,revision,save_key,envelope) VALUES (?,?,?,?,?,?)')
                ->execute([$this->id, $this->actor['id'], $id, $revision, $key, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $row['draft'] = $d;
            $row['messages'][] = ['role' => 'assistant', 'text' => 'Đã tạo bài #' . $problemId . ': ' . $b['title'], 'links' => [['label' => 'Mở bài vừa tạo', 'url' => $d['edit_url']]]];
            return $d;
        });
    }
}
