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
function importSerialNumbers(mixed $value, ?string $quantity, string $category): ?string
{
    if ($value === null || $value === '') return null;
    if (is_string($value)) {
        if (!mb_check_encoding($value,'UTF-8') || strlen($value)>202000) throw new DomainException('Danh sách serial không hợp lệ.');
        $value = preg_split('/\r\n|\r|\n/',$value);
    }
    if (!is_array($value) || !array_is_list($value) || count($value)>2000) throw new DomainException('Nhập tối đa 2000 serial theo thứ tự từng chiếc.');
    $serials=[]; $seen=[];
    foreach ($value as $serial) {
        if (!is_string($serial) || !mb_check_encoding($serial,'UTF-8') || mb_strlen($serial)>100 || preg_match('/[\x00-\x1F\x7F]/u',$serial)) throw new DomainException('Mỗi serial tối đa 100 ký tự, không chứa ký tự điều khiển.');
        $serial=trim($serial); $key=mb_strtoupper($serial);
        if ($serial!=='' && isset($seen[$key])) throw new DomainException('Serial bị trùng trong cùng một dòng hàng.');
        if ($serial!=='') $seen[$key]=true;
        $serials[]=$serial;
    }
    while ($serials && end($serials)==='') array_pop($serials);
    if (!$serials) return null;
    if (!in_array($category,['ccdc','fixed_asset'],true)) throw new DomainException('Serial từng chiếc chỉ áp dụng cho CCDC và tài sản cố định.');
    if ($quantity===null || bccomp($quantity,bcadd($quantity,'0',0),6)!==0 || bccomp((string)count($serials),$quantity,6)>0) throw new DomainException('Serial cần số lượng nguyên và không được nhiều hơn số chiếc đã nhập.');
    return json_encode($serials,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
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
            'tax_amount' => $text($line, 'TThue'), 'note' => ''];
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
    $pdf = receivePdf($files); $stored = null; $storedImages = [];
    $images = receiveImportImages($files, $input, count($items));
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        if (!userCanAct($actor) || warehousePermission($pdo, $actorId, $warehouse) !== 'keeper') throw new DomainException('Bạn không có quyền nhập vào kho này hoặc kho đã ngừng hoạt động.');
        $access = userAccess($pdo, $actorId);
        $catalogueIds = $access['admin'] ? null : array_fill_keys(array_column(loadProducts($pdo, '', $access), 'id'), true);
        if ($supplierId !== null && !$access['admin']) {
            $supplierIds = array_column(loadImports($pdo, $access), 'supplier_id');
            if (!in_array($supplierId, array_map('strval', $supplierIds), true)) throw new DomainException('Nhà cung cấp không thuộc phạm vi kho được cấp quyền.');
        }
        $audit = static function (string $entity, string $id, array $data) use ($pdo, $actorId, $warehouse, $access): void {
            $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,after_data,created_at,notification_admin) VALUES (?,?,'create',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR,?)")
                ->execute([$actorId, $warehouse, $entity, $id, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (int)$access['admin']]);
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
        $lines = []; $newProducts = []; $categoryChanges = []; $selectedCategories = []; $beforeTax = '0'; $afterTax = '0';
        foreach ($items as $item) {
            if (!is_array($item)) throw new DomainException('Dòng hàng không hợp lệ.');
            $productId = managementId($item['product_id'] ?? '', true);
            if ($productId !== null && $catalogueIds !== null && !isset($catalogueIds[$productId])) throw new DomainException('Hàng hóa không thuộc phạm vi kho được cấp quyền.');
            if ($productId !== null) {
                $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? FOR UPDATE'); $stmt->execute([$productId]); $product = $stmt->fetch();
                if (!$product || $product['status'] !== 'active') throw new DomainException('Hàng hóa đã ngừng sử dụng hoặc không tồn tại.');
                if (managementText($item, 'version', 32, true) !== $product['updated_at']) throw new DomainException('Danh mục hàng đã thay đổi. Tải lại trang và kiểm tra hàng hóa trước khi lưu.');
                $category = array_key_exists('category',$item) ? managementText($item,'category',20,true) : $product['category'];
                if (!isset(productCategories()[$category])) throw new DomainException('Nhóm hàng không hợp lệ.');
                if (isset($selectedCategories[$productId]) && $selectedCategories[$productId] !== $category) throw new DomainException('Các dòng của cùng một hàng hóa phải chọn cùng nhóm hàng.');
                $selectedCategories[$productId] = $category;
                if ($category !== $product['category']) {
                    if (!$access['admin']) {
                        $stmt = $pdo->prepare('SELECT b.warehouse_id FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id WHERE d.product_id=? UNION SELECT b.warehouse_id FROM export_bill_details d JOIN export_bill b ON b.id=d.export_bill_id WHERE d.product_id=?');
                        $stmt->execute([$productId,$productId]);
                        foreach ($stmt as $used) if (!canManageWarehouse($access,(string)$used['warehouse_id'])) throw new DomainException('Hàng hóa dùng chung với kho ngoài quyền quản lý. Cần quản trị viên đổi nhóm hàng.');
                    }
                    $before = ['id'=>$productId,'code'=>$product['code'],'name'=>$product['name'],'category'=>$product['category']];
                    $categoryChanges[$productId] = [$before,array_replace($before,['category'=>$category])];
                    $product['category'] = $category;
                }
            } else {
                $product = ['code' => managementText($item, 'code', 64), 'name' => managementText($item, 'name', 255, true),
                    'category' => managementText($item, 'category', 20, true), 'unit' => managementText($item, 'unit', 50, true),
                    'tracking_mode' => managementText($item, 'tracking_mode', 10, true)];
                if (!isset(productCategories()[$product['category']]) || !in_array($product['tracking_mode'], ['quantity', 'none'], true)) throw new DomainException('Nhóm hàng hoặc cách theo dõi không hợp lệ.');
                $key = json_encode($product, JSON_THROW_ON_ERROR);
                if (isset($newProducts[$key])) {
                    [$productId, $product] = $newProducts[$key];
                } else {
                    if ($product['code'] === '') $product['code'] = 'HH-' . strtoupper(bin2hex(random_bytes(6)));
                    $stmt = $pdo->prepare('SELECT id FROM products WHERE code=?'); $stmt->execute([$product['code']]);
                    if ($stmt->fetchColumn()) throw new DomainException('Mã hàng đã tồn tại. Hãy chọn hàng có sẵn thay vì tạo mới.');
                    $pdo->prepare('INSERT INTO products (code,name,category,unit,tracking_mode,created_by) VALUES (?,?,?,?,?,?)')->execute([...array_values($product), $actorId]);
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
                'note' => managementText($item, 'note', 16000) ?: null,
                'serial_numbers' => importSerialNumbers($item['serial_numbers'] ?? null,$quantity,$product['category'])];
        }
        foreach (['invoice_before_tax' => $beforeTax, 'invoice_tax' => bcsub($afterTax, $beforeTax, 6), 'invoice_after_tax' => $afterTax] as $field => $total) {
            $expected = managementText($input, $field, 32);
            if ($expected !== '' && bccomp(importDecimal($expected), $total, 6) !== 0) throw new DomainException('Tổng các dòng hàng chưa khớp tổng hóa đơn XML. Kiểm tra thành tiền và tiền thuế trước khi lưu.');
        }
        $printSnapshot = receiptPrintSnapshot($pdo, $warehouse, $actorId, $input);
        foreach ($categoryChanges as $productId=>[$before,$after]) {
            $pdo->prepare('UPDATE products SET category=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')->execute([$after['category'],$productId]);
            $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at,notification_admin) VALUES (?,?,'update','products',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR,?)")
                ->execute([$actorId,$warehouse,$productId,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$access['admin']]);
        }
        $header = [$warehouse, $number, $date, $type, $supplierId, $supplierName ?: null, $invoice ?: null, $series ?: null, $invoiceDate, $sender ?: null, $beforeTax, $afterTax, $status, $actorId, json_encode($printSnapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        $pdo->prepare('INSERT INTO import_bill (warehouse_id,number,received_date,receipt_type,supplier_id,supplier_name,invoice_number,invoice_series,invoice_date,sender_name,total_before_tax,total_after_tax,status,created_by,print_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($header);
        $billId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO import_bill_details (import_bill_id,product_id,received_quantity,unit_price,amount_before_tax,tax_code,tax_amount,product_name,unit,note,serial_numbers,decision_status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending',?)");
        foreach ($lines as $index => $line) {
            $stmt->execute([$billId, ...array_values($line), $actorId]);
            $detailId = $pdo->lastInsertId();
            foreach ($images[$index] ?? [] as $image) $storedImages[] = storeAttachment($pdo, $image, 'import_bill_detail_id', $detailId, $actorId);
        }
        $stored = storeAttachment($pdo, $pdf, 'import_bill_id', $billId, $actorId);
        $audit('import_bill', $billId, ['number' => $number, 'warehouse_id' => $warehouse, 'received_date' => $date, 'status' => $status, 'total_before_tax' => $beforeTax, 'total_after_tax' => $afterTax, 'items' => $lines, 'print_snapshot'=>$printSnapshot, 'pdf' => $pdf['name'] ?? null, 'images'=>array_map(static fn($photos)=>array_column($photos,'name'), $images)]);
        $pdo->commit();
        return 'Đã lưu phiếu nhập ' . $number . ($status === 'confirmed' ? ' và cập nhật tồn kho.' : ' ở trạng thái nháp.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($stored !== null && is_file($stored)) unlink($stored);
        foreach ($storedImages as $path) if (is_file($path)) unlink($path);
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1062) throw new DomainException('Số phiếu hoặc mã hàng đã tồn tại. Hãy kiểm tra trước khi lưu lại.');
        throw $error;
    }
}
function importVersion(array $bill, array $items): string
{
    return hash('sha256', json_encode([$bill['updated_at'], array_map(static fn($item) => [(string)$item['id'], $item['updated_at']], $items)], JSON_THROW_ON_ERROR));
}
function updateImport(PDO $pdo, string $actorId, array $input): string
{
    if (($input['action'] ?? '') !== 'update' || ($input['form_complete'] ?? '') !== '1') throw new DomainException('Biểu mẫu chỉnh sửa chưa đầy đủ. Hãy tải lại trang.');
    $id = managementId($input['id'] ?? null);
    try { $items = json_decode(managementText($input, 'items', 500000, true), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new DomainException('Danh sách hàng hóa không hợp lệ.'); }
    if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 200) throw new DomainException('Danh sách hàng hóa không hợp lệ.');
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        $stmt = $pdo->prepare('SELECT * FROM import_bill WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $bill = $stmt->fetch();
        if (!$bill || !userCanAct($actor) || warehousePermission($pdo, $actorId, (string)$bill['warehouse_id']) !== 'keeper') throw new DomainException('Phiếu không tồn tại hoặc bạn không có quyền sửa tại kho này.');
        if ($bill['status'] === 'cancelled') throw new DomainException('Phiếu đã hủy chỉ được tra cứu.');
        $stmt = $pdo->prepare('SELECT d.*,p.tracking_mode,p.category,p.unit AS catalogue_unit, EXISTS(SELECT 1 FROM export_bill_details e WHERE e.source_import_detail_id=d.id) AS stock_locked
            FROM import_bill_details d LEFT JOIN products p ON p.id=d.product_id WHERE d.import_bill_id=? ORDER BY d.id FOR UPDATE');
        $stmt->execute([$id]); $before = $stmt->fetchAll();
        if (managementText($input, 'version', 64, true) !== importVersion($bill, $before)) throw new DomainException('Phiếu hoặc quyết định liên kết đã thay đổi. Hãy tải lại trang trước khi sửa.');
        if (count($items) !== count($before)) throw new DomainException('Phải giữ đủ các dòng hàng đã lập; không thêm hoặc xóa dòng tại đây.');
        $original = array_column($before, null, 'id'); $seen = []; $after = []; $total = '0'; $afterTax = '0';
        foreach ($items as $item) {
            if (!is_array($item)) throw new DomainException('Dòng hàng không hợp lệ.');
            $lineId = managementId($item['id'] ?? null);
            if (!isset($original[$lineId]) || isset($seen[$lineId])) throw new DomainException('Dòng hàng không thuộc phiếu này hoặc bị trùng.');
            $seen[$lineId] = true; $old = $original[$lineId];
            $quantity = $old['tracking_mode'] === 'none' || ($old['received_quantity'] === null && ($item['received_quantity'] ?? '') === '') ? null : importDecimal($item['received_quantity'] ?? '', true);
            $unit = managementText($item, 'unit', 50, $old['unit'] !== null) ?: null;
            $line = ['product_name'=>managementText($item, 'product_name', 255, true), 'unit'=>$unit,
                'received_quantity'=>$quantity, 'unit_price'=>importDecimal($item['unit_price'] ?? ''), 'amount_before_tax'=>importDecimal($item['amount_before_tax'] ?? ''),
                'tax_amount'=>importDecimal($item['tax_amount'] ?? ''), 'tax_code'=>managementText($item, 'tax_code', 32) ?: null];
            // Existing exports and paired transfers retain their original lot quantity, unit and valuation.
            if ($old['stock_locked'] || $old['source_export_detail_id'] !== null) {
                foreach ($line as $field=>$value) if ($value !== $old[$field]) throw new DomainException('Dòng đã được dùng để xuất kho hoặc nhận điều chuyển: chỉ sửa serial, quyết định và ghi chú.');
            } elseif ($line['unit'] !== $old['unit'] && $old['tracking_mode'] === 'quantity' && $line['unit'] !== $old['catalogue_unit']) {
                throw new DomainException('Đơn vị hàng theo dõi tồn kho phải khớp đơn vị trong danh mục; không tự chuyển đổi số lượng.');
            }
            $decision = managementText($item, 'decision', 20, true);
            $decisionId = in_array($decision, ['pending','none'], true) ? null : managementId($decision);
            $decisionStatus = $decisionId === null ? $decision : 'linked';
            if ($decisionId !== null && (string)$old['decision_id'] !== $decisionId) {
                if ($bill['status'] !== 'confirmed') throw new DomainException('Cần xác nhận phiếu trước khi gắn quyết định.');
                $stmt = $pdo->prepare('SELECT warehouse_id FROM decisions WHERE id=? FOR UPDATE'); $stmt->execute([$decisionId]);
                if ((string)$stmt->fetchColumn() !== (string)$bill['warehouse_id']) throw new DomainException('Quyết định không tồn tại hoặc thuộc kho khác.');
            }
            $line += ['decision_id'=>$decisionId, 'decision_status'=>$decisionStatus, 'note'=>managementText($item, 'note', 16000) ?: null];
            $line['serial_numbers'] = array_key_exists('serial_numbers',$item)
                ? importSerialNumbers($item['serial_numbers'],$quantity,$old['category']) : $old['serial_numbers'];
            if (!array_key_exists('serial_numbers',$item) && $old['serial_numbers']!==null) importSerialNumbers(json_decode($old['serial_numbers'],true,512,JSON_THROW_ON_ERROR),$quantity,'fixed_asset');
            if ($decisionStatus === 'none' && $line['note'] === null) throw new DomainException('Dòng không có quyết định cần ghi rõ lý do trong ghi chú.');
            $total = importDecimal(bcadd($total, $line['amount_before_tax'], 6));
            $afterTax = importDecimal(bcadd($afterTax, bcadd($line['amount_before_tax'], $line['tax_amount'], 6), 6));
            $pdo->prepare('UPDATE import_bill_details SET product_name=?,unit=?,received_quantity=?,unit_price=?,amount_before_tax=?,tax_amount=?,tax_code=?,decision_id=?,decision_status=?,note=?,serial_numbers=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')
                ->execute([...array_values($line), $lineId]);
            $after[] = ['id'=>$lineId] + $line;
        }
        $pdo->prepare('UPDATE import_bill SET total_before_tax=?,total_after_tax=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')->execute([$total, $afterTax, $id]);
        $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at) VALUES (?,?,'update','import_bill',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
            ->execute([$actorId, $bill['warehouse_id'], $id, json_encode(['total_before_tax'=>$bill['total_before_tax'], 'total_after_tax'=>$bill['total_after_tax'], 'items'=>$before], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode(['total_before_tax'=>$total, 'total_after_tax'=>$afterTax, 'items'=>$after], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return 'Đã cập nhật hàng hóa trong phiếu nhập ' . $bill['number'] . '.';
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function importRemovalReason(array $bill, array $items): string
{
    if ($bill['status'] === 'cancelled') return 'Phiếu đã hủy, được giữ lại để tra cứu lịch sử.';
    if (($bill['warehouse_status'] ?? 'active') !== 'active') return 'Kho đã ngừng hoạt động.';
    if ($bill['receipt_type'] === 'transfer') return 'Phiếu điều chuyển được giữ nguyên để đối chiếu giữa các kho.';
    foreach ($items as $item) {
        if ($item['source_export_detail_id'] ?? null) return 'Phiếu nhận điều chuyển được giữ nguyên để đối chiếu giữa các kho.';
        if ((int)($item['stock_locked'] ?? 0)) return 'Phiếu có hàng đã liên kết phiếu xuất; không thể xóa hoặc hủy.';
    }
    return '';
}
function detachImportDecisions(PDO $pdo, string $billId): void
{
    $pdo->prepare("UPDATE import_bill_details SET decision_id=NULL,decision_status='pending',updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE import_bill_id=? AND decision_id IS NOT NULL")->execute([$billId]);
}
function changeImportState(PDO $pdo, string $actorId, array $input): string
{
    $action = managementText($input, 'action', 20, true);
    if (!in_array($action, ['delete','cancel','confirm'], true)) throw new DomainException('Thao tác phiếu nhập không hợp lệ.');
    $id = managementId($input['id'] ?? null);
    $reason = $action === 'cancel' ? managementText($input, 'cancel_reason', 16000, true) : null;
    $paths = []; $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $actor = findUser($pdo, 'id', $actorId);
        $stmt = $pdo->prepare('SELECT * FROM import_bill WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $bill = $stmt->fetch();
        if (!$bill || !userCanAct($actor) || warehousePermission($pdo, $actorId, (string)$bill['warehouse_id']) !== 'keeper') throw new DomainException('Phiếu không tồn tại hoặc bạn không có quyền tại kho này.');
        $stmt = $pdo->prepare('SELECT d.*,EXISTS(SELECT 1 FROM export_bill_details e WHERE e.source_import_detail_id=d.id) AS stock_locked
            FROM import_bill_details d WHERE d.import_bill_id=? ORDER BY d.id FOR UPDATE'); $stmt->execute([$id]); $items = $stmt->fetchAll();
        if (managementText($input, 'version', 64, true) !== importVersion($bill, $items)) throw new DomainException('Phiếu hoặc quyết định liên kết đã thay đổi. Hãy tải lại trang trước khi thực hiện.');
        if ($blocked = importRemovalReason($bill, $items)) throw new DomainException($blocked);
        if ($bill['status'] !== ($action === 'cancel' ? 'confirmed' : 'draft')) throw new DomainException('Chỉ xác nhận/xóa phiếu nháp hoặc hủy phiếu đã xác nhận. Hãy tải lại trang để kiểm tra trạng thái.');
        $stmt = $pdo->prepare('SELECT a.* FROM attachments a LEFT JOIN import_bill_details d ON d.id=a.import_bill_detail_id
            WHERE a.import_bill_id=? OR d.import_bill_id=? ORDER BY a.id FOR UPDATE'); $stmt->execute([$id,$id]); $files = $stmt->fetchAll();
        $before = $bill + ['items'=>$items,'attachments'=>$files];
        if ($action === 'delete') {
            $uploads = dirname(__DIR__) . '/storage/uploads/'; $root = realpath($uploads);
            foreach ($files as $file) {
                $owner = $file['import_bill_id'] !== null ? 'import_bill_id' : 'import_bill_detail_id';
                if (!validAttachmentStoragePath($file, $owner)) throw new DomainException('Đường dẫn tệp đính kèm không hợp lệ; chưa xóa phiếu.');
                $path = realpath($uploads . $file['storage_path']);
                if ($path === false) continue; // A missing upload does not prevent removing its metadata.
                if ($root === false || !is_file($path) || !str_starts_with(str_replace('\\','/',$path), str_replace('\\','/',$root).'/')) throw new DomainException('Tệp đính kèm nằm ngoài thư mục lưu trữ; chưa xóa phiếu.');
                $paths[$file['storage_path']] = $path;
            }
            $pdo->prepare('DELETE a FROM attachments a LEFT JOIN import_bill_details d ON d.id=a.import_bill_detail_id WHERE a.import_bill_id=? OR d.import_bill_id=?')->execute([$id,$id]);
            $pdo->prepare('DELETE FROM import_bill_details WHERE import_bill_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM import_bill WHERE id=?')->execute([$id]);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM attachments WHERE storage_path=?');
            foreach ($paths as $relative=>$path) { $stmt->execute([$relative]); if ($stmt->fetchColumn()) unset($paths[$relative]); }
            $after = null;
        } elseif ($action === 'confirm') {
            if (!$items) throw new DomainException('Phiếu chưa có dòng hàng. Hãy bổ sung hàng hóa trước khi xác nhận.');
            if ($bill['receipt_type'] === 'purchase' && !$bill['supplier_id'] && !$bill['supplier_name']) throw new DomainException('Phiếu mua hàng chưa có nhà cung cấp.');
            $beforeTax = '0'; $afterTax = '0';
            $productQuery = $pdo->prepare('SELECT status,tracking_mode,unit FROM products WHERE id=? FOR UPDATE');
            foreach ($items as $item) {
                $productQuery->execute([$item['product_id']]); $product = $productQuery->fetch();
                if (!$product || $product['status'] !== 'active') throw new DomainException('Phiếu có hàng hóa không tồn tại hoặc đã ngừng sử dụng.');
                if (!$item['product_name'] || !$item['unit']) throw new DomainException('Dòng hàng thiếu tên hoặc đơn vị. Hãy chỉnh sửa trước khi xác nhận.');
                if ($product['tracking_mode'] === 'quantity') {
                    importDecimal($item['received_quantity'],true);
                    if ($item['unit'] !== $product['unit']) throw new DomainException('Đơn vị hàng theo dõi tồn kho phải khớp danh mục trước khi xác nhận.');
                } elseif ($item['received_quantity'] !== null) throw new DomainException('Hàng không theo dõi số lượng phải để trống số lượng.');
                importDecimal($item['unit_price']);
                $amount = importDecimal($item['amount_before_tax']); $tax = importDecimal($item['tax_amount']);
                $beforeTax = bcadd($beforeTax,$amount,6); $afterTax = bcadd($afterTax,bcadd($amount,$tax,6),6);
                if ($item['serial_numbers'] !== null) importSerialNumbers(json_decode($item['serial_numbers'],true,512,JSON_THROW_ON_ERROR),$item['received_quantity'],'fixed_asset');
            }
            if (bccomp($beforeTax,$bill['total_before_tax'],6)!==0 || bccomp($afterTax,$bill['total_after_tax'],6)!==0) throw new DomainException('Tổng phiếu chưa khớp các dòng hàng. Hãy chỉnh sửa và lưu trước khi xác nhận.');
            if ($bill['supplier_id'] && $bill['invoice_number']) {
                $stmt = $pdo->prepare("SELECT id FROM import_bill WHERE supplier_id=? AND invoice_number=? AND COALESCE(invoice_series,'')=? AND status<>'cancelled' AND id<>? LIMIT 1 FOR UPDATE");
                $stmt->execute([$bill['supplier_id'],$bill['invoice_number'],$bill['invoice_series'] ?? '',$id]);
                if ($stmt->fetchColumn()) throw new DomainException('Hóa đơn này đã có phiếu nhập khác. Hãy kiểm tra trước khi xác nhận.');
            }
            $pdo->prepare("UPDATE import_bill SET status='confirmed',updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?")->execute([$id]);
            $after = array_replace($before,['status'=>'confirmed']); unset($after['updated_at']);
        } else {
            $pdo->prepare("UPDATE import_bill SET status='cancelled',cancel_reason=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?")->execute([$reason,$id]);
            detachImportDecisions($pdo, $id);
            $after = array_replace($before, ['status'=>'cancelled','cancel_reason'=>$reason]); unset($after['updated_at']);
            $stmt = $pdo->prepare('SELECT d.*,EXISTS(SELECT 1 FROM export_bill_details e WHERE e.source_import_detail_id=d.id) AS stock_locked FROM import_bill_details d WHERE d.import_bill_id=? ORDER BY d.id');
            $stmt->execute([$id]); $after['items'] = $stmt->fetchAll();
        }
        $pdo->prepare("INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at) VALUES (?,?,?,'import_bill',?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)")
            ->execute([$actorId,$bill['warehouse_id'],$action,$id,json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$after === null ? null : json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && ($error->errorInfo[1] ?? 0) === 1451) throw new DomainException('Phiếu đang có dữ liệu liên kết; không thể xóa. Hãy tải lại trang để kiểm tra.');
        throw $error;
    }
    $cleanupFailed = false;
    foreach ($paths as $path) if (is_file($path) && !@unlink($path)) { $cleanupFailed = true; error_log('QLVT receipt upload cleanup failed: '.$path); }
    if ($action === 'confirm') return 'Đã xác nhận nhập kho phiếu '.$bill['number'].', cập nhật tồn kho và lưu nhật ký.';
    return $action === 'cancel' ? 'Đã hủy phiếu '.$bill['number'].', cập nhật tồn kho, gỡ liên kết quyết định và giữ lịch sử cùng tệp đính kèm.'
        : 'Đã xóa phiếu nháp '.$bill['number'].' và lưu nhật ký.' . ($cleanupFailed ? ' Một số tệp chưa dọn được do quyền thư mục.' : '');
}
function loadImports(PDO $pdo, ?array $access = null): array
{
    $records = [];
    // ponytail: reuse client-side list filtering; move pagination and detail loading to SQL when receipt volume grows.
    foreach ($pdo->query("SELECT b.*,w.name AS warehouse_name,w.status AS warehouse_status,s.tax_code AS supplier_tax_code,
        COALESCE(e.full_name,u.username) AS creator_name FROM import_bill b
        JOIN warehouses w ON w.id=b.warehouse_id LEFT JOIN suppliers s ON s.id=b.supplier_id
        LEFT JOIN users u ON u.id=b.created_by LEFT JOIN employees e ON e.id=u.employee_id
        ORDER BY b.received_date DESC,b.id DESC") as $row) {
        if ($access !== null && !canViewWarehouse($access, (string)$row['warehouse_id'])) continue;
        $row['id'] = (string) $row['id']; $row['warehouse_id'] = (string) $row['warehouse_id'];
        $records[$row['id']] = $row + ['items' => [], 'attachments' => []];
    }
    foreach ($pdo->query('SELECT d.*,p.code AS product_code,p.tracking_mode,p.category,q.number AS decision_number,
        EXISTS(SELECT 1 FROM export_bill_details e WHERE e.source_import_detail_id=d.id) AS stock_locked
        FROM import_bill_details d LEFT JOIN products p ON p.id=d.product_id
        LEFT JOIN decisions q ON q.id=d.decision_id ORDER BY d.id') as $item) {
        if (!isset($records[(string) $item['import_bill_id']])) continue;
        foreach (['id','product_id','decision_id'] as $field) if ($item[$field] !== null) $item[$field] = (string)$item[$field];
        foreach (['received_quantity', 'unit_price', 'amount_before_tax', 'tax_amount'] as $field) {
            $item[$field . '_display'] = $item[$field] === null ? 'Chưa có' : productQuantity($item[$field]);
        }
        if ($item['tracking_mode'] === 'none') $item['received_quantity_display'] = 'Không theo dõi';
        $item['serial_numbers'] = $item['serial_numbers']===null ? [] : json_decode($item['serial_numbers'],true,512,JSON_THROW_ON_ERROR);
        $records[(string) $item['import_bill_id']]['items'][] = $item;
    }
    foreach ($pdo->query('SELECT id,import_bill_id,original_name FROM attachments WHERE import_bill_id IS NOT NULL ORDER BY id') as $file) {
        $file['id'] = (string)$file['id'];
        if (isset($records[(string) $file['import_bill_id']])) $records[(string) $file['import_bill_id']]['attachments'][] = $file;
    }
    $photos = [];
    foreach ($pdo->query("SELECT id,import_bill_detail_id,original_name FROM attachments WHERE import_bill_detail_id IS NOT NULL AND mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY id") as $photo) {
        $photo['id'] = (string)$photo['id']; $photos[(string)$photo['import_bill_detail_id']][] = $photo;
    }
    foreach ($records as &$row) {
        $row['version'] = importVersion($row, $row['items']);
        $row['remove_reason'] = importRemovalReason($row, $row['items']);
        $row['can_edit'] = canManageWarehouse($access, (string)$row['warehouse_id']);
        foreach ($row['items'] as &$item) $item['images'] = $photos[(string)$item['id']] ?? []; unset($item);
        foreach (['total_before_tax', 'total_after_tax'] as $field) $row[$field . '_display'] = $row[$field] === null ? 'Chưa có' : productQuantity($row[$field]) . ' ₫';
    } unset($row);
    return array_values($records);
}
