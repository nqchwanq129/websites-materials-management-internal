<?php
$assetUrl = static fn(string $path): string => $path . '?v=' . substr(hash_file('sha256', dirname(__DIR__, 3) . '/public/' . $path), 0, 12);
$displayName = $user['full_name'] ?? $user['username'];
$access = $access ?? ['admin'=>(int)$user['is_super_admin'] === 1, 'warehouses'=>[], 'warehouse_names'=>[]];
$topbarWarehouse = $access['admin'] ? 'Toàn hệ thống' : (count($access['warehouses']) > 1 ? count($access['warehouses']).' kho được cấp' : (array_values($access['warehouse_names'] ?? [])[0] ?? 'Chưa được cấp quyền kho'));
$topbarWarehouseHint = ((int)$user['is_super_admin'] === 1 ? 'Phạm vi quản trị: ' : 'Kho của bạn: ') . $topbarWarehouse;
$navigation = [
    ['box', 'Hàng hóa', array_map(static fn($label, $page) => [$label, 'index.php?page=' . $page], ['Danh sách hàng hóa', 'Vật tư', 'Phụ tùng thay thế', 'Công cụ, dụng cụ', 'Tài sản cố định', 'Hàng hóa khác'], ['products', 'materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products'])],
    ['down', 'Phiếu nhập kho', [['Danh sách phiếu nhập', 'index.php?page=imports'], ['Tạo phiếu nhập', 'index.php?page=import-create']]],
    ['up', 'Phiếu xuất kho', [['Danh sách phiếu xuất', 'index.php?page=exports'], ['Tạo phiếu xuất', 'index.php?page=export-create']]],
    ['warehouse', 'Kho hàng', [['Danh sách kho', 'index.php?page=warehouses'], ['Tồn kho', '']]],
    ['file', 'Quyết định', [['Danh sách quyết định', 'index.php?page=decisions']]],
    ['chart', 'Báo cáo & thống kê', array_map(static fn($label, $kind) => [$label, 'index.php?page=reports&kind=' . $kind], ['Nhập · xuất · tồn', 'Báo cáo vật tư', 'Báo cáo phụ tùng', 'Báo cáo công cụ, dụng cụ', 'Báo cáo tài sản cố định'], ['inventory', 'materials', 'parts', 'tools', 'assets'])],
    ['users', 'Danh mục', [['Nhà cung cấp', 'index.php?page=suppliers'], ['Nhân viên', 'index.php?page=employees']]],
    ['shield', 'Hệ thống', [['Tài khoản & phân quyền', 'index.php?page=accounts'], ['Nhật ký thao tác', 'index.php?page=audit']]],
];
if (!$access['admin']) {
    foreach ($navigation as &$group) {
        $group[2] = array_values(array_filter($group[2], static function ($link) use ($access): bool {
            parse_str((string)parse_url($link[1],PHP_URL_QUERY),$query);
            return isset($query['page']) && canAccessPage($access,$query['page']);
        }));
    } unset($group);
    $navigation = array_values(array_filter($navigation,static fn($group)=>(bool)$group[2]));
}
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
$activeNavTarget = $activeNavTarget ?? 'index.php?page=' . $activePage;
$openGroup = ['exports' => 'Phiếu xuất kho', 'export-create' => 'Phiếu xuất kho', 'import-create' => 'Phiếu nhập kho', 'imports' => 'Phiếu nhập kho', 'accounts' => 'Hệ thống', 'audit' => 'Hệ thống', 'employees' => 'Danh mục', 'suppliers' => 'Danh mục', 'decisions' => 'Quyết định', 'warehouses' => 'Kho hàng', 'reports' => 'Báo cáo & thống kê'][$activePage] ?? 'Hàng hóa';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#29235c">
    <title><?= escapeHtml($pageTitle) ?> · Quản lý vật tư VISHIPEL</title>
    <link rel="icon" href="assets/images/company-logo.png" type="image/png">
    <link rel="stylesheet" href="<?= $assetUrl('assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= $assetUrl('assets/vendor/simple-notify/simple-notify.css') ?>">
    <link rel="stylesheet" href="<?= $assetUrl('assets/css/notifications.css') ?>">
    <script src="<?= $assetUrl('assets/vendor/simple-notify/simple-notify.min.js') ?>" defer></script>
    <script src="<?= $assetUrl('assets/js/dashboard.js') ?>" defer></script>
    <link rel="stylesheet" href="<?= $assetUrl('assets/css/accounts.css') ?>">
    <script src="<?= $assetUrl('assets/js/profile.js') ?>" defer></script>
    <?php if (in_array($activePage, ['imports','import-create'], true)): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/import-images.css') ?>"><script src="<?= $assetUrl('assets/js/import-images.js') ?>" defer></script><?php endif; ?>
    <?php if (in_array($activePage, ['imports', 'import-create', 'export-create'], true)): ?><script src="<?= $assetUrl('assets/js/receipt-decimal.js') ?>" defer></script><?php endif; ?>
    <?php $isProductPage = in_array($activePage, ['products', 'materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products'], true); ?>
    <?php if ($isProductPage || in_array($activePage, ['export-create', 'exports', 'import-create', 'imports', 'accounts', 'employees', 'warehouses', 'suppliers', 'decisions', 'audit'], true)): ?><script src="<?= $assetUrl('assets/js/' . ($isProductPage ? 'products' : $activePage) . '.js') ?>" defer></script><?php endif; ?>
    <?php if (in_array($activePage, ['import-create', 'export-create'], true)): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/import-create.css') ?>"><script src="<?= $assetUrl('assets/js/receipt-print.js') ?>" defer></script><?php endif; ?>
    <?php if ($isProductPage || in_array($activePage, ['exports', 'imports', 'suppliers', 'decisions'], true)): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/audit.css') ?>"><?php endif; ?>
    <?php if ($isProductPage): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/products.css') ?>"><?php endif; ?>
    <?php if ($activePage === 'decisions'): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/decisions.css') ?>"><?php endif; ?>
    <?php if ($activePage === 'reports'): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/reports.css') ?>"><script src="<?= $assetUrl('assets/vendor/sheetjs/xlsx.mini.min.js') ?>" defer></script><script src="<?= $assetUrl('assets/js/reports.js') ?>" defer></script><?php endif; ?>
    <?php if ($activePage === 'audit'): ?><link rel="stylesheet" href="<?= $assetUrl('assets/css/audit.css') ?>"><?php endif; ?>
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
        <a class="nav-item<?= $activePage === 'home' ? ' active' : '' ?>" href="index.php?page=home#overview"<?= $activePage === 'home' ? ' aria-current="page"' : '' ?> data-nav-home><?= $icon('grid') ?><span>Tổng quan</span></a>
        <?php foreach ($navigation as [$glyph, $group, $links]): ?>
            <details class="nav-group"<?= $group === $openGroup ? ' open' : '' ?>>
                <summary class="nav-item"><?= $icon($glyph) ?><span><?= $group ?></span><span class="nav-chevron"><?= $icon('chevron') ?></span></summary>
                <div class="nav-children">
                <?php foreach ($links as [$label, $target]): ?>
                    <?php if ($target): ?><a class="nav-child<?= $target === $activeNavTarget ? ' active' : '' ?>" href="<?= escapeHtml($target) ?>"<?= $target === $activeNavTarget ? ' aria-current="page"' : '' ?>><?= $label ?></a>
                    <?php else: ?><button class="nav-child" type="button" data-feature="<?= escapeHtml($label) ?>"><?= $label ?></button><?php endif; ?>
                <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <p class="nav-empty" role="status" hidden>Không tìm thấy chức năng phù hợp.</p>
        </nav>
        <div class="sidebar-bottom"><button class="nav-item" type="button" data-feature="Hướng dẫn sử dụng"><?= $icon('help') ?>Hướng dẫn sử dụng</button><div class="sidebar-account"><span class="avatar" data-user-initial><?= escapeHtml(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></span><div><strong data-user-name><?= escapeHtml($displayName) ?></strong><small><?= $access['admin'] ? 'Quản trị viên' : (in_array('keeper', $access['warehouses'], true) ? 'Thủ kho' : ($access['warehouses'] ? 'Người xem' : 'Chưa phân quyền')) ?></small></div><form action="index.php?page=logout" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><button class="icon-button" type="submit" aria-label="Đăng xuất" title="Đăng xuất"><?= $icon('logout') ?></button></form></div></div>
    </aside>
    <button class="menu-backdrop" type="button" aria-label="Đóng menu" hidden></button>
    <div class="content-shell">
        <header class="topbar"><div class="breadcrumb"><button id="menu-toggle" class="icon-button" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Mở menu"><?= $icon('menu') ?></button><span>Không gian làm việc</span><span class="breadcrumb-divider">/</span><strong><?= escapeHtml($pageTitle) ?></strong></div><div class="topbar-right"><span class="internal-badge topbar-warehouse" title="<?= escapeHtml($topbarWarehouseHint) ?>"><?= $icon((int)$user['is_super_admin'] === 1 ? 'shield' : 'warehouse') ?><span class="topbar-warehouse-text"><?= escapeHtml($topbarWarehouse) ?></span></span><button id="user-menu-toggle" class="user-menu-toggle" type="button" popovertarget="user-menu" aria-label="Tài khoản của tôi" title="Tài khoản của tôi"><span class="avatar avatar-small" data-user-initial><?= escapeHtml(mb_strtoupper(mb_substr($displayName, 0, 1))) ?></span><?= $icon('chevron') ?></button></div></header>
        <main id="main-content" tabindex="-1">
