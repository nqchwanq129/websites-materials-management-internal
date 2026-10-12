<?php
declare(strict_types=1);

function receiptPrintTextFields(): array
{
    return ['unit_name'=>200, 'department_name'=>200, 'department_address'=>500];
}
function receiptPrintSigners(): array
{
    return ['keeper'=>'Thủ kho', 'accountant'=>'Phụ trách kế toán', 'director'=>'Giám đốc'];
}
function receiptPrintConfigVersion(array $warehouse, array $settings): string
{
    $values = [$warehouse['name'], $warehouse['address'], $warehouse['updated_at']];
    foreach (receiptPrintTextFields() as $field=>$max) $values[] = $settings[$field] ?? '';
    foreach (receiptPrintSigners() as $role=>$label) $values[] = (string)($settings[$role.'_employee_id'] ?? '');
    return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function warehousePrintSettings(PDO $pdo, string $warehouseId): array
{
    $stmt = $pdo->prepare('SELECT unit_name,department_name,department_address,location,keeper_employee_id,accountant_employee_id,director_employee_id FROM warehouse_print_settings WHERE warehouse_id=?');
    $stmt->execute([$warehouseId]);
    return $stmt->fetch() ?: ['unit_name'=>'','department_name'=>'','department_address'=>'','location'=>'',
        'keeper_employee_id'=>null,'accountant_employee_id'=>null,'director_employee_id'=>null];
}
function receiptSignerName(PDO $pdo, ?string $id, bool $active = true): string
{
    if ($id === null) return '';
    $stmt = $pdo->prepare('SELECT full_name,status FROM employees WHERE id=?');
    $stmt->execute([$id]); $employee = $stmt->fetch();
    if (!$employee || ($active && $employee['status'] !== 'active')) throw new DomainException('Người ký không tồn tại hoặc đã ngừng hoạt động. Hãy chọn lại nhân viên.');
    return $employee['full_name'];
}
function saveWarehousePrintSettings(PDO $pdo, string $warehouseId, array $input): void
{
    $values = [];
    foreach (receiptPrintTextFields() as $field=>$max) $values[] = managementText($input, $field, $max);
    foreach (receiptPrintSigners() as $role=>$label) {
        $id = managementId($input[$role.'_employee_id'] ?? '', true);
        receiptSignerName($pdo, $id); $values[] = $id;
    }
    $pdo->prepare('INSERT INTO warehouse_print_settings (warehouse_id,unit_name,department_name,department_address,keeper_employee_id,accountant_employee_id,director_employee_id)
        VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE unit_name=VALUES(unit_name),department_name=VALUES(department_name),department_address=VALUES(department_address),
        keeper_employee_id=VALUES(keeper_employee_id),accountant_employee_id=VALUES(accountant_employee_id),director_employee_id=VALUES(director_employee_id)')
        ->execute([$warehouseId,...$values]);
}
function receiptPrintSnapshot(PDO $pdo, string $warehouseId, string $actorId, array $input = [], bool $activeSigners = true): array
{
    $stmt = $pdo->prepare('SELECT name,address,updated_at FROM warehouses WHERE id=?');
    $stmt->execute([$warehouseId]); $warehouse = $stmt->fetch();
    if (!$warehouse) throw new DomainException('Kho không tồn tại.');
    $settings = warehousePrintSettings($pdo, $warehouseId);
    if (array_key_exists('print_version', $input) && managementText($input, 'print_version', 64, true) !== receiptPrintConfigVersion($warehouse, $settings)) {
        throw new DomainException('Cấu hình in phiếu của kho đã thay đổi. Hãy tải lại trang trước khi lưu.');
    }
    $signers = $input['print_signers'] ?? [];
    if (!is_array($signers)) throw new DomainException('Thông tin người ký không hợp lệ.');
    $snapshot = array_intersect_key($settings, receiptPrintTextFields());
    $snapshot['department_address'] = $snapshot['department_address'] ?: ($warehouse['address'] ?? '');
    $snapshot['location'] = $warehouse['address'] ?? '';
    $snapshot['warehouse_name'] = $warehouse['name'];
    $stmt = $pdo->prepare('SELECT COALESCE(e.full_name,u.username) FROM users u LEFT JOIN employees e ON e.id=u.employee_id WHERE u.id=?');
    $stmt->execute([$actorId]); $snapshot['creator_name'] = $stmt->fetchColumn() ?: '';
    foreach (receiptPrintSigners() as $role=>$label) {
        $field = $role.'_employee_id';
        $id = managementId(array_key_exists($field, $signers) ? $signers[$field] : ($settings[$field] === null ? '' : (string)$settings[$field]), true);
        $snapshot[$role.'_name'] = receiptSignerName($pdo, $id, $activeSigners);
    }
    return $snapshot;
}
