            <footer class="page-footer"><span>© <?= date('Y') ?> VISHIPEL · Quản lý vật tư</span><span>Gọn gàng. Rõ ràng. Hiệu quả.</span></footer>
        </main>
    </div>
</div>
<dialog id="feature-dialog" aria-labelledby="feature-title"><form method="dialog"><button class="icon-button dialog-close" aria-label="Đóng"><?= $icon('close') ?></button><span class="icon-tile purple"><?= $icon('file') ?></span><h2 id="feature-title">Chức năng</h2><p>Phần này sẽ được xây dựng ở bước tiếp theo. Hiện bạn có thể xem tổng quan và thử tìm kiếm, lọc bảng phiếu minh họa.</p><button class="button button-primary" type="submit">Đã hiểu</button></form></dialog>
<?php if (in_array($activePage, ['employees', 'warehouses'], true)) require __DIR__ . '/delete-dialog.php'; ?>
<?php require __DIR__ . '/profile-dialogs.php'; ?>
<div id="notification-center" class="sn-notifications-container" popover="manual" aria-label="Thông báo"></div>
</body>
</html>
