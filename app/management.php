<?php
declare(strict_types=1);
require_once __DIR__ . '/decisions.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/imports.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/exports.php';

function managementText(array $input, string $key, int $max, bool $required = false): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new DomainException('Thông tin nhập không hợp lệ.');
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max || str_contains($value, "\0")) {
        $label = ['username' => 'tên đăng nhập', 'code' => 'mã', 'full_name' => 'họ và tên',
            'email' => 'email', 'phone' => 'số điện thoại', 'name' => 'tên kho', 'address' => 'địa chỉ', 'status' => 'trạng thái',
            'action' => 'thao tác', 'version' => 'phiên bản dữ liệu'][$key] ?? 'thông tin nhập';
        throw new DomainException('Vui lòng kiểm tra ' . $label . ' và độ dài cho phép.');
    }
    return $value;
}

function managementId(mixed $value, bool $optional = false): ?string
{
    if ($optional && ($value === '' || $value === null)) return null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,19}$/D', $value)) throw new DomainException('Mã bản ghi không hợp lệ.');
    return $value;
}

function managementSnapshot(PDO $pdo, string $page, string $id): ?array
{
    if ($page === 'accounts') {
        $statement = $pdo->prepare('SELECT id,username,employee_id,is_super_admin,status,updated_at FROM users WHERE id=?');
    } elseif ($page === 'warehouses') {
        $statement = $pdo->prepare('SELECT id,code,name,address,status,updated_at FROM warehouses WHERE id=?');
    } else {
        $statement = $pdo->prepare('SELECT id,code,full_name,email,phone,warehouse_id,status,updated_at FROM employees WHERE id=?');
    }
    $statement->execute([$id]);
    $row = $statement->fetch();
    if (!$row) return null;
    if ($page === 'accounts') {
        $row['is_super_admin'] = (bool) $row['is_super_admin'];
        $statement = $pdo->prepare('SELECT warehouse_id,role FROM user_warehouses WHERE user_id=? ORDER BY warehouse_id');
        $statement->execute([$id]);
        $row['warehouses'] = [];
        foreach ($statement as $grant) $row['warehouses'][(string) $grant['warehouse_id']] = $grant['role'];
    }
    return $row;
}

