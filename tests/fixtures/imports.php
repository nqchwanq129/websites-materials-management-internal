<?php
require_once dirname(__DIR__,2).'/app/imports.php';
$managementData=['warehouses'=>['1'=>'Kho trung tâm','2'=>'Kho Hải Phòng'],'imports'=>[]];
for ($i=1;$i<=12;$i++) {
    $items=[];
    for ($j=1;$j<=($i===1?14:1);$j++) $items[]=['id'=>(string)$j,'tracking_mode'=>'quantity','received_quantity'=>'1.500000','unit_price'=>'12345678901234.123456','amount_before_tax'=>'100.000000','tax_amount'=>'10.000000','product_name'=>'Thiết bị <script> '.$j,'product_code'=>'HH-'.$j,'unit'=>'Chiếc',
        'received_quantity_display'=>'1,5','unit_price_display'=>'12.345.678.901.234,123456','amount_before_tax_display'=>'100','tax_amount_display'=>'10','tax_code'=>'10%', 'decision_status'=>'pending','decision_number'=>null,'note'=>'Ghi chú dòng hàng'];
    $managementData['imports'][]=['id'=>(string)$i,'number'=>'PN-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'received_date'=>'2026-10-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),
        'warehouse_id'=>$i%2?'1':'2','warehouse_name'=>$i%2?'Kho trung tâm':'Kho Hải Phòng','receipt_type'=>array_keys(importTypes())[($i-1)%5],
        'status'=>['draft','confirmed','cancelled'][($i-1)%3],'supplier_name'=>'Nhà cung cấp <script> '.$i,'supplier_tax_code'=>'0123456789','sender_name'=>'Nguyễn Văn A',
        'invoice_number'=>'HD-'.$i,'invoice_series'=>'C26T','invoice_date'=>'2026-10-01','creator_name'=>'Quản trị viên','cancel_reason'=>$i%3===0?'Sai thông tin':'',
        'total_before_tax_display'=>'100 ₫','total_after_tax_display'=>'110 ₫','items'=>$items];
}
