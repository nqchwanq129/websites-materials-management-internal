<?php
declare(strict_types=1);
// Render the dashboard without any database connection: php tests/home.php [--render].
function escapeHtml(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$user = ['username' => 'preview-admin', 'full_name' => 'Quản trị viên <script>', 'is_super_admin' => 1];
if(in_array('--warehouse-label',$argv,true)) $user['is_super_admin']=0;
$user['employee_warehouse_name']='Kho Hải Phòng <script> với tên dài để kiểm tra giao diện';
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$preview = 'dashboard';
$productPages = ['materials' => 3, 'spare-parts' => 3, 'tools' => 2, 'fixed-assets' => 2, 'other-products' => 2];
foreach (array_merge(['export-create', 'exports', 'import-create', 'imports', 'products', 'accounts', 'employees', 'warehouses', 'suppliers', 'decisions', 'reports', 'audit'], array_keys($productPages)) as $option) {
    if (in_array('--' . $option, $argv, true)) $preview = $option;
}
$sampleRows = ['dashboard' => ['data-receipt ', 5], 'import-create' => ['id="import-create-form"', 1], 'imports' => ['data-import="', 12], 'products' => ['data-product="', 12], 'accounts' => ['data-account="', 6],
    'employees' => ['data-employee="', 12], 'warehouses' => ['data-warehouse="', 12], 'suppliers' => ['data-supplier="', 12], 'decisions' => ['data-decision="', 12], 'reports' => ['data-report-row>', 60], 'audit' => ['data-audit="', 14]];
$previewOnly = true;
$sampleRows['exports'] = ['data-export="', 12]; $sampleRows['export-create'] = ['id="export-create-form"', 1];
foreach ($productPages as $productPage => $count) $sampleRows[$productPage] = ['data-product="', $count];
if ($preview === 'reports') {
    require dirname(__DIR__) . '/app/reports.php';
    $reportData = require dirname(__DIR__) . '/resources/data/reports-preview.php';
    $kind = 'inventory';
    foreach ($argv as $argument) if (str_starts_with($argument, '--report-kind=')) $kind = substr($argument, 14);
    $filters = reportFilters(['kind' => $kind], $reportData); $report = buildReport($reportData, $filters); $reportError = '';
    $sampleRows['reports'][1] = $kind === 'inventory' ? 60 : 12;
} else require __DIR__ . '/fixtures/' . (isset($productPages[$preview]) ? 'products' : $preview) . '.php';
ob_start();
require dirname(__DIR__) . '/resources/views/' . ($preview === 'dashboard' ? 'dashboard/home.php' : $preview . '/index.php');
$html = ob_get_clean();
if(!str_contains($html,'title="'.escapeHtml($user['is_super_admin']?'Phạm vi quản trị: Toàn hệ thống':'Kho của bạn: '.$user['employee_warehouse_name']).'"')) throw new RuntimeException('Topbar warehouse label or escaping failed.');
if (str_contains($html, 'Quản trị viên <script>') || !str_contains($html, 'Quản trị viên &lt;script&gt;')
    || substr_count($html, $sampleRows[$preview][0]) !== $sampleRows[$preview][1]
    || !str_contains($html, 'name="csrf_token"')
    || !str_contains(mb_strtolower($html), $preview === 'dashboard' ? 'số liệu minh họa' : 'dữ liệu mẫu')) {
    throw new RuntimeException('Dashboard rendering, escaping or sample-data labeling failed.');
}
echo in_array('--render', $argv, true) ? $html : 'PASS: ' . $preview . " renders without database, escapes account name, labels sample data and preserves logout CSRF.\n";
