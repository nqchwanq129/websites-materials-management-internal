<?php
require_once dirname(__DIR__,2).'/app/exports.php';
$managementData=['warehouses'=>['1'=>'Kho trung tâm','2'=>'Kho Hải Phòng','3'=>'Kho đã ngừng'],'warehouseStatuses'=>['1'=>'active','2'=>'active','3'=>'inactive'],'sources'=>[]];
foreach ([['1','1','2026-09-01'],['2','2','2026-09-01'],['3','1','2099-12-31']] as [$id,$warehouse,$date]) {
    $managementData['sources'][]=['id'=>$id,'warehouse_id'=>$warehouse,'warehouse_name'=>$managementData['warehouses'][$warehouse],
        'product_code'=>'HH-'.$id,'product_name'=>'Thiết bị <script> '.$id,'receipt_number'=>'PN-'.$id,'received_date'=>$date,'unit'=>'Chiếc',
        'received_quantity'=>'3.000000','remaining_quantity'=>'3.000000','remaining_display'=>'3','amount_before_tax'=>'10.000000','remaining_amount'=>'10.000000',
        'unit_price'=>'3.333333','version'=>str_repeat('a',64)];
}
