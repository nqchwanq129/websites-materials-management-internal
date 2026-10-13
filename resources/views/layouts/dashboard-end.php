            <footer class="page-footer"><span>© <?= date('Y') ?> VISHIPEL · Quản lý vật tư</span><span>Gọn gàng. Rõ ràng. Hiệu quả.</span></footer>
        </main>
    </div>
</div>
<dialog id="feature-dialog" aria-labelledby="feature-title"><form method="dialog"><button class="icon-button dialog-close" aria-label="Đóng"><?= $icon('close') ?></button><span class="icon-tile purple"><?= $icon('file') ?></span><h2 id="feature-title">Chức năng</h2><p>Phần này sẽ được xây dựng ở bước tiếp theo. Hiện bạn có thể xem tổng quan và thử tìm kiếm, lọc bảng phiếu minh họa.</p><button class="button button-primary" type="submit">Đã hiểu</button></form></dialog>
<?php if (in_array($activePage, ['employees', 'warehouses'], true)) require __DIR__ . '/delete-dialog.php'; ?>
<?php if (in_array($activePage, ['imports','import-create'], true)) require __DIR__ . '/import-image-dialog.php'; ?>
<?php require __DIR__ . '/profile-dialogs.php'; ?>
<section id="activity-panel" class="activity-panel" popover="auto" aria-labelledby="activity-title" data-user="<?= escapeHtml((string)$user['id']) ?>" data-preview="<?= ($previewOnly ?? false)?'true':'false' ?>">
    <input id="activity-csrf" name="csrf_token" type="hidden" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
    <header class="activity-heading"><div><h2 id="activity-title">Thông báo</h2><p id="activity-count" aria-live="polite">Đang tải…</p></div><button id="activity-sound" class="activity-sound" type="button" aria-pressed="true">Âm báo: Bật</button></header>
    <div class="activity-body"><p id="activity-error" class="activity-error" role="alert" hidden><span></span></p><div id="activity-list" role="list" aria-label="Thông báo chưa đọc"></div><p id="activity-empty" class="activity-empty">Không có thông báo chưa đọc.</p></div>
    <footer class="activity-footer"><button id="activity-more" class="activity-more" type="button" hidden>Hiện thêm thông báo cũ hơn</button><small>Thông báo đã xem sẽ rời danh sách này.</small><button id="activity-retry" class="activity-more" type="button">Làm mới</button></footer>
</section>
<div id="notification-center" class="sn-notifications-container" popover="manual" aria-label="Thông báo"></div>
</body>
</html>
