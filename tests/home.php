<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/auth.php';
// Render the dashboard without any database connection: php tests/home.php [--render].
function escapeHtml(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$user = ['id'=>'1', 'username' => 'preview-admin', 'full_name' => 'Quản trị viên <script>', 'is_super_admin' => 1];
if(in_array('--warehouse-label',$argv,true)) $user['is_super_admin']=0;
$user['employee_warehouse_name']='Kho Hải Phòng <script> với tên dài để kiểm tra giao diện';
$rolePreview = in_array('--keeper',$argv,true) ? 'keeper' : (in_array('--viewer',$argv,true) || in_array('--warehouse-label',$argv,true) ? 'viewer' : '');
if ($rolePreview !== '') {
    $user['is_super_admin']=0;
    $access=['admin'=>false,'warehouses'=>['1'=>$rolePreview],'warehouse_names'=>['1'=>$user['employee_warehouse_name']]];
    if (in_array('--mixed',$argv,true)) { $access['warehouses']['2']='viewer'; $access['warehouse_names']['2']='Kho chỉ xem'; }
}
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$preview = 'dashboard';
$productPages = ['materials' => 3, 'spare-parts' => 3, 'tools' => 2, 'fixed-assets' => 2, 'other-products' => 2];
foreach (array_merge(['export-create', 'exports', 'import-create', 'imports', 'products', 'accounts', 'employees', 'warehouses', 'suppliers', 'decisions', 'reports', 'audit'], array_keys($productPages)) as $option) {
    if (in_array('--' . $option, $argv, true)) $preview = $option;
}
$sampleRows = ['dashboard' => ['data-receipt ', 5], 'import-create' => ['id="import-create-form"', 1], 'imports' => ['data-import="', 12], 'products' => ['data-product="', 12], 'accounts' => ['data-account="', 6],
    'employees' => ['data-employee="', 12], 'warehouses' => ['data-warehouse="', 12], 'suppliers' => ['data-supplier="', 12], 'decisions' => ['data-decision="', 12], 'reports' => ['data-report-row>', 60], 'audit' => ['data-audit="', 14]];
$previewOnly = true;
$ownProfilePreview = ['username'=>$user['username'],'full_name'=>$user['full_name'],'email'=>'preview@example.com','phone'=>'0901234567','code'=>'NV-001',
    'warehouse'=>$user['employee_warehouse_name'],'role'=>$user['is_super_admin']?'Quản trị viên':'Người xem','editable'=>!in_array('--unlinked-profile',$argv,true),'version'=>'preview'];
$sampleRows['exports'] = ['data-export="', 12]; $sampleRows['export-create'] = ['id="export-create-form"', 1];
foreach ($productPages as $productPage => $count) $sampleRows[$productPage] = ['data-product="', $count];
if ($preview === 'reports') {
    require dirname(__DIR__) . '/app/reports.php';
    $reportData = require __DIR__ . '/fixtures/reports.php';
    if (in_array('--report-checks', $argv, true)) {
        $reportData['movements'][0]['quantity']=null;
        $reportData['receipts']=[['id'=>'1','direction'=>'import','number'=>'CHECK-<script>','date'=>$reportData['movements'][0]['date'],
            'warehouse_id'=>'1','header'=>'10.000001','details'=>'10.000000','line_count'=>1]];
    }
    $kind = 'inventory';
    foreach ($argv as $argument) if (str_starts_with($argument, '--report-kind=')) $kind = substr($argument, 14);
    $filters = reportFilters(['kind' => $kind], $reportData); $report = buildReport($reportData, $filters); $reportError = '';
    $previewOnly = !in_array('--live-report', $argv, true);
    $sampleRows['reports'][1] = $kind === 'inventory' ? 60 : 12;
} else require __DIR__ . '/fixtures/' . (isset($productPages[$preview]) ? 'products' : $preview) . '.php';
if ($rolePreview !== '') {
    if (isset($managementData['exports'])) foreach ($managementData['exports'] as &$bill) $bill['can_confirm']=canManageWarehouse($access,(string)$bill['warehouse_id']); unset($bill);
    if (isset($managementData['imports'])) foreach ($managementData['imports'] as &$bill) $bill['can_edit']=canManageWarehouse($access,(string)$bill['warehouse_id']); unset($bill);
    if (isset($managementData['decisions'])) foreach ($managementData['decisions'] as &$decision) $decision['can_link']=canManageWarehouse($access,(string)$decision['warehouse_id']); unset($decision);
    if (isset($reportData)) $reportData['accounts']=[];
}
if (in_array($preview, ['warehouses','import-create','export-create'], true)) {
    $managementData['printEmployees'] = [['id'=>'1','code'=>'NV01','full_name'=>'Người ký <script>','status'=>'active']];
    $managementData['printSettings'] = ['1'=>['unit_name'=>'Đơn vị thử','department_name'=>'Bộ phận thử','department_address'=>'Địa chỉ bộ phận thử',
        'location'=>'Ngăn A','keeper_employee_id'=>'1','accountant_employee_id'=>null,'director_employee_id'=>null]];
    $managementData['printVersions'] = ['1'=>str_repeat('b',64),'2'=>str_repeat('c',64)];
    $managementData['printWarehouses'] = ['1'=>['name'=>'Kho 1','address'=>'Địa chỉ kho 1'],'2'=>['name'=>'Kho 2','address'=>'Địa chỉ kho 2']];
    if ($preview==='warehouses') $managementData['warehouseRecords'][0]['print_settings'] = $managementData['printSettings']['1'];
}
ob_start();
require dirname(__DIR__) . '/resources/views/' . ($preview === 'dashboard' ? 'dashboard/home.php' : $preview . '/index.php');
$html = ob_get_clean();
if (preg_match('/(?:data-field|name)="specifications"|(?:product|report)-detail-specifications/', $html)) throw new RuntimeException('Removed specifications controls must not render.');
preg_match_all('/(?:href|src)="(assets\/[^"?]+\.(?:css|js))(\?v=[a-f0-9]{12})?"/', $html, $assets, PREG_SET_ORDER);
foreach ($assets as $asset) {
    if (($asset[2] ?? '') !== '?v=' . substr(hash_file('sha256', dirname(__DIR__) . '/public/' . $asset[1]), 0, 12)) {
        throw new RuntimeException('CSS/JavaScript must use a content version to avoid stale browser caches: ' . $asset[1]);
    }
}
if(!str_contains($html,'title="'.escapeHtml($user['is_super_admin']?'Phạm vi quản trị: Toàn hệ thống':'Kho của bạn: '.$topbarWarehouse).'"')) throw new RuntimeException('Topbar warehouse label or escaping failed.');
if (str_contains($html, 'Quản trị viên <script>') || !str_contains($html, 'Quản trị viên &lt;script&gt;')
    || substr_count($html, $sampleRows[$preview][0]) !== $sampleRows[$preview][1]
    || !str_contains($html, 'name="csrf_token"')
    || !str_contains(mb_strtolower($html), $preview === 'dashboard' ? 'số liệu minh họa' : ($previewOnly ? 'dữ liệu mẫu' : 'dữ liệu thực tế'))) {
    throw new RuntimeException('Dashboard rendering, escaping or sample-data labeling failed.');
}
echo in_array('--render', $argv, true) ? $html : 'PASS: ' . $preview . " renders without database, escapes account name, labels data source and preserves logout CSRF.\n";
