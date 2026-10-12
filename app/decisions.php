<?php
declare(strict_types=1);

function decisionLinksVersion(array $links): string
{
    foreach ($links as &$versions) ksort($versions, SORT_NUMERIC); unset($versions);
    return hash('sha256', json_encode($links, JSON_THROW_ON_ERROR));
}

function decisionSnapshot(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT id,warehouse_id,number,issued_date,decision_type,issuing_authority,content,updated_at FROM decisions WHERE id=?');
    $stmt->execute([$id]); $row = $stmt->fetch();
    if (!$row) return null;
    $row['links'] = ['import' => [], 'export' => []];
    foreach (['import' => 'import_bill_details', 'export' => 'export_bill_details'] as $direction => $table) {
        $stmt = $pdo->prepare("SELECT id,updated_at FROM $table WHERE decision_id=? ORDER BY id FOR UPDATE");
        $stmt->execute([$id]);
        foreach ($stmt as $item) $row['links'][$direction][(string) $item['id']] = $item['updated_at'];
    }
    $stmt = $pdo->prepare('SELECT id,original_name FROM attachments WHERE decision_id=? ORDER BY id');
    $stmt->execute([$id]); $row['attachments'] = $stmt->fetchAll();
    return $row;
}

function saveDecision(PDO $pdo, string $actorId, array $input, array $files): string
{
    if (managementText($input, 'action', 20, true) !== 'save') throw new DomainException('Thao tác không hợp lệ.');
    if (($input['form_complete'] ?? null) !== '1') throw new DomainException('Biểu mẫu chưa được nhận đầy đủ. Hãy giảm số dòng chọn hoặc tải lại trang.');
    $id = managementId($input['id'] ?? null, true);
    $warehouse = managementId($input['warehouse'] ?? null);
    $number = managementText($input, 'number', 100, true);
    $date = managementText($input, 'issued_date', 10, true);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1000-01-01') throw new DomainException('Ngày ban hành không hợp lệ.');
    $type = managementText($input, 'decision_type', 20, true);
    if (!in_array($type, ['increase', 'allocation', 'transfer', 'decrease'], true)) throw new DomainException('Loại quyết định không hợp lệ.');
    $authority = managementText($input, 'issuing_authority', 255, true);
    $content = managementText($input, 'content', 16000);
    $selected = $input['lines'] ?? [];
    if (!is_array($selected) || array_diff(array_keys($selected), ['import', 'export'])) throw new DomainException('Danh sách hàng hóa không hợp lệ.');
    $pdf = receivePdf($files);
    $stored = null;
    $pdo->beginTransaction();
    try {
        // Same low-volume write lock as account/employee management.
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        if (!userCanSignIn($actor) || !(int) $actor['is_super_admin']) throw new DomainException('Bạn không có quyền thực hiện thao tác này.');
        $before = $id ? decisionSnapshot($pdo, $id) : null;
        if ($id && (!$before || managementText($input, 'version', 32, true) !== $before['updated_at']
            || managementText($input, 'links_version', 64, true) !== decisionLinksVersion($before['links']))) throw new DomainException('Quyết định hoặc hàng hóa liên kết đã thay đổi. Hãy tải lại trang trước khi sửa.');
        $stmt = $pdo->prepare('SELECT status FROM warehouses WHERE id=?'); $stmt->execute([$warehouse]);
        $status = $stmt->fetchColumn();
        if (!$status || ($status !== 'active' && (string) ($before['warehouse_id'] ?? '') !== $warehouse)) throw new DomainException('Vui lòng chọn kho đang hoạt động.');
        // A linked decision cannot move to another warehouse.
        if ($before && (string) $before['warehouse_id'] !== $warehouse && ($before['links']['import'] || $before['links']['export'])) throw new DomainException('Quyết định đã liên kết hàng hóa không thể đổi kho.');
        $stmt = $pdo->prepare('SELECT id FROM decisions WHERE warehouse_id=? AND number=? AND id<>? LIMIT 1');
        $stmt->execute([$warehouse, $number, $id ?? '0']);
        if ($stmt->fetch()) throw new DomainException('Số quyết định đã tồn tại trong kho này.');
        $desired = ['import' => [], 'export' => []];
        foreach (['import' => ['import_bill_details', 'import_bill', 'import_bill_id'], 'export' => ['export_bill_details', 'export_bill', 'export_bill_id']] as $direction => [$table, $bills, $billId]) {
            $lines = $selected[$direction] ?? [];
            if (!is_array($lines) || count($lines) > 1000) throw new DomainException('Danh sách hàng hóa không hợp lệ hoặc vượt 1.000 dòng.');
            foreach ($lines as $lineId => $version) {
                $lineId = managementId((string) $lineId);
                $stmt = $pdo->prepare("SELECT d.decision_id,d.decision_status,d.updated_at,b.warehouse_id,b.status FROM $table d JOIN $bills b ON b.id=d.$billId WHERE d.id=? FOR UPDATE");
                $stmt->execute([$lineId]); $line = $stmt->fetch();
                $alreadyLinked = $id !== null && $line && (string) $line['decision_id'] === $id;
                if (!$line || !is_string($version) || $version !== $line['updated_at'] || (string) $line['warehouse_id'] !== $warehouse
                    || (!$alreadyLinked && ($line['decision_id'] !== null || $line['decision_status'] !== 'pending' || $line['status'] !== 'confirmed' || $status !== 'active'))) {
                    throw new DomainException('Dòng hàng đã thay đổi, thuộc kho khác, chưa xác nhận hoặc đã gắn quyết định khác. Hãy tải lại trang.');
                }
                $desired[$direction][$lineId] = $version;
            }
        }
        if ($id) $pdo->prepare('UPDATE decisions SET warehouse_id=?,number=?,issued_date=?,decision_type=?,issuing_authority=?,content=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')
            ->execute([$warehouse, $number, $date, $type, $authority, $content ?: null, $id]);
        else {
            $pdo->prepare('INSERT INTO decisions (warehouse_id,number,issued_date,decision_type,issuing_authority,content,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$warehouse, $number, $date, $type, $authority, $content ?: null, $actorId]);
            $id = $pdo->lastInsertId();
        }
        foreach (['import' => 'import_bill_details', 'export' => 'export_bill_details'] as $direction => $table) {
            foreach ($before['links'][$direction] ?? [] as $lineId => $version) if (!isset($desired[$direction][$lineId])) {
                $pdo->prepare("UPDATE $table SET decision_id=NULL,decision_status='pending',updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=? AND decision_id=?")->execute([$lineId, $id]);
            }
            foreach ($desired[$direction] as $lineId => $version) if (!isset($before['links'][$direction][$lineId])) {
                $pdo->prepare("UPDATE $table SET decision_id=?,decision_status='linked',updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?")->execute([$id, $lineId]);
            }
        }
        $stored = storeAttachment($pdo, $pdf, 'decision_id', $id, $actorId);
        $after = decisionSnapshot($pdo, $id);
        unset($before['updated_at'], $after['updated_at']);
        if ($before !== $after) $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at) VALUES (?,?,?,'decisions',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
            ->execute([$actorId, $warehouse, $before === null ? 'create' : 'update', $id,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return 'Đã lưu quyết định, liên kết hàng hóa và file đính kèm.';
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored !== null && is_file($stored)) unlink($stored);
        throw $error;
    }
}

function downloadDecisionPdf(PDO $pdo, mixed $attachmentId): never
{
    serveAttachment($pdo, $attachmentId, 'decision_id');
}
