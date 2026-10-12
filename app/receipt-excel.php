<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function receiptMoneyWords(string $amount): string
{
    $digits = ['không','một','hai','ba','bốn','năm','sáu','bảy','tám','chín'];
    $negative = str_starts_with($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '');
    $whole = ltrim($whole, '0') ?: '0';
    $fraction = rtrim($fraction, '0');
    $groups = str_split(str_pad($whole, (int) ceil(strlen($whole) / 3) * 3, '0', STR_PAD_LEFT), 3);
    $scales = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];
    $words = [];
    foreach ($groups as $index => $group) {
        if ((int) $group === 0) continue;
        [$hundreds, $tens, $ones] = array_map('intval', str_split($group));
        $part = [];
        if ($hundreds || $words) $part[] = $digits[$hundreds] . ' trăm';
        if ($tens > 1) $part[] = $digits[$tens] . ' mươi';
        elseif ($tens === 1) $part[] = 'mười';
        elseif ($ones && $part) $part[] = 'linh';
        if ($ones) $part[] = match (true) {
            $ones === 1 && $tens > 1 => 'mốt',
            $ones === 5 && $tens > 0 => 'lăm',
            default => $digits[$ones],
        };
        $part[] = $scales[count($groups) - $index - 1];
        $words[] = trim(implode(' ', $part));
    }
    $text = ($negative && ($whole !== '0' || $fraction !== '') ? 'âm ' : '') . (implode(' ', $words) ?: 'không');
    if ($fraction !== '') $text .= ' phẩy ' . implode(' ', array_map(static fn($digit) => $digits[(int) $digit], str_split($fraction)));
    return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) . ' đồng ./.';
}

