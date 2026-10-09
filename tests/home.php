<?php
declare(strict_types=1);
// Render the dashboard without any database connection: php tests/home.php [--render].
function escapeHtml(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$user = ['username' => 'preview-admin', 'full_name' => 'Quản trị viên <script>', 'is_super_admin' => 1];
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$preview = 'dashboard';
foreach (['accounts', 'employees', 'warehouses', 'suppliers', 'audit'] as $option) {
    if (in_array('--' . $option, $argv, true)) $preview = $option;
}
$sampleRows = ['dashboard' => ['data-receipt ', 5], 'accounts' => ['data-account="', 6],
    'employees' => ['data-employee="', 12], 'warehouses' => ['data-warehouse="', 12], 'suppliers' => ['data-supplier="', 12], 'audit' => ['data-audit="', 14]];
$previewOnly = true;
if ($preview !== 'dashboard') require __DIR__ . '/fixtures/' . $preview . '.php';
ob_start();
require dirname(__DIR__) . '/resources/views/' . ($preview === 'dashboard' ? 'dashboard/home.php' : $preview . '/index.php');
$html = ob_get_clean();
if (str_contains($html, 'Quản trị viên <script>') || !str_contains($html, 'Quản trị viên &lt;script&gt;')
    || substr_count($html, $sampleRows[$preview][0]) !== $sampleRows[$preview][1]
    || !str_contains($html, 'name="csrf_token"')
    || !str_contains($html, $preview === 'dashboard' ? 'Số liệu minh họa' : 'dữ liệu mẫu')) {
    throw new RuntimeException('Dashboard rendering, escaping or sample-data labeling failed.');
}
echo in_array('--render', $argv, true) ? $html : 'PASS: ' . $preview . " renders without database, escapes account name, labels sample data and preserves logout CSRF.\n";
