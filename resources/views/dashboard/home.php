<?php
// UI sample only: no business database queries. Replace these samples when dashboard data is implemented.
$displayName = $user['full_name'] ?? $user['username'];
$receipts = [
    ['PN-2026-0048', 'Nhập kho', 'Thiết bị thông tin hàng hải', 'Kho trung tâm', '09/10/2026', '128.500.000', 'confirmed', 'Đã xác nhận'],
    ['PX-2026-0032', 'Xuất kho', 'Cấp phát vật tư bảo dưỡng', 'Kho Hải Phòng', '09/10/2026', '24.800.000', 'draft', 'Bản nháp'],
    ['PN-2026-0047', 'Nhập kho', 'Phụ tùng thay thế định kỳ', 'Kho Đà Nẵng', '08/10/2026', '46.200.000', 'confirmed', 'Đã xác nhận'],
    ['PX-2026-0031', 'Xuất kho', 'Điều chuyển thiết bị giữa kho', 'Kho trung tâm', '08/10/2026', '18.600.000', 'confirmed', 'Đã xác nhận'],
    ['PN-2026-0046', 'Nhập kho', 'Mua sắm công cụ, dụng cụ', 'Kho Hải Phòng', '07/10/2026', '12.400.000', 'cancelled', 'Đã hủy'],
];
$navigation = [
    ['box', 'Hàng hóa', [['Danh sách hàng hóa', ''], ['Vật tư', ''], ['Phụ tùng thay thế', ''], ['Công cụ, dụng cụ', ''], ['Tài sản cố định', ''], ['Hàng hóa khác', '']]],
    ['down', 'Phiếu nhập kho', [['Danh sách phiếu nhập', '#receipts'], ['Tạo phiếu nhập', '']]],
    ['up', 'Phiếu xuất kho', [['Danh sách phiếu xuất', '#receipts'], ['Tạo phiếu xuất', '']]],
    ['warehouse', 'Kho hàng', [['Danh sách kho', ''], ['Tồn kho', '']]],
    ['file', 'Quyết định', [['Danh sách quyết định', ''], ['Tăng vật tư', ''], ['Cấp phát vật tư', ''], ['Điều chuyển vật tư', ''], ['Giảm vật tư', '']]],
    ['chart', 'Báo cáo & thống kê', [['Nhập · xuất · tồn', ''], ['Báo cáo vật tư', ''], ['Báo cáo phụ tùng', ''], ['Báo cáo công cụ, dụng cụ', ''], ['Báo cáo tài sản cố định', '']]],
    ['users', 'Danh mục', [['Nhà cung cấp', ''], ['Nhân viên', '']]],
    ['shield', 'Hệ thống', [['Tài khoản & phân quyền', ''], ['Nhật ký thao tác', '']]],
];
$icons = [
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'chart' => '<path d="M4 4v16h16M8 15v-4m5 4V7m5 8v-6"/>',
    'box' => '<path d="m12 3 9 5v8l-9 5-9-5V8l9-5Zm-9 5 9 5 9-5m-9 5v8M7.5 5.5l9 5"/>',
    'down' => '<path d="M12 3v12m-5-5 5 5 5-5M4 15v5h16v-5"/>',
    'up' => '<path d="M12 15V3m-5 5 5-5 5 5M4 15v5h16v-5"/>',
    'warehouse' => '<path d="m3 9 9-6 9 6v12H3V9Zm4 12V11h10v10M7 15h10m-10 3h10"/>',
    'file' => '<path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8m-8 4h6"/>',
    'truck' => '<path d="M3 6h11v11H3V6Zm11 4h4l3 4v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'users' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-17a3 3 0 0 1 0 6m2 5a5 5 0 0 1 3 5"/>',
    'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Zm-4 9 3 3 5-6"/>',
    'history' => '<path d="M3 10a9 9 0 1 1 2 8M3 4v6h6m3-3v5l3 2"/>',
    'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
    'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
    'logout' => '<path d="M9 4H4v16h5m5-13 5 5-5 5M8 12h11"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 5m0 3h.01"/>',
    'chevron' => '<path d="m7 10 5 5 5-5"/>',
];
$icon = static fn(string $name): string => '<svg class="icon" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#29235c">
    <title>Trang chủ · Quản lý vật tư VISHIPEL</title>
    <link rel="icon" href="assets/images/company-logo.png" type="image/png">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <script src="assets/js/dashboard.js" defer></script>
