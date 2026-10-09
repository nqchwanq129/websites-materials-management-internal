<?php
// Sample employees only; no employee queries or writes.
$pageTitle = 'Nhân viên';
$activePage = 'employees';
$warehouses = ['central' => 'Kho trung tâm', 'haiphong' => 'Kho Hải Phòng', 'danang' => 'Kho Đà Nẵng'];
$sampleEmployees = [
    ['Nguyễn Minh Anh', 'central', 'active', 'minhanh'],
    ['Trần Quốc Huy', 'haiphong', 'active', 'quochuy'],
    ['Lê Hoàng Nam', 'danang', 'active', 'hoangnam'],
    ['Phạm Thu Hà', 'central', 'active', 'thuha'],
    ['Đỗ Thanh Tùng', 'central', 'inactive', 'thanhtung'],
    ['Vũ Ngọc Linh', 'haiphong', 'active', ''],
    ['Hoàng Đức Long', 'danang', 'active', ''],
    ['Bùi Mai Phương', 'central', 'active', ''],
    ['Đặng Quang Hải', 'haiphong', 'active', ''],
    ['Đinh Thảo Nguyên', 'danang', 'active', ''],
    ['Ngô Tuấn Kiệt', 'central', 'active', ''],
    ['Phan Hồng Nhung', 'haiphong', 'inactive', ''],
];
$employees = [];
foreach ($sampleEmployees as $index => [$name, $warehouse, $status, $account]) {
    $employees[] = ['code' => 'NV' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'full_name' => $name,
        'warehouse' => $warehouse, 'status' => $status, 'account' => $account,
        'email' => ($account ?: 'nhanvien' . ($index + 1)) . '@example.com', 'phone' => ''];
}
require dirname(__DIR__) . '/layouts/dashboard-start.php';
?>
<section class="page-heading"><div><p class="eyebrow">DANH MỤC NHÂN SỰ</p><h1>Nhân viên<span>.</span></h1><p>Quản lý thông tin nhân viên và kho làm việc.</p></div><button class="button button-primary" id="create-employee" type="button"><?= $icon('plus') ?>Thêm nhân viên</button></section>
<div class="demo-note"><span class="demo-tag">Bản xem trước</span><span>Thông tin nhân viên bên dưới là dữ liệu mẫu. Các thao tác chưa lưu vào hệ thống.</span></div>
<p id="employee-notice" class="account-notice" role="status" hidden></p>
<section class="metrics" aria-label="Tổng quan nhân viên minh họa">
<?php foreach ([['users', 'Tổng nhân viên', '12', 'Hồ sơ nhân viên minh họa', 'purple'], ['users', 'Đang hoạt động', '10', 'Nhân viên đang làm việc', 'green'], ['users', 'Ngừng hoạt động', '02', 'Giữ lại hồ sơ và lịch sử', 'orange'], ['warehouse', 'Kho làm việc', '03', 'Nhân viên được gắn với kho', 'blue']] as [$glyph, $label, $value, $description, $tone]): ?>
<article class="metric"><div class="metric-heading"><span><?= $label ?></span><span class="icon-tile <?= $tone ?>"><?= $icon($glyph) ?></span></div><strong class="metric-value"><?= $value ?></strong><p><?= $description ?></p></article>
<?php endforeach; ?>
</section>
<section class="card" aria-labelledby="employees-list-title">
    <div class="card-heading"><div><h2 id="employees-list-title">Danh sách nhân viên</h2><p>Thông tin liên hệ, kho làm việc và tài khoản liên kết</p></div><span class="subtle-badge">12 nhân viên mẫu</span></div>
    <div class="table-toolbar account-toolbar"><label class="search-field"><?= $icon('search') ?><input id="employee-search" type="search" placeholder="Tìm mã, tên, email…" aria-label="Tìm nhân viên"></label><div class="table-filters"><select id="employee-status" aria-label="Lọc trạng thái nhân viên"><option value="">Tất cả trạng thái</option><option value="active">Đang hoạt động</option><option value="inactive">Ngừng hoạt động</option></select><select id="employee-warehouse" aria-label="Lọc kho làm việc"><option value="">Tất cả kho</option><?php foreach ($warehouses as $key => $name): ?><option value="<?= $key ?>"><?= $name ?></option><?php endforeach; ?></select><select id="employee-account" aria-label="Lọc tài khoản liên kết"><option value="">Tất cả tài khoản</option><option value="linked">Đã liên kết tài khoản</option><option value="unlinked">Chưa liên kết tài khoản</option></select></div></div>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Danh sách nhân viên, có thể cuộn ngang"><table class="accounts-table"><thead><tr><th>Nhân viên</th><th>Thông tin liên hệ</th><th>Kho làm việc</th><th>Tài khoản liên kết</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
    <?php foreach ($employees as $employee): ?>
    <tr data-employee="<?= escapeHtml(json_encode($employee, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>"><td><div class="account-identity"><span class="avatar"><?= escapeHtml(mb_substr($employee['full_name'], 0, 1)) ?></span><div><strong><?= escapeHtml($employee['full_name']) ?></strong><small><?= escapeHtml($employee['code']) ?></small></div></div></td><td><?= escapeHtml($employee['email']) ?><small><?= $employee['phone'] ? escapeHtml($employee['phone']) : 'Chưa cập nhật số điện thoại' ?></small></td><td><?= $warehouses[$employee['warehouse']] ?></td><td><?php if ($employee['account']): ?><span class="role-pill viewer"><?= escapeHtml($employee['account']) ?></span><?php else: ?><span class="employee-unlinked">Chưa liên kết</span><?php endif; ?></td><td><span class="status <?= $employee['status'] === 'active' ? 'confirmed' : 'cancelled' ?>"><i></i><?= $employee['status'] === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động' ?></span></td><td><div class="account-actions"><button class="button edit-employee" type="button">Chỉnh sửa</button><button class="icon-button employee-state-preview" type="button" aria-label="<?= $employee['status'] === 'active' ? 'Ngừng hoạt động' : 'Kích hoạt' ?> nhân viên <?= escapeHtml($employee['full_name']) ?>" title="<?= $employee['status'] === 'active' ? 'Ngừng hoạt động' : 'Kích hoạt nhân viên' ?>"><?= $icon('shield') ?></button></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div><p class="empty-state employee-empty" hidden>Không tìm thấy nhân viên phù hợp. Hãy thử bộ lọc khác.</p><div class="table-footer account-table-footer"><span id="employee-count" role="status">Hiển thị 1–10 / 12 nhân viên mẫu</span><nav class="account-pagination" aria-label="Phân trang nhân viên"><span>10 nhân viên / trang</span><button id="employee-page-previous" class="button" type="button" aria-label="Trang trước" disabled>Trước</button><span id="employee-page-label" aria-live="polite">1 / 2</span><button id="employee-page-next" class="button" type="button" aria-label="Trang sau">Sau</button></nav></div>
