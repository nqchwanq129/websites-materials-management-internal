<?php
declare(strict_types=1);
// Render the dashboard without any database connection: php tests/home.php [--render].
function escapeHtml(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$user = ['username' => 'preview-admin', 'full_name' => 'Quản trị viên <script>', 'is_super_admin' => 1];
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$accountPreview = in_array('--accounts', $argv, true);
$employeePreview = in_array('--employees', $argv, true);
ob_start();
require dirname(__DIR__) . '/resources/views/' . ($employeePreview ? 'employees/index.php' : ($accountPreview ? 'accounts/index.php' : 'dashboard/home.php'));
$html = ob_get_clean();
if (str_contains($html, 'Quản trị viên <script>') || !str_contains($html, 'Quản trị viên &lt;script&gt;')
    || ($employeePreview ? substr_count($html, 'data-employee="') !== 12 : ($accountPreview ? substr_count($html, 'data-account="') !== 6 : substr_count($html, 'data-receipt ') !== 5))
    || !str_contains($html, 'name="csrf_token"')
    || !str_contains($html, $accountPreview || $employeePreview ? 'dữ liệu mẫu' : 'Số liệu minh họa')) {
    throw new RuntimeException('Dashboard rendering, escaping or sample-data labeling failed.');
}
echo in_array('--render', $argv, true) ? $html : 'PASS: ' . ($employeePreview ? 'employees' : ($accountPreview ? 'accounts' : 'dashboard')) . " renders without database, escapes account name, labels sample data and preserves logout CSRF.\n";
