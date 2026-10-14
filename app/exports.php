<?php
declare(strict_types=1);
function exportTypes(): array
{
    return ['allocation' => 'Cấp phát', 'transfer' => 'Điều chuyển kho', 'disposal' => 'Thanh lý / Loại bỏ', 'adjustment' => 'Điều chỉnh giảm'];
}
function loadExportSources(PDO $pdo, ?string $id = null, bool $lock = false, ?array $access = null): array
{
    // ponytail: load eligible lots for the form; move search/pagination to SQL when receipt volume grows.
    $stmt = $pdo->prepare("SELECT d.*,b.number AS receipt_number,b.received_date,b.warehouse_id,w.name AS warehouse_name,p.code AS product_code,
        b.updated_at AS bill_version,p.updated_at AS product_version,
        COALESCE(x.quantity,0) AS used_quantity,COALESCE(x.amount,0) AS used_amount
        FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id JOIN warehouses w ON w.id=b.warehouse_id
        JOIN products p ON p.id=d.product_id LEFT JOIN
        (SELECT e.source_import_detail_id,SUM(e.issued_quantity) AS quantity,SUM(e.amount_before_tax) AS amount
            FROM export_bill_details e JOIN export_bill h ON h.id=e.export_bill_id WHERE h.status='confirmed' GROUP BY e.source_import_detail_id) x ON x.source_import_detail_id=d.id
        WHERE b.status='confirmed' AND w.status='active' AND p.status='active' AND p.tracking_mode='quantity' AND d.received_quantity IS NOT NULL
        " . ($id === null ? '' : ' AND d.id=?') . ' ORDER BY b.received_date,d.id' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($id === null ? [] : [$id]); $records = [];
    foreach ($stmt as $row) {
        if ($access !== null && !canManageWarehouse($access, (string)$row['warehouse_id'])) continue;
        $row['id'] = (string) $row['id']; $row['warehouse_id'] = (string) $row['warehouse_id'];
        $row['remaining_quantity'] = bcsub($row['received_quantity'], $row['used_quantity'], 6);
        $row['remaining_amount'] = bcsub($row['amount_before_tax'], $row['used_amount'], 6);
        $row['version'] = hash('sha256', implode('|', [$row['id'], $row['updated_at'], $row['bill_version'], $row['product_version']]));
        $row['remaining_display'] = productQuantity($row['remaining_quantity']);
        if ($id !== null || bccomp($row['remaining_quantity'], '0', 6) > 0) $records[] = $row;
    }
    return $records;
}
function allocateExportLines(PDO $pdo, string $warehouse, string $date, array $items, bool $checkVersions = true): array
{
    $sources = []; $lines = []; $total = '0.000000';
    foreach ($items as $item) {
        if (!is_array($item)) throw new DomainException('Dòng xuất không hợp lệ.');
        $sourceId = managementId($item['source_import_detail_id'] ?? null);
        if (!isset($sources[$sourceId])) {
            $source = loadExportSources($pdo, $sourceId, true)[0] ?? null;
            if (!$source || $source['warehouse_id'] !== $warehouse || $source['received_date'] > $date) throw new DomainException('Lô nhập không thuộc kho xuất, chưa xác nhận, chưa đến ngày nhập hoặc hàng đã ngừng sử dụng.');
            $sources[$sourceId] = $source;
        }
        $source = &$sources[$sourceId];
        if ($checkVersions && managementText($item, 'version', 64, true) !== $source['version']) throw new DomainException('Lô hàng đã thay đổi. Tải lại trang trước khi xuất.');
        $quantity = importDecimal($item['quantity'] ?? '', true);
        if (bccomp($quantity, $source['remaining_quantity'], 6) > 0) throw new DomainException('Số lượng xuất vượt tồn lô ' . $source['receipt_number'] . ' / ' . $source['product_name'] . '. Hãy tải lại để kiểm tra tồn mới nhất.');
        if (bccomp($source['remaining_amount'], '0', 6) < 0) throw new DomainException('Giá trị đã xuất vượt giá trị lô nhập. Kiểm tra các phiếu cũ trước khi xuất tiếp.');
        // Allocate stored invoice value; the final withdrawal takes the remaining value to retain invoice rounding.
        $amount = bccomp($quantity, $source['remaining_quantity'], 6) === 0 ? $source['remaining_amount']
            : bcdiv(bcmul($source['amount_before_tax'], $quantity, 12), $source['received_quantity'], 6);
        if (bccomp($amount, $source['remaining_amount'], 6) > 0) throw new DomainException('Giá trị phân bổ vượt giá trị còn lại của lô.');
        $amount = importDecimal($amount); $total = importDecimal(bcadd($total, $amount, 6));
        $source['remaining_quantity'] = bcsub($source['remaining_quantity'], $quantity, 6);
        $source['remaining_amount'] = bcsub($source['remaining_amount'], $amount, 6);
        $lines[] = ['product_id' => (string) $source['product_id'], 'source_import_detail_id' => $sourceId, 'issued_quantity' => $quantity,
            'unit_price' => $source['unit_price'], 'amount_before_tax' => $amount, 'product_name' => $source['product_name'], 'unit' => $source['unit'],
            'note' => managementText($item, 'note', 16000) ?: null];
        unset($source);
    }
    return [$lines, $total];
}
function createExportTransfer(PDO $pdo, string $actorId, string $destination, string $id, string $date, array $lines, string $total, string $sender): string
{
    $transferNumber = 'DC-PX-' . $id;
    $transferPrint = receiptPrintSnapshot($pdo, $destination, $actorId);
    $pdo->prepare("INSERT INTO import_bill (warehouse_id,number,received_date,receipt_type,sender_name,total_before_tax,total_after_tax,status,created_by,print_snapshot) VALUES (?,?,?,'transfer',?,?,?,'confirmed',?,?)")
        ->execute([$destination, $transferNumber, $date, $sender, $total, $total, $actorId, json_encode($transferPrint,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $transferId = $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,unit_price,amount_before_tax,product_name,unit,note,source_export_detail_id,decision_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'pending',?)");
    foreach ($lines as $line) $stmt->execute([$transferId, $line['product_id'], $line['issued_quantity'], $line['unit_price'], $line['amount_before_tax'], $line['product_name'], $line['unit'], $line['note'], $line['id'], $actorId]);
    $data = ['number' => $transferNumber, 'source_export_bill_id' => $id, 'status' => 'confirmed', 'total_before_tax' => $total, 'items' => $lines, 'print_snapshot'=>$transferPrint];
    $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,after_data,created_at) VALUES (?,?,'create','import_bill',?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
        ->execute([$actorId,$destination,$transferId,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    return $transferId;
}
function saveExport(PDO $pdo, string $actorId, array $input, array $files = []): string
{
    if (($input['action'] ?? '') !== 'create') throw new DomainException('Thao tác phiếu xuất không hợp lệ.');
    $warehouse = managementId($input['warehouse'] ?? null); $destination = managementId($input['destination_warehouse'] ?? '', true);
    $number = managementText($input, 'number', 100, true); $date = importDate($input, 'issued_date');
    $type = managementText($input, 'issue_type', 20, true); $status = managementText($input, 'status', 10, true);
    $reason = managementText($input, 'reason', 500, true); $receiver = managementText($input, 'receiver_name', 200);
    if (!isset(exportTypes()[$type]) || !in_array($status, ['draft', 'confirmed'], true)) throw new DomainException('Loại xuất hoặc trạng thái không hợp lệ.');
    if (($type !== 'transfer' && $destination !== null) || ($type === 'transfer' && ($destination === null || $destination === $warehouse))) throw new DomainException('Điều chuyển cần chọn kho nhận khác kho xuất; các loại xuất khác không có kho nhận.');
    try { $items = json_decode(managementText($input, 'items', 500000, true), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new DomainException('Danh sách hàng xuất không hợp lệ.'); }
    if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 200) throw new DomainException('Phiếu phải có từ 1 đến 200 dòng hàng.');
    $pdf = receivePdf($files); $stored = null;
    $pdo->beginTransaction();
    try {
        // ponytail: shared global management lock prevents double spending; use ordered lot locks if write volume grows.
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        if (!userCanAct($actor) || warehousePermission($pdo, $actorId, $warehouse) !== 'keeper') throw new DomainException('Bạn không có quyền xuất kho hoặc kho đã ngừng hoạt động.');
        if ($destination !== null && warehousePermission($pdo, $actorId, $destination) !== 'keeper') throw new DomainException('Kho nhận không tồn tại hoặc đã ngừng hoạt động.');
        [$lines, $total] = allocateExportLines($pdo, $warehouse, $date, $items);
        $printSnapshot = receiptPrintSnapshot($pdo, $warehouse, $actorId, $input);
        $pdo->prepare('INSERT INTO export_bill (warehouse_id,number,issued_date,issue_type,reason,receiver_name,total_before_tax,status,destination_warehouse_id,created_by,print_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$warehouse, $number, $date, $type, $reason, $receiver ?: null, $total, $status, $destination, $actorId, json_encode($printSnapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO export_bill_details (export_bill_id,product_id,source_import_detail_id,issued_quantity,unit_price,amount_before_tax,product_name,unit,note,decision_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,'pending',?)");
        foreach ($lines as &$line) { $stmt->execute([$id, ...array_values($line), $actorId]); $line['id'] = $pdo->lastInsertId(); } unset($line);
        $audit = static function (string $entity, string $entityId, string $warehouseId, array $data) use ($pdo, $actorId): void {
            $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,after_data,created_at) VALUES (?,?,'create',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
                ->execute([$actorId, $warehouseId, $entity, $entityId, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        };
        $transferId = null;
        if ($type === 'transfer' && $status === 'confirmed') {
            $transferId = createExportTransfer($pdo, $actorId, $destination, $id, $date, $lines, $total, $actor['full_name'] ?? $actor['username']);
        }
        $stored = storeAttachment($pdo, $pdf, 'export_bill_id', $id, $actorId);
        $audit('export_bill', $id, $warehouse, ['number' => $number, 'issued_date' => $date, 'issue_type' => $type, 'reason' => $reason, 'receiver_name' => $receiver,
            'status' => $status, 'destination_warehouse_id' => $destination, 'destination_import_bill_id' => $transferId, 'total_before_tax' => $total, 'items' => $lines, 'print_snapshot'=>$printSnapshot, 'pdf' => $pdf['name'] ?? null]);
        $pdo->commit();
        return 'Đã lưu phiếu xuất ' . $number . ($status === 'draft' ? ' ở trạng thái nháp.' : ($transferId ? ' và phiếu nhập điều chuyển ở kho nhận.' : ' và cập nhật tồn kho.'));
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored !== null && is_file($stored)) unlink($stored);
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) throw new DomainException('Số phiếu đã tồn tại trong kho và năm này. Hãy kiểm tra trước khi lưu lại.');
        throw $error;
    }
}
function confirmExport(PDO $pdo, string $actorId, array $input): string
{
    if (managementText($input, 'action', 20, true) !== 'confirm') throw new DomainException('Thao tác phiếu xuất không hợp lệ.');
    $id = managementId($input['id'] ?? null);
    $pdo->beginTransaction();
    try {
        // Use the same management lock as receipt creation so confirmations cannot spend the same stock twice.
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        $stmt = $pdo->prepare('SELECT * FROM export_bill WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $bill = $stmt->fetch();
        if (!$bill || !userCanAct($actor) || warehousePermission($pdo, $actorId, (string)$bill['warehouse_id']) !== 'keeper') throw new DomainException('Phiếu không tồn tại hoặc bạn không có quyền tại kho này.');
        if ($bill['status'] !== 'draft') throw new DomainException('Chỉ xác nhận phiếu xuất đang ở trạng thái Nháp. Hãy tải lại trang.');
        $destination = $bill['destination_warehouse_id'];
        if ($bill['issue_type'] === 'transfer' && ($destination === null || $destination === $bill['warehouse_id'] || warehousePermission($pdo, $actorId, (string)$destination) !== 'keeper')) throw new DomainException('Kho nhận không hợp lệ hoặc bạn không có quyền điều chuyển vào kho này.');
        if ($bill['issue_type'] !== 'transfer' && $destination !== null) throw new DomainException('Phiếu xuất có kho nhận không hợp lệ.');
        $stmt = $pdo->prepare('SELECT * FROM export_bill_details WHERE export_bill_id=? ORDER BY id FOR UPDATE'); $stmt->execute([$id]); $items = $stmt->fetchAll();
        if (managementText($input, 'version', 64, true) !== importVersion($bill, $items)) throw new DomainException('Phiếu hoặc quyết định liên kết đã thay đổi. Hãy tải lại trang trước khi xác nhận.');
        if (!$items || count($items) > 200) throw new DomainException('Phiếu phải có từ 1 đến 200 dòng hàng.');
        $before = $bill + ['items'=>$items];
        [$lines, $total] = allocateExportLines($pdo, (string)$bill['warehouse_id'], $bill['issued_date'], array_map(static fn($item)=>[
            'source_import_detail_id'=>(string)$item['source_import_detail_id'], 'quantity'=>$item['issued_quantity'], 'note'=>$item['note'] ?? ''
        ], $items), false);
        $stmt = $pdo->prepare('UPDATE export_bill_details SET amount_before_tax=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?');
        foreach ($lines as $index=>&$line) { $line['id'] = (string)$items[$index]['id']; $stmt->execute([$line['amount_before_tax'],$line['id']]); } unset($line);
        $pdo->prepare("UPDATE export_bill SET status='confirmed',total_before_tax=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?")->execute([$total,$id]);
        $transferId = $bill['issue_type'] === 'transfer' ? createExportTransfer($pdo, $actorId, (string)$destination, $id, $bill['issued_date'], $lines, $total, $actor['full_name'] ?? $actor['username']) : null;
        $stmt = $pdo->prepare('SELECT * FROM export_bill_details WHERE export_bill_id=? ORDER BY id'); $stmt->execute([$id]);
        $after = array_replace($bill, ['status'=>'confirmed','total_before_tax'=>$total,'items'=>$stmt->fetchAll(),'destination_import_bill_id'=>$transferId]);
        unset($before['updated_at'], $after['updated_at']);
        $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at) VALUES (?,?,'confirm','export_bill',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
            ->execute([$actorId,$bill['warehouse_id'],$id,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return 'Đã xuất kho phiếu '.$bill['number'].', cập nhật tồn kho'.($transferId ? ' và tạo phiếu nhập điều chuyển tại kho nhận.' : ' và lưu nhật ký.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
function loadExports(PDO $pdo, ?array $access = null): array
{
    $records = [];
    foreach ($pdo->query('SELECT b.*,w.name AS warehouse_name,z.name AS destination_warehouse_name,COALESCE(e.full_name,u.username) AS creator_name
        FROM export_bill b JOIN warehouses w ON w.id=b.warehouse_id LEFT JOIN warehouses z ON z.id=b.destination_warehouse_id
        LEFT JOIN users u ON u.id=b.created_by LEFT JOIN employees e ON e.id=u.employee_id ORDER BY b.issued_date DESC,b.id DESC') as $row) {
        if ($access !== null && !canViewWarehouse($access, (string)$row['warehouse_id'])) continue;
        $row['id'] = (string) $row['id']; $row['warehouse_id'] = (string) $row['warehouse_id'];
        $row['total_before_tax_display'] = productQuantity($row['total_before_tax']) . ' ₫';
        $records[$row['id']] = $row + ['items' => [], 'attachments' => [], 'transfers' => []];
    }
    foreach ($pdo->query('SELECT d.*,q.number AS decision_number,p.code AS product_code,b.number AS source_number,b.received_date AS source_date
        FROM export_bill_details d JOIN import_bill_details s ON s.id=d.source_import_detail_id JOIN import_bill b ON b.id=s.import_bill_id
        JOIN products p ON p.id=d.product_id LEFT JOIN decisions q ON q.id=d.decision_id ORDER BY d.id') as $item) {
        if (!isset($records[(string)$item['export_bill_id']])) continue;
        foreach (['issued_quantity','unit_price','amount_before_tax'] as $field) $item[$field . '_display'] = productQuantity($item[$field]);
        $records[(string) $item['export_bill_id']]['items'][] = $item;
    }
    foreach ($pdo->query('SELECT id,export_bill_id,original_name FROM attachments WHERE export_bill_id IS NOT NULL ORDER BY id') as $file) if (isset($records[(string)$file['export_bill_id']])) $records[(string) $file['export_bill_id']]['attachments'][] = $file;
    foreach ($pdo->query('SELECT DISTINCT e.export_bill_id,b.id,b.number,b.status,b.warehouse_id FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id
        JOIN export_bill_details e ON e.id=d.source_export_detail_id ORDER BY b.id') as $transfer) {
        if (isset($records[(string)$transfer['export_bill_id']]) && ($access === null || canViewWarehouse($access, (string)$transfer['warehouse_id']))) $records[(string)$transfer['export_bill_id']]['transfers'][] = $transfer;
    }
    foreach ($records as &$row) {
        $row['version'] = importVersion($row, $row['items']);
        $row['can_confirm'] = canManageWarehouse($access, (string)$row['warehouse_id'])
            && ($row['issue_type'] !== 'transfer' || canManageWarehouse($access, (string)$row['destination_warehouse_id']));
    } unset($row);
    return array_values($records);
}