</section>
<dialog id="employee-dialog" class="account-dialog" aria-labelledby="employee-dialog-title"><form id="employee-form" method="dialog">
    <header class="account-dialog-heading"><div><span class="eyebrow">DANH MỤC NHÂN VIÊN</span><h2 id="employee-dialog-title">Thêm nhân viên</h2><p>Thông tin cá nhân, liên hệ và kho làm việc.</p></div><button class="icon-button close-employee-dialog" type="button" aria-label="Đóng"><?= $icon('close') ?></button></header>
    <div class="account-dialog-body"><section><h3>Thông tin nhân viên</h3><div class="account-form-grid"><label>Mã nhân viên <span>*</span><input id="edit-employee-code" name="code" required maxlength="32" placeholder="Ví dụ: NV013" autocomplete="off"></label><label>Họ và tên <span>*</span><input id="edit-employee-name" name="full_name" required maxlength="200" placeholder="Nhập họ và tên" autocomplete="name"></label><label>Email<input id="edit-employee-email" name="email" type="email" maxlength="254" placeholder="ten@example.com" autocomplete="email"></label><label>Số điện thoại<input id="edit-employee-phone" name="phone" type="tel" maxlength="32" placeholder="Nhập số điện thoại" autocomplete="tel"></label><label>Kho làm việc <span>*</span><select id="edit-employee-warehouse" name="warehouse" required><option value="">Chọn kho làm việc</option><?php foreach ($warehouses as $key => $name): ?><option value="<?= $key ?>"><?= $name ?></option><?php endforeach; ?></select></label><label>Trạng thái<select id="edit-employee-status" name="status"><option value="active">Đang hoạt động</option><option value="inactive">Ngừng hoạt động</option></select></label></div></section>
    <section class="account-permissions"><h3>Tài khoản liên kết</h3><div class="employee-account-note"><span class="icon-tile purple"><?= $icon('users') ?></span><div><strong id="employee-linked-account">Chưa liên kết tài khoản</strong><p>Nhân viên và tài khoản đăng nhập được quản lý riêng. Liên kết tài khoản và phân quyền tại mục Tài khoản &amp; phân quyền.</p></div></div><p class="employee-inactive-note" id="employee-inactive-note" hidden>Nhân viên ngừng hoạt động sẽ không được đăng nhập bằng tài khoản liên kết. Hồ sơ và lịch sử vẫn được giữ lại.</p></section><p class="form-preview-note">Bản xem trước: thông tin nhập vào chưa được gửi hoặc lưu vào hệ thống.</p></div>
    <footer class="account-dialog-footer"><button class="button close-employee-dialog" type="button">Hủy</button><button class="button button-primary" type="submit">Lưu (bản xem trước)</button></footer>
</form></dialog>
<dialog id="employee-state-dialog" aria-labelledby="employee-state-title"><form method="dialog"><span class="icon-tile orange"><?= $icon('users') ?></span><h2 id="employee-state-title">Trạng thái nhân viên</h2><p id="employee-state-description"></p><p>Đây là giao diện mẫu. Xác nhận chỉ xem trước thao tác, không thay đổi thông tin nhân viên thực tế.</p><div class="state-dialog-actions"><button class="button" value="cancel">Hủy</button><button class="button button-primary" value="preview">Xác nhận (xem trước)</button></div></form></dialog>
<?php require dirname(__DIR__) . '/layouts/dashboard-end.php'; ?>