function buildReceiptWorkbook(string $direction, array $bill): Spreadsheet
{
    if (!in_array($direction, ['import', 'export'], true)) throw new DomainException('Loại phiếu không hợp lệ.');
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $incoming = $direction === 'import';
    $book = IOFactory::load(dirname(__DIR__) . '/resources/templates/receipts/mau_phieu_' . ($incoming ? 'nhap' : 'xuat') . '_kho.xlsx');
    $sheet = $book->getActiveSheet();
    if (!$incoming) foreach (['A4:E4','F4:I4','A6:E6','F6:I6'] as $range) $sheet->mergeCells($range);
    $print = is_string($bill['print_snapshot'] ?? null) ? json_decode($bill['print_snapshot'], true, 16, JSON_THROW_ON_ERROR) : ($bill['print_snapshot'] ?? []);
    $sheet->setCellValueExplicit('A1', "Đơn vị:\n" . ($print['unit_name'] ?? '') . "\nBộ phận:\n" . ($print['department_name'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('F4', 'Địa chỉ bộ phận: ' . ($print['department_address'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('F6', 'Địa điểm: ' . ($print['location'] ?? ''), DataType::TYPE_STRING);
    $firstRow = $incoming ? 9 : 10;
    // Keep the original single blank row when a historical receipt has no details.
    $rowCount = max(1, count($bill['items']));
    if ($rowCount > 1) $sheet->insertNewRowBefore($firstRow + 1, $rowCount - 1);
    $totalRow = $firstRow + $rowCount;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $bill[$incoming ? 'received_date' : 'issued_date']);
    if (!$date || $date->format('Y-m-d') !== $bill[$incoming ? 'received_date' : 'issued_date']) throw new DomainException('Ngày của phiếu không hợp lệ.');
    $dateLabel = 'Ngày ' . $date->format('d') . ' tháng ' . $date->format('m') . ' năm ' . $date->format('Y');
    $sheet->mergeCells('C1:D2');
    $sheet->setCellValueExplicit('C1', ($incoming ? 'PHIẾU NHẬP KHO' : 'PHIẾU XUẤT KHO') . "\n" . $dateLabel, DataType::TYPE_STRING);
    $sheet->getStyle('C1:D2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $status = ['draft' => ' · BẢN NHÁP', 'confirmed' => '', 'cancelled' => ' · ĐÃ HỦY'][$bill['status']];
    $sheet->mergeCells('C3:I3');
    $sheet->setCellValueExplicit('C3', 'Số phiếu: ' . $bill['number'] . $status, DataType::TYPE_STRING);
    $sheet->getStyle('C3')->getFont()->setSize(10);
    $sheet->getStyle('C3')->getAlignment()->setShrinkToFit(true);
    $person = (string) ($bill[$incoming ? 'sender_name' : 'receiver_name'] ?? '');
    $sheet->setCellValueExplicit('A4', '-  Họ và tên người ' . ($incoming ? 'giao' : 'nhận') . ' hàng: ' . $person, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('A5', $incoming
        ? '-  Theo: ' . (!empty($bill['invoice_number']) ? 'Hoá đơn số ' . $bill['invoice_number'] : 'Phiếu số ' . $bill['number'])
        : '-  Lý do xuất kho: ' . ($bill['reason'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('A6', '-  ' . ($incoming ? 'Nhập' : 'Xuất') . ' tại kho (ngăn lô): ' . ($print['warehouse_name'] ?? $bill['warehouse_name']), DataType::TYPE_STRING);
    $total = '0.000000';
    foreach ($bill['items'] as $index => $item) {
        $row = $firstRow + $index;
        $sheet->mergeCells('B' . $row . ':C' . $row);
        $sheet->setCellValue('A' . $row, $index + 1);
        $values = ['B' => $item['product_name'], 'D' => $item['product_code'] ?? '', 'E' => $item['unit'] ?? '',
            'F' => $item[$incoming ? 'received_quantity' : 'issued_quantity'], 'G' => $item[$incoming ? 'received_quantity' : 'issued_quantity'],
            'H' => $item['unit_price'], 'I' => $item['amount_before_tax'], 'J' => '', 'K' => $item['note'] ?? ''];
        foreach ($values as $column => $value) {
            // Strings retain all six DECIMAL places and cannot become spreadsheet formulas.
            $sheet->setCellValueExplicit($column . $row, $value === null ? '' : (in_array($column, ['F','G','H','I'], true) ? productQuantity($value) : $value), DataType::TYPE_STRING);
        }
        $sheet->getStyle('B' . $row)->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_LEFT);
        if (str_contains($values['B'], "\n")) $sheet->getRowDimension($row)->setRowHeight(max(30, $sheet->getRowDimension($row)->getRowHeight()));
        $total = bcadd($total, $item['amount_before_tax'], 6);
    }
    if (!$bill['items']) {
        foreach (range('A', 'K') as $column) $sheet->setCellValueExplicit($column . $firstRow, '', DataType::TYPE_STRING);
    }
    if (bccomp($total, $bill['total_before_tax'], 6) !== 0) {
        $sheet->setCellValueExplicit('C3', 'Số phiếu: ' . $bill['number'] . $status . ' · CẦN ĐỐI SOÁT TỔNG PHIẾU', DataType::TYPE_STRING);
    }
    $sheet->setCellValueExplicit('I' . $totalRow, productQuantity($total), DataType::TYPE_STRING);
    $sheet->getStyle('F' . $firstRow . ':I' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setShrinkToFit(true);
    $sheet->setCellValueExplicit('A' . ($totalRow + 2), '- Tổng số tiền (viết bằng chữ): ' . receiptMoneyWords($total), DataType::TYPE_STRING);
    $invoices = $incoming ? array_filter([$bill['invoice_number'] ?? '']) : array_filter(array_column($bill['items'], 'source_invoice_number'));
    $sources = $incoming ? ($invoices ? [] : [$bill['number']]) : array_filter(array_column(array_filter($bill['items'], static fn($item) => empty($item['source_invoice_number'])), 'source_number'));
    $documents = $invoices ? ['Hoá đơn số ' . implode(', ', array_unique($invoices))] : [];
    if ($sources) $documents[] = 'Phiếu số ' . implode(', ', array_unique($sources));
    $documents = implode('; ', $documents) ?: 'Phiếu số ' . $bill['number'];
    $sheet->setCellValueExplicit('A' . ($totalRow + 3), '- Số chứng từ gốc kèm theo: ' . $documents, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('G' . ($totalRow + 5), $dateLabel, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C' . ($totalRow + 13), $person, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('A' . ($totalRow + 13), $print['creator_name'] ?? ($bill['creator_name'] ?? ''), DataType::TYPE_STRING);
    foreach (['D'=>'keeper','F'=>'accountant','H'=>'director'] as $column=>$role) $sheet->setCellValueExplicit($column . ($totalRow + 13), $print[$role.'_name'] ?? '', DataType::TYPE_STRING);
    foreach (['A1','A4','F4','A6','F6'] as $cell) $sheet->getStyle($cell)->getAlignment()->setShrinkToFit(true);
    return $book;
}

function downloadReceiptExcel(PDO $pdo, string $direction, mixed $value, string $userId): never
{
    $id = managementId($value);
    if (!in_array($direction, ['import', 'export'], true)) throw new DomainException('Loại phiếu không hợp lệ.');
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT b.*,w.name AS warehouse_name,COALESCE(e.full_name,u.username) AS creator_name
            FROM ' . $direction . '_bill b JOIN warehouses w ON w.id=b.warehouse_id
            LEFT JOIN users u ON u.id=b.created_by LEFT JOIN employees e ON e.id=u.employee_id WHERE b.id=?');
        $statement->execute([$id]);
        $bill = $statement->fetch();
        if ($bill && !canViewWarehouse(userAccess($pdo, $userId), (string)$bill['warehouse_id'])) $bill = false;
        if ($bill) {
            // Legacy receipts have no saved printing metadata; use the current warehouse configuration.
            if ($bill['print_snapshot'] === null) $bill['print_snapshot'] = receiptPrintSnapshot($pdo, (string)$bill['warehouse_id'], (string)($bill['created_by'] ?? '0'), [], false);
            $statement = $pdo->prepare($direction === 'import'
                ? 'SELECT d.*,p.code AS product_code FROM import_bill_details d LEFT JOIN products p ON p.id=d.product_id WHERE d.import_bill_id=? ORDER BY d.id'
                : 'SELECT d.*,p.code AS product_code,b.number AS source_number,b.invoice_number AS source_invoice_number
                    FROM export_bill_details d LEFT JOIN products p ON p.id=d.product_id
                    LEFT JOIN import_bill_details s ON s.id=d.source_import_detail_id LEFT JOIN import_bill b ON b.id=s.import_bill_id
                    WHERE d.export_bill_id=? ORDER BY d.source_import_detail_id,d.id');
            $statement->execute([$id]);
            $bill['items'] = $statement->fetchAll();
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    if (!$bill) { http_response_code(404); exit('Phiếu không tồn tại.'); }
    $book = buildReceiptWorkbook($direction, $bill);
    $temporary = tempnam(sys_get_temp_dir(), 'qlvt-receipt-');
    if ($temporary === false) throw new RuntimeException('Cannot create XLSX download.');
    try {
        (new Xlsx($book))->save($temporary);
        $name = 'Phieu_' . ($direction === 'import' ? 'nhap' : 'xuat') . '_kho_' . $id . '_' . $bill[$direction === 'import' ? 'received_date' : 'issued_date'] . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($temporary));
        header('Cache-Control: no-store');
        readfile($temporary);
    } finally {
        $book->disconnectWorksheets();
        unlink($temporary);
    }
    exit;
}
