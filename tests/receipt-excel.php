<?php
declare(strict_types=1);
// php tests/receipt-excel.php (Composer dependencies, ZIP and BCMath required; no database writes).
require dirname(__DIR__) . '/app/products.php';
require dirname(__DIR__) . '/app/receipt-excel.php';
require dirname(__DIR__) . '/vendor/autoload.php';
function checkReceipt(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
checkReceipt(receiptMoneyWords('0.000000') === 'Không đồng ./.', 'Zero amount in words.');
checkReceipt(receiptMoneyWords('1000000001.000001') === 'Một tỷ không trăm linh một phẩy không không không không không một đồng ./.', 'Billions and six decimal places in words.');
checkReceipt(receiptMoneyWords('-125.150000') === 'Âm một trăm hai mươi lăm phẩy một năm đồng ./.', 'Sign and trailing decimal zeros in words.');
checkReceipt(str_contains(receiptMoneyWords('99999999999999.123456'), 'nghìn tỷ'), 'Largest database amount must have valid words.');
$temporary = tempnam(sys_get_temp_dir(), 'receipt-check-');
try {
    foreach (['import', 'export'] as $direction) {
        $first = $direction === 'import' ? 9 : 10;
        $template = \PhpOffice\PhpSpreadsheet\IOFactory::load(dirname(__DIR__) . '/resources/templates/receipts/mau_phieu_' . ($direction === 'import' ? 'nhap' : 'xuat') . '_kho.xlsx');
        $original = $template->getActiveSheet();
        foreach ([0, 1, 4] as $count) {
            $items = array_fill(0, $count, ['product_name'=>'=HYPERLINK("https://example.com","formula")', 'product_code'=>'00123',
                'unit'=>'Cái', 'received_quantity'=>null, 'issued_quantity'=>'1.123456',
                'unit_price'=>'12345678901234.123456', 'amount_before_tax'=>'6.666667', 'note'=>'+SUM(1,2)',
                'source_number'=>'PN-001', 'source_invoice_number'=>'0000123']);
            $bill = ['number'=>'PX/PN-001','received_date'=>'2026-10-12','issued_date'=>'2026-10-11', 'sender_name'=>'Người giao',
                'receiver_name'=>'Người nhận', 'reason'=>'Cấp phát', 'warehouse_name'=>'Kho thử', 'invoice_number'=>'0000123',
                'creator_name'=>'Tên mới của người lập', 'status'=>'draft', 'items'=>$items, 'total_before_tax'=>bcmul('6.666667',(string)$count,6),
                'print_snapshot'=>json_encode(['unit_name'=>'Đơn vị thử','department_name'=>'Bộ phận thử','department_address'=>'Địa chỉ thử',
                    'location'=>'Ngăn A','warehouse_name'=>'Tên kho lúc lập','creator_name'=>'Người lập','keeper_name'=>'Thủ kho thử',
                    'accountant_name'=>'=SUM(1,2)','director_name'=>'Giám đốc thử'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            $book = buildReceiptWorkbook($direction, $bill);
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($temporary);
            $book->disconnectWorksheets();
            $read = \PhpOffice\PhpSpreadsheet\IOFactory::load($temporary);
            $sheet = $read->getActiveSheet();
            $total = $first + max(1, $count);
            checkReceipt($sheet->getCell('I'.$total)->getValue() === productQuantity($bill['total_before_tax']), 'Exact saved line amounts must be totaled rather than recomputed from price.');
            checkReceipt($sheet->getCell('B'.$total)->getValue() === 'Cộng', 'Original total row must move below all items.');
            checkReceipt($sheet->getCell('C'.($total+13))->getValue() === ($direction==='import'?'Người giao':'Người nhận'), 'Person signature row must move with template.');
            checkReceipt($sheet->getCell('A'.($total+13))->getValue() === 'Người lập', 'Creator signature must use the actual creator.');
            checkReceipt($sheet->getCell('A1')->getValue() === "Đơn vị:\nĐơn vị thử\nBộ phận:\nBộ phận thử" && $sheet->getCell('F4')->getValue() === 'Địa chỉ bộ phận: Địa chỉ thử', 'Department metadata must replace the fixed template text.');
            checkReceipt($sheet->getCell('F6')->getValue() === 'Địa điểm: Ngăn A' && str_ends_with($sheet->getCell('A6')->getValue(),'Tên kho lúc lập'), 'Warehouse location/name must use the saved snapshot.');
            foreach (['D'=>'Thủ kho thử','F'=>'=SUM(1,2)','H'=>'Giám đốc thử'] as $column=>$name) checkReceipt($sheet->getCell($column.($total+13))->getValue() === $name && $sheet->getCell($column.($total+13))->getDataType() === 's', 'Signer names must be saved safe text and move with the table.');
            checkReceipt(str_contains($sheet->getCell('C3')->getValue(),'BẢN NHÁP'), 'Draft receipts must be labelled.');
            checkReceipt(str_contains($sheet->getCell('A'.($total+3))->getValue(),$direction==='import'||$count?'0000123':$bill['number']), 'Source invoice numbers must retain leading zeros; empty exports must fall back to their receipt number.');
            checkReceipt(str_contains($sheet->getCell('C1')->getValue(),$direction==='import'?'12 tháng 10':'11 tháng 10'), 'Receipt date must be used, not download date.');
            foreach (['E1','B7','J7','K7'] as $cell) {
                checkReceipt($sheet->getCell($cell)->getValue() === $original->getCell($cell)->getValue(), 'Template heading was changed: '.$cell);
                checkReceipt($sheet->getStyle($cell)->getFont()->getName() === $original->getStyle($cell)->getFont()->getName(), 'Template font was changed.');
                checkReceipt($sheet->getStyle($cell)->getBorders()->exportArray() === $original->getStyle($cell)->getBorders()->exportArray(), 'Template borders were changed.');
            }
            foreach (range('A','K') as $column) checkReceipt($sheet->getColumnDimension($column)->getWidth() === $original->getColumnDimension($column)->getWidth(), 'Template column width was changed.');
            checkReceipt($sheet->getPageSetup()->getOrientation() === $original->getPageSetup()->getOrientation()
                && $sheet->getPageSetup()->getScale() === $original->getPageSetup()->getScale()
                && $sheet->getPageMargins() == $original->getPageMargins(), 'Template print settings were changed.');
            $expectedArea = preg_replace_callback('/([A-Z]+)([0-9]+)/', static fn($m) => $m[1].((int)$m[2]+((int)$m[2]>$first?max(0,$count-1):0)), $original->getPageSetup()->getPrintArea());
            checkReceipt($sheet->getPageSetup()->getPrintArea() === $expectedArea, 'Print area must extend to include shifted signatures.');
            if ($count) {
                checkReceipt($sheet->getCell('B'.$first)->getDataType() === 's' && str_starts_with($sheet->getCell('B'.$first)->getValue(),'=HYPERLINK'), 'Product names must stay text rather than executable formulas.');
                checkReceipt($sheet->getCell('H'.$first)->getValue() === '12.345.678.901.234,123456', 'Excel must retain full DECIMAL precision.');
                checkReceipt((string)$sheet->getCell('F'.$first)->getValue() === ($direction==='import'?'':'1,123456'), 'Unknown quantities must stay blank; known quantities must retain precision.');
                checkReceipt($sheet->getCell('D'.$first)->getValue() === '00123' && (string)$sheet->getCell('J'.$first)->getValue() === '', 'Product code and unavailable serial columns.');
                checkReceipt($sheet->getCell('K'.$first)->getDataType() === 's', 'Notes must stay text.');
                foreach (range($first,$first+$count-1) as $row) {
                    checkReceipt(in_array('B'.$row.':C'.$row,$sheet->getMergeCells(),true), 'Every inserted item row must keep the name merge.');
                    checkReceipt($sheet->getStyle('I'.$row)->getBorders()->exportArray() === $original->getStyle('I'.$first)->getBorders()->exportArray(), 'Inserted rows must retain table borders.');
                }
            } else checkReceipt((string)$sheet->getCell('B'.$first)->getValue() === '', 'Empty receipts must not display sample goods.');
            $read->disconnectWorksheets();
        }
        $bill['status'] = 'cancelled'; $bill['total_before_tax'] = '999';
        $book = buildReceiptWorkbook($direction,$bill);
        checkReceipt(str_contains($book->getActiveSheet()->getCell('C3')->getValue(),'ĐÃ HỦY') && str_contains($book->getActiveSheet()->getCell('C3')->getValue(),'ĐỐI SOÁT'), 'Cancellation and header discrepancies must remain visible.');
        $book->disconnectWorksheets(); $template->disconnectWorksheets();
    }
    $bill['items'][1]['source_invoice_number'] = ''; $bill['items'][1]['source_number'] = 'PN-OPENING';
    $book = buildReceiptWorkbook('export',$bill);
    $documents = $book->getActiveSheet()->getCell('A'.(10+count($bill['items'])+3))->getValue();
    checkReceipt(str_contains($documents,'Hoá đơn số 0000123') && str_contains($documents,'Phiếu số PN-OPENING'), 'Mixed source invoices and receipts must all remain listed.');
    $book->disconnectWorksheets();
    $bill['print_snapshot'] = null;
    $book = buildReceiptWorkbook('export', $bill);
    checkReceipt($book->getActiveSheet()->getCell('D'.(10+count($bill['items'])+13))->getValue() === '' && $book->getActiveSheet()->getCell('F4')->getValue() === 'Địa chỉ bộ phận: ', 'Unconfigured receipts must never retain sample names or addresses.');
    $book->disconnectWorksheets();
    $bill['issued_date']='2026-02-30';
    try { buildReceiptWorkbook('export',$bill); throw new RuntimeException('Invalid stored dates must not silently change on Excel.'); }
    catch (DomainException $error) { /* Expected validation failure. */ }
    echo "PASS: both original receipt templates, empty/single/multiple lines, styles/merges/print settings, exact DECIMAL amounts, historical dates, signatures, invoice numbers, safe text and status labels.\n";
} finally { unlink($temporary); }
