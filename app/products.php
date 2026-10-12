<?php
declare(strict_types=1);

function productCategories(): array
{
    return ['consumable' => 'Vật tư', 'spare_part' => 'Phụ tùng thay thế', 'ccdc' => 'Công cụ, dụng cụ', 'fixed_asset' => 'Tài sản cố định', 'other' => 'Hàng hóa khác'];
}
function productPages(): array
{
    return ['products' => '', 'materials' => 'consumable', 'spare-parts' => 'spare_part', 'tools' => 'ccdc', 'fixed-assets' => 'fixed_asset', 'other-products' => 'other'];
}
function productQuantity(?string $value): string
{
    if ($value === null) return 'Không theo dõi';
    [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
    $decimal = rtrim($decimal, '0');
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole) . ($decimal !== '' ? ',' . $decimal : '');
}
function loadProducts(PDO $pdo, string $category = ''): array
{
    $records = [];
    $statement = $pdo->prepare('SELECT id,code,name,category,unit,tracking_mode,status,updated_at AS version FROM products WHERE (?=\'\' OR category=?) ORDER BY id DESC');
    $statement->execute([$category, $category]);
    foreach ($statement as $row) {
        $row['id'] = (string) $row['id'];
        $records[$row['id']] = $row + ['incoming' => '0.000000', 'outgoing' => '0.000000', 'closing' => '0.000000', 'missing_quantity' => false, 'last_received_date' => '', 'stocks' => [], 'movements' => []];
    }
    // ponytail: load receipt history for client filters/details; use SQL pagination and on-demand details when volume grows.
    foreach (['import' => ['import_bill_details', 'import_bill', 'import_bill_id', 'received_date', 'received_quantity'],
        'export' => ['export_bill_details', 'export_bill', 'export_bill_id', 'issued_date', 'issued_quantity']] as $direction => [$details, $bills, $billId, $date, $quantity]) {
        foreach ($pdo->query("SELECT d.id,d.product_id,d.product_name,d.unit,d.$quantity AS quantity,d.amount_before_tax,d.decision_status,b.number,b.$date AS date,b.status,b.warehouse_id,w.name AS warehouse_name,
            q.number AS decision_number FROM $details d JOIN $bills b ON b.id=d.$billId JOIN warehouses w ON w.id=b.warehouse_id LEFT JOIN decisions q ON q.id=d.decision_id ORDER BY b.$date DESC,d.id DESC") as $item) {
            if (!isset($records[(string) $item['product_id']])) continue;
            $row = &$records[(string) $item['product_id']];
            $item['direction'] = $direction; $row['movements'][] = $item;
            if ($item['status'] === 'confirmed') {
                $warehouse = (string) $item['warehouse_id'];
                $row['stocks'][$warehouse] ??= ['warehouse_id' => $warehouse, 'warehouse_name' => $item['warehouse_name'], 'incoming' => '0.000000', 'outgoing' => '0.000000', 'closing' => '0.000000', 'missing_quantity' => false];
                if ($item['quantity'] === null && $row['tracking_mode'] === 'quantity') $row['missing_quantity'] = $row['stocks'][$warehouse]['missing_quantity'] = true;
                $field = $direction === 'import' ? 'incoming' : 'outgoing';
                $row[$field] = bcadd($row[$field], $item['quantity'] ?? '0', 6);
                $row['stocks'][$warehouse][$field] = bcadd($row['stocks'][$warehouse][$field], $item['quantity'] ?? '0', 6);
                if ($direction === 'import') $row['last_received_date'] = max($row['last_received_date'], $item['date']);
            }
            unset($row);
        }
    }
    foreach ($records as &$row) {
        usort($row['movements'], static fn($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
        $row['closing'] = bcsub($row['incoming'], $row['outgoing'], 6);
        $row['stock'] = $row['tracking_mode'] === 'none' ? 'untracked' : ($row['missing_quantity'] ? 'unknown' : (bccomp($row['closing'], '0', 6) === 0 ? 'empty' : (bccomp($row['closing'], '0', 6) > 0 ? 'stock' : 'negative')));
        foreach ($row['stocks'] as &$stock) {
            $stock['closing'] = bcsub($stock['incoming'], $stock['outgoing'], 6);
            if ($row['tracking_mode'] === 'none') foreach (['incoming', 'outgoing', 'closing'] as $field) $stock[$field] = null;
            elseif ($stock['missing_quantity']) $stock['incoming'] = $stock['closing'] = null;
        } unset($stock);
        if ($row['tracking_mode'] === 'none') foreach (['incoming', 'outgoing', 'closing'] as $field) $row[$field] = null;
        elseif ($row['missing_quantity']) $row['incoming'] = $row['closing'] = null;
        $row['stocks'] = array_values($row['stocks']);
    } unset($row);
    return array_values($records);
}
function saveProduct(PDO $pdo, string $actorId, array $input, string $category = ''): string
{
    if (managementText($input, 'action', 20, true) !== 'save') throw new DomainException('Thao tác không hợp lệ.');
    $id = managementId($input['id'] ?? null);
    $values = ['code' => managementText($input, 'code', 64, true), 'name' => managementText($input, 'name', 255, true),
        'category' => managementText($input, 'category', 20, true), 'unit' => managementText($input, 'unit', 50, true),
        'tracking_mode' => managementText($input, 'tracking_mode', 10, true), 'status' => managementText($input, 'status', 10, true)];
    if (!isset(productCategories()[$values['category']]) || !in_array($values['tracking_mode'], ['quantity', 'none'], true) || !in_array($values['status'], ['active', 'inactive'], true)) throw new DomainException('Nhóm hàng hoặc trạng thái không hợp lệ.');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        if (!userCanSignIn($actor) || !(int) $actor['is_super_admin']) throw new DomainException('Bạn không có quyền sửa hàng hóa.');
        $stmt = $pdo->prepare('SELECT id,code,name,category,unit,tracking_mode,status,updated_at FROM products WHERE id=? FOR UPDATE');
        $stmt->execute([$id]); $before = $stmt->fetch();
        if (!$before || managementText($input, 'version', 32, true) !== $before['updated_at']) throw new DomainException('Hàng hóa đã thay đổi. Hãy tải lại trang trước khi sửa.');
        if ($category !== '' && ($before['category'] !== $category || $values['category'] !== $category)) throw new DomainException('Trang này chỉ được sửa hàng hóa thuộc đúng nhóm đang quản lý.');
        $stmt = $pdo->prepare('SELECT (EXISTS(SELECT 1 FROM import_bill_details WHERE product_id=?)) OR (EXISTS(SELECT 1 FROM export_bill_details WHERE product_id=?))');
        $stmt->execute([$id, $id]);
        if ($stmt->fetchColumn() && ($values['unit'] !== $before['unit'] || $values['tracking_mode'] !== $before['tracking_mode'])) throw new DomainException('Hàng hóa đã có phiếu không được đổi đơn vị hoặc cách theo dõi số lượng.');
        $after = ['id' => $before['id']] + $values;
        unset($before['updated_at']);
        if ($before !== $after) {
            $pdo->prepare('UPDATE products SET code=?,name=?,category=?,unit=?,tracking_mode=?,status=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')->execute([...array_values($values), $id]);
            $pdo->prepare("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,before_data,after_data,created_at) VALUES (?,'update','products',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")->execute([$actorId, $id, json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit(); return 'Đã lưu thông tin hàng hóa và nhật ký thay đổi.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) throw new DomainException('Mã hàng hóa đã tồn tại.');
        throw $error;
    }
}
