<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/stock-book-excel.php';
function assetCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
assetCheck(importSerialNumbers("\nSN-002\n",'2.000000','fixed_asset')==='["","SN-002"]','Blank serial positions must be preserved.');
foreach([["A\nA",'2.000000'],["A\na",'2.000000'],["A\nB",'1.000000'],['A','1.500000'],['A',null]] as [$serials,$qty]){
    $rejected=false;try{importSerialNumbers($serials,$qty,'fixed_asset');}catch(DomainException $e){$rejected=true;}assetCheck($rejected,'Invalid serial assignment was accepted.');
}
$file=tempnam(sys_get_temp_dir(),'asset-book-check-');
try{
    foreach(['tools','fixed-assets'] as $kind)foreach([0,1,3] as $count){
        $rows=[];for($i=0;$i<$count;$i++)$rows[]=['name'=>'=HYPERLINK("https://example.com","test")','code'=>'0007','unit'=>'Cái','description'=>'',
            'decision_number'=>'00012/QĐ','decision_date'=>'2026-10-13','decrease_number'=>'','decrease_date'=>'','decrease_reason'=>'','note'=>'','serial'=>$i===1?'':'SN-'.$i,'invoice_number'=>'0000123'];
        $data=['filters'=>['kind'=>$kind,'warehouse'=>'1','from_month'=>'2026-01','to_month'=>'2026-12'],'rows'=>$rows,
            'print'=>['unit_name'=>'Đơn vị thử','warehouse_name'=>'Kho thử','location'=>'Địa chỉ thử','keeper_name'=>'Người ghi thử','accountant_name'=>'=SUM(1,2)','director_name'=>'Giám đốc thử']];
        $book=buildStockBookWorkbook($data);(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);$book->disconnectWorksheets();
        $read=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);$sheet=$read->getActiveSheet();$total=12+max(1,$count);
        assetCheck($read->getSheetCount()===1,'Historical sheets must not leak into exports.');
        assetCheck($sheet->getCell('A1')->getValue()==='Đơn vị: Đơn vị thử' && $sheet->getCell('A2')->getValue()==='Địa chỉ: Địa chỉ thử','Unit/address must be dynamic.');
        assetCheck(str_contains($sheet->getCell('A4')->getValue(),$kind==='tools'?'CÔNG CỤ DỤNG CỤ':'TÀI SẢN CỐ ĐỊNH') && str_contains($sheet->getCell('A4')->getValue(),'2026'),'Category/year must replace the original values.');
        if($count){assetCheck($sheet->getCell('F12')->getValue()==='00012/QĐ' && $sheet->getCell('G12')->getValue()==='13/10/2026','Decision identity/date must use the correct 2024 columns.');
            assetCheck($sheet->getCell('M12')->getValue()==='0000123' && $sheet->getCell('B12')->getDataType()==='s' && $sheet->getCell('C12')->getValue()==='0007','Invoice/code leading zeros and formula-looking names must remain safe text.');
        }else assetCheck($sheet->getCell('B12')->getValue()===null && $sheet->getCell('L12')->getValue()===null,'Empty exports must contain no dummy asset.');
        if($count===3)assetCheck($sheet->getCell('L12')->getValue()==='SN-0' && ($sheet->getCell('L13')->getValue() ?? '')==='' && $sheet->getCell('L14')->getValue()==='SN-2','Every piece must have its corresponding serial or blank.');
        assetCheck($sheet->getCell('B'.($total+9))->getValue()==='Người ghi thử' && $sheet->getCell('E'.($total+9))->getDataType()==='s','Configured signatures must move and remain safe text.');
        assetCheck($sheet->getPageSetup()->getPrintArea()==='A1:N'.($total+9),'Serial/invoice columns and shifted signatures must be printed.');
        $template=\PhpOffice\PhpSpreadsheet\IOFactory::load(dirname(__DIR__).'/resources/templates/products/'.($kind==='tools'?'mau_cong_cu_dung_cu':'mau_tai_san_co_dinh').'.xlsx')->getActiveSheet();
        foreach(range('A','N') as $col)assetCheck($template->getColumnDimension($col)->getWidth()===$sheet->getColumnDimension($col)->getWidth(),'2024 column widths changed.');
        assetCheck($template->getStyle('F9')->getBorders()->exportArray()===$sheet->getStyle('F9')->getBorders()->exportArray(),'Header formatting changed.');
        $read->disconnectWorksheets();
    }
    echo "PASS: serial validation/positions, both clean 2024 templates, titles, decision dates, separate pieces, leading zeros, safe text, dynamic metadata, formatting and printing.\n";
}finally{unlink($file);}
