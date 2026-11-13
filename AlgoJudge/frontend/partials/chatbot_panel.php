<?php
// Shared, dependency-free chatbot. Identity comes from the authenticated PHP page.
if (empty($user) || !empty($chatbotPanelRendered)) {
    return;
}
$chatbotPanelRendered = true;
$chatbotFloating = ($chatbotVariant ?? 'floating') !== 'page';
$chatbotStaff = in_array($user['role'] ?? '', ['teacher', 'admin'], true);
?>
<link rel="stylesheet" href="<?= e(url('/frontend/assets/css/chatbot.css')) ?>?v=20261113-1">
<div class="aj-chatbot aj-chatbot--<?= $chatbotFloating ? 'floating' : 'page' ?>"
     data-chatbot data-staff="<?= $chatbotStaff ? '1' : '0' ?>" data-base-url="<?= e(url('')) ?>">
    <?php if ($chatbotFloating): ?>
    <button type="button" class="chatbot-launcher" data-chatbot-launcher
            aria-label="Mở trợ lý AlgoJudge" aria-controls="aj-chatbot-panel" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11.5a8 8 0 0 1-8 8H5l-3 2v-10a9 9 0 0 1 18 0Z"/><path d="M7 10h10M7 14h6"/></svg>
    </button>
    <?php endif; ?>
    <section id="aj-chatbot-panel" class="chatbot-panel" data-chatbot-panel
             <?= $chatbotFloating ? 'hidden role="dialog"' : 'role="region"' ?> aria-labelledby="aj-chatbot-heading">
        <header class="chatbot-header">
            <div>
                <h2 id="aj-chatbot-heading">Trợ lý AlgoJudge</h2>
                <p><?= $chatbotStaff ? 'Hướng dẫn · Tra cứu · Soạn bản nháp' : 'Hướng dẫn ứng dụng · Tra cứu dữ liệu của bạn' ?></p>
            </div>
            <?php if ($chatbotFloating): ?>
            <button type="button" class="chatbot-icon-button" data-chatbot-close aria-label="Đóng trợ lý">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
            <?php endif; ?>
        </header>
        <div class="chatbot-toolbar">
            <button type="button" class="chatbot-button chatbot-button--quiet" data-chatbot-restore>Khôi phục trạng thái</button>
            <?php if ($chatbotFloating && $chatbotStaff): ?>
            <a class="chatbot-link" href="<?= e(url('/frontend/instructor/ai_chat.php')) ?>">Mở trang soạn bài</a>
            <?php endif; ?>
        </div>
        <div class="chatbot-scroll" data-chatbot-scroll>
            <div class="chatbot-messages" data-chatbot-messages role="log" aria-label="Cuộc trò chuyện" aria-live="polite" aria-relevant="additions"></div>
            <div class="chatbot-notice" data-chatbot-notice role="alert" hidden>
                <p data-chatbot-notice-text></p>
                <ul data-chatbot-errors hidden></ul>
            </div>
            <div class="chatbot-recovery" data-chatbot-recovery hidden>
                <p>Nạp trạng thái máy chủ sẽ thay bản nháp đang sửa. Một bản sao các ô nhập hiện tại đã được giữ ngay bên dưới; hãy sao chép nếu cần.</p>
                <div class="chatbot-actions">
                    <button type="button" class="chatbot-button" data-chatbot-restore-confirm>Nạp trạng thái máy chủ</button>
                    <button type="button" class="chatbot-button chatbot-button--quiet" data-chatbot-restore-keep>Giữ bản đang sửa</button>
                </div>
            </div>
            <details class="chatbot-backups" data-chatbot-backups hidden>
                <summary>Bản sao chỉnh sửa trên máy</summary>
                <p>Chỉ được giữ trong trang này, không lưu vào bộ nhớ trình duyệt. Sao chép trước khi rời trang.</p>
                <div data-chatbot-backup-list></div>
            </details>
            <?php if ($chatbotStaff): ?>
            <section class="chatbot-staging" data-chatbot-staging aria-labelledby="aj-chatbot-staging-heading" hidden>
                <h3 id="aj-chatbot-staging-heading">Nội dung đang biên soạn</h3>
                <p data-chatbot-staging-note>Các phần nhận dần chỉ để theo dõi. Chưa thay thế bản nháp hiện hành và chưa thể lưu.</p>
                <div data-chatbot-staging-fields></div>
            </section>
            <section class="chatbot-draft" data-chatbot-draft aria-labelledby="aj-chatbot-draft-heading" hidden>
                <div class="chatbot-draft__heading">
                    <h3 id="aj-chatbot-draft-heading">Bản nháp hiện hành</h3>
                    <span class="chatbot-badge" data-chatbot-draft-revision></span>
                </div>
                <p class="chatbot-draft__status" data-chatbot-draft-status></p>
                <ul class="chatbot-issues" data-chatbot-issues hidden></ul>
                <form data-chatbot-draft-form novalidate>
                    <fieldset class="chatbot-fieldset" data-chatbot-draft-fields>
                        <legend class="chatbot-sr-only">Chỉnh sửa bản nháp bài tập</legend>
                        <div data-chatbot-draft-editor></div>
                    </fieldset>
                    <div class="chatbot-review" data-chatbot-review-area>
                        <label class="chatbot-check">
                            <input type="checkbox" data-chatbot-reviewed>
                            <span>Tôi đã đối chiếu yêu cầu, ví dụ và expected output của từng test sau chỉnh sửa.</span>
                        </label>
                        <p>Duyệt chỉ kiểm tra cấu trúc và tính nhất quán; không phải chứng minh đáp án thuật toán đúng.</p>
                        <div class="chatbot-actions">
                            <button type="button" class="chatbot-button" data-chatbot-sync>Đồng bộ chỉnh sửa</button>
                            <button type="button" class="chatbot-button" data-chatbot-review>Kiểm tra và duyệt chỉnh sửa</button>
                        </div>
                    </div>
                    <div class="chatbot-actions chatbot-draft__actions">
                        <button type="button" class="chatbot-button chatbot-button--primary" data-chatbot-save>Thêm bài</button>
                        <button type="button" class="chatbot-button chatbot-button--quiet" data-chatbot-cancel>Hủy bản nháp</button>
                    </div>
                    <div class="chatbot-cancel-confirm" data-chatbot-cancel-confirm hidden>
                        <p>Hủy bản nháp hiện tại và bỏ các chỉnh sửa chưa đồng bộ? Thao tác này không xóa bài tập đã tạo.</p>
                        <div class="chatbot-actions">
                            <button type="button" class="chatbot-button" data-chatbot-cancel-yes>Hủy bản nháp này</button>
                            <button type="button" class="chatbot-button chatbot-button--quiet" data-chatbot-cancel-no>Giữ bản nháp</button>
                        </div>
                    </div>
                </form>
                <div class="chatbot-draft__saved" data-chatbot-saved></div>
            </section>
            <?php endif; ?>
        </div>
        <div class="chatbot-status-row">
            <p class="chatbot-status" data-chatbot-status role="status" aria-live="polite">Sẵn sàng</p>
            <button type="button" class="chatbot-button chatbot-button--quiet" data-chatbot-stop hidden>Dừng chờ</button>
        </div>
        <form class="chatbot-composer" data-chatbot-composer>
            <label class="chatbot-sr-only" for="aj-chatbot-input">Tin nhắn cho trợ lý</label>
            <textarea id="aj-chatbot-input" data-chatbot-input rows="3" maxlength="12000"
                      placeholder="<?= $chatbotStaff ? 'Dán đề bài, yêu cầu sửa bản nháp hoặc hỏi về ứng dụng…' : 'Hỏi cách dùng ứng dụng hoặc tra cứu dữ liệu của bạn…' ?>"
                      aria-describedby="aj-chatbot-input-hint"></textarea>
            <div class="chatbot-composer__footer">
                <small id="aj-chatbot-input-hint">Enter để gửi · Shift+Enter xuống dòng</small>
                <button type="submit" class="chatbot-button chatbot-button--primary" data-chatbot-send>Gửi</button>
            </div>
        </form>
        <noscript><p class="chatbot-noscript">Cần bật JavaScript để sử dụng trợ lý.</p></noscript>
    </section>
</div>
<script src="<?= e(url('/frontend/assets/js/modules/chatbot.js')) ?>?v=20261113-1" defer></script>
