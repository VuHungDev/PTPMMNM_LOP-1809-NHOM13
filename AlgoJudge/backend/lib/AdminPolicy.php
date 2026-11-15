<?php
/** Site-owner boundary. The primary ID is server configuration, never a role/session/POST flag. */
final class AdminPolicy
{
    public static function primaryId(): int
    {
        // The original installation's confirmed owner is users.id = 1 (username admin).
        // Other deployments may pin a different immutable ID in server-side .env/config.
        // Never elect MIN(id), the first active admin, or the currently logged-in admin.
        $value = getenv('AJ_PRIMARY_ADMIN_ID');
        if ($value === false || $value === '') $value = '1';
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? 0 : $id; // Invalid configuration fails closed; no automatic replacement.
    }

    public static function isPrimary(array $user): bool
    {
        $id = self::primaryId();
        return $id > 0 && (int)($user['id'] ?? 0) === $id && ($user['role'] ?? '') === 'admin';
    }

    public static function canManageAccount(array $actor, array $target): bool
    {
        $primaryId = self::primaryId();
        $actorId = (int)($actor['id'] ?? 0);
        $targetId = (int)($target['id'] ?? 0);
        if ($primaryId < 1 || $actorId < 1 || $targetId < 1 || ($actor['role'] ?? '') !== 'admin'
            || (isset($actor['is_active']) && (int)$actor['is_active'] !== 1)
            || $actorId === $targetId || $targetId === $primaryId) {
            return false;
        }
        // Delegated admins manage ordinary accounts, not their peers or the owner.
        return ($target['role'] ?? '') !== 'admin' || self::isPrimary($actor);
    }

    public static function assertCanManageAccount(array $actor, array $target): void
    {
        if (!self::canManageAccount($actor, $target)) {
            throw new DomainException('Không có quyền thay đổi tài khoản này. Admin chính được bảo vệ; chỉ admin chính được quản lý các admin phụ.');
        }
    }

    public static function assertCanAssignRole(array $actor, string $role): void
    {
        if (self::primaryId() < 1 || ($actor['role'] ?? '') !== 'admin'
            || (isset($actor['is_active']) && (int)$actor['is_active'] !== 1)
            || !in_array($role, ['student', 'teacher', 'admin'], true)) {
            throw new DomainException('Không có quyền cấp vai trò hoặc cấu hình admin chính không hợp lệ.');
        }
        if ($role === 'admin' && !self::isPrimary($actor)) {
            throw new DomainException('Chỉ admin chính được tạo hoặc cấp quyền admin phụ.');
        }
    }
}