function saveManagement(PDO $pdo, string $page, string $actorId, array $input, array $files = []): string
{
    if ($page === 'import-create') return saveImport($pdo, $actorId, $input, $files);
    if ($page === 'imports') return ($input['action'] ?? '') === 'update' ? updateImport($pdo, $actorId, $input) : changeImportState($pdo, $actorId, $input);
    if ($page === 'export-create') return saveExport($pdo, $actorId, $input, $files);
    if ($page === 'decisions') return saveDecision($pdo, $actorId, $input, $files);
    if (array_key_exists($page, productPages())) return saveProduct($pdo, $actorId, $input, productPages()[$page]);
    if (!in_array($page, ['accounts', 'employees', 'warehouses'], true)) throw new DomainException('Không được thay đổi nhật ký.');
    $action = managementText($input, 'action', 20, true);
    if (!in_array($action, ['save', 'state', 'delete'], true) || ($action === 'delete' && $page === 'accounts')) throw new DomainException('Thao tác không hợp lệ.');
    $id = managementId($input['id'] ?? null, true);
    if (in_array($action, ['state', 'delete'], true) && $id === null) throw new DomainException('Thiếu bản ghi cần thay đổi.');
    $pdo->beginTransaction();
    try {
        // ponytail: serialize low-volume management writes by locking users; use a dedicated lock row if throughput grows.
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        if (!userCanSignIn($actor) || !(int) $actor['is_super_admin']) throw new DomainException('Bạn không có quyền thực hiện thao tác này.');
        $before = $id ? managementSnapshot($pdo, $page, $id) : null;
        if ($id && !$before) throw new DomainException('Bản ghi không còn tồn tại.');
        if ($id && managementText($input, 'version', 32, true) !== $before['updated_at']) {
            throw new DomainException('Thông tin đã được người khác cập nhật. Hãy tải lại trang trước khi sửa.');
        }
        $auditAction = $id ? 'update' : 'create';
        $passwordChanged = false;
        if ($action === 'delete') {
            $table = $page === 'warehouses' ? 'warehouses' : 'employees';
            $statement = $pdo->prepare("DELETE FROM $table WHERE id=? AND updated_at=?");
            $statement->execute([$id, $before['updated_at']]);
            if ($statement->rowCount() !== 1) throw new DomainException('Thông tin đã thay đổi. Hãy tải lại trang trước khi xóa.');
            $auditAction = 'delete';
        } elseif ($page === 'accounts') {
            if ($action === 'state') {
                $status = managementText($input, 'status', 10, true);
                if (!in_array($status, ['active', 'locked'], true)) throw new DomainException('Trạng thái tài khoản không hợp lệ.');
                $pdo->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status, $id]);
                $auditAction = $status === 'locked' ? 'lock' : 'unlock';
            } else {
                $username = managementText($input, 'username', 100, true);
                $employeeId = managementId($input['employee'] ?? null, true);
                $adminValue = $input['admin'] ?? '0';
                if (!is_string($adminValue) || !in_array($adminValue, ['0', '1', 'on'], true)) throw new DomainException('Quyền quản trị không hợp lệ.');
                $admin = $adminValue !== '0';
                $status = managementText($input, 'status', 10, true);
                if (!in_array($status, ['active', 'locked'], true)) throw new DomainException('Trạng thái tài khoản không hợp lệ.');
                if ($employeeId) {
                    $stmt = $pdo->prepare('SELECT id FROM employees WHERE id=?');
                    $stmt->execute([$employeeId]);
                    if (!$stmt->fetch()) throw new DomainException('Nhân viên liên kết không tồn tại.');
                }
                $roles = $input['warehouses'] ?? [];
                if (!is_array($roles)) throw new DomainException('Quyền theo kho không hợp lệ.');
                $desiredRoles = [];
                if (!$admin) foreach ($roles as $warehouseId => $role) {
                    $warehouseId = managementId((string) $warehouseId);
                    if (!is_string($role) || !in_array($role, ['', 'keeper', 'viewer'], true)) throw new DomainException('Vai trò theo kho không hợp lệ.');
                    if ($role === '') continue;
                    $stmt = $pdo->prepare('SELECT status FROM warehouses WHERE id=?');
                    $stmt->execute([$warehouseId]);
                    $warehouseStatus = $stmt->fetchColumn();
                    if (!$warehouseStatus || ($warehouseStatus !== 'active' && ($before['warehouses'][$warehouseId] ?? null) !== $role)) {
                        throw new DomainException('Chỉ được cấp quyền mới cho kho đang hoạt động.');
                    }
                    $desiredRoles[$warehouseId] = $role;
                }
                $password = $input['password'] ?? '';
                if (!is_string($password) || str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')
                    || (($id === null || $password !== '') && (mb_strlen($password) < 8 || strlen($password) > 72))) {
                    throw new DomainException('Mật khẩu cần ít nhất 8 ký tự và tối đa 72 byte.');
                }
                if ($id) {
                    $pdo->prepare('UPDATE users SET username=?,employee_id=?,is_super_admin=?,status=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')
                        ->execute([$username, $employeeId, (int) $admin, $status, $id]);
                    if ($password !== '') {
                        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                        $passwordChanged = true;
                    }
                } else {
                    $pdo->prepare('INSERT INTO users (username,password_hash,employee_id,is_super_admin,status,created_by) VALUES (?,?,?,?,?,?)')
                        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $employeeId, (int) $admin, $status, $actorId]);
                    $id = $pdo->lastInsertId();
                }
                $existingRoles = $before['warehouses'] ?? [];
                ksort($desiredRoles);
                foreach ($existingRoles as $warehouseId => $role) {
                    if (!isset($desiredRoles[$warehouseId])) $pdo->prepare('DELETE FROM user_warehouses WHERE user_id=? AND warehouse_id=?')->execute([$id, $warehouseId]);
                }
                foreach ($desiredRoles as $warehouseId => $role) {
                    $pdo->prepare('INSERT INTO user_warehouses (user_id,warehouse_id,role,created_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)')
                        ->execute([$id, $warehouseId, $role, $actorId]);
                }
                if ($before && $before['warehouses'] !== $desiredRoles) $auditAction = 'assign_access';
            }
        } elseif ($page === 'warehouses') {
            $status = managementText($input, 'status', 10, true);
            if (!in_array($status, ['active', 'inactive'], true)) throw new DomainException('Trạng thái kho không hợp lệ.');
            if ($action === 'state') {
                $pdo->prepare('UPDATE warehouses SET status=? WHERE id=?')->execute([$status, $id]);
                $auditAction = $status === 'inactive' ? 'deactivate' : 'activate';
            } else {
                $code = managementText($input, 'code', 32, true);
                $name = managementText($input, 'name', 200, true);
                $address = managementText($input, 'address', 500);
                if ($id) $pdo->prepare('UPDATE warehouses SET code=?,name=?,address=?,status=? WHERE id=?')->execute([$code, $name, $address ?: null, $status, $id]);
                else {
                    $pdo->prepare('INSERT INTO warehouses (code,name,address,status,created_by) VALUES (?,?,?,?,?)')->execute([$code, $name, $address ?: null, $status, $actorId]);
                    $id = $pdo->lastInsertId();
                }
            }
        } else {
            if ($action === 'state') {
                $status = managementText($input, 'status', 10, true);
                if (!in_array($status, ['active', 'inactive'], true)) throw new DomainException('Trạng thái nhân viên không hợp lệ.');
                $pdo->prepare('UPDATE employees SET status=? WHERE id=?')->execute([$status, $id]);
                $auditAction = $status === 'inactive' ? 'deactivate' : 'activate';
            } else {
                $code = managementText($input, 'code', 32, true);
                $name = managementText($input, 'full_name', 200, true);
                $email = managementText($input, 'email', 254);
                $phone = managementText($input, 'phone', 32);
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Email không hợp lệ.');
                $warehouseId = managementId($input['warehouse'] ?? null);
                $stmt = $pdo->prepare('SELECT status FROM warehouses WHERE id=?');
                $stmt->execute([$warehouseId]);
                $warehouseStatus = $stmt->fetchColumn();
                if (!$warehouseStatus || ($warehouseStatus !== 'active' && (string) ($before['warehouse_id'] ?? '') !== $warehouseId)) {
                    throw new DomainException('Vui lòng chọn kho làm việc đang hoạt động.');
                }
                $status = managementText($input, 'status', 10, true);
                if (!in_array($status, ['active', 'inactive'], true)) throw new DomainException('Trạng thái nhân viên không hợp lệ.');
                if ($id) $pdo->prepare('UPDATE employees SET code=?,full_name=?,email=?,phone=?,warehouse_id=?,status=? WHERE id=?')
                    ->execute([$code, $name, $email ?: null, $phone ?: null, $warehouseId, $status, $id]);
                else {
                    $pdo->prepare('INSERT INTO employees (code,full_name,email,phone,warehouse_id,status,created_by) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$code, $name, $email ?: null, $phone ?: null, $warehouseId, $status, $actorId]);
                    $id = $pdo->lastInsertId();
                }
            }
        }
        $currentActor = findUser($pdo, 'id', $actorId);
        if (!userCanSignIn($currentActor) || !(int) $currentActor['is_super_admin']) {
            throw new DomainException('Không thể tự khóa, ngừng hoạt động hoặc gỡ quyền quản trị của chính mình.');
        }
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM users u LEFT JOIN employees e ON e.id=u.employee_id
            WHERE u.is_super_admin=1 AND u.status='active' AND (u.employee_id IS NULL OR e.status='active')")->fetchColumn();
        if ($admins < 1) throw new DomainException('Cần giữ ít nhất một quản trị viên đang hoạt động.');
        $after = managementSnapshot($pdo, $page, $id);
        unset($before['updated_at'], $after['updated_at']);
        if ($before === $after && !$passwordChanged) {
            $pdo->commit();
            return 'Không có thông tin thay đổi.';
        }
        if ($passwordChanged) $after['password_changed'] = true;
        $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at)
            VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")->execute([
                $actorId, $page === 'warehouses' ? ($action === 'delete' ? null : $id) : ($page === 'employees' ? ($after['warehouse_id'] ?? $before['warehouse_id']) : null), $auditAction,
                $page === 'accounts' ? 'users' : $page, $id,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        $pdo->commit();
        return $action === 'delete' ? 'Đã xóa và lưu nhật ký thao tác.' : ($action === 'state' ? 'Đã cập nhật trạng thái.' : 'Đã lưu thông tin và ghi nhật ký thao tác.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1451) throw new DomainException($page === 'warehouses'
            ? 'Không thể xóa kho có nhân viên, quyền truy cập, phiếu, quyết định hoặc nhật ký liên quan. Bạn có thể ngừng hoạt động kho để giữ lịch sử.'
            : 'Không thể xóa nhân viên đang liên kết tài khoản. Hãy gỡ liên kết ở phần Tài khoản & phân quyền trước, hoặc ngừng hoạt động nhân viên.');
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) throw new DomainException($page === 'warehouses' ? 'Mã kho đã tồn tại. Vui lòng dùng mã khác.' : 'Tên đăng nhập hoặc mã nhân viên đã tồn tại.');
        throw $error;
    }
}

