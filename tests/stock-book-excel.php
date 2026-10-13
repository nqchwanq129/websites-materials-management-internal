<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/stock-book-excel.php';
function stockCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['from_month'=>'2026-13','to_month'=>'2026-01','kind'=>'products','warehouse'=>'0'] as $key=>$value) {
    $rejected=false;
    try { stockBookFilters(array_replace(['kind'=>'materials','warehouse'=>'1','from_month'=>'2026-09','to_month'=>'2026-10'],[$key=>$value])); }
    catch(DomainException $error) { $rejected=true; }
    stockCheck($rejected,'Invalid period, category or warehouse accepted.');
}
$file=tempnam(sys_get_temp_dir(),'stock-book-check-');
try {
    foreach (['materials','spare-parts'] as $kind) {
        $template=\PhpOffice\PhpSpreadsheet\IOFactory::load(dirname(__DIR__).'/resources/templates/products/'.($kind==='materials'?'mau_vat_tu':'mau_phu_tung_thay_the').'.xlsx');
        $original=$template->getActiveSheet();
        foreach ([0,1,4] as $count) {
            $rows=[];
            for ($i=0;$i<$count;$i++) $rows[]=['name'=>'=HYPERLINK("https://example.com","formula")','code'=>'HH-'.$i,'unit'=>'Cái','tracking_mode'=>'quantity',
                'opening'=>'2.123456','incoming'=>'5.000001','outgoing'=>'1.000002','closing'=>'6.123455'];
            $data=['filters'=>['kind'=>$kind,'warehouse'=>'1','from_month'=>'2025-12','to_month'=>'2026-01'],'rows'=>$rows,
                'print'=>['unit_name'=>'Đơn vị thử','warehouse_name'=>'Kho thử','location'=>'Địa chỉ thử','keeper_name'=>'Người ghi sổ thử','accountant_name'=>'=SUM(1,2)','director_name'=>'Giám đốc thử']];
            $book=buildStockBookWorkbook($data);(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);$book->disconnectWorksheets();
            $read=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);$sheet=$read->getActiveSheet();$total=8+max(1,$count);
            stockCheck($sheet->getCell('A2')->getValue()==='Đơn vị: Đơn vị thử' && $sheet->getCell('A3')->getValue()==='Địa chỉ: Địa chỉ thử','Unit/address not dynamic.');
            stockCheck(str_contains($sheet->getCell('A5')->getValue(),$kind==='materials'?'SỔ THEO DÕI VẬT TƯ':'SỔ THEO DÕI PHỤ TÙNG THAY THẾ') && str_contains($sheet->getCell('A5')->getValue(),'12/2025 đến tháng 1/2026'),'Wrong title or year-spanning period.');
            stockCheck($sheet->getCell('E'.($total+3))->getValue()==="Giám đốc\nĐơn vị thử\n(Ký, họ tên, đóng dấu)",'Fixed director unit leaked.');
            stockCheck($sheet->getCell('A'.($total+10))->getValue()==='Người ghi sổ thử' && $sheet->getCell('C'.($total+10))->getDataType()==='s','Signer names or safe text changed.');
            stockCheck($sheet->getCell('E'.$total)->getValue()===reportNumber(bcmul('1.000002',(string)$count,6)) && $sheet->getCell('F'.$total)->getValue()===reportNumber(bcmul('6.123455',(string)$count,6)),'Export and closing columns swapped or totals rounded.');
            if ($count) stockCheck($sheet->getCell('B8')->getDataType()==='s' && str_starts_with($sheet->getCell('B8')->getValue(),'=HYPERLINK') && $sheet->getCell('C8')->getValue()==='2,123456','Names must remain safe text and six-decimal quantities exact.');
            else stockCheck($sheet->getCell('B8')->getValue()===null,'Empty book retained sample goods.');
            foreach (range('A','F') as $column) stockCheck($sheet->getColumnDimension($column)->getWidth()===$original->getColumnDimension($column)->getWidth(),'Original column widths changed.');
            stockCheck($sheet->getStyle('B6')->getFont()->exportArray()===$original->getStyle('B6')->getFont()->exportArray() && $sheet->getStyle('E7')->getBorders()->exportArray()===$original->getStyle('E7')->getBorders()->exportArray(),'Template formatting changed.');
            stockCheck($sheet->getPageSetup()->getPrintArea()==='A1:F'.($total+10) && $sheet->getPageSetup()->getOrientation()===$original->getPageSetup()->getOrientation() && $sheet->getPageMargins()==$original->getPageMargins(),'Print area or settings changed.');
            stockCheck(in_array('A'.($total+10).':B'.($total+10),$sheet->getMergeCells(),true),'Signature merges did not move with item rows.');
            $read->disconnectWorksheets();
        }
        $data['rows'][0]['unit']='Lít';$book=buildStockBookWorkbook($data);
        stockCheck($book->getActiveSheet()->getCell('C12')->getValue()==='—','Different units must not be added together.');$book->disconnectWorksheets();
        $data['rows'][0]['unit']='Cái';$data['rows'][0]['opening']=null;$book=buildStockBookWorkbook($data);
        stockCheck($book->getActiveSheet()->getCell('C8')->getValue()==='Chưa đủ dữ liệu' && $book->getActiveSheet()->getCell('C12')->getValue()==='Chưa đủ dữ liệu','Unknown quantities must not become zero.');$book->disconnectWorksheets();
        $data['rows']=[];$data['print']=array_fill_keys(array_keys($data['print']),'');$book=buildStockBookWorkbook($data);
        stockCheck($book->getActiveSheet()->getCell('A19')->getValue()==='' && $book->getActiveSheet()->getCell('E19')->getValue()==='' && $book->getActiveSheet()->getCell('A3')->getValue()==='Địa chỉ: ','Unconfigured books leaked fixed people or address.');
        $book->disconnectWorksheets();$template->disconnectWorksheets();
    }
    echo "PASS: both templates, month validation, cross-year periods, exact quantities, correct columns, dynamic metadata, safe text, mixed units, empty books, formatting and shifted printing/signatures.\n";
} finally { unlink($file); }
