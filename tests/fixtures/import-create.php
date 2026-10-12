<?php
require_once dirname(__DIR__,2).'/app/products.php';
require_once dirname(__DIR__,2).'/app/imports.php';
$managementData=['warehouses'=>['1'=>'Kho trung tâm','2'=>'Kho đã ngừng'],'warehouseStatuses'=>['1'=>'active','2'=>'inactive'],
    'products'=>[['id'=>'1','version'=>'2026-10-10 10:00:00.000000','code'=>'HH-001','name'=>'Thiết bị <script>','category'=>'spare_part','unit'=>'Chiếc','tracking_mode'=>'quantity']],
    'suppliers'=>[['id'=>'1','name'=>'Bên bán <script>','tax_code'=>'1234567890','address'=>'Địa chỉ','phone'=>'0123456789','email'=>'seller@example.test']]];
