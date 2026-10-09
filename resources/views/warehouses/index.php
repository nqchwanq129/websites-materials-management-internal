<?php
$pageTitle = 'Kho hàng'; $activePage = 'warehouses';
$previewOnly = $previewOnly ?? false;
$records = $managementData['warehouseRecords'];
require dirname(__DIR__) . '/layouts/dashboard-start.php';
?>
<section class="page-heading"><div><p class="eyebrow">QUẢN LÝ KHO HÀNG</p><h1>Kho hàng<span>.</span></h1><p>Quản lý các kho, nơi làm việc và phạm vi truy cập.</p></div><button class="button button-primary" id="create-warehouse" type="button"><?= $icon('plus') ?>Thêm kho</button></section>
<?php if ($previewOnly): ?><div class="demo-note"><span class="demo-tag">Bản xem trước</span><span>Danh sách kho bên dưới là dữ liệu mẫu. Các thao tác chưa lưu vào hệ thống.</span></div><?php endif; ?>
<p id="warehouse-notice" data-management-flash class="account-notice" role="status"<?= empty($managementNotice) ? ' hidden' : '' ?>><?= escapeHtml($managementNotice ?? '') ?></p>
<section class="metrics" aria-label="Tổng quan kho hàng">
<?php foreach ([['warehouse', 'Tổng số kho', count($records), 'Kho trong hệ thống', 'purple'], ['warehouse', 'Đang hoạt động', count(array_filter($records, static fn($w) => $w['status'] === 'active')), 'Có thể cấp quyền và gắn nhân viên', 'green'], ['warehouse', 'Ngừng hoạt động', count(array_filter($records, static fn($w) => $w['status'] === 'inactive')), 'Giữ lại hồ sơ và lịch sử', 'orange'], ['users', 'Nhân viên đang làm việc', array_sum(array_column($records, 'active_employee_count')), 'Nhân viên đang hoạt động tại các kho', 'blue']] as [$glyph, $label, $value, $description, $tone]): ?>
<article class="metric"><div class="metric-heading"><span><?= $label ?></span><span class="icon-tile <?= $tone ?>"><?= $icon($glyph) ?></span></div><strong class="metric-value"><?= $value ?></strong><p><?= $description ?></p></article>
<?php endforeach; ?>
</section>
<section class="card" aria-labelledby="warehouses-list-title">
    <div class="card-heading"><div><h2 id="warehouses-list-title">Danh sách kho</h2><p>Thông tin kho, nhân viên và quyền truy cập riêng theo kho</p></div><span class="subtle-badge"><?= count($records) ?> kho</span></div>
    <div class="table-toolbar account-toolbar"><label class="search-field"><?= $icon('search') ?><input id="warehouse-search" type="search" placeholder="Tìm mã kho, tên hoặc địa chỉ…" aria-label="Tìm kho hàng"></label><div class="table-filters"><select id="warehouse-status" aria-label="Lọc trạng thái kho"><option value="">Tất cả trạng thái</option><option value="active">Đang hoạt động</option><option value="inactive">Ngừng hoạt động</option></select></div></div>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Danh sách kho, có thể cuộn ngang"><table class="accounts-table"><thead><tr><th>Kho hàng</th><th>Địa chỉ</th><th>Nhân viên</th><th>Quyền truy cập riêng</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
    <?php foreach ($records as $record): ?>
    <tr data-warehouse="<?= escapeHtml(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"><td><div class="account-identity"><span class="icon-tile purple"><?= $icon('warehouse') ?></span><div><strong><?= escapeHtml($record['name']) ?></strong><small><?= escapeHtml($record['code']) ?></small></div></div></td><td><?= $record['address'] !== '' ? escapeHtml($record['address']) : 'Chưa cập nhật địa chỉ' ?></td><td><strong><?= (int) $record['employee_count'] ?> nhân viên</strong><small><?= (int) $record['active_employee_count'] ?> đang hoạt động</small></td><td><span class="role-pill viewer"><?= (int) $record['access_count'] ?> tài khoản</span><small>Không gồm quản trị viên toàn hệ thống</small></td><td><span class="status <?= $record['status'] === 'active' ? 'confirmed' : 'cancelled' ?>"><i></i><?= $record['status'] === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động' ?></span></td><td><div class="account-actions"><button class="button edit-warehouse" type="button">Chỉnh sửa</button><button class="icon-button warehouse-state" type="button" aria-label="<?= $record['status'] === 'active' ? 'Ngừng hoạt động' : 'Kích hoạt' ?> kho <?= escapeHtml($record['name']) ?>" title="<?= $record['status'] === 'active' ? 'Ngừng hoạt động kho' : 'Kích hoạt kho' ?>"><?= $icon('shield') ?></button><button class="button button-danger" type="button" data-delete-record aria-label="Xóa <?= escapeHtml($record['name']) ?>">Xóa</button></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="empty-state warehouse-empty" hidden><?= $records ? 'Không tìm thấy kho phù hợp. Hãy thử bộ lọc khác.' : 'Chưa có kho nào. Chọn “Thêm kho” để tạo kho đầu tiên.' ?></p>
    <div class="table-footer account-table-footer"><span id="warehouse-count" role="status">Hiển thị <?= min(10, count($records)) ?> / <?= count($records) ?> kho</span><nav class="account-pagination" aria-label="Phân trang kho"><span>10 kho / trang</span><button id="warehouse-page-previous" class="button" type="button" aria-label="Trang trước" disabled>Trước</button><span id="warehouse-page-label" aria-live="polite">1 / <?= max(1, (int) ceil(count($records) / 10)) ?></span><button id="warehouse-page-next" class="button" type="button" aria-label="Trang sau"<?= count($records) <= 10 ? ' disabled' : '' ?>>Sau</button></nav></div>
