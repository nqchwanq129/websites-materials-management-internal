<?php
require_once dirname(__DIR__,2).'/app/products.php';
$dashboardData=['products'=>1248,'categories'=>['consumable'=>624,'spare_part'=>312,'ccdc'=>187,'fixed_asset'=>100,'other'=>25],
    'counts'=>['import'=>['total'=>48,'confirmed'=>36],'export'=>['total'=>32,'confirmed'=>24]],'active_warehouses'=>3,
    'warehouses'=>['1'=>'Kho trung tâm','2'=>'Kho Hải Phòng','3'=>'Kho Đà Nẵng'],'updated_at'=>'10/10/2026 10:00','admin'=>true,'months'=>[],'receipts'=>[]];
foreach ([['05',28,18],['06',35,22],['07',30,26],['08',42,24],['09',38,30],['10',48,32]] as [$month,$imports,$exports]) $dashboardData['months']['2026-'.$month]=['import'=>$imports,'export'=>$exports];
foreach ([['48','PN-2026-0048','import','Thiết bị thông tin <script>','1','09','128500000','confirmed'],['32','PX-2026-0032','export','Cấp phát vật tư bảo dưỡng','2','09','24800000','draft'],
    ['47','PN-2026-0047','import','Phụ tùng thay thế định kỳ','3','08','46200000','confirmed'],['31','PX-2026-0031','export','Điều chuyển thiết bị giữa kho','1','08','18600000','confirmed'],['46','PN-2026-0046','import','Mua sắm công cụ, dụng cụ','2','07','12400000','cancelled']] as [$id,$number,$direction,$description,$warehouse,$day,$amount,$status]) {
    $dashboardData['receipts'][]=['id'=>$id,'number'=>$number,'direction'=>$direction,'description'=>$description,'warehouse_id'=>$warehouse,'warehouse_name'=>$dashboardData['warehouses'][$warehouse],'date'=>'2026-10-'.$day,'amount'=>$amount,'status'=>$status];
}
if(in_array('--empty',$argv,true)) {
    $dashboardData['products']=0; $dashboardData['categories']=array_fill_keys(array_keys(productCategories()),0); $dashboardData['receipts']=[];
    $dashboardData['warehouses']=[]; $dashboardData['active_warehouses']=0; $dashboardData['counts']=['import'=>['total'=>0,'confirmed'=>0],'export'=>['total'=>0,'confirmed'=>0]];
    foreach($dashboardData['months'] as &$values) $values=['import'=>0,'export'=>0]; unset($values);
    $sampleRows['dashboard'][1]=0;
}
