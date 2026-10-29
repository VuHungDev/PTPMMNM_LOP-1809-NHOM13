</main>
<script src="<?= url('/frontend/assets/js/app.js') ?>"></script>
<script type="module">
    // Module dùng chung mọi trang đăng nhập: sidebar + giám sát phiên (khóa/xóa → đá ra).
    import { startSessionWatch } from '<?= url('/frontend/assets/js/modules/session-watch.js') ?>';
    if (document.getElementById('app-sidebar')) startSessionWatch();
</script>
<?= ($pageScripts ?? '') ?>
</body>
</html>
