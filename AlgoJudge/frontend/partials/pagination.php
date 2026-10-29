<?php
// frontend/partials/pagination.php — Thanh phân trang dùng chung
// Biến đầu vào: $page (int, trang hiện tại), $totalPages (int), $baseQuery (array giữ filter)
if (!isset($page, $totalPages) || $totalPages <= 1) {
    return;
}
$baseQuery = $baseQuery ?? [];
$q = function (int $p) use ($baseQuery): string {
    return '?' . http_build_query(array_merge($baseQuery, ['page' => $p]));
};
$window = 2; // số trang hiển thị quanh trang hiện tại
$from = max(1, $page - $window);
$to = min($totalPages, $page + $window);
?>
<div style="display:flex; gap:6px; align-items:center; justify-content:center; padding:16px; flex-wrap:wrap;">
    <?php if ($page > 1): ?>
        <a class="btn" style="background:#e2e8f0; color:#334155; padding:6px 12px;" href="<?= e($q(1)) ?>">« Đầu</a>
        <a class="btn" style="background:#e2e8f0; color:#334155; padding:6px 12px;" href="<?= e($q($page - 1)) ?>">‹ Trước</a>
    <?php endif; ?>
    <?php if ($from > 1): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
    <?php for ($i = $from; $i <= $to; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="btn btn-primary" style="padding:6px 12px;"><?= $i ?></span>
        <?php else: ?>
            <a class="btn" style="background:#e2e8f0; color:#334155; padding:6px 12px;" href="<?= e($q($i)) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    <?php if ($to < $totalPages): ?><span style="color:#94a3b8;">…</span><?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a class="btn" style="background:#e2e8f0; color:#334155; padding:6px 12px;" href="<?= e($q($page + 1)) ?>">Sau ›</a>
        <a class="btn" style="background:#e2e8f0; color:#334155; padding:6px 12px;" href="<?= e($q($totalPages)) ?>">Cuối »</a>
    <?php endif; ?>
    <span style="font-size:0.85rem; color:var(--cl-muted); margin-left:8px;">Trang <?= $page ?> / <?= $totalPages ?></span>
</div>
