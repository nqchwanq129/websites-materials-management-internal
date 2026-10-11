<?php
declare(strict_types=1);
// XAMPP required. Clone the schema into an isolated database and serve a temporary app copy.
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/management.php';
require dirname(__DIR__) . '/app/dashboard.php';
function verify(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejected(callable $operation): void {
    try { $operation(); } catch (DomainException $error) { return; }
    throw new RuntimeException('An invalid mutation was accepted.');
}
$source = connectDatabase();
$database = 'qlvt_management_test_' . bin2hex(random_bytes(5));
$testRoot = sys_get_temp_dir() . '/qlvt-mgmt-' . bin2hex(random_bytes(5));
$server = null;
$pdo = $source;
$original = $source->query('SELECT DATABASE()')->fetchColumn();
$prefix = 'mgmt-check-' . bin2hex(random_bytes(5));
$actorId = null;
$cookie = '';
$base = getenv('LOGIN_URL') ?: 'http://localhost/qlvt-remake/public/';
function httpManagement(string $page, ?array $fields = null): array {
    global $base, $cookie;
    $context = stream_context_create(['http' => ['method' => $fields === null ? 'GET' : 'POST',
        'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => $fields === null ? '' : http_build_query($fields), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    $body = file_get_contents($base . 'index.php?page=' . $page, false, $context);
    verify($body !== false, 'Cannot reach XAMPP.');
    foreach ($http_response_header as $header) if (preg_match('/^Set-Cookie: (qlvt_session=[^;]*)/i', $header, $match)) $cookie = $match[1];
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], $body, $http_response_header];
}
function httpDecisionPdf(array $fields, string $pdf, string $name = 'quyet-dinh.pdf', string $page = 'decisions', string $fileField = 'pdf'): array {
    global $base, $cookie;
    $boundary = 'qlvt-' . bin2hex(random_bytes(12)); $body = '';
    foreach ($fields as $key => $value) {
        verify(is_string($value), 'Multipart test fields must be strings.');
        $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
    }
    $mime = $fileField === 'xml' ? 'application/xml' : 'application/pdf';
    $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$fileField\"; filename=\"$name\"\r\nContent-Type: $mime\r\n\r\n$pdf\r\n--$boundary--\r\n";
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Cookie: $cookie\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n",
        'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    $response = file_get_contents($base . 'index.php?page=' . $page, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], $response];
}
function tokenFrom(string $body): string {
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $match);
    verify(isset($match[1]), 'Missing CSRF: ' . substr(strip_tags($body), 0, 1500));
    return $match[1];
}
try {
    $source->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $source->exec("USE `$database`");
    $config = parse_ini_file(dirname(__DIR__) . '/.env', false, INI_SCANNER_RAW);
    $original = $config['DB_NAME'];
    $foreignKeys = [];
    foreach ($source->query("SHOW TABLES FROM `$original`")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $definition = $source->query("SHOW CREATE TABLE `$original`.`$table`")->fetch(PDO::FETCH_NUM)[1];
        $lines = explode("\n", $definition);
        $lines = array_filter($lines, static function ($line) use (&$foreignKeys, $table) {
            if (str_contains($line, 'CONSTRAINT') && str_contains($line, 'FOREIGN KEY')) {
                $foreignKeys[] = "ALTER TABLE `$table` ADD " . rtrim(trim($line), ',');
                return false;
            }
            return true;
        });
        $source->exec(preg_replace('/,\n\)/', "\n)", implode("\n", $lines)));
    }
    foreach ($foreignKeys as $sql) $source->exec($sql);
    foreach ($source->query("SHOW TRIGGERS FROM `$original`")->fetchAll() as $trigger) {
        $source->exec("CREATE TRIGGER `{$trigger['Trigger']}` {$trigger['Timing']} {$trigger['Event']} ON `{$trigger['Table']}` FOR EACH ROW " . $trigger['Statement']);
    }
    mkdir($testRoot);
    mkdir($testRoot . '/storage');
    foreach (['app', 'config', 'resources', 'public'] as $directory) {
        $path = dirname(__DIR__) . '/' . $directory;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        mkdir($testRoot . '/' . $directory);
        foreach ($files as $file) {
            $target = $testRoot . '/' . $directory . '/' . substr($file->getPathname(), strlen($path) + 1);
            if ($file->isDir()) mkdir($target); else copy($file->getPathname(), $target);
        }
    }
    file_put_contents($testRoot . '/.env', preg_replace('/^DB_NAME\s*=.*$/m', 'DB_NAME=' . $database, file_get_contents(dirname(__DIR__) . '/.env')));
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $testRoot . '/storage', '-S', $address, '-t', $testRoot . '/public'], [0 => ['pipe', 'r'], 1 => ['file', $testRoot . '/server.log', 'a'], 2 => ['file', $testRoot . '/server.log', 'a']], $pipes);
    verify(is_resource($server), 'Cannot start isolated PHP server.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($ready) { fclose($ready); break; }
        usleep(30000);
    }
    $password = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO users (username,password_hash,is_super_admin) VALUES (?,?,1)')->execute([$prefix, password_hash($password, PASSWORD_DEFAULT)]);
    $actorId = $pdo->lastInsertId();
    $warehouseIds = [];
    foreach ([1, 2] as $number) {
        $pdo->prepare('INSERT INTO warehouses (code,name,created_by) VALUES (?,?,?)')->execute([$prefix . '-' . $number, 'Test warehouse ' . $number, $actorId]);
        $warehouseIds[] = $pdo->lastInsertId();
    }
    [, $body] = httpManagement('login');
    verify(httpManagement('login', ['username' => $prefix, 'password' => $password, 'csrf_token' => tokenFrom($body)])[0] === 303, 'Test login failed.');
    [, $body] = httpManagement('employees');
    $csrf = tokenFrom($body);
    $employeeInput = ['csrf_token' => $csrf, 'action' => 'save', 'code' => $prefix, 'full_name' => 'Người thử <script>',
        'email' => 'test@example.com', 'phone' => '', 'warehouse' => $warehouseIds[0], 'status' => 'active'];
    verify(httpManagement('employees', array_diff_key($employeeInput, ['csrf_token' => true]))[0] === 403, 'CSRF bypass.');
    verify(httpManagement('employees', $employeeInput)[0] === 200, 'Employee creation failed.');
    $stmt = $pdo->prepare('SELECT id FROM employees WHERE code=?'); $stmt->execute([$prefix]); $employeeId = (string) $stmt->fetchColumn();
    $employee = managementSnapshot($pdo, 'employees', $employeeId);
    verify($employee['full_name'] === 'Người thử <script>', 'Employee was not persisted.');
    rejected(fn() => saveManagement($pdo, 'employees', $actorId, $employeeInput));
    $accountInput = ['csrf_token' => $csrf, 'action' => 'save', 'username' => $prefix . '-user', 'password' => $password,
        'employee' => $employeeId, 'admin' => '0', 'status' => 'active', 'warehouses' => [$warehouseIds[0] => 'keeper', $warehouseIds[1] => 'viewer']];
    verify(httpManagement('accounts', $accountInput)[0] === 200, 'Account and warehouse grants failed.');
    $createdUser = findUser($pdo, 'username', $prefix . '-user');
    verify($createdUser['employee_warehouse_name']==='Test warehouse 1','User lookup must load the assigned employee warehouse.');
    $targetId = (string) $createdUser['id'];
    verify(password_verify($password, $createdUser['password_hash']), 'Password not hashed correctly.');
    verify(warehousePermission($pdo, $targetId, $warehouseIds[0]) === 'keeper', 'Keeper role failed.');
    verify(warehousePermission($pdo, $targetId, $warehouseIds[1]) === 'viewer', 'Viewer role failed.');
    rejected(fn() => saveManagement($pdo, 'employees', $targetId, $employeeInput));
    $before = managementSnapshot($pdo, 'accounts', $targetId);
    $update = $accountInput + ['id' => $targetId, 'version' => $before['updated_at']];
    $update['password'] = '';
    $update['warehouses'] = [$warehouseIds[0] => 'viewer'];
    verify(httpManagement('accounts', $update)[0] === 200, 'Role update failed.');
    verify(warehousePermission($pdo, $targetId, $warehouseIds[0]) === 'viewer' && warehousePermission($pdo, $targetId, $warehouseIds[1]) === null, 'Roles not updated/revoked.');
    rejected(fn() => saveManagement($pdo, 'accounts', $actorId, $update));
    $current = managementSnapshot($pdo, 'accounts', $targetId);
    $invalid = $update; $invalid['version'] = $current['updated_at']; $invalid['username'] = $prefix . '-invalid'; $invalid['warehouses'] = ['999999999' => 'keeper'];
    rejected(fn() => saveManagement($pdo, 'accounts', $actorId, $invalid));
    verify(findUser($pdo, 'id', $targetId)['username'] === $prefix . '-user', 'Failed mutation did not roll back.');
    $actor = managementSnapshot($pdo, 'accounts', $actorId);
    rejected(fn() => saveManagement($pdo, 'accounts', $actorId, ['action' => 'state', 'id' => $actorId, 'version' => $actor['updated_at'], 'status' => 'locked']));
    verify(userCanSignIn(findUser($pdo, 'id', $actorId)), 'Self-lock protection failed.');
    $employee = managementSnapshot($pdo, 'employees', $employeeId);
    saveManagement($pdo, 'employees', $actorId, ['action' => 'state', 'id' => $employeeId, 'version' => $employee['updated_at'], 'status' => 'inactive']);
    verify(!userCanSignIn(findUser($pdo, 'id', $targetId)) && warehousePermission($pdo, $targetId, $warehouseIds[0]) === null, 'Inactive employee retained access.');
    [, $body] = httpManagement('accounts');
    verify(str_contains($body, $prefix . '-user') && !str_contains($body, $createdUser['password_hash']) && !str_contains($body, $password), 'Account UI leaks secrets or misses real data.');
    [, $body] = httpManagement('employees');
    verify(str_contains($body, 'Người thử &lt;script&gt;'), 'Stored employee text must be escaped.');
    $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE user_id=? ORDER BY id'); $stmt->execute([$actorId]); $logs = $stmt->fetchAll();
    verify(count($logs) === 4, 'Every successful change must create one audit record; failed changes must not.');
    verify($logs[2]['action'] === 'assign_access' && $logs[3]['action'] === 'deactivate', 'Audit actions incorrect.');
    foreach ($logs as $log) verify(!str_contains($log['after_data'], $password) && !str_contains($log['after_data'], 'password_hash'), 'Audit log contains password data.');
    [, $body] = httpManagement('audit');
    verify(str_contains($body, $prefix . '-user') && !str_contains($body, $password), 'Audit UI misses committed history.');
    $current = managementSnapshot($pdo, 'accounts', $targetId);
    $update['version'] = $current['updated_at']; $update['password'] = 'changed-' . bin2hex(random_bytes(10));
    verify(httpManagement('accounts', $update)[0] === 200, 'Password reset failed.');
    verify(password_verify($update['password'], findUser($pdo, 'id', $targetId)['password_hash']), 'Reset password not persisted.');
    $stmt->execute([$actorId]); $updatedLogs = $stmt->fetchAll();
    $latest = $updatedLogs[array_key_last($updatedLogs)];
    verify(json_decode($latest['after_data'], true)['password_changed'] === true && !str_contains($latest['after_data'], $update['password']), 'Password reset must be audited without the secret.');
    foreach (['locked', 'active'] as $status) {
        $current = managementSnapshot($pdo, 'accounts', $targetId);
        verify(httpManagement('accounts', ['csrf_token' => $csrf, 'action' => 'state', 'id' => $targetId, 'version' => $current['updated_at'], 'status' => $status])[0] === 200, 'Account state change failed.');
        verify(findUser($pdo, 'id', $targetId)['status'] === $status, 'Account state not persisted.');
    }
    $warehouseInput = ['csrf_token' => $csrf, 'action' => 'save', 'code' => $prefix . '-new', 'name' => 'Kho thử <script>', 'address' => 'Địa chỉ thử', 'status' => 'active'];
    verify(httpManagement('warehouses', $warehouseInput)[0] === 200, 'Warehouse creation failed.');
    $warehouse = $pdo->query('SELECT id FROM warehouses ORDER BY id DESC LIMIT 1')->fetchColumn();
    $warehouse = managementSnapshot($pdo, 'warehouses', (string) $warehouse);
    verify($warehouse['name'] === $warehouseInput['name'], 'Warehouse was not persisted.');
    verify(httpManagement('warehouses', $warehouseInput)[0] === 422, 'Duplicate warehouse code accepted.');
    $withoutCsrf = $warehouseInput; unset($withoutCsrf['csrf_token']);
    verify(httpManagement('warehouses', $withoutCsrf)[0] === 403, 'Warehouse CSRF bypass.');
    rejected(fn() => saveManagement($pdo, 'warehouses', $targetId, $warehouseInput));
    $warehouseUpdate = $warehouseInput + ['id' => (string) $warehouse['id'], 'version' => $warehouse['updated_at']];
    $warehouseUpdate['address'] = 'Địa chỉ mới';
    verify(httpManagement('warehouses', $warehouseUpdate)[0] === 200, 'Warehouse edit failed.');
    verify(managementSnapshot($pdo, 'warehouses', (string) $warehouse['id'])['address'] === 'Địa chỉ mới', 'Warehouse edit not persisted.');
    verify(httpManagement('warehouses', $warehouseUpdate)[0] === 422, 'Stale warehouse edit accepted.');
    [, $body] = httpManagement('warehouses');
    verify(str_contains($body, 'Kho thử &lt;script&gt;') && !str_contains($body, 'Kho thử <script>'), 'Warehouse HTML must escape persisted names.');
    $records = loadManagement($pdo, 'warehouses')['warehouseRecords'];
    $firstWarehouse = array_values(array_filter($records, fn($w) => $w['id'] === $warehouseIds[0]))[0];
    verify((int) $firstWarehouse['employee_count'] === 1 && (int) $firstWarehouse['active_employee_count'] === 0 && (int) $firstWarehouse['access_count'] === 1, 'Warehouse relationship counts incorrect.');
    $employee = managementSnapshot($pdo, 'employees', $employeeId);
    saveManagement($pdo, 'employees', $actorId, ['action' => 'state', 'id' => $employeeId, 'version' => $employee['updated_at'], 'status' => 'active']);
    verify(warehousePermission($pdo, $targetId, $warehouseIds[0]) === 'viewer', 'Active warehouse permission missing.');
    foreach (['inactive', 'active'] as $status) {
        $currentWarehouse = managementSnapshot($pdo, 'warehouses', $warehouseIds[0]);
        verify(httpManagement('warehouses', ['csrf_token' => $csrf, 'action' => 'state', 'id' => $warehouseIds[0], 'version' => $currentWarehouse['updated_at'], 'status' => $status])[0] === 200, 'Warehouse state change failed.');
        verify(warehousePermission($pdo, $targetId, $warehouseIds[0]) === ($status === 'active' ? 'viewer' : null), 'Warehouse state must revoke/restore access.');
        if ($status === 'inactive') {
            $blockedEmployee = $employeeInput; $blockedEmployee['code'] = $prefix . '-blocked';
            verify(httpManagement('employees', $blockedEmployee)[0] === 422, 'New employee assigned to inactive warehouse.');
            $blockedAccount = $accountInput; $blockedAccount['username'] = $prefix . '-blocked';
            verify(httpManagement('accounts', $blockedAccount)[0] === 422, 'New account granted inactive warehouse access.');
        }
    }
    $stmt = $pdo->prepare("SELECT action,warehouse_id,after_data FROM audit_logs WHERE entity_type='warehouses' ORDER BY id");
    $stmt->execute(); $warehouseLogs = $stmt->fetchAll();
    verify(array_column($warehouseLogs, 'action') === ['create', 'update', 'deactivate', 'activate'], 'Warehouse changes must be audited once each.');
    [, $body] = httpManagement('audit');
    verify(str_contains($body, 'Kho hàng') && str_contains($body, $warehouseInput['code']), 'Warehouse audit history missing from UI.');
    $employee = managementSnapshot($pdo, 'employees', $employeeId);
    $deleteInput = ['csrf_token' => $csrf, 'action' => 'delete', 'id' => $employeeId, 'version' => $employee['updated_at']];
    verify(httpManagement('employees', $deleteInput)[0] === 422, 'Linked employee must not be deleted.');
    verify(managementSnapshot($pdo, 'employees', $employeeId) === $employee, 'Blocked deletion modified an employee.');
    $currentWarehouse = managementSnapshot($pdo, 'warehouses', $warehouseIds[0]);
    verify(httpManagement('warehouses', ['csrf_token' => $csrf, 'action' => 'delete', 'id' => $warehouseIds[0], 'version' => $currentWarehouse['updated_at']])[0] === 422, 'Referenced warehouse must not be deleted.');
    $deleteEmployeeInput = $employeeInput; $deleteEmployeeInput['code'] = $prefix . '-delete';
    verify(httpManagement('employees', $deleteEmployeeInput)[0] === 200, 'Delete-test employee creation failed.');
    $stmt = $pdo->prepare('SELECT id FROM employees WHERE code=?'); $stmt->execute([$deleteEmployeeInput['code']]); $deleteEmployeeId = (string) $stmt->fetchColumn();
    $deleteEmployee = managementSnapshot($pdo, 'employees', $deleteEmployeeId);
    $deleteInput['id'] = $deleteEmployeeId; $deleteInput['version'] = $deleteEmployee['updated_at'];
    $badDelete = $deleteInput; $badDelete['csrf_token'] = 'bad';
    verify(httpManagement('employees', $badDelete)[0] === 403, 'Employee deletion bypassed CSRF.');
    rejected(fn() => saveManagement($pdo, 'employees', $targetId, $deleteInput));
    verify(httpManagement('employees', $deleteInput)[0] === 200 && managementSnapshot($pdo, 'employees', $deleteEmployeeId) === null, 'Unlinked employee deletion failed.');
    $pdo->prepare('INSERT INTO warehouses (code,name,created_by) VALUES (?,?,?)')->execute([$prefix . '-delete', 'Unreferenced warehouse', $actorId]);
    $deleteWarehouseId = $pdo->lastInsertId();
    $deleteWarehouse = managementSnapshot($pdo, 'warehouses', $deleteWarehouseId);
    $pdo->prepare("UPDATE warehouses SET name='Changed',updated_at=updated_at + INTERVAL 1 SECOND WHERE id=?")->execute([$deleteWarehouseId]);
    $deleteInput = ['csrf_token' => $csrf, 'action' => 'delete', 'id' => $deleteWarehouseId, 'version' => $deleteWarehouse['updated_at']];
    verify(httpManagement('warehouses', $deleteInput)[0] === 422, 'Stale warehouse deletion accepted.');
    $deleteInput['version'] = managementSnapshot($pdo, 'warehouses', $deleteWarehouseId)['updated_at'];
    verify(httpManagement('warehouses', $deleteInput)[0] === 200 && managementSnapshot($pdo, 'warehouses', $deleteWarehouseId) === null, 'Unreferenced warehouse deletion failed.');
    $deletes = $pdo->query("SELECT * FROM audit_logs WHERE action='delete' ORDER BY id")->fetchAll();
    verify(count($deletes) === 2 && array_column($deletes, 'entity_type') === ['employees', 'warehouses'], 'Successful deletions must be audited once each.');
    foreach ($deletes as $log) verify($log['before_data'] !== null && $log['after_data'] === null, 'Deletion audit must retain the removed snapshot.');
    verify($deletes[1]['warehouse_id'] === null, 'Deleted warehouse audit must not reference a missing row.');
    $pdo->prepare('INSERT INTO employees (code,full_name,warehouse_id,created_by) VALUES (?,?,?,?)')->execute([$prefix . '-rollback', 'Rollback deletion', $warehouseIds[0], $actorId]);
    $rollbackEmployeeId = $pdo->lastInsertId();
    $rollbackEmployee = managementSnapshot($pdo, 'employees', $rollbackEmployeeId);
    $pdo->prepare('INSERT INTO suppliers (name,tax_code,created_by) VALUES (?,?,?)')->execute(['Supplier <script>', '0012345678', $actorId]);
    $supplierId = $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO suppliers (name,created_by) VALUES (?,?)')->execute(['Unlinked supplier', $actorId]);
    foreach (['draft', 'confirmed', 'cancelled'] as $supplierBillStatus) {
        $pdo->prepare("INSERT INTO import_bill (warehouse_id,number,received_date,receipt_type,supplier_id,supplier_name,invoice_number,status,cancel_reason,created_by)
            VALUES (?,?,'2026-10-09','purchase',?,?,'001',?,?,?)")->execute([$warehouseIds[0], $prefix . '-' . $supplierBillStatus, $supplierId, 'Supplier <script>', $supplierBillStatus, $supplierBillStatus === 'cancelled' ? 'Test cancellation' : null, $actorId]);
    }
    $supplierRecords = loadManagement($pdo, 'suppliers')['suppliers'];
    $supplierRecord = array_values(array_filter($supplierRecords, fn($s) => $s['id'] === $supplierId))[0];
    verify($supplierRecord['tax_code'] === '0012345678' && $supplierRecord['receipt_count'] === 3 && $supplierRecord['last_received_date'] === '2026-10-09', 'Supplier identity/receipt aggregation incorrect.');
    verify(count($supplierRecords[0]['receipts']) === 0 && $supplierRecords[0]['phone'] === '', 'Unlinked supplier must not inherit another supplier invoices.');
    [, $body] = httpManagement('suppliers');
    verify(str_contains($body, 'Supplier &lt;script&gt;') && !str_contains($body, 'Supplier <script>') && str_contains($body, '0012345678'), 'Supplier UI must preserve tax code and escape names.');
    verify(httpManagement('suppliers', ['csrf_token' => $csrf, 'action' => 'save', 'name' => 'Forbidden'])[0] === 405, 'Supplier tab must not allow direct entry.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn() === 2, 'Supplier read-only POST changed data.');
    rejected(fn() => saveManagement($pdo, 'suppliers', $actorId, ['action' => 'save', 'name' => 'Forbidden']));
    $pdo->prepare("INSERT INTO decisions (warehouse_id,number,issued_date,decision_type,issuing_authority,content) VALUES (?,?,'2026-10-09','increase','Authority','Content <script>')")->execute([$warehouseIds[0], 'QD <script>']);
    $decisionId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO decisions (warehouse_id,number,issued_date,decision_type,issuing_authority) VALUES (?,?,'2026-10-08','transfer','Authority')")->execute([$warehouseIds[0], 'Unlinked']);
    $pdo->exec("INSERT INTO products (code,name,category,unit) VALUES ('decision-test','Product','other','Piece')");
    $productId = $pdo->lastInsertId();
    $billId = $pdo->query('SELECT id FROM import_bill ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,product_name,unit,decision_id,decision_status) VALUES (?,?,'12345678901234.123456','Product <script>','Piece',?,'linked')")->execute([$billId, $productId, $decisionId]);
    $detailId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO export_bill (warehouse_id,number,issued_date,issue_type,reason) VALUES (?,'EXP-TEST','2026-10-09','allocation','Test')")->execute([$warehouseIds[0]]);
    $exportId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO export_bill_details (export_bill_id,product_id,source_import_detail_id,decision_id,decision_status,issued_quantity,product_name,unit) VALUES (?,?,?,?,'linked',1,'Product','Piece')")->execute([$exportId, $productId, $detailId, $decisionId]);
    $decisionRecords = loadManagement($pdo, 'decisions')['decisions'];
    verify($decisionRecords[0]['id'] === $decisionId && $decisionRecords[0]['import_count'] === 1 && $decisionRecords[0]['export_count'] === 1, 'Decision must aggregate both receipt directions.');
    verify($decisionRecords[0]['items'][0]['quantity'] === '12345678901234.123456' && !$decisionRecords[1]['items'], 'Decision quantity precision or unlinked records incorrect.');
    [, $body] = httpManagement('decisions');
    verify(str_contains($body, 'QD &lt;script&gt;') && !str_contains($body, 'QD <script>') && !str_contains($body, 'data-feature="Tăng vật tư"'), 'Decision escaping or simplified navigation incorrect.');
    verify(httpManagement('decisions', ['action' => 'save'])[0] === 403, 'Decision writes must require CSRF.');
    $decisionInput = ['csrf_token' => $csrf, 'action' => 'save', 'form_complete' => '1', 'warehouse' => $warehouseIds[0], 'number' => 'CREATED-QD',
        'issued_date' => '2026-10-09', 'decision_type' => 'increase', 'issuing_authority' => 'Authority <script>', 'content' => 'Test'];
    verify(httpManagement('decisions', $decisionInput)[0] === 200, 'Decision creation failed.');
    $newDecisionId = (string) $pdo->query("SELECT id FROM decisions WHERE number='CREATED-QD'")->fetchColumn();
    rejected(fn() => saveManagement($pdo, 'decisions', $targetId, $decisionInput));
    verify(httpManagement('decisions', $decisionInput)[0] === 422, 'Duplicate decision accepted.');
    $invalidDate = $decisionInput; $invalidDate['number'] = 'INVALID'; $invalidDate['issued_date'] = '2026-02-30';
    verify(httpManagement('decisions', $invalidDate)[0] === 422, 'Invalid date accepted.');
    $incomplete = $decisionInput; unset($incomplete['form_complete']);
    verify(httpManagement('decisions', $incomplete)[0] === 422, 'Truncated input accepted.');
    $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,product_name,unit) VALUES (?,?,2,'Pending','Piece')")->execute([$billId, $productId]);
    $pendingId = $pdo->lastInsertId();
    $pdo->prepare("UPDATE import_bill SET status='confirmed' WHERE id=?")->execute([$billId]);
    $pendingVersion = $pdo->query("SELECT updated_at FROM import_bill_details WHERE id=$pendingId")->fetchColumn();
    $currentDecision = decisionSnapshot($pdo, $newDecisionId);
    $decisionInput += ['id' => $newDecisionId, 'version' => $currentDecision['updated_at'], 'links_version' => decisionLinksVersion($currentDecision['links'])];
    $otherLinked = $decisionInput; $otherLinked['lines'] = ['import' => [$detailId => $pdo->query("SELECT updated_at FROM import_bill_details WHERE id=$detailId")->fetchColumn()]];
    verify(httpManagement('decisions', $otherLinked)[0] === 422, 'Existing decision link was overwritten.');
    $wrongWarehouse = $decisionInput; $wrongWarehouse['warehouse'] = $warehouseIds[1]; $wrongWarehouse['lines'] = ['import' => [$pendingId => $pendingVersion]];
    verify(httpManagement('decisions', $wrongWarehouse)[0] === 422, 'Cross-warehouse link accepted.');
    $decisionInput['lines'] = ['import' => [$pendingId => $pendingVersion]];
    $pdo->prepare("UPDATE import_bill SET status='draft' WHERE id=?")->execute([$billId]);
    verify(httpManagement('decisions', $decisionInput)[0] === 422, 'Draft receipt linked as newly imported stock.');
    $pdo->prepare("UPDATE import_bill SET status='confirmed' WHERE id=?")->execute([$billId]);
    verify(httpManagement('decisions', $decisionInput)[0] === 200, 'Pending import line did not link.');
    verify(httpManagement('decisions', $decisionInput)[0] === 422, 'Stale decision write accepted.');
    $currentDecision = decisionSnapshot($pdo, $newDecisionId);
    verify(isset($currentDecision['links']['import'][$pendingId]), 'Link was not persisted.');
    $decisionInput['version'] = $currentDecision['updated_at']; $decisionInput['links_version'] = decisionLinksVersion($currentDecision['links']);
    $pdo->prepare("UPDATE import_bill_details SET note='Concurrent edit',updated_at=updated_at + INTERVAL 1 SECOND WHERE id=?")->execute([$pendingId]);
    unset($decisionInput['lines']);
    verify(httpManagement('decisions', $decisionInput)[0] === 422, 'Concurrent line edit was silently unlinked.');
    $currentDecision = decisionSnapshot($pdo, $newDecisionId);
    $decisionInput['links_version'] = decisionLinksVersion($currentDecision['links']);
    verify(httpManagement('decisions', $decisionInput)[0] === 200, 'Unlink failed.');
    verify($pdo->query("SELECT decision_status FROM import_bill_details WHERE id=$pendingId")->fetchColumn() === 'pending', 'Unlinked line must return to pending.');
    $currentDecision = decisionSnapshot($pdo, $newDecisionId);
    $decisionInput['version'] = $currentDecision['updated_at']; $decisionInput['links_version'] = decisionLinksVersion($currentDecision['links']);
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    verify(httpDecisionPdf($decisionInput, '<?php echo 1; ?>')[0] === 422, 'Fake PDF accepted.');
    verify(httpDecisionPdf($decisionInput, $pdf, 'payload.php')[0] === 422, 'Non-PDF extension accepted.');
    verify(httpDecisionPdf($decisionInput, $pdf)[0] === 200, 'PDF upload failed.');
    $file = $pdo->query("SELECT * FROM attachments WHERE decision_id=$newDecisionId")->fetch();
    verify($file && is_file($testRoot . '/storage/uploads/' . $file['storage_path']), 'PDF or metadata missing.');
    [$status, $download, $headers] = httpManagement('decision-file&id=' . $file['id']);
    verify($status === 200 && $download === $pdf && str_contains(implode(' ', $headers), 'Content-Disposition: attachment'), 'PDF download failed.');
    $savedCookie = $cookie; $cookie = '';
    verify(httpManagement('decision-file&id=' . $file['id'])[0] === 303, 'Guest PDF access accepted.'); $cookie = $savedCookie;
    $pdo->prepare('UPDATE users SET is_super_admin=0 WHERE id=?')->execute([$actorId]);
    verify(httpManagement('decision-file&id=' . $file['id'])[0] === 403, 'Non-admin PDF access accepted.');
    $pdo->prepare('UPDATE users SET is_super_admin=1 WHERE id=?')->execute([$actorId]);
    $pdo->prepare("UPDATE attachments SET storage_path='../.env' WHERE id=?")->execute([$file['id']]);
    verify(httpManagement('decision-file&id=' . $file['id'])[0] === 404, 'Download path traversal accepted.');
    $pdo->prepare('UPDATE attachments SET storage_path=? WHERE id=?')->execute([$file['storage_path'], $file['id']]);
    $currentDecision = decisionSnapshot($pdo, $newDecisionId);
    $decisionInput['version'] = $currentDecision['updated_at']; $decisionInput['links_version'] = decisionLinksVersion($currentDecision['links']);
    verify(httpManagement('decisions', $decisionInput)[0] === 200, 'Editing without upload must retain existing PDF.');
    verify((int) $pdo->query("SELECT COUNT(*) FROM attachments WHERE decision_id=$newDecisionId")->fetchColumn() === 1, 'Edit removed existing PDF.');
    $pdo->prepare("UPDATE export_bill SET status='confirmed' WHERE id=?")->execute([$exportId]);
    $pdo->prepare("INSERT INTO import_bill (warehouse_id,number,received_date,receipt_type,status) VALUES (?,'PRODUCT-DRAFT','2026-10-09','purchase','draft')")->execute([$warehouseIds[0]]);
    $draftBillId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,product_name,unit) VALUES (?,?,999,'Product','Piece')")->execute([$draftBillId, $productId]);
    $pdo->prepare("INSERT INTO import_bill (warehouse_id,number,received_date,receipt_type,status) VALUES (?,'PRODUCT-SECOND','2026-10-09','purchase','confirmed')")->execute([$warehouseIds[1]]);
    $secondBillId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,product_name,unit) VALUES (?,?,3,'Product','Piece')")->execute([$secondBillId, $productId]);
    $records = loadManagement($pdo, 'products')['products']; $product = $records[0];
    verify($product['incoming'] === '12345678901239.123456' && $product['outgoing'] === '1.000000' && $product['closing'] === '12345678901238.123456', 'Product stock must retain DECIMAL precision and exclude drafts without join multiplication.');
    verify(count($product['stocks']) === 2 && count($product['movements']) === 5, 'Product warehouse balance/history incorrect.');
    $importRecords = loadManagement($pdo, 'imports')['imports'];
    $importRecord = array_values(array_filter($importRecords, static fn($row) => $row['id'] === (string) $billId))[0];
    verify($importRecord['status'] === 'confirmed' && count($importRecord['items']) > 0, 'Import list must include header and related lines.');
    verify(count(array_filter($importRecords, static fn($row) => $row['status'] === 'draft')) > 0, 'Import list must preserve draft receipts.');
    [$importStatus, $importBody] = httpManagement('imports');
    verify($importStatus === 200 && str_contains($importBody, 'data-import=') && str_contains($importBody, 'id="import-dialog"'), 'Import route must render database receipts.');
    verify(httpManagement('imports', ['csrf_token' => $csrf, 'action' => 'delete', 'id' => (string) $billId])[0] === 405, 'Import list must not mutate receipts.');
    $productInput = ['csrf_token' => $csrf, 'action' => 'save', 'id' => $productId, 'version' => $product['version'], 'code' => $product['code'], 'name' => 'Product <script>',
        'category' => 'other', 'unit' => 'Piece', 'tracking_mode' => 'quantity', 'status' => 'inactive', 'specifications' => 'Updated specification'];
    verify(httpManagement('products', array_diff_key($productInput, ['csrf_token' => true]))[0] === 403, 'Product CSRF bypass.');
    rejected(fn() => saveManagement($pdo, 'products', $targetId, $productInput));
    $badProduct = $productInput; $badProduct['unit'] = 'Box';
    verify(httpManagement('products', $badProduct)[0] === 422, 'Changing units with history accepted.');
    $badProduct = $productInput; $badProduct['tracking_mode'] = 'none';
    verify(httpManagement('products', $badProduct)[0] === 422, 'Changing tracking with history accepted.');
    verify(httpManagement('products', $productInput)[0] === 200, 'Product edit failed.');
    verify(httpManagement('products', $productInput)[0] === 422, 'Stale product edit accepted.');
    [, $body] = httpManagement('products');
    verify(str_contains($body, 'Product &lt;script&gt;') && !str_contains($body, 'Product <script>'), 'Product name must be escaped.');
    [, $categoryBody] = httpManagement('other-products');
    verify(str_contains($categoryBody, 'id="products-list" data-category="other"') && !str_contains($categoryBody, 'class="product-groups"'), 'Sidebar category must reach product filtering without duplicate cards.');
    verify(loadManagement($pdo, 'materials')['products'] === [], 'Category page must exclude products from other categories.');
    $wrongCategory = $productInput;
    $wrongCategory['version'] = loadManagement($pdo, 'products')['products'][0]['version'];
    verify(httpManagement('materials', $wrongCategory)[0] === 422, 'Category edit must reject products outside its scope.');
    $product = loadProducts($pdo)[0]; $productInput['version'] = $product['version'];
    verify($product['status'] === 'inactive' && $product['closing'] === '12345678901238.123456', 'Metadata edit must not change receipt-derived stock.');
    $pdo->exec("INSERT INTO products (code,name,category,unit,tracking_mode) VALUES ('UNTRACKED','Untracked','other','Piece','none')");
    $untrackedId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,product_name,unit) VALUES (?,?,NULL,'Untracked','Piece')")->execute([$billId, $untrackedId]);
    $records = loadProducts($pdo);
    verify($records[0]['closing'] === null && $records[0]['stocks'][0]['closing'] === null, 'Untracked product quantities must not appear as zero.');
    $duplicateProduct = $productInput; $duplicateProduct['code'] = 'UNTRACKED';
    verify(httpManagement('products', $duplicateProduct)[0] === 422, 'Duplicate product code accepted.');
    $pdo->exec("INSERT INTO products (code,name,category,unit) VALUES ('MISSING-QTY','Missing quantity','other','Piece')");
    $missingQuantityId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,product_name,unit) VALUES (?,?,NULL,'Missing quantity','Piece')")->execute([$billId, $missingQuantityId]);
    $missingProduct = loadProducts($pdo)[0];
    verify($missingProduct['stock'] === 'unknown' && $missingProduct['closing'] === null, 'Missing tracked quantity must not be presented as zero stock.');
    foreach (productPages() as $page => $category) {
        $category = $category ?: 'other';
        $code = 'PAGE-' . $page;
        $pdo->prepare('INSERT INTO products (code,name,category,unit) VALUES (?,?,?,?)')->execute([$code, $code, $category, 'Piece']);
        $pageProduct = loadManagement($pdo, $page)['products'][0];
        verify($pageProduct['code'] === $code && $pageProduct['category'] === $category, 'Category page must read its real database product.');
        [$pageStatus, $pageBody] = httpManagement($page);
        verify($pageStatus === 200 && str_contains($pageBody, $code) && !str_contains($pageBody, 'data-preview="true"'), 'Category page must render database records without preview mode.');
        $pageInput = ['csrf_token' => $csrf, 'action' => 'save', 'id' => $pageProduct['id'], 'version' => $pageProduct['version'],
            'code' => $code, 'name' => 'Edited ' . $page, 'category' => $category, 'unit' => 'Piece', 'tracking_mode' => 'quantity', 'status' => 'active', 'specifications' => ''];
        verify(httpManagement($page, $pageInput)[0] === 200, 'Category page must save through its own endpoint.');
        verify(loadManagement($pdo, $page)['products'][0]['name'] === $pageInput['name'], 'Category page edit must persist in database.');
        if (productPages()[$page] !== '') {
            $pageInput['version'] = loadManagement($pdo, $page)['products'][0]['version'];
            $pageInput['category'] = $category === 'other' ? 'consumable' : 'other';
            verify(httpManagement($page, $pageInput)[0] === 422, 'Dedicated category page must reject moving a product to another group.');
        }
    }
    $invoiceXml = '<HDon xmlns="urn:test"><DLHDon><TTChung><SHDon>XML-001</SHDon><KHHDon>C26T</KHHDon><NLap>2026-10-10</NLap><DVTTe>VND</DVTTe></TTChung><NDHDon><NBan><Ten>Seller XML</Ten><MST>XML-TAX</MST><DChi>Address</DChi></NBan><DSHHDVu><HHDVu><TChat>1</TChat><MHHDVu>XML-SKU</MHHDVu><THHDVu>XML product</THHDVu><DVTinh>Piece</DVTinh><SLuong>1.123456</SLuong><DGia>2.5</DGia><ThTien>2.808640</ThTien><TSuat>10%</TSuat></HHDVu></DSHHDVu><TToan><TgTCThue>2.808640</TgTCThue><TgTTTBSo>3.089504</TgTTTBSo></TToan></NDHDon></DLHDon></HDon>';
    $parsedInvoice = parseImportXml($invoiceXml);
    verify($parsedInvoice['tax_code'] === 'XML-TAX' && $parsedInvoice['items'][0]['quantity'] === '1.123456', 'Namespaced XML seller and quantities must retain precision.');
    rejected(fn() => parseImportXml('<!DOCTYPE HDon [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $invoiceXml));
    rejected(fn() => parseImportXml('<broken>'));
    rejected(fn() => parseImportXml(str_replace('<TChat>1</TChat>', '<TChat>3</TChat>', $invoiceXml)));
    verify(httpDecisionPdf(['action' => 'xml'], $invoiceXml, 'invoice.xml', 'import-create', 'xml')[0] === 403, 'XML upload must require CSRF.');
    [$xmlStatus, $xmlBody] = httpDecisionPdf(['action' => 'xml', 'csrf_token' => $csrf], $invoiceXml, 'invoice.xml', 'import-create', 'xml');
    verify($xmlStatus === 200 && json_decode($xmlBody, true)['invoice']['supplier_name'] === 'Seller XML', 'XML endpoint must return parsed preview without writes.');
    $createInput = ['csrf_token' => $csrf, 'action' => 'create', 'warehouse' => $warehouseIds[0], 'number' => 'CREATED-XML', 'received_date' => '2026-10-10',
        'receipt_type' => 'purchase', 'status' => 'confirmed', 'supplier_id' => '', 'supplier_name' => 'Seller XML', 'tax_code' => 'XML-TAX',
        'invoice_number' => 'XML-001', 'invoice_series' => 'C26T', 'invoice_date' => '2026-10-10', 'invoice_before_tax' => '2.808640', 'invoice_tax' => '0.280864', 'invoice_after_tax' => '3.089504',
        'items' => json_encode($parsedInvoice['items'])];
    verify(httpManagement('import-create', array_diff_key($createInput, ['csrf_token' => true]))[0] === 403, 'Receipt create must require CSRF.');
    rejected(fn() => saveManagement($pdo, 'import-create', $targetId, $createInput));
    $beforeCounts = $pdo->query('SELECT (SELECT COUNT(*) FROM products) AS products,(SELECT COUNT(*) FROM suppliers) AS suppliers,(SELECT COUNT(*) FROM import_bill) AS bills')->fetch();
    $badCreate = $createInput; $badCreate['invoice_after_tax'] = '99';
    verify(httpManagement('import-create', $badCreate)[0] === 422, 'Invoice total mismatch must be rejected.');
    $badTaxCreate = $createInput; $badTaxCreate['invoice_tax'] = '0.28';
    verify(httpManagement('import-create', $badTaxCreate)[0] === 422, 'XML tax total must match the tax amounts actually saved.');
    verify($pdo->query('SELECT (SELECT COUNT(*) FROM products) AS products,(SELECT COUNT(*) FROM suppliers) AS suppliers,(SELECT COUNT(*) FROM import_bill) AS bills')->fetch() === $beforeCounts, 'Invalid receipt must roll back supplier/product creation.');
    verify(httpManagement('import-create', $createInput)[0] === 200, 'Creating receipt from XML preview failed.');
    $createdBill = array_values(array_filter(loadImports($pdo), static fn($row) => $row['number'] === 'CREATED-XML'))[0];
    verify($createdBill['total_before_tax'] === '2.808640' && $createdBill['total_after_tax'] === '3.089504' && $createdBill['items'][0]['decision_status'] === 'pending', 'Server totals or pending decisions incorrect.');
    $createdProduct = array_values(array_filter(loadProducts($pdo), static fn($row) => $row['code'] === 'XML-SKU'))[0];
    verify($createdProduct['closing'] === '1.123456' && $createdProduct['category'] === 'other', 'Confirmed new receipt must appear in stock and category pages.');
    $duplicateCreate = $createInput; $duplicateCreate['number'] = 'CREATED-XML-2';
    verify(httpManagement('import-create', $duplicateCreate)[0] === 422, 'Duplicate supplier invoice must be rejected.');
    $draftItem = ['product_id' => $createdProduct['id'], 'version' => $createdProduct['version'], 'quantity' => '2', 'unit_price' => '1', 'tax_code' => 'KCT', 'name' => 'Tampered name', 'unit' => 'Tampered unit'];
    $draftCreate = $createInput; $draftCreate['number'] = 'CREATED-DRAFT'; $draftCreate['status'] = 'draft'; $draftCreate['invoice_number'] = '';
    $draftCreate['invoice_before_tax'] = ''; $draftCreate['invoice_tax'] = ''; $draftCreate['invoice_after_tax'] = ''; $draftCreate['items'] = json_encode([$draftItem]);
    verify(httpManagement('import-create', $draftCreate)[0] === 200, 'Existing product draft receipt failed.');
    $draftBill = array_values(array_filter(loadImports($pdo), static fn($row) => $row['number'] === 'CREATED-DRAFT'))[0];
    verify($draftBill['items'][0]['product_name'] === 'XML product' && $draftBill['items'][0]['unit'] === 'Piece', 'Existing product metadata must come from database.');
    verify(array_values(array_filter(loadProducts($pdo), static fn($row) => $row['code'] === 'XML-SKU'))[0]['closing'] === '1.123456', 'Draft must not increase stock.');
    verify(httpManagement('import-create', $draftCreate)[0] === 422, 'Duplicate warehouse/year receipt number must be rejected.');
    $staleCreate = $draftCreate; $staleCreate['number'] = 'STALE-CATALOGUE';
    $staleItem = $draftItem; $staleItem['version'] = '2000-01-01 00:00:00.000000'; $staleCreate['items'] = json_encode([$staleItem]);
    verify(httpManagement('import-create', $staleCreate)[0] === 422, 'Receipt must reject stale product metadata to prevent reinterpreting quantities under a changed unit.');
    $badCreate = $draftCreate; $badCreate['number'] = 'BAD-QUANTITY'; $draftItem['quantity'] = '0'; $badCreate['items'] = json_encode([$draftItem]);
    verify(httpManagement('import-create', $badCreate)[0] === 422, 'Zero tracked quantity must be rejected.');
    $service = ['product_id' => '', 'code' => 'SERVICE', 'name' => 'Service', 'category' => 'other', 'unit' => 'Lần', 'tracking_mode' => 'none', 'quantity' => '', 'unit_price' => '10', 'tax_code' => 'KCT'];
    $serviceCreate = $draftCreate; $serviceCreate['number'] = 'SERVICE-RECEIPT'; $serviceCreate['status'] = 'confirmed'; $serviceCreate['items'] = json_encode([$service]);
    verify(httpManagement('import-create', $serviceCreate)[0] === 200, 'Untracked service receipt failed.');
    verify(array_values(array_filter(loadProducts($pdo), static fn($row) => $row['code'] === 'SERVICE'))[0]['closing'] === null, 'Untracked service must not invent stock.');
    $repeatedService = $service; $repeatedService['code'] = 'REPEATED-SERVICE';
    $repeatedCreate = $serviceCreate; $repeatedCreate['number'] = 'REPEATED-LINES'; $repeatedCreate['items'] = json_encode([$repeatedService, $repeatedService]);
    verify(httpManagement('import-create', $repeatedCreate)[0] === 200, 'Repeated new SKU lines must be accepted without duplicate catalogue products.');
    $repeatedBill = array_values(array_filter(loadImports($pdo), static fn($row) => $row['number'] === 'REPEATED-LINES'))[0];
    verify(count($repeatedBill['items']) === 2 && $repeatedBill['items'][0]['product_id'] === $repeatedBill['items'][1]['product_id'] && $repeatedBill['total_before_tax'] === '20.000000', 'Repeated lines must retain individual amounts and share one new product.');
    $pdfCreate = $draftCreate; $pdfCreate['number'] = 'PDF-RECEIPT';
    verify(httpDecisionPdf($pdfCreate, '<html>bad file</html>', 'bad.pdf', 'import-create')[0] === 422, 'Receipt attachment must reject non-PDF files.');
    verify(httpDecisionPdf($pdfCreate, $pdf, 'invoice.pdf', 'import-create')[0] === 200, 'Receipt PDF upload failed.');
    $pdfBill = array_values(array_filter(loadImports($pdo), static fn($row) => $row['number'] === 'PDF-RECEIPT'))[0];
    verify(count($pdfBill['attachments']) === 1, 'Receipt list must expose linked PDF metadata.');
    $importPdfId = (string) $pdfBill['attachments'][0]['id'];
    [$downloadStatus, $downloadPdf] = httpManagement('import-file&id=' . $importPdfId);
    verify($downloadStatus === 200 && $downloadPdf === $pdf, 'Receipt PDF must download after authorization.');
    verify(httpManagement('decision-file&id=' . $importPdfId)[0] === 404, 'Receipt PDF must not be exposed through the decision file endpoint.');
    $savedCookie = $cookie; $cookie = '';
    verify(httpManagement('import-file&id=' . $importPdfId)[0] === 303, 'Guest must not download receipt PDF.'); $cookie = $savedCookie;
    $pdo->prepare('UPDATE users SET is_super_admin=0 WHERE id=?')->execute([$actorId]);
    verify(httpManagement('import-file&id=' . $importPdfId)[0] === 403, 'Non-admin must not download receipt PDF.');
    $pdo->prepare('UPDATE users SET is_super_admin=1 WHERE id=?')->execute([$actorId]);
    try { $pdo->exec('DELETE FROM audit_logs'); throw new RuntimeException('Immutable audit trigger missing.'); }
    catch (PDOException $error) { verify($error->getCode() === '45000', 'Unexpected audit trigger error.'); }
    $lotCreate = $draftCreate; $lotCreate['number'] = 'EXPORT-SOURCE'; $lotCreate['status'] = 'confirmed';
    $lotCreate['items'] = json_encode([['product_id'=>$createdProduct['id'],'version'=>$createdProduct['version'],'quantity'=>'3','unit_price'=>'3.333333','amount_before_tax'=>'10','tax_code'=>'KCT']]);
    verify(httpManagement('import-create', $lotCreate)[0] === 200, 'Export source receipt failed.');
    $lotBill = array_values(array_filter(loadImports($pdo), static fn($row)=>$row['number']==='EXPORT-SOURCE'))[0];
    $lotId = (string)$lotBill['items'][0]['id']; $lot = loadExportSources($pdo,$lotId)[0];
    $exportLine = ['source_import_detail_id'=>$lotId,'version'=>$lot['version'],'quantity'=>'1','note'=>'Lot note'];
    $exportInput = ['csrf_token'=>$csrf,'action'=>'create','warehouse'=>$warehouseIds[0],'number'=>'EXPORT-DRAFT','issued_date'=>'2026-10-10',
        'issue_type'=>'allocation','status'=>'draft','reason'=>'Cấp phát kiểm thử','receiver_name'=>'Người nhận <script>','items'=>json_encode([$exportLine])];
    verify(httpManagement('export-create', array_diff_key($exportInput,['csrf_token'=>true]))[0] === 403, 'Exports require CSRF.');
    rejected(fn()=>saveExport($pdo,$targetId,$exportInput));
    foreach (['exports','export-create'] as $route) verify(httpManagement($route)[0]===200,'Export route failed.');
    verify(httpManagement('exports',$exportInput)[0]===405,'Export list must be read-only.');
    verify(httpManagement('export-create',$exportInput)[0]===200 && loadExportSources($pdo,$lotId)[0]['remaining_quantity']==='3.000000','Draft must not consume stock.');
    verify(httpManagement('export-create',$exportInput)[0]===422,'Duplicate export number must fail.');
    foreach (['quantity'=>'0','version'=>str_repeat('b',64),'source_import_detail_id'=>'999999999'] as $key=>$value) {
        $bad=$exportInput; $bad['number']='BAD-EXPORT'; $line=$exportLine; $line[$key]=$value; $bad['items']=json_encode([$line]);
        verify(httpManagement('export-create',$bad)[0]===422,'Invalid export line accepted: '.$key);
    }
    $bad=$exportInput; $bad['number']='BAD-EXPORT'; $line=$exportLine; $line['quantity']='2'; $bad['items']=json_encode([$line,$line]);
    verify(httpManagement('export-create',$bad)[0]===422,'Repeated lot lines must share the availability limit.');
    $bad=$exportInput; $bad['warehouse']=$warehouseIds[1]; verify(httpManagement('export-create',$bad)[0]===422,'Cross-warehouse source must fail.');
    $bad=$exportInput; $bad['issued_date']='2026-10-09'; verify(httpManagement('export-create',$bad)[0]===422,'Export cannot precede its source import.');
    $bad=$exportInput; $bad['issue_type']='transfer'; verify(httpManagement('export-create',$bad)[0]===422,'Transfer destination is required.');
    $bad['destination_warehouse']=$warehouseIds[0]; verify(httpManagement('export-create',$bad)[0]===422,'Same-warehouse transfer must fail.');
    $bad['destination_warehouse']='999999999'; verify(httpManagement('export-create',$bad)[0]===422,'Missing destination must fail.');
    $confirmed=$exportInput; $confirmed['number']='EXPORT-FIRST'; $confirmed['status']='confirmed';
    verify(httpDecisionPdf($confirmed,'not a PDF','bad.pdf','export-create')[0]===422,'Export PDF validation failed.');
    verify(httpDecisionPdf($confirmed,$pdf,'export.pdf','export-create')[0]===200,'Confirmed export with PDF failed.');
    $first=array_values(array_filter(loadExports($pdo),static fn($row)=>$row['number']==='EXPORT-FIRST'))[0];
    verify($first['receiver_name']==='Người nhận <script>' && $first['total_before_tax']==='3.333333' && $first['items'][0]['decision_status']==='pending','Export snapshot, receiver or precise allocation incorrect.');
    verify($first['items'][0]['product_name']===$lotBill['items'][0]['product_name'] && $first['items'][0]['source_number']==='EXPORT-SOURCE','Export must retain source snapshots.');
    $exportPdfId=(string)$first['attachments'][0]['id'];
    verify(httpManagement('export-file&id='.$exportPdfId)[1]===$pdf,'Export PDF download failed.');
    verify(httpManagement('import-file&id='.$exportPdfId)[0]===404 && httpManagement('export-file&id='.$importPdfId)[0]===404,'PDF owner boundaries failed.');
    $savedCookie=$cookie; $cookie=''; verify(httpManagement('export-file&id='.$exportPdfId)[0]===303,'Guest download must fail.'); $cookie=$savedCookie;
    $pdo->prepare('UPDATE users SET is_super_admin=0 WHERE id=?')->execute([$actorId]);
    foreach (['exports','export-create','export-file&id='.$exportPdfId] as $route) verify(httpManagement($route)[0]===403,'Non-admin export access must fail.');
    $pdo->prepare('UPDATE users SET is_super_admin=1 WHERE id=?')->execute([$actorId]);
    $bad=$confirmed; $bad['number']='EXPORT-OVERDRAW'; $line=$exportLine; $line['quantity']='3'; $bad['items']=json_encode([$line]);
    verify(httpManagement('export-create',$bad)[0]===422,'Save must recheck stock after another confirmed export.');
    $final=$confirmed; $final['number']='EXPORT-FINAL'; $line['quantity']='2'; $final['items']=json_encode([$line]);
    verify(httpManagement('export-create',$final)[0]===200,'Final withdrawal failed.');
    $last=array_values(array_filter(loadExports($pdo),static fn($row)=>$row['number']==='EXPORT-FINAL'))[0];
    verify($last['total_before_tax']==='6.666667' && loadExportSources($pdo,$lotId)[0]['remaining_amount']==='0.000000' && loadExportSources($pdo,$lotId)[0]['remaining_quantity']==='0.000000','Final withdrawal must consume exact remaining value and quantity.');
    $lotCreate['number']='TRANSFER-SOURCE'; verify(httpManagement('import-create',$lotCreate)[0]===200,'Transfer source failed.');
    $transferLot=array_values(array_filter(loadImports($pdo),static fn($row)=>$row['number']==='TRANSFER-SOURCE'))[0]['items'][0];
    $transferId=(string)$transferLot['id']; $transferSource=loadExportSources($pdo,$transferId)[0];
    $transfer=$confirmed; $transfer['number']='EXPORT-TRANSFER'; $transfer['issue_type']='transfer'; $transfer['destination_warehouse']=$warehouseIds[1];
    $transfer['items']=json_encode([['source_import_detail_id'=>$transferId,'version'=>$transferSource['version'],'quantity'=>'1']]);
    verify(httpManagement('export-create',$transfer)[0]===200,'Confirmed transfer failed.');
    $transferBill=array_values(array_filter(loadExports($pdo),static fn($row)=>$row['number']==='EXPORT-TRANSFER'))[0];
    verify(count($transferBill['transfers'])===1,'Transfer must expose paired incoming receipt.');
    $incoming=array_values(array_filter(loadImports($pdo),static fn($row)=>$row['id']===(string)$transferBill['transfers'][0]['id']))[0];
    verify($incoming['warehouse_id']===$warehouseIds[1] && $incoming['status']==='confirmed' && $incoming['total_before_tax']===$transferBill['total_before_tax'] && (string)$incoming['items'][0]['source_export_detail_id']===(string)$transferBill['items'][0]['id'],'Paired import must preserve transfer lot, warehouse, amount and status.');
    $transfer['number']='DRAFT-TRANSFER'; $transfer['status']='draft'; verify(httpManagement('export-create',$transfer)[0]===200,'Draft transfer failed.');
    $draftTransfer=array_values(array_filter(loadExports($pdo),static fn($row)=>$row['number']==='DRAFT-TRANSFER'))[0];
    verify(!$draftTransfer['transfers'] && loadExportSources($pdo,$transferId)[0]['remaining_quantity']==='2.000000','Draft transfer must not move stock or create incoming receipt.');
    [, $body]=httpManagement('exports'); verify(str_contains($body,'EXPORT-TRANSFER') && str_contains($body,'Người nhận &lt;script&gt;') && !str_contains($body,'data-preview="true"'),'Export list must render real records and escape receivers.');
    $dashboardTime=new DateTimeImmutable('2026-10-10 12:00:00',new DateTimeZone('Asia/Ho_Chi_Minh'));
    $overview=loadDashboard($pdo,$actorId,$dashboardTime);
    verify($overview['products']===(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() && array_sum($overview['categories'])===$overview['products'],'Dashboard must count distinct catalogue products rather than receipt lines.');
    verify($overview['active_warehouses']===(int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status='active'")->fetchColumn(),'Active warehouse count incorrect.');
    foreach(['import'=>['import_bill','received_date'],'export'=>['export_bill','issued_date']] as $direction=>[$table,$dateField]) {
        $expected=(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $dateField>='2026-10-01' AND $dateField<'2026-11-01'")->fetchColumn();
        $confirmedCount=(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $dateField>='2026-10-01' AND $dateField<'2026-11-01' AND status='confirmed'")->fetchColumn();
        verify($overview['counts'][$direction]===['total'=>$expected,'confirmed'=>$confirmedCount] && $overview['months']['2026-10'][$direction]===$expected,'Dashboard monthly status counts or chart incorrect.');
    }
    verify(count($overview['receipts'])===10 && count($overview['months'])===6 && array_key_first($overview['months'])==='2026-05','Dashboard must limit recent receipts and retain empty months.');
    $recentAmounts=array_column($overview['receipts'],'amount'); verify(in_array('6.666667',$recentAmounts,true),'Dashboard must preserve stored before-tax DECIMAL values.');
    $rollover=loadDashboard($pdo,$actorId,new DateTimeImmutable('2027-01-01T00:15:00+07:00'));
    verify(array_keys($rollover['months'])===['2026-08','2026-09','2026-10','2026-11','2026-12','2027-01'] && $rollover['counts']['import']['total']===0,'Dashboard month windows must cross year boundaries.');
    $scoped=loadDashboard($pdo,$targetId,$dashboardTime);
    verify(count($scoped['warehouses'])===1 && isset($scoped['warehouses'][$warehouseIds[0]]) && array_reduce($scoped['receipts'],static fn($ok,$row)=>$ok && (string)$row['warehouse_id']===$warehouseIds[0],true),'Dashboard must filter warehouse grants on the server.');
    $expectedScoped=(int)$pdo->query("SELECT COUNT(*) FROM import_bill WHERE warehouse_id={$warehouseIds[0]} AND received_date>='2026-10-01' AND received_date<'2026-11-01'")->fetchColumn();
    verify($scoped['counts']['import']['total']===$expectedScoped && $scoped['months']['2026-10']['import']===$expectedScoped && $scoped['products']<$overview['products'],'Restricted dashboards must not leak global metric/chart/catalogue totals.');
    $pdo->prepare('DELETE FROM user_warehouses WHERE user_id=? AND warehouse_id=?')->execute([$targetId,$warehouseIds[0]]);
    $emptyOverview=loadDashboard($pdo,$targetId,$dashboardTime);
    verify($emptyOverview['products']===0 && !$emptyOverview['receipts'] && !$emptyOverview['warehouses'] && $emptyOverview['counts']['import']['total']===0,'No grants must expose no business data.');
    $pdo->prepare("INSERT INTO user_warehouses (user_id,warehouse_id,role) VALUES (?,?,'viewer')")->execute([$targetId,$warehouseIds[0]]);
    $pdo->prepare("UPDATE warehouses SET status='inactive' WHERE id=?")->execute([$warehouseIds[0]]);
    verify(!loadDashboard($pdo,$targetId,$dashboardTime)['receipts'],'Inactive warehouses must not remain visible to ordinary users.');
    $pdo->prepare("UPDATE warehouses SET status='active' WHERE id=?")->execute([$warehouseIds[0]]);
    [, $body]=httpManagement('home'); verify(str_contains($body,'Dữ liệu thực tế') && !str_contains($body,'Số liệu minh họa') && str_contains($body,'data-dashboard-metric='),'Home must render real database totals.');
    verify(httpManagement('home',['csrf_token'=>$csrf])[0]===405,'Dashboard must reject writes.');
    $pdo->prepare('UPDATE users SET is_super_admin=0 WHERE id=?')->execute([$actorId]);
    [, $body]=httpManagement('home'); verify(!str_contains($body,'data-receipt ') && !str_contains($body,'class="heading-actions"'),'Ordinary account without grants must see no receipts or create shortcuts.');
    $pdo->prepare('UPDATE users SET is_super_admin=1 WHERE id=?')->execute([$actorId]);
    $pdo->exec("CREATE TRIGGER test_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test audit failure'");
    $rollbackExport=$confirmed; $rollbackExport['number']='ROLLBACK-EXPORT'; $rollbackExport['items']=$transfer['items'];
    $exportFilesBefore=count(glob($testRoot.'/storage/uploads/exports/*.pdf'));
    verify(httpDecisionPdf($rollbackExport,$pdf,'rollback.pdf','export-create')[0]===503,'Export audit failure must abort save.');
    verify(!(int)$pdo->query("SELECT COUNT(*) FROM export_bill WHERE number='ROLLBACK-EXPORT'")->fetchColumn() && count(glob($testRoot.'/storage/uploads/exports/*.pdf'))===$exportFilesBefore && loadExportSources($pdo,$transferId)[0]['remaining_quantity']==='2.000000','Failed export must roll back receipt, stock and PDF.');
    $transfer['number']='ROLLBACK-TRANSFER'; $transfer['status']='confirmed'; $importCount=(int)$pdo->query('SELECT COUNT(*) FROM import_bill')->fetchColumn();
    verify(httpManagement('export-create',$transfer)[0]===503 && (int)$pdo->query('SELECT COUNT(*) FROM import_bill')->fetchColumn()===$importCount && !(int)$pdo->query("SELECT COUNT(*) FROM export_bill WHERE number='ROLLBACK-TRANSFER'")->fetchColumn(),'Transfer and paired incoming receipt must roll back together.');
    $rollbackCreate = $draftCreate; $rollbackCreate['number'] = 'MUST-ROLLBACK';
    verify(httpManagement('import-create', $rollbackCreate)[0] === 503, 'Receipt creation must fail if audit insert fails.');
    verify(!(int) $pdo->query("SELECT COUNT(*) FROM import_bill WHERE number='MUST-ROLLBACK'")->fetchColumn(), 'Receipt must roll back together with audit.');
    $importFilesBefore = count(glob($testRoot . '/storage/uploads/imports/*.pdf'));
    verify(httpDecisionPdf($rollbackCreate, $pdf, 'rollback.pdf', 'import-create')[0] === 503, 'PDF receipt must roll back on audit failure.');
    verify(count(glob($testRoot . '/storage/uploads/imports/*.pdf')) === $importFilesBefore, 'Rollback must remove receipt PDF from disk.');
    $productInput['name'] = 'Must roll back';
    verify(httpManagement('products', $productInput)[0] === 503, 'Product write must fail if audit insert fails.');
    $stmt = $pdo->prepare('SELECT name FROM products WHERE id=?'); $stmt->execute([$productId]);
    verify($stmt->fetchColumn() === 'Product <script>', 'Product update did not roll back with audit.');
    $rollbackDecisionInput = $decisionInput;
    $currentDecision = decisionSnapshot($pdo, $newDecisionId);
    $rollbackDecisionInput['version'] = $currentDecision['updated_at']; $rollbackDecisionInput['links_version'] = decisionLinksVersion($currentDecision['links']);
    $rollbackDecisionInput['number'] = 'ROLLBACK-QD';
    $rollbackDecisionInput['lines[import][' . $pendingId . ']'] = $pdo->query("SELECT updated_at FROM import_bill_details WHERE id=$pendingId")->fetchColumn();
    verify(httpDecisionPdf($rollbackDecisionInput, $pdf)[0] === 503, 'Audit failure must reject PDF save.');
    verify(decisionSnapshot($pdo, $newDecisionId) === $currentDecision && count(glob($testRoot . '/storage/uploads/decisions/*.pdf')) === 1, 'Rollback left changed links, metadata or orphan PDF.');
    $current = managementSnapshot($pdo, 'accounts', $targetId);
    try {
        saveManagement($pdo, 'accounts', $actorId, ['action' => 'state', 'id' => $targetId, 'version' => $current['updated_at'], 'status' => 'locked']);
        throw new RuntimeException('Write committed without its audit record.');
    } catch (PDOException $error) { verify($error->getCode() === '45000', 'Unexpected audit insertion error.'); }
    verify(managementSnapshot($pdo, 'accounts', $targetId) === $current, 'Audit insertion failure must roll back the data write.');
    $currentWarehouse = managementSnapshot($pdo, 'warehouses', $warehouseIds[0]);
    try {
        saveManagement($pdo, 'warehouses', $actorId, ['action' => 'state', 'id' => $warehouseIds[0], 'version' => $currentWarehouse['updated_at'], 'status' => 'inactive']);
        throw new RuntimeException('Warehouse change committed without audit.');
    } catch (PDOException $error) { verify($error->getCode() === '45000', 'Unexpected warehouse audit failure.'); }
    verify(managementSnapshot($pdo, 'warehouses', $warehouseIds[0]) === $currentWarehouse, 'Warehouse write must roll back when audit fails.');
    verify(httpManagement('employees', ['csrf_token' => $csrf, 'action' => 'delete', 'id' => $rollbackEmployeeId, 'version' => $rollbackEmployee['updated_at']])[0] === 503, 'Deletion must fail when audit insert fails.');
    verify(managementSnapshot($pdo, 'employees', $rollbackEmployeeId) === $rollbackEmployee, 'Audit failure must restore the deleted employee.');
    echo "PASS: real HTTP management saves, CSRF, permissions, stale writes, import/export lot balances, rounding, paired transfers, PDFs, rollback and atomic audit history.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $source->exec("USE `$original`");
    if (preg_match('/^qlvt_management_test_[a-f0-9]{10}$/D', $database)) $source->exec("DROP DATABASE IF EXISTS `$database`");
    if (is_dir($testRoot)) {
        verify(dirname(realpath($testRoot)) === realpath(sys_get_temp_dir()) && preg_match('/^qlvt-mgmt-[a-f0-9]{10}$/D', basename($testRoot)), 'Unsafe temporary cleanup path.');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($testRoot);
    }
}
