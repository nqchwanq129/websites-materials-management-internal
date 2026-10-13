<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/management.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/receipt-print.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

function stockBookFilters(array $input): array
{
    $kind = $input['kind'] ?? '';
    if (!is_string($kind) || !in_array($kind, ['materials','spare-parts','tools','fixed-assets'], true)) throw new DomainException('Loại sổ theo dõi không hợp lệ.');
    $month = (new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m');
    $result = ['kind'=>$kind, 'warehouse'=>managementId($input['warehouse'] ?? null)];
    foreach (['from_month','to_month'] as $key) {
        $value = $input[$key] ?? $month;
        if (!is_string($value) || !preg_match('/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/D',$value)) throw new DomainException('Chọn tháng hợp lệ từ năm 2000 đến 2100.');
        $result[$key] = $value;
    }
    if ($result['from_month'] > $result['to_month']) throw new DomainException('Tháng bắt đầu phải trước hoặc bằng tháng kết thúc.');
    return $result;
}
function loadStockBook(PDO $pdo, array $input, string $userId): array
{
    $filters = stockBookFilters($input);
    $pdo->beginTransaction();
    try {
        $data = loadReportData($pdo,$userId);
        if (!isset($data['warehouses'][$filters['warehouse']])) throw new DomainException('Kho không tồn tại hoặc bạn chưa có quyền truy cập.');
        if (in_array($filters['kind'],['tools','fixed-assets'],true)) $rows=loadAssetBookRows($pdo,$filters);
        else {
            $reportFilters = reportFilters(['kind'=>$filters['kind']==='materials'?'materials':'parts', 'warehouse'=>$filters['warehouse'],
                'from'=>$filters['from_month'].'-01', 'to'=>(new DateTimeImmutable($filters['to_month'].'-01'))->modify('last day of this month')->format('Y-m-d')],$data);
            $rows = buildReport($data,$reportFilters)['rows'];
        }
        $print = receiptPrintSnapshot($pdo,$filters['warehouse'],$userId,[],false);
        $pdo->commit();
        return ['filters'=>$filters,'rows'=>$rows,'print'=>$print];
    } catch(Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function buildStockBookWorkbook(array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $filters = stockBookFilters($data['filters']);
    if (in_array($filters['kind'],['tools','fixed-assets'],true)) return buildAssetBookWorkbook($data);
    $parts = $filters['kind']==='spare-parts';
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load(dirname(__DIR__).'/resources/templates/products/'.($parts?'mau_phu_tung_thay_the':'mau_vat_tu').'.xlsx');
    $sheet = $book->getActiveSheet();
    $print = $data['print']; $rows = $data['rows'];
    $unit = $print['unit_name'] ?: $print['warehouse_name'];
    $text = static function(string $cell, string $value) use ($sheet): void { $sheet->setCellValueExplicit($cell,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); };
    $text('A2','Đơn vị: '.$unit);
    $text('A3','Địa chỉ: '.($print['location'] ?? ''));
    $from = new DateTimeImmutable($filters['from_month'].'-01'); $to = new DateTimeImmutable($filters['to_month'].'-01');
    $period = $from==$to ? 'Tháng '.$from->format('n').' năm '.$from->format('Y') : 'Từ tháng '.$from->format('n/Y').' đến tháng '.$to->format('n/Y');
    $text('A5', 'SỔ THEO DÕI '.($parts?'PHỤ TÙNG THAY THẾ':'VẬT TƯ')."\n(Phục vụ hoạt động cung cấp dịch vụ sự nghiệp công TTDH)\n".$period);
    $count = max(1,count($rows));
    if ($count > 1) $sheet->insertNewRowBefore(9,$count-1);
    $fields = ['C'=>'opening','D'=>'incoming','E'=>'outgoing','F'=>'closing'];
    $totals = array_fill_keys(array_values($fields),'0');
    foreach ($rows as $index=>$item) {
        $row = 8+$index;
        $sheet->setCellValue('A'.$row,$index+1);
        $text('B'.$row,$item['name']."\n".$item['code'].' · '.$item['unit']);
        $sheet->getStyle('B'.$row)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(-1);
        foreach ($fields as $column=>$field) {
            $text($column.$row, reportNumber($item[$field],6,$item['tracking_mode']));
            $totals[$field] = $totals[$field]===null || $item[$field]===null ? null : bcadd($totals[$field],$item[$field],6);
        }
    }
    $totalRow = 8+$count;
    $mixedUnits = count(array_unique(array_column($rows,'unit'))) > 1;
    $text('A'.$totalRow,$mixedUnits?'Tổng cộng (khác đơn vị)':'Tổng cộng');
    $sheet->setCellValue('B'.$totalRow,null);
    $sheet->mergeCells('A'.$totalRow.':B'.$totalRow);
    foreach ($fields as $column=>$field) $text($column.$totalRow,$mixedUnits?'—':reportNumber($totals[$field]));
    $today = new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh'));
    $text('D'.($totalRow+2),'Ngày '.$today->format('d').' tháng '.$today->format('m').' năm '.$today->format('Y'));
    $text('E'.($totalRow+3),"Giám đốc\n".$unit."\n(Ký, họ tên, đóng dấu)");
    foreach (['A'=>'keeper_name','C'=>'accountant_name','E'=>'director_name'] as $column=>$field) $text($column.($totalRow+10),$print[$field] ?? '');
    return $book;
}
function downloadStockBookExcel(PDO $pdo, array $input, string $userId): never
{
    $data = loadStockBook($pdo,$input,$userId);
    $book = buildStockBookWorkbook($data);
    $file = tempnam(sys_get_temp_dir(),'qlvt-stock-book-');
    if ($file===false) { $book->disconnectWorksheets(); throw new RuntimeException('Cannot create Excel download.'); }
    try {
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
        $f=$data['filters'];
        $names=['materials'=>'vat_tu','spare-parts'=>'phu_tung_thay_the','tools'=>'cong_cu_dung_cu','fixed-assets'=>'tai_san_co_dinh'];
        $name='So_'.$names[$f['kind']].'_kho_'.$f['warehouse'].'_'.$f['from_month'].'_'.$f['to_month'].'.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($file)); header('Cache-Control: no-store');
        readfile($file);
    } finally { $book->disconnectWorksheets(); unlink($file); }
    exit;
}

function loadAssetBookRows(PDO $pdo,array $filters): array
{
    $stmt=$pdo->prepare("SELECT d.*,p.code,p.category,b.invoice_number,b.received_date,b.number AS receipt_number,
        q.number AS decision_number,q.issued_date AS decision_date
        FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id JOIN products p ON p.id=d.product_id LEFT JOIN decisions q ON q.id=d.decision_id
        WHERE b.status='confirmed' AND b.warehouse_id=? AND p.category=? ORDER BY b.received_date,b.id,d.id");
    $stmt->execute([$filters['warehouse'],$filters['kind']==='tools'?'ccdc':'fixed_asset']);
    $lots=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT e.source_import_detail_id,e.issued_quantity,q.number,q.issued_date,b.reason,b.number AS receipt_number
        FROM export_bill_details e JOIN export_bill b ON b.id=e.export_bill_id JOIN decisions q ON q.id=e.decision_id
        WHERE b.status='confirmed' AND b.warehouse_id=? AND q.decision_type IN ('decrease','transfer') AND q.issued_date<=? ORDER BY b.issued_date,b.id,e.id");
    $last=(new DateTimeImmutable($filters['to_month'].'-01'))->modify('last day of this month')->format('Y-m-d');
    $stmt->execute([$filters['warehouse'],$last]);$reductions=[];
    foreach($stmt as $reduction)$reductions[(string)$reduction['source_import_detail_id']][]=$reduction;
    $rows=[];
    foreach($lots as $lot){
        $date=$lot['decision_date'] ?? $lot['received_date'];
        if($date<$filters['from_month'].'-01'||$date>$last)continue;
        $quantity=$lot['received_quantity'];
        if($quantity!==null && bccomp($quantity,bcadd($quantity,'0',0),6)!==0)throw new DomainException('CCDC/TSCĐ cần số lượng nguyên để tách từng chiếc. Kiểm tra phiếu '.$lot['receipt_number'].'.');
        // ponytail: PhpSpreadsheet keeps cells in memory; cap expanded rows, use a streaming writer for larger registers.
        if($quantity!==null && bccomp($quantity,(string)(2000-count($rows)),6)>0)throw new DomainException('Sổ vượt 2000 dòng. Chọn khoảng tháng nhỏ hơn.');
        $count=$quantity===null?1:(int)$quantity;
        if(count($rows)+$count>2000)throw new DomainException('Sổ vượt 2000 dòng. Chọn khoảng tháng nhỏ hơn.');
        $serials=$lot['serial_numbers']===null?[]:json_decode($lot['serial_numbers'],true,512,JSON_THROW_ON_ERROR);
        $events=$reductions[(string)$lot['id']] ?? [];
        $full=count($events)===1 && $quantity!==null && bccomp($events[0]['issued_quantity'],$quantity,6)===0;
        $note=$lot['note'] ?? '';
        if($quantity===null)$note.=($note?"\n":'').'Chưa có số lượng để xác định số chiếc.';
        if($events && !$full)$note.=($note?"\n":'').'Ghi giảm theo lô; chưa xác định serial từng chiếc. Phiếu: '.implode(', ',array_column($events,'receipt_number'));
        for($i=0;$i<$count;$i++)$rows[]=['name'=>$lot['product_name'],'code'=>$lot['code'],'unit'=>$lot['unit'] ?? '',
            'description'=>'',
            'decision_number'=>$lot['decision_number'] ?? '', 'decision_date'=>$lot['decision_date'] ?? '',
            'decrease_number'=>$full?$events[0]['number']:'','decrease_date'=>$full?$events[0]['issued_date']:'','decrease_reason'=>$full?$events[0]['reason']:'',
            'note'=>$note,'serial'=>$serials[$i] ?? '','invoice_number'=>$lot['invoice_number'] ?? ''];
    }
    return $rows;
}
function buildAssetBookWorkbook(array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet
{
    $f=stockBookFilters($data['filters']);$asset=$f['kind']==='fixed-assets';
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load(dirname(__DIR__).'/resources/templates/products/'.($asset?'mau_tai_san_co_dinh':'mau_cong_cu_dung_cu').'.xlsx');
    $sheet=$book->getActiveSheet();$rows=$data['rows'];$print=$data['print'];$count=max(1,count($rows));
    if($count>1)$sheet->insertNewRowBefore(13,$count-1);
    $text=static function(string $cell,string $value)use($sheet):void{$sheet->setCellValueExplicit($cell,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);};
    $unit=$print['unit_name'] ?: $print['warehouse_name'];
    $text('A1','Đơn vị: '.$unit);$text('A2','Địa chỉ: '.($print['location'] ?? ''));
    $from=new DateTimeImmutable($f['from_month'].'-01');$to=new DateTimeImmutable($f['to_month'].'-01');
    $period=$from->format('Y')===$to->format('Y')?'Năm: '.$from->format('Y'):'Từ tháng '.$from->format('n/Y').' đến tháng '.$to->format('n/Y');
    if($from->format('Y')===$to->format('Y') && ($from->format('m')!=='01'||$to->format('m')!=='12'))$period.=' · Tháng '.$from->format('n').'–'.$to->format('n');
    $text('A4','SỔ THEO DÕI '.($asset?'TÀI SẢN CỐ ĐỊNH':'CÔNG CỤ DỤNG CỤ')."\n".$period);
    $fields=['B'=>'name','C'=>'code','D'=>'description','E'=>'unit','F'=>'decision_number','G'=>'decision_date','H'=>'decrease_number',
        'I'=>'decrease_date','J'=>'decrease_reason','K'=>'note','L'=>'serial','M'=>'invoice_number'];
    foreach($rows as $index=>$item){$row=12+$index;$sheet->setCellValue('A'.$row,$index+1);
        foreach($fields as $column=>$field){$value=$item[$field] ?? '';if(in_array($field,['decision_date','decrease_date'],true) && $value!=='')$value=(new DateTimeImmutable($value))->format('d/m/Y');$text($column.$row,$value);}
        $text('N'.$row,$print['warehouse_name']);
        $sheet->getStyle('B'.$row.':N'.$row)->getAlignment()->setWrapText(true);$sheet->getRowDimension($row)->setRowHeight(-1);
    }
    $total=12+$count;$text('A'.$total,'Tổng cộng: '.count($rows).' dòng');
    $today=new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh'));
    $text('H'.($total+2),'Ngày '.$today->format('d').' tháng '.$today->format('m').' năm '.$today->format('Y'));
    $text('H'.($total+3),"Giám đốc\n".$unit."\n(Ký, họ tên, đóng dấu)");
    foreach(['B'=>'keeper_name','E'=>'accountant_name','H'=>'director_name'] as $column=>$key)$text($column.($total+9),$print[$key] ?? '');
    return $book;
}
