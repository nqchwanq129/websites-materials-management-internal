<?php
require_once dirname(__DIR__,2).'/app/exports.php';
$managementData=['warehouses'=>['1'=>'Kho trung tâm','2'=>'Kho Hải Phòng'],'exports'=>[]];
for ($i=1;$i<=12;$i++) {
    $items=[]; $type=array_keys(exportTypes())[($i-1)%4];
    for ($j=1;$j<=($i===1?14:1);$j++) $items[]=['product_name'=>'Thiết bị <script> '.$j,'product_code'=>'HH-'.$j,'unit'=>'Chiếc',
        'issued_quantity_display'=>'1,5','unit_price_display'=>'12.345.678.901.234,123456','amount_before_tax_display'=>'100',
        'source_number'=>'PN-'.$j,'source_date'=>'2026-09-01','decision_status'=>'pending','decision_number'=>null,'note'=>'Ghi chú dòng hàng'];
    $managementData['exports'][]=['id'=>(string)$i,'version'=>str_repeat('a',64),'can_confirm'=>true,'number'=>'PX-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'issued_date'=>'2026-10-'.str_pad((string)$i,2,'0',STR_PAD_LEFT),
        'warehouse_id'=>$i%2?'1':'2','warehouse_name'=>$i%2?'Kho trung tâm':'Kho Hải Phòng','issue_type'=>$type,
        'destination_warehouse_name'=>$type==='transfer'?'Kho trung tâm':null,'receiver_name'=>'Người nhận <script> '.$i,'reason'=>'Cấp phát phục vụ công việc',
        'status'=>['draft','confirmed','cancelled'][($i-1)%3],'creator_name'=>'Quản trị viên','cancel_reason'=>$i%3===0?'Sai thông tin':'',
        'total_before_tax_display'=>'100 ₫','items'=>$items,'attachments'=>[['id'=>'1','original_name'=>'Chứng từ <script>.pdf']],
        'transfers'=>$type==='transfer'?[['number'=>'DC-PX-'.$i,'status'=>'confirmed']]:[]];
}