</head>
<body>
<svg class="icon-definitions" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <?php foreach ($icons as $name => $paths): ?><symbol id="i-<?= $name ?>" viewBox="0 0 24 24"><?= $paths ?></symbol><?php endforeach; ?>
</svg>
<a class="skip-link" href="#main-content">Đến nội dung chính</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Menu chính">
        <div class="sidebar-brand"><a class="brand" href="index.php?page=home" aria-label="VISHIPEL — Trang chủ"><span class="brand-logo"><img src="assets/images/company-logo.png" alt="VISHIPEL" width="500" height="500"></span><span class="brand-copy"><small>HỆ THỐNG QUẢN LÝ</small><strong>Vật tư &amp; kho hàng</strong></span></a><button class="icon-button sidebar-close" type="button" aria-label="Đóng menu"><?= $icon('close') ?></button></div>
        <label class="sidebar-search"><?= $icon('search') ?><input id="navigation-search" type="search" placeholder="Tìm kiếm" aria-label="Tìm kiếm chức năng" aria-keyshortcuts="Control+/ Meta+/"><kbd>Ctrl + /</kbd></label>
        <nav class="navigation" aria-label="Điều hướng chính">
        <a class="nav-item active" href="#overview" aria-current="page" data-nav-home><?= $icon('grid') ?><span>Tổng quan</span></a>
        <?php foreach ($navigation as [$glyph, $group, $links]): ?>
            <details class="nav-group"<?= $group === 'Hàng hóa' ? ' open' : '' ?>>
                <summary class="nav-item"><?= $icon($glyph) ?><span><?= $group ?></span><span class="nav-chevron"><?= $icon('chevron') ?></span></summary>
                <div class="nav-children">
                <?php foreach ($links as [$label, $target]): ?>
                    <?php if ($target): ?><a class="nav-child" href="<?= $target ?>"><?= $label ?></a>
                    <?php else: ?><button class="nav-child" type="button" data-feature="<?= escapeHtml($label) ?>"><?= $label ?></button><?php endif; ?>
                <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <p class="nav-empty" role="status" hidden>Không tìm thấy chức năng phù hợp.</p>
        </nav>
        <div class="sidebar-bottom"><button class="nav-item" type="button" data-feature="Hướng dẫn sử dụng"><?= $icon('help') ?>Hướng dẫn sử dụng</button><div class="sidebar-account"><span class="avatar"><?= escapeHtml(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></span><div><strong><?= escapeHtml($displayName) ?></strong><small><?= (int) $user['is_super_admin'] === 1 ? 'Quản trị viên' : 'Nhân viên' ?></small></div><form action="index.php?page=logout" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><button class="icon-button" type="submit" aria-label="Đăng xuất" title="Đăng xuất"><?= $icon('logout') ?></button></form></div></div>
    </aside>
    <button class="menu-backdrop" type="button" aria-label="Đóng menu" hidden></button>
    <div class="content-shell">
        <header class="topbar"><div class="breadcrumb"><button id="menu-toggle" class="icon-button" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Mở menu"><?= $icon('menu') ?></button><span>Không gian làm việc</span><span class="breadcrumb-divider">/</span><strong>Trang chủ</strong></div><div class="topbar-right"><span class="internal-badge"><span class="live-dot"></span> Hệ thống nội bộ</span><span class="avatar avatar-small"><?= escapeHtml(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></span></div></header>
        <main id="main-content" tabindex="-1">
            <section class="page-heading" id="overview"><div><p class="eyebrow">TỔNG QUAN HOẠT ĐỘNG</p><h1>Trang chủ<span>.</span></h1><p>Xin chào, <?= escapeHtml($displayName) ?>. Cùng theo dõi hoạt động vật tư hôm nay.</p></div><div class="heading-actions"><button class="button" type="button" data-feature="Tạo phiếu xuất"><?= $icon('up') ?>Tạo phiếu xuất</button><button class="button button-primary" type="button" data-feature="Tạo phiếu nhập"><?= $icon('plus') ?>Tạo phiếu nhập</button></div></section>
            <div class="demo-note"><span class="demo-tag">Bản xem trước</span><span>Số liệu minh họa để xem giao diện, chưa phải dữ liệu thực tế.</span></div>
            <section class="metrics" aria-label="Số liệu tổng quan minh họa">
                <?php foreach ([['box','Hàng hóa','1.248','5 nhóm hàng hóa','purple'], ['down','Phiếu nhập tháng này','48','36 phiếu đã xác nhận','green'], ['up','Phiếu xuất tháng này','32','24 phiếu đã xác nhận','orange'], ['warehouse','Kho đang hoạt động','03','Theo dõi vật tư tại nhiều kho','blue']] as [$glyph,$label,$value,$detail,$tone]): ?>
                <article class="metric"><div class="metric-heading"><span><?= $label ?></span><span class="icon-tile <?= $tone ?>"><?= $icon($glyph) ?></span></div><strong class="metric-value"><?= $value ?></strong><p><?= $detail ?></p></article>
                <?php endforeach; ?>
            </section>
            <div class="overview-grid">
                <section class="card movement-card"><div class="card-heading"><div><h2>Hoạt động nhập · xuất</h2><p>Số phiếu theo tháng · Năm 2026</p></div><span class="subtle-badge">6 tháng gần nhất</span></div><div class="chart-legend"><span><i class="legend-dot purple-dot"></i>Phiếu nhập</span><span><i class="legend-dot orange-dot"></i>Phiếu xuất</span></div>
                    <svg class="movement-chart" viewBox="0 0 680 220" role="img" aria-labelledby="chart-title chart-desc"><title id="chart-title">Số phiếu nhập và xuất minh họa từ tháng 5 đến tháng 10 năm 2026</title><desc id="chart-desc">Nhập: 28, 35, 30, 42, 38, 48. Xuất: 18, 22, 26, 24, 30, 32.</desc><defs><linearGradient id="chart-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#7565b7" stop-opacity=".18"/><stop offset="100%" stop-color="#7565b7" stop-opacity="0"/></linearGradient></defs><g class="chart-grid"><path d="M42 20H660M42 60H660M42 100H660M42 140H660M42 180H660"/></g><g class="chart-labels"><text x="8" y="24">50</text><text x="8" y="64">40</text><text x="8" y="104">30</text><text x="8" y="144">20</text><text x="15" y="184">10</text></g><path d="M52 108 172 80 292 100 412 52 532 68 652 28V180H52Z" fill="url(#chart-fill)"/><path class="chart-line import-line" d="M52 108 172 80 292 100 412 52 532 68 652 28"/><path class="chart-line export-line" d="M52 148 172 132 292 116 412 124 532 100 652 92"/><g class="chart-points"><circle cx="52" cy="108" r="4"/><circle cx="172" cy="80" r="4"/><circle cx="292" cy="100" r="4"/><circle cx="412" cy="52" r="4"/><circle cx="532" cy="68" r="4"/><circle cx="652" cy="28" r="4"/></g><g class="chart-labels month-labels"><text x="52" y="211">Tháng 5</text><text x="172" y="211">Tháng 6</text><text x="292" y="211">Tháng 7</text><text x="412" y="211">Tháng 8</text><text x="532" y="211">Tháng 9</text><text x="652" y="211">Tháng 10</text></g></svg>
                </section>
                <section class="card category-card"><div class="card-heading"><div><h2>Cơ cấu hàng hóa</h2><p>Theo danh mục quản lý</p></div><?= $icon('box') ?></div><div class="category-body"><div class="donut" role="img" aria-label="Minh họa 1.248 hàng hóa: 45% vật tư, 25% phụ tùng, 15% công cụ dụng cụ, 10% tài sản cố định, 5% khác"><div><strong>1.248</strong><span>hàng hóa</span></div></div><ul class="category-list"><li><span><i class="legend-dot purple-dot"></i>Vật tư</span><strong>45%</strong></li><li><span><i class="legend-dot orange-dot"></i>Phụ tùng</span><strong>25%</strong></li><li><span><i class="legend-dot lilac-dot"></i>Công cụ, dụng cụ</span><strong>15%</strong></li><li><span><i class="legend-dot blue-dot"></i>Tài sản cố định</span><strong>10%</strong></li><li><span><i class="legend-dot gray-dot"></i>Khác</span><strong>5%</strong></li></ul></div></section>
            </div>
            <section class="card receipts-card" id="receipts"><div class="card-heading"><div><h2>Phiếu gần đây</h2><p>Theo dõi các phiếu nhập và xuất kho</p></div><span class="subtle-badge">Dữ liệu minh họa</span></div><div class="table-toolbar"><div class="receipt-tabs" role="group" aria-label="Loại phiếu"><button class="tab active" type="button" data-type="all" aria-pressed="true">Tất cả</button><button class="tab" type="button" data-type="Nhập kho" aria-pressed="false">Phiếu nhập</button><button class="tab" type="button" data-type="Xuất kho" aria-pressed="false">Phiếu xuất</button></div><div class="table-filters"><label class="search-field"><?= $icon('search') ?><input id="receipt-search" type="search" placeholder="Tìm mã phiếu, nội dung…" aria-label="Tìm phiếu minh họa"></label><select id="warehouse-filter" aria-label="Lọc phiếu theo kho"><option value="">Tất cả kho</option><option>Kho trung tâm</option><option>Kho Hải Phòng</option><option>Kho Đà Nẵng</option></select></div></div>
                <div class="table-scroll" tabindex="0" role="region" aria-label="Bảng phiếu gần đây, có thể cuộn ngang"><table><thead><tr><th>Mã phiếu / Nội dung</th><th>Loại phiếu</th><th>Kho</th><th>Ngày lập</th><th class="amount">Giá trị trước thuế</th><th>Trạng thái</th><th><span class="sr-only">Chi tiết</span></th></tr></thead><tbody>
                    <?php foreach ($receipts as [$number,$type,$description,$warehouse,$date,$amount,$status,$statusLabel]): ?><tr data-receipt data-type="<?= $type ?>" data-warehouse="<?= $warehouse ?>"><td><button class="receipt-link" type="button" data-feature="<?= $number ?>"><?= $number ?></button><small><?= $description ?></small></td><td><span class="type-label <?= $type === 'Nhập kho' ? 'incoming' : 'outgoing' ?>"><?= $icon($type === 'Nhập kho' ? 'down' : 'up') ?><?= $type ?></span></td><td><?= $warehouse ?></td><td><?= $date ?></td><td class="amount"><?= $amount ?> <small class="currency">₫</small></td><td><span class="status <?= $status ?>"><i></i><?= $statusLabel ?></span></td><td><button class="icon-button" type="button" data-feature="<?= $number ?>" aria-label="Xem phiếu <?= $number ?>"><?= $icon('arrow') ?></button></td></tr><?php endforeach; ?>
                </tbody></table></div><p class="empty-state" hidden>Không tìm thấy phiếu phù hợp. Hãy thử từ khóa hoặc kho khác.</p><div class="table-footer"><span id="receipt-count" role="status">Hiển thị 5 phiếu minh họa</span><span>Đơn vị tiền tệ: VND</span></div>
            </section>
            <section class="quick-links" aria-label="Truy cập nhanh"><button type="button" data-feature="Báo cáo nhập · xuất · tồn"><span class="icon-tile purple"><?= $icon('chart') ?></span><span><strong>Báo cáo nhập · xuất · tồn</strong><small>Tổng hợp hoạt động vật tư</small></span><?= $icon('arrow') ?></button><button type="button" data-feature="Quyết định vật tư"><span class="icon-tile orange"><?= $icon('file') ?></span><span><strong>Quyết định vật tư</strong><small>Theo dõi hồ sơ tăng, giảm, điều chuyển</small></span><?= $icon('arrow') ?></button><button type="button" data-feature="Nhật ký thao tác"><span class="icon-tile blue"><?= $icon('history') ?></span><span><strong>Nhật ký thao tác</strong><small>Tra cứu lịch sử hoạt động</small></span><?= $icon('arrow') ?></button></section>
            <footer class="page-footer"><span>© <?= date('Y') ?> VISHIPEL · Quản lý vật tư</span><span>Gọn gàng. Rõ ràng. Hiệu quả.</span></footer>
        </main>
    </div>
</div>
<dialog id="feature-dialog" aria-labelledby="feature-title"><form method="dialog"><button class="icon-button dialog-close" aria-label="Đóng"><?= $icon('close') ?></button><span class="icon-tile purple"><?= $icon('file') ?></span><h2 id="feature-title">Chức năng</h2><p>Phần này sẽ được xây dựng ở bước tiếp theo. Hiện bạn có thể xem tổng quan và thử tìm kiếm, lọc bảng phiếu minh họa.</p><button class="button button-primary" type="submit">Đã hiểu</button></form></dialog>
</body>
</html>
