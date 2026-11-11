<?php
/** OTP lifecycle. The sender is injected so tests never send real email. */
final class PasswordReset
{
    private PDO $db;
    private string $key;
    private $send;

    public function __construct(PDO $db, string $key, callable $send)
    {
        if (strlen($key) < 32) throw new InvalidArgumentException('OTP_HASH_KEY cần ít nhất 32 ký tự ngẫu nhiên.');
        $this->db = $db;
        $this->key = $key;
        $this->send = $send;
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    /** Shared recovery/profile limits. Hold advisory locks until the transaction finishes. */
    private function limitedRequest(string $email, string $ip, callable $issue)
    {
        $emailDigest = $this->digest('email:' . $email);
        $ipDigest = $this->digest('ip:' . $ip);
        $database = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        $locks = [hash('sha256', $database . ':reset-email:' . $emailDigest), hash('sha256', $database . ':reset-ip:' . $ipDigest)];
        sort($locks);
        $held = [];
        try {
            foreach ($locks as $name) {
                $stmt = $this->db->prepare('SELECT GET_LOCK(?, 3)');
                $stmt->execute([$name]);
                if ((int)$stmt->fetchColumn() !== 1) return null;
                $held[] = $name;
            }
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("SELECT
                (SELECT COUNT(*) FROM password_reset_requests WHERE email_digest = ? AND requested_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS email_count,
                (SELECT COUNT(*) FROM password_reset_requests WHERE ip_digest = ? AND requested_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS ip_count");
            $stmt->execute([$emailDigest, $ipDigest]);
            $counts = $stmt->fetch();
            if ((int)$counts['email_count'] >= 3 || (int)$counts['ip_count'] >= 10) {
                $this->db->commit();
                return null;
            }
            $this->db->prepare('INSERT INTO password_reset_requests (email_digest, ip_digest) VALUES (?, ?)')->execute([$emailDigest, $ipDigest]);
            $result = $issue();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            foreach (array_reverse($held) as $name) {
                $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute([$name]);
            }
        }
    }

    private function coolingDown(int $uid): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM password_reset_otps WHERE user_id = ? AND sent_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1');
        $stmt->execute([$uid]);
        return (bool)$stmt->fetchColumn();
    }

    private function invalidate(int $uid): void
    {
        $this->db->prepare('UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$uid]);
    }

    private function failedAttempt(int $id): void
    {
        $this->db->prepare('UPDATE password_reset_otps SET used_at = IF(attempts >= 4, NOW(), used_at), attempts = attempts + 1 WHERE id = ?')->execute([$id]);
    }

    private function usable($record): bool
    {
        return $record && $record['used_at'] === null && (int)$record['valid_time'] === 1 && (int)$record['attempts'] < 5;
    }

    /** Internal ID only: never return it to the browser or reveal whether email matched. */
    public function request(string $email, string $ip): ?int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) return null;
        return $this->limitedRequest($email, $ip, function () use ($email) {
            // All credential writers lock the user first, then its OTP rows.
            $stmt = $this->db->prepare('SELECT id, role, is_active, auth_version FROM users WHERE email = ? FOR UPDATE');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user || !(int)$user['is_active'] || !in_array($user['role'], ['student', 'teacher'], true)) return null;
            $uid = (int)$user['id'];
            if ($this->coolingDown($uid)) return null;
            $otp = (string)random_int(100000, 999999);
            $this->invalidate($uid);
            $hash = $this->digest($uid . ':' . $email . ':' . $otp);
            $this->db->prepare("INSERT INTO password_reset_otps (user_id, otp_hash, purpose, auth_version, expires_at) VALUES (?, ?, 'password_reset', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
                ->execute([$uid, $hash, (int)$user['auth_version']]);
            $id = (int)$this->db->lastInsertId();
            ($this->send)($email, $otp);
            return $id;
        });
    }

    public function verify(int $id, string $email, string $otp, string $password): bool
    {
        if (!preg_match('/^[0-9]{6}$/D', $otp) || strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) return false;
        $email = strtolower(trim($email));
        // Do the expensive hash regardless of whether an account/token exists.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, role, is_active, auth_version FROM users WHERE email = ? FOR UPDATE');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            $stmt = $this->db->prepare("SELECT *, expires_at > NOW() AS valid_time FROM password_reset_otps WHERE id = ? AND user_id = ? AND purpose = 'password_reset' FOR UPDATE");
            $stmt->execute([$id, (int)($user['id'] ?? 0)]);
            $record = $stmt->fetch();
            if (!$user || !(int)$user['is_active'] || !in_array($user['role'], ['student', 'teacher'], true) || !$this->usable($record)
                || ($record['auth_version'] !== null && (int)$record['auth_version'] !== (int)$user['auth_version'])) {
                $this->db->commit();
                return false;
            }
            if (!hash_equals($record['otp_hash'], $this->digest($user['id'] . ':' . $email . ':' . $otp))) {
                $this->failedAttempt($id);
                $this->db->commit();
                return false;
            }
            $this->db->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$hash, (int)$user['id']]);
            $this->invalidate((int)$user['id']);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function profileActor(array $actor, $user): bool
    {
        return $user && (int)$user['is_active'] === 1 && in_array($user['role'], ['student', 'teacher'], true)
            && (int)($actor['id'] ?? 0) === (int)$user['id'] && ($actor['role'] ?? '') === $user['role']
            && (int)($actor['auth_version'] ?? 0) === (int)$user['auth_version'];
    }

    private function profilePurpose(string $purpose): bool
    {
        return in_array($purpose, ['profile_password', 'profile_email'], true);
    }

    private function profileContext(array $user, string $purpose, string $binding, string $nonce, string $email): string
    {
        return $this->digest('profile-context:' . json_encode([
            (int)$user['id'], $user['role'], (int)$user['auth_version'], strtolower($user['email']),
            $purpose, $binding, $nonce, $email,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * $actor must come from Auth::requireLogin(), never POST. $sessionBinding is session_id().
     * Both issuing and confirming require the current password. The returned id/nonce is
     * opaque SERVER SESSION state only; never put it in HTML, URLs or client-side storage.
     * No proposed email, password or OTP is stored in that state. Re-enter email on confirm.
     */
    public function requestProfile(array $actor, string $purpose, string $currentPassword, string $ip, string $sessionBinding, string $proposedEmail = ''): ?array
    {
        if (!$this->profilePurpose($purpose) || $sessionBinding === '' || $currentPassword === '' || strlen($currentPassword) > 72 || strpos($currentPassword, "\0") !== false) return null;
        $proposedEmail = strtolower(trim($proposedEmail));
        if ($purpose === 'profile_email' && (!filter_var($proposedEmail, FILTER_VALIDATE_EMAIL) || strlen($proposedEmail) > 100)) return null;
        if ($purpose === 'profile_password' && $proposedEmail !== '') return null;
        // Resolve the recipient on the server, then recheck it after obtaining the user lock.
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int)($actor['id'] ?? 0)]);
        $snapshot = $stmt->fetch();
        if (!$this->profileActor($actor, $snapshot)) return null;
        $email = strtolower($snapshot['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) return null;
        return $this->limitedRequest($email, $ip, function () use ($actor, $purpose, $currentPassword, $sessionBinding, $proposedEmail, $email) {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([(int)$actor['id']]);
            $user = $stmt->fetch();
            if (!$this->profileActor($actor, $user) || strtolower($user['email']) !== $email
                || !password_verify($currentPassword, $user['password_hash']) || $this->coolingDown((int)$user['id'])) return null;
            if ($purpose === 'profile_email') {
                if ($proposedEmail === $email) return null;
                $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$proposedEmail]);
                if ($stmt->fetchColumn()) return null;
            }
            $nonce = bin2hex(random_bytes(32));
            $context = $this->profileContext($user, $purpose, $sessionBinding, $nonce, $proposedEmail);
            $otp = (string)random_int(100000, 999999);
            $this->invalidate((int)$user['id']);
            $this->db->prepare('INSERT INTO password_reset_otps (user_id, otp_hash, purpose, context_hash, auth_version, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))')
                ->execute([(int)$user['id'], $this->digest('profile-otp:' . $context . ':' . $otp), $purpose, $context, (int)$user['auth_version']]);
            $id = (int)$this->db->lastInsertId();
            // SMTP failure rolls back the OTP, invalidation and shared rate-limit reservation.
            ($this->send)($email, $otp, $purpose);
            return ['id' => $id, 'nonce' => $nonce];
        });
    }

    /** $value is the new password or the proposed email. Returns a fresh safe user row on success. */
    public function verifyProfile(array $actor, array $challenge, string $purpose, string $otp, string $currentPassword, string $value, string $sessionBinding): ?array
    {
        if (!$this->profilePurpose($purpose) || $sessionBinding === '' || !is_int($challenge['id'] ?? null) || $challenge['id'] < 1
            || !is_string($challenge['nonce'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $challenge['nonce'])
            || !preg_match('/^[0-9]{6}$/D', $otp) || $currentPassword === '' || strlen($currentPassword) > 72 || strpos($currentPassword, "\0") !== false) return null;
        $email = $purpose === 'profile_email' ? strtolower(trim($value)) : '';
        if ($purpose === 'profile_email' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) return null;
        if ($purpose === 'profile_password' && (strlen($value) < 8 || strlen($value) > 72 || strpos($value, "\0") !== false)) return null;
        $newHash = $purpose === 'profile_password' ? password_hash($value, PASSWORD_DEFAULT) : null;
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([(int)($actor['id'] ?? 0)]);
            $user = $stmt->fetch();
            $stmt = $this->db->prepare('SELECT *, expires_at > NOW() AS valid_time FROM password_reset_otps WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$challenge['id'], (int)($user['id'] ?? 0)]);
            $record = $stmt->fetch();
            if (!$this->profileActor($actor, $user) || !$this->usable($record) || $record['purpose'] !== $purpose
                || (int)$record['auth_version'] !== (int)$user['auth_version'] || !is_string($record['context_hash'])
                || !hash_equals($record['context_hash'], $this->profileContext($user, $purpose, $sessionBinding, $challenge['nonce'], $email))) {
                $this->db->commit();
                return null;
            }
            if (!password_verify($currentPassword, $user['password_hash'])
                || !hash_equals($record['otp_hash'], $this->digest('profile-otp:' . $record['context_hash'] . ':' . $otp))) {
                $this->failedAttempt($challenge['id']);
                $this->db->commit();
                return null;
            }
            if ($purpose === 'profile_email') {
                if ($email === strtolower($user['email'])) {
                    $this->db->commit();
                    return null;
                }
                $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $stmt->execute([$email, (int)$user['id']]);
                if ($stmt->fetchColumn()) {
                    $this->db->commit();
                    return null;
                }
                $this->db->prepare('UPDATE users SET email = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$email, (int)$user['id']]);
                $user['email'] = $email;
            } else {
                $this->db->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$newHash, (int)$user['id']]);
            }
            $this->invalidate((int)$user['id']);
            $this->db->commit();
            $user['auth_version'] = (int)$user['auth_version'] + 1;
            unset($user['password_hash']);
            return $user;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
