<?php
// frontend/partials/sidebar.php — Menu điều hướng dọc bên trái (nhóm chức năng + menu con xổ xuống).
// Biến đầu vào: $user (array), $activeNav (string)
// Hành vi: thanh menu tự thu gọn còn icon khi người dùng chọn một chức năng, rê chuột vào thì bung đầy đủ.

if (!function_exists('sbIcon')) {
    function sbIcon(string $name): string
    {
        $paths = [
            'brand'    => '<path d="M9 8l-4 4 4 4"/><path d="M15 8l4 4-4 4"/>',
            'list'     => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
            'trophy'   => '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h2a2 2 0 0 1 0 4h-2"/><path d="M7 5H5a2 2 0 0 0 0 4h2"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'teach'    => '<path d="M4 5a2 2 0 0 1 2-2h11v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h6"/><path d="M8 11h6"/>',
            'layers'   => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
            'inbox'    => '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M4 13V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v7"/><path d="M4 13v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>',
            'chart'    => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-6"/><path d="M22 20H2"/>',
            'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
            'chevron'  => '<path d="M6 9l6 6 6-6"/>',
            'pin'      => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
            'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            'play'     => '<circle cx="12" cy="12" r="10"/><path d="M10 8l6 4-6 4V8z"/>',
        ];
        $body = $paths[$name] ?? $paths['list'];
        return '<svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
             . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    }
}

$sbRole = $user['role'] ?? 'user';

$sbGroups = [
    [
        'key'    => 'train',
        'label'  => 'Luyện tập',
        'icon'   => 'brand',
        'active' => ['train', 'rank', 'subs', 'profile', 'play'],
        'items'  => [
            ['nav' => 'train',   'href' => '/frontend/index.php',       'label' => 'Danh sách bài tập',  'icon' => 'list'],
            ['nav' => 'play',    'href' => '/frontend/playground.php',  'label' => 'Sân chơi tự do',     'icon' => 'play'],
            ['nav' => 'rank',    'href' => '/frontend/leaderboard.php', 'label' => 'Bảng xếp hạng',      'icon' => 'trophy'],
            ['nav' => 'subs',    'href' => '/frontend/submissions.php', 'label' => 'Lịch sử nộp bài',    'icon' => 'clock'],
            ['nav' => 'profile', 'href' => '/frontend/profile.php',     'label' => 'Hồ sơ & điểm XP',    'icon' => 'user'],
        ],
    ],
    [
        'key'    => 'teach',
        'label'  => 'Quản lý giảng dạy',
        'icon'   => 'teach',
        'active' => ['probs', 'prob_new', 'prob_ai', 'data', 'rejudge', 'queue', 'prog'],
        'roles'  => ['teacher', 'admin'],
        'items'  => [
            ['nav' => 'probs',    'href' => '/frontend/instructor/problems.php',         'label' => 'Danh sách bài tập',    'icon' => 'layers'],
            ['nav' => 'prob_new', 'href' => '/frontend/instructor/problem_form.php',    'label' => 'Thêm bài tập',         'icon' => 'teach'],
            ['nav' => 'prob_ai',  'href' => '/frontend/instructor/ai_chat.php',         'label' => 'AI tạo bài tập',       'icon' => 'brand'],
            ['nav' => 'data',     'href' => '/frontend/instructor/dataset_versions.php', 'label' => 'Bộ test & phiên bản',  'icon' => 'chart'],
            ['nav' => 'rejudge',  'href' => '/frontend/instructor/rejudge.php',        'label' => 'Chấm lại & đối chiếu', 'icon' => 'chart'],
            ['nav' => 'queue',    'href' => '/frontend/instructor/queue.php',            'label' => 'Hàng đợi chấm',        'icon' => 'inbox'],
            ['nav' => 'prog',     'href' => '/frontend/instructor/progress.php',        'label' => 'Tiến độ học viên',     'icon' => 'user'],
        ],
    ],
    [
        'key'    => 'admin',
        'label'  => 'Quản trị hệ thống',
        'icon'   => 'shield',
        'active' => ['admin', 'admin_new'],
        'roles'  => ['admin'],
        'items'  => [
            ['nav' => 'admin',     'href' => '/frontend/admin/users.php',     'label' => 'Danh sách tài khoản', 'icon' => 'shield'],
            ['nav' => 'admin_new', 'href' => '/frontend/admin/user_form.php', 'label' => 'Tạo tài khoản',        'icon' => 'shield'],
        ],
    ],
];
?>
<aside id="app-sidebar" class="sb" aria-label="Menu chính">
    <div class="sb-top">
        <a href="<?= url('/frontend/index.php') ?>" class="sb-brand" title="AlgoJudge">
            <?php $logoSize = 30; require __DIR__ . '/logo.php'; ?>
        </a>
        <button type="button" class="sb-pin" id="sbPin" title="Ghim mở rộng menu" aria-label="Ghim mở rộng menu">
            <?= sbIcon('pin') ?>
        </button>
    </div>

    <nav class="sb-nav">
        <?php foreach ($sbGroups as $g):
            if (isset($g['roles']) && !in_array($sbRole, $g['roles'], true)) continue;
            $isOpen = in_array($activeNav ?? '', $g['active'], true);
        ?>
        <div class="sb-group <?= $isOpen ? 'open' : '' ?>" data-key="<?= e($g['key']) ?>">
            <button type="button" class="sb-item sb-parent <?= $isOpen ? 'is-active' : '' ?>"
                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" title="<?= e($g['label']) ?>">
                <?= sbIcon($g['icon']) ?>
                <span class="sb-label"><?= e($g['label']) ?></span>
                <span class="sb-chev"><?= sbIcon('chevron') ?></span>
            </button>
            <div class="sb-sub">
                <div class="sb-sub-inner">
                    <?php foreach ($g['items'] as $it): ?>
                    <a class="sb-link <?= ($activeNav ?? '') === $it['nav'] ? 'active' : '' ?>"
                       href="<?= url($it['href']) ?>" data-nav="<?= e($it['nav']) ?>" title="<?= e($it['label']) ?>">
                        <span class="sb-dot"></span>
                        <span class="sb-label"><?= e($it['label']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </nav>

    <div class="sb-foot">
        <a href="<?= url('/frontend/profile.php') ?>" class="sb-user" title="<?= e($user['full_name']) ?>">
            <span class="sb-avatar"><?= e(mb_strtoupper(mb_substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
            <span class="sb-label sb-user-text">
                <strong><?= e($user['full_name']) ?></strong>
                <small><?= e($user['role']) ?> · Lớp <?= e($user['class_code']) ?></small>
            </span>
        </a>
        <a href="<?= url('/frontend/auth/logout.php') ?>" class="sb-item sb-logout" title="Đăng xuất">
            <?= sbIcon('logout') ?>
            <span class="sb-label">Đăng xuất</span>
        </a>
    </div>
</aside>
