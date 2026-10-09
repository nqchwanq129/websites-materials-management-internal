<?php
declare(strict_types=1);

function importTypes(): array
{
    return ['purchase' => 'Mua hàng', 'return' => 'Nhập trả lại', 'opening' => 'Tồn đầu kỳ', 'transfer' => 'Điều chuyển', 'adjustment' => 'Điều chỉnh'];
}
function importDate(array $input, string $key, bool $required = true): ?string
{
    $value = managementText($input, $key, 10, $required);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') throw new DomainException('Ngày nhập hoặc ngày hóa đơn không hợp lệ.');
    return $value;
}
function importDecimal(mixed $value, bool $positive = false): string
{
    if (!is_string($value) || !preg_match('/^\d{1,14}([.,]\d{1,6})?$/D', $value)) throw new DomainException('Số lượng và tiền phải là số không âm, tối đa 14 chữ số và 6 số thập phân; không nhập dấu phân cách hàng nghìn.');
    $value = str_replace(',', '.', $value);
    if ($positive && bccomp($value, '0', 6) <= 0) throw new DomainException('Số lượng hàng theo dõi phải lớn hơn 0.');
    return bcadd($value, '0', 6);
}
function parseImportXml(string $contents): array
{
    if (strlen($contents) > 2 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) throw new DomainException('XML không hợp lệ hoặc vượt 2 MB.');
    $previous = libxml_use_internal_errors(true);
    try { $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    if (!$xml) throw new DomainException('Không đọc được hóa đơn XML.');
    $invoices = $xml->xpath('descendant-or-self::*[local-name()="DLHDon"]');
    if (count($invoices) !== 1) throw new DomainException('Chọn XML chứa đúng một hóa đơn DLHDon.');
    $invoice = $invoices[0];
    $text = static function (SimpleXMLElement $node, string $tag): string {
        $values = $node->xpath('.//*[local-name()="' . $tag . '"]');
        return trim((string) ($values[0] ?? ''));
    };
    $seller = $invoice->xpath('.//*[local-name()="NBan"]')[0] ?? null;
    $result = ['invoice_number' => $text($invoice, 'SHDon'), 'invoice_series' => $text($invoice, 'KHHDon'), 'invoice_date' => $text($invoice, 'NLap'),
        'supplier_name' => $seller ? $text($seller, 'Ten') : '', 'tax_code' => $seller ? $text($seller, 'MST') : '',
        'address' => $seller ? $text($seller, 'DChi') : '', 'phone' => $seller ? $text($seller, 'SDThoai') : '', 'email' => $seller ? $text($seller, 'DCTDTu') : '',
        'invoice_before_tax' => $text($invoice, 'TgTCThue'), 'invoice_tax' => $text($invoice, 'TgTThue'), 'invoice_after_tax' => $text($invoice, 'TgTTTBSo'), 'items' => []];
    if ($result['invoice_number'] === '' || $result['supplier_name'] === '' || $result['invoice_date'] === '') throw new DomainException('XML thiếu số hóa đơn, ngày hóa đơn hoặc tên bên bán.');
    importDate($result, 'invoice_date');
    $currency = $text($invoice, 'DVTTe');
    if ($currency !== '' && strtoupper($currency) !== 'VND') throw new DomainException('Hiện chỉ hỗ trợ hóa đơn VND.');
    foreach ($invoice->xpath('.//*[local-name()="HHDVu"]') as $line) {
        $nature = $text($line, 'TChat');
        if (!in_array($nature, ['', '1', '2'], true)) throw new DomainException('Hóa đơn có dòng chiết khấu/điều chỉnh. Vui lòng nhập và đối chiếu thủ công.');
        $name = $text($line, 'THHDVu');
        if ($name === '') throw new DomainException('XML có dòng hàng không có tên.');
        $quantity = $text($line, 'SLuong');
        $result['items'][] = ['product_id' => '', 'code' => $text($line, 'MHHDVu'), 'name' => $name, 'category' => 'other', 'unit' => $text($line, 'DVTinh') ?: 'Lần',
            'tracking_mode' => $quantity !== '' && bccomp(importDecimal($quantity), '0', 6) > 0 ? 'quantity' : 'none', 'quantity' => $quantity ?: '',
            'unit_price' => $text($line, 'DGia') ?: '0', 'amount_before_tax' => $text($line, 'ThTien') ?: '', 'tax_code' => $text($line, 'TSuat'),
            'tax_amount' => $text($line, 'TThue'), 'specifications' => '', 'note' => ''];
    }
    if (!$result['items'] || count($result['items']) > 200) throw new DomainException('XML phải có từ 1 đến 200 dòng hàng.');
    return $result;
}
function saveImport(PDO $pdo, string $actorId, array $input, array $files = []): string
{
    if (($input['action'] ?? '') !== 'create') throw new DomainException('Thao tác phiếu nhập không hợp lệ.');
    $warehouse = managementId($input['warehouse'] ?? null);
    $number = managementText($input, 'number', 100, true);
    $date = importDate($input, 'received_date');
    $type = managementText($input, 'receipt_type', 20, true);
    $status = managementText($input, 'status', 10, true);
    if (!isset(importTypes()[$type]) || !in_array($status, ['draft', 'confirmed'], true)) throw new DomainException('Loại nhập hoặc trạng thái không hợp lệ.');
    try { $items = json_decode(managementText($input, 'items', 500000, true), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new DomainException('Danh sách hàng hóa không hợp lệ.'); }
    if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 200) throw new DomainException('Phiếu phải có từ 1 đến 200 dòng hàng.');
    $supplierId = managementId($input['supplier_id'] ?? '', true);
    $supplierName = managementText($input, 'supplier_name', 255);
    $taxCode = managementText($input, 'tax_code', 32);
    $supplierValues = ['name' => $supplierName, 'tax_code' => $taxCode ?: null, 'address' => managementText($input, 'address', 500) ?: null,
        'phone' => managementText($input, 'phone', 32) ?: null, 'email' => managementText($input, 'email', 254) ?: null];
    if ($supplierValues['email'] && !filter_var($supplierValues['email'], FILTER_VALIDATE_EMAIL)) throw new DomainException('Email nhà cung cấp không hợp lệ.');
    if (($type === 'purchase' || $taxCode !== '') && $supplierName === '' && $supplierId === null) throw new DomainException('Vui lòng nhập nhà cung cấp.');
    $invoice = managementText($input, 'invoice_number', 100);
    $series = managementText($input, 'invoice_series', 50);
    $invoiceDate = importDate($input, 'invoice_date', false);
    $sender = managementText($input, 'sender_name', 200);
    $pdf = receivePdf($files); $stored = null;
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        if (!userCanSignIn($actor) || !(int) $actor['is_super_admin'] || warehousePermission($pdo, $actorId, $warehouse) !== 'keeper') throw new DomainException('Bạn không có quyền nhập vào kho này hoặc kho đã ngừng hoạt động.');
        $audit = static function (string $entity, string $id, array $data) use ($pdo, $actorId, $warehouse): void {
            $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,after_data,created_at) VALUES (?,?,'create',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
                ->execute([$actorId, $warehouse, $entity, $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        };
        if ($supplierId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id=? FOR UPDATE'); $stmt->execute([$supplierId]); $supplier = $stmt->fetch();
            if (!$supplier || $supplier['status'] !== 'active') throw new DomainException('Nhà cung cấp không tồn tại hoặc đã ngừng hoạt động.');
            $supplierName = $supplierName ?: $supplier['name'];
        } elseif ($supplierName !== '') {
            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE tax_code=? OR (tax_code IS NULL AND name=? AND ? IS NULL) ORDER BY id LIMIT 1 FOR UPDATE');
            $stmt->execute([$taxCode ?: null, $supplierName, $taxCode ?: null]); $supplier = $stmt->fetch();
            if ($supplier) {
                if ($supplier['status'] !== 'active') throw new DomainException('Nhà cung cấp này đã ngừng hoạt động.');
                $supplierId = (string) $supplier['id'];
            } else {
                $pdo->prepare('INSERT INTO suppliers (name,tax_code,address,phone,email,created_by) VALUES (?,?,?,?,?,?)')->execute([...array_values($supplierValues), $actorId]);
                $supplierId = $pdo->lastInsertId(); $audit('suppliers', $supplierId, $supplierValues);
            }
        }
        if ($invoice !== '' && $supplierId !== null) {
            $stmt = $pdo->prepare("SELECT id FROM import_bill WHERE supplier_id=? AND invoice_number=? AND COALESCE(invoice_series,'')=? AND status<>'cancelled' LIMIT 1");
            $stmt->execute([$supplierId, $invoice, $series]);
            if ($stmt->fetchColumn()) throw new DomainException('Hóa đơn này đã có phiếu nhập. Hãy kiểm tra danh sách để tránh nhập trùng.');
        }
        $lines = []; $newProducts = []; $beforeTax = '0'; $afterTax = '0';
        foreach ($items as $item) {
            if (!is_array($item)) throw new DomainException('Dòng hàng không hợp lệ.');
            $productId = managementId($item['product_id'] ?? '', true);
            if ($productId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? FOR UPDATE'); $stmt->execute([$productId]); $product = $stmt->fetch();
                if (!$product || $product['status'] !== 'active') throw new DomainException('Hàng hóa đã ngừng sử dụng hoặc không tồn tại.');
                if (managementText($item, 'version', 32, true) !== $product['updated_at']) throw new DomainException('Danh mục hàng đã thay đổi. Tải lại trang và kiểm tra hàng hóa trước khi lưu.');
            } else {
                $product = ['code' => managementText($item, 'code', 64), 'name' => managementText($item, 'name', 255, true),
                    'category' => managementText($item, 'category', 20, true), 'unit' => managementText($item, 'unit', 50, true),
                    'specifications' => managementText($item, 'specifications', 16000) ?: null, 'tracking_mode' => managementText($item, 'tracking_mode', 10, true)];
                if (!isset(productCategories()[$product['category']]) || !in_array($product['tracking_mode'], ['quantity', 'none'], true)) throw new DomainException('Nhóm hàng hoặc cách theo dõi không hợp lệ.');
                $key = json_encode($product, JSON_THROW_ON_ERROR);
                if (isset($newProducts[$key])) {
                    [$productId, $product] = $newProducts[$key];
                } else {
                    if ($product['code'] === '') $product['code'] = 'HH-' . strtoupper(bin2hex(random_bytes(6)));
                    $stmt = $pdo->prepare('SELECT id FROM products WHERE code=?'); $stmt->execute([$product['code']]);
                    if ($stmt->fetchColumn()) throw new DomainException('Mã hàng đã tồn tại. Hãy chọn hàng có sẵn thay vì tạo mới.');
                    $pdo->prepare('INSERT INTO products (code,name,category,unit,specifications,tracking_mode,created_by) VALUES (?,?,?,?,?,?,?)')->execute([...array_values($product), $actorId]);
                    $productId = $pdo->lastInsertId(); $audit('products', $productId, $product);
                    $newProducts[$key] = [$productId, $product];
                }
            }
            $quantity = $product['tracking_mode'] === 'none' ? null : importDecimal($item['quantity'] ?? '', true);
            $price = importDecimal($item['unit_price'] ?? '0');
            $amount = ($item['amount_before_tax'] ?? '') === '' ? importDecimal(bcmul($quantity ?? '1', $price, 6)) : importDecimal($item['amount_before_tax']);
            $tax = managementText($item, 'tax_code', 32);
            $taxAmount = $item['tax_amount'] ?? '';
            if ($taxAmount === '') {
                if (preg_match('/^(\d{1,2}(?:[.,]\d{1,6})?)%?$/D', $tax, $rate)) $taxAmount = bcdiv(bcmul($amount, str_replace(',', '.', $rate[1]), 12), '100', 6);
                elseif (in_array(strtoupper($tax), ['', 'KCT', 'KKKNT'], true)) $taxAmount = '0';
                else throw new DomainException('Mã thuế không phải phần trăm: hãy nhập số tiền thuế của dòng hàng.');
            }
            $taxAmount = importDecimal($taxAmount);
            $beforeTax = importDecimal(bcadd($beforeTax, $amount, 6)); $afterTax = importDecimal(bcadd($afterTax, bcadd($amount, $taxAmount, 6), 6));
            $lines[] = ['product_id' => $productId, 'received_quantity' => $quantity, 'unit_price' => $price, 'amount_before_tax' => $amount, 'tax_code' => $tax ?: null,
                'tax_amount' => $taxAmount, 'product_name' => $product['name'], 'unit' => $product['unit'],
                'specifications' => managementText($item, 'specifications', 16000) ?: ($product['specifications'] ?? null), 'note' => managementText($item, 'note', 16000) ?: null];
        }
        foreach (['invoice_before_tax' => $beforeTax, 'invoice_tax' => bcsub($afterTax, $beforeTax, 6), 'invoice_after_tax' => $afterTax] as $field => $total) {
            $expected = managementText($input, $field, 32);
            if ($expected !== '' && bccomp(importDecimal($expected), $total, 6) !== 0) throw new DomainException('Tổng các dòng hàng chưa khớp tổng hóa đơn XML. Kiểm tra thành tiền và tiền thuế trước khi lưu.');
        }
        $header = [$warehouse, $number, $date, $type, $supplierId, $supplierName ?: null, $invoice ?: null, $series ?: null, $invoiceDate, $sender ?: null, $beforeTax, $afterTax, $status, $actorId];
        $pdo->prepare('INSERT INTO import_bill (warehouse_id,number,received_date,receipt_type,supplier_id,supplier_name,invoice_number,invoice_series,invoice_date,sender_name,total_before_tax,total_after_tax,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($header);
        $billId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,unit_price,amount_before_tax,tax_code,tax_amount,product_name,unit,specifications,note,decision_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?)");
        foreach ($lines as $line) $stmt->execute([$billId, ...array_values($line), $actorId]);
        $stored = storePdfAttachment($pdo, $pdf, 'import_bill_id', $billId, $actorId);
        $audit('import_bill', $billId, ['number' => $number, 'warehouse_id' => $warehouse, 'received_date' => $date, 'status' => $status, 'total_before_tax' => $beforeTax, 'total_after_tax' => $afterTax, 'items' => $lines, 'pdf' => $pdf['name'] ?? null]);
        $pdo->commit();
        return 'Đã lưu phiếu nhập ' . $number . ($status === 'confirmed' ? ' và cập nhật tồn kho.' : ' ở trạng thái nháp.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored !== null && is_file($stored)) unlink($stored);
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) throw new DomainException('Số phiếu hoặc mã hàng đã tồn tại. Hãy kiểm tra trước khi lưu lại.');
        throw $error;
    }
}
function loadImports(PDO $pdo): array
{
    $records = [];
    // ponytail: reuse client-side list filtering; move pagination and detail loading to SQL when receipt volume grows.
    foreach ($pdo->query("SELECT b.*,w.name AS warehouse_name,s.tax_code AS supplier_tax_code,
        COALESCE(e.full_name,u.username) AS creator_name FROM import_bill b
        JOIN warehouses w ON w.id=b.warehouse_id LEFT JOIN suppliers s ON s.id=b.supplier_id
        LEFT JOIN users u ON u.id=b.created_by LEFT JOIN employees e ON e.id=u.employee_id
        ORDER BY b.received_date DESC,b.id DESC") as $row) {
        $row['id'] = (string) $row['id']; $row['warehouse_id'] = (string) $row['warehouse_id'];
        $records[$row['id']] = $row + ['items' => [], 'attachments' => []];
    }
    foreach ($pdo->query('SELECT d.*,p.code AS product_code,p.tracking_mode,q.number AS decision_number
        FROM import_bill_details d LEFT JOIN products p ON p.id=d.product_id
        LEFT JOIN decisions q ON q.id=d.decision_id ORDER BY d.id') as $item) {
        if (!isset($records[(string) $item['import_bill_id']])) continue;
        foreach (['received_quantity', 'unit_price', 'amount_before_tax', 'tax_amount'] as $field) {
            $item[$field . '_display'] = $item[$field] === null ? 'Chưa có' : productQuantity($item[$field]);
        }
        if ($item['tracking_mode'] === 'none') $item['received_quantity_display'] = 'Không theo dõi';
        $records[(string) $item['import_bill_id']]['items'][] = $item;
    }
    foreach ($pdo->query('SELECT id,import_bill_id,original_name FROM attachments WHERE import_bill_id IS NOT NULL ORDER BY id') as $file) {
        if (isset($records[(string) $file['import_bill_id']])) $records[(string) $file['import_bill_id']]['attachments'][] = $file;
    }
    foreach ($records as &$row) {
        foreach (['total_before_tax', 'total_after_tax'] as $field) $row[$field . '_display'] = $row[$field] === null ? 'Chưa có' : productQuantity($row[$field]) . ' ₫';
    } unset($row);
    return array_values($records);
}