function loadManagement(PDO $pdo, string $page): array
{
    $data = ['warehouses' => [], 'warehouseStatuses' => []];
    foreach ($pdo->query('SELECT id,name,status FROM warehouses ORDER BY name,id') as $row) {
        $data['warehouses'][(string) $row['id']] = $row['name'];
        $data['warehouseStatuses'][(string) $row['id']] = $row['status'];
    }
    // ponytail: client-side filtering loads complete lists; move filters/pagination to SQL when data volume grows.
    if ($page === 'export-create') {
        $data['sources'] = loadExportSources($pdo);
    } elseif ($page === 'exports') {
        $data['exports'] = loadExports($pdo);
    } elseif ($page === 'import-create') {
        $data['products'] = $pdo->query("SELECT id,code,name,category,unit,tracking_mode,updated_at AS version FROM products WHERE status='active' ORDER BY name,id")->fetchAll();
        $data['suppliers'] = $pdo->query("SELECT id,name,tax_code,address,phone,email FROM suppliers WHERE status='active' ORDER BY name,id")->fetchAll();
    } elseif ($page === 'imports') {
        $data['imports'] = loadImports($pdo);
        $data['decisions'] = $pdo->query('SELECT id,warehouse_id,number FROM decisions ORDER BY issued_date DESC,id DESC')->fetchAll();
    } elseif (array_key_exists($page, productPages())) {
        $data['products'] = loadProducts($pdo, productPages()[$page]);
    } elseif ($page === 'accounts') {
        $data['employees'] = $data['employeeOptions'] = [];
        foreach ($pdo->query('SELECT id,code,full_name FROM employees ORDER BY full_name,id') as $row) {
            $data['employees'][(string) $row['id']] = $row['full_name'];
            $data['employeeOptions'][(string) $row['id']] = $row['code'] . ' · ' . $row['full_name'];
        }
        $grants = [];
        foreach ($pdo->query('SELECT user_id,warehouse_id,role FROM user_warehouses ORDER BY warehouse_id') as $row) $grants[$row['user_id']][(string) $row['warehouse_id']] = $row['role'];
        $data['accounts'] = [];
        foreach ($pdo->query('SELECT id,username,employee_id,is_super_admin,status,updated_at FROM users ORDER BY id DESC') as $row) {
            $data['accounts'][] = ['id' => (string) $row['id'], 'version' => $row['updated_at'], 'username' => $row['username'],
                'employee' => (string) ($row['employee_id'] ?? ''), 'admin' => (bool) $row['is_super_admin'], 'status' => $row['status'],
                'warehouses' => (object) ($grants[$row['id']] ?? [])];
        }
    } elseif ($page === 'employees') {
        $accounts = [];
        foreach ($pdo->query('SELECT employee_id,username FROM users WHERE employee_id IS NOT NULL ORDER BY id') as $row) $accounts[$row['employee_id']][] = $row['username'];
        $data['employees'] = [];
        foreach ($pdo->query('SELECT id,code,full_name,email,phone,warehouse_id,status,updated_at FROM employees ORDER BY id DESC') as $row) {
            $data['employees'][] = ['id' => (string) $row['id'], 'version' => $row['updated_at'], 'code' => $row['code'], 'full_name' => $row['full_name'],
                'email' => $row['email'] ?? '', 'phone' => $row['phone'] ?? '', 'warehouse' => (string) $row['warehouse_id'], 'status' => $row['status'],
                'account' => implode(', ', $accounts[$row['id']] ?? [])];
        }
    } elseif ($page === 'warehouses') {
        $data['warehouseRecords'] = $pdo->query("SELECT w.id,w.code,w.name,w.address,w.status,w.updated_at AS version,
            (SELECT COUNT(*) FROM employees e WHERE e.warehouse_id=w.id) AS employee_count,
            (SELECT COUNT(*) FROM employees e WHERE e.warehouse_id=w.id AND e.status='active') AS active_employee_count,
            (SELECT COUNT(*) FROM user_warehouses uw WHERE uw.warehouse_id=w.id) AS access_count
            FROM warehouses w ORDER BY w.id DESC")->fetchAll();
        foreach ($data['warehouseRecords'] as &$row) { $row['id'] = (string) $row['id']; $row['address'] ??= ''; } unset($row);
    } elseif ($page === 'suppliers') {
        $receipts = [];
        foreach ($pdo->query('SELECT b.id,b.supplier_id,b.number,b.received_date,b.invoice_number,b.status,w.name AS warehouse_name
            FROM import_bill b JOIN warehouses w ON w.id=b.warehouse_id WHERE b.supplier_id IS NOT NULL ORDER BY b.received_date DESC,b.id DESC') as $bill) {
            $receipts[$bill['supplier_id']][] = ['id' => (string) $bill['id'], 'number' => $bill['number'], 'received_date' => $bill['received_date'],
                'invoice_number' => $bill['invoice_number'] ?? '', 'status' => $bill['status'], 'warehouse_name' => $bill['warehouse_name']];
        }
        $data['suppliers'] = [];
        foreach ($pdo->query('SELECT id,name,tax_code,address,phone,email,status,created_at,updated_at FROM suppliers ORDER BY id DESC') as $row) {
            $row['id'] = (string) $row['id'];
            foreach (['tax_code', 'address', 'phone', 'email'] as $key) $row[$key] ??= '';
            $row['receipts'] = $receipts[$row['id']] ?? [];
            $row['receipt_count'] = count($row['receipts']);
            $row['last_received_date'] = $row['receipts'][0]['received_date'] ?? '';
            $data['suppliers'][] = $row;
        }
    } elseif ($page === 'decisions') {
        $data['decisionLines'] = [];
        $attachments = [];
        foreach ($pdo->query('SELECT id,decision_id,original_name FROM attachments WHERE decision_id IS NOT NULL ORDER BY id') as $file) $attachments[$file['decision_id']][] = $file;
        $items = [];
        foreach (['import' => ['import_bill_details', 'import_bill', 'import_bill_id', 'received_date', 'received_quantity'],
            'export' => ['export_bill_details', 'export_bill', 'export_bill_id', 'issued_date', 'issued_quantity']] as $direction => [$details, $bills, $billId, $date, $quantity]) {
            foreach ($pdo->query("SELECT d.id,d.updated_at AS version,d.decision_id,d.decision_status,d.product_id,d.product_name,d.unit,d.$quantity AS quantity,b.warehouse_id,b.number,b.$date AS date,b.status,w.name AS warehouse_name
                FROM $details d JOIN $bills b ON b.id=d.$billId JOIN warehouses w ON w.id=b.warehouse_id
                WHERE d.decision_id IS NOT NULL OR (d.decision_status='pending' AND b.status='confirmed') ORDER BY b.$date DESC,d.id DESC") as $item) {
                $item['id'] = (string) $item['id'];
                $item['direction'] = $direction;
                $data['decisionLines'][] = $item;
                if ($item['decision_id'] !== null) $items[$item['decision_id']][] = $item;
            }
        }
        $data['decisions'] = $pdo->query('SELECT d.id,d.updated_at AS version,d.number,d.issued_date,d.decision_type,d.issuing_authority,d.content,d.warehouse_id,w.name AS warehouse_name
            FROM decisions d JOIN warehouses w ON w.id=d.warehouse_id ORDER BY d.issued_date DESC,d.id DESC')->fetchAll();
        foreach ($data['decisions'] as &$row) {
            $row['id'] = (string) $row['id']; $row['content'] ??= '';
            $row['items'] = $items[$row['id']] ?? [];
            $row['attachments'] = $attachments[$row['id']] ?? [];
            $links = ['import' => [], 'export' => []];
            foreach ($row['items'] as $item) $links[$item['direction']][$item['id']] = $item['version'];
            $row['links_version'] = decisionLinksVersion($links);
            $row['import_count'] = count(array_filter($row['items'], static fn($item) => $item['direction'] === 'import'));
            $row['export_count'] = count($row['items']) - $row['import_count'];
        } unset($row);
    } elseif ($page === 'audit') {
        $data['logs'] = [];
        foreach ($pdo->query('SELECT a.*,u.username,e.full_name,w.name AS warehouse_name FROM audit_logs a
            LEFT JOIN users u ON u.id=a.user_id LEFT JOIN employees e ON e.id=u.employee_id LEFT JOIN warehouses w ON w.id=a.warehouse_id
            ORDER BY a.created_at DESC,a.id DESC') as $row) {
            $before = $row['before_data'] === null ? null : json_decode($row['before_data'], true, 512, JSON_THROW_ON_ERROR);
            $after = $row['after_data'] === null ? null : json_decode($row['after_data'], true, 512, JSON_THROW_ON_ERROR);
            foreach (['password', 'password_hash', 'csrf_token'] as $secret) { unset($before[$secret], $after[$secret]); }
            $reference = (string) ($after['username'] ?? $after['code'] ?? $after['number'] ?? $before['username'] ?? $before['code'] ?? $before['number'] ?? '#' . $row['entity_id']);
            $data['logs'][] = ['id' => (string) $row['id'], 'created_at' => substr($row['created_at'], 0, 19), 'username' => $row['username'],
                'full_name' => $row['full_name'] ?? $row['username'] ?? 'Hệ thống', 'warehouse' => $row['warehouse_id'] === null ? null : (string) $row['warehouse_id'],
                'warehouse_name' => $row['warehouse_name'] ?? 'Toàn hệ thống', 'action' => $row['action'], 'entity_type' => $row['entity_type'],
                'entity_id' => (string) $row['entity_id'], 'reference' => $reference, 'before_data' => $before, 'after_data' => $after];
        }
    }
    return $data;
}