</section>
<dialog id="warehouse-dialog" class="account-dialog" aria-labelledby="warehouse-dialog-title">
<form id="warehouse-form" action="index.php?page=warehouses" method="post" data-preview="<?= $previewOnly ? 'true' : 'false' ?>">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id"><input type="hidden" name="version">
    <header class="account-dialog-heading"><div><span class="eyebrow">QUẢN LÝ KHO HÀNG</span><h2 id="warehouse-dialog-title">Thêm kho</h2><p>Thông tin kho và trạng thái sử dụng trong hệ thống.</p></div><button class="icon-button close-warehouse-dialog" type="button" aria-label="Đóng"><?= $icon('close') ?></button></header>
    <div class="account-dialog-body"><p class="form-error" data-form-error role="alert" hidden></p><section><h3>Thông tin kho</h3><div class="account-form-grid">
        <label>Mã kho <span>*</span><input name="code" required maxlength="32" placeholder="Ví dụ: KHO01" autocomplete="off"></label>
        <label>Tên kho <span>*</span><input name="name" required maxlength="200" placeholder="Nhập tên kho" autocomplete="off"></label>
        <label>Địa chỉ<input name="address" maxlength="500" placeholder="Nhập địa chỉ kho" autocomplete="off"></label>
        <label>Trạng thái<select name="status"><option value="active">Đang hoạt động</option><option value="inactive">Ngừng hoạt động</option></select></label>
    </div></section>
    <section class="account-permissions"><h3>Nhân viên &amp; quyền truy cập</h3><div class="employee-account-note"><span class="icon-tile purple"><?= $icon('users') ?></span><div><strong id="warehouse-linked-summary">Kho mới chưa có nhân viên hoặc quyền truy cập riêng.</strong><p>Gắn kho làm việc tại mục Nhân viên. Cấp quyền Thủ kho hoặc Chỉ xem tại mục Tài khoản &amp; phân quyền.</p></div></div><p class="employee-inactive-note" id="warehouse-inactive-note" hidden>Kho ngừng hoạt động sẽ không được truy cập, gắn nhân viên mới hoặc cấp quyền mới. Hồ sơ, quyền đã gán và lịch sử vẫn được giữ lại.</p></section>
    <p class="form-preview-note">Mã kho phải duy nhất. Mỗi thay đổi được lưu cùng nhật ký thao tác.</p></div>
    <footer class="account-dialog-footer"><button class="button close-warehouse-dialog" type="button">Hủy</button><button class="button button-primary" type="submit">Lưu</button></footer>
</form></dialog>
<dialog id="warehouse-state-dialog" class="account-dialog" aria-labelledby="warehouse-state-title">
<form id="warehouse-state-form" method="post" action="index.php?page=warehouses" data-preview="<?= $previewOnly ? 'true' : 'false' ?>">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="state"><input type="hidden" name="id"><input type="hidden" name="version"><input type="hidden" name="status">
    <header class="account-dialog-heading"><div><span class="eyebrow">QUẢN LÝ KHO HÀNG</span><h2 id="warehouse-state-title">Trạng thái kho</h2><p id="warehouse-state-description"></p></div><button class="icon-button" data-close-state type="button" aria-label="Đóng"><?= $icon('close') ?></button></header>
    <div class="account-dialog-body"><p class="form-error" data-form-error role="alert" hidden></p><p>Ngừng hoạt động sẽ chặn truy cập kho, gắn nhân viên mới và cấp quyền mới. Kích hoạt lại sẽ khôi phục khả năng truy cập theo quyền đã gán. Hồ sơ và lịch sử được giữ lại.</p></div>
    <footer class="account-dialog-footer"><button class="button" data-close-state type="button">Hủy</button><button class="button button-primary" type="submit">Xác nhận</button></footer>
</form></dialog>
<?php require dirname(__DIR__) . '/layouts/dashboard-end.php'; ?>
