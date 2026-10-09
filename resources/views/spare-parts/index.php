<?php
$activePage = 'spare-parts'; $pageTitle = 'Phụ tùng thay thế'; $selectedCategory = 'spare_part';
$catalogueDescription = 'Tra cứu quy cách phụ tùng, tồn kho và lần nhập gần nhất để phục vụ thay thế.';
$catalogueColumns = ['specifications' => 'Quy cách phụ tùng', 'unit' => 'Đơn vị', 'closing' => 'Phụ tùng còn tồn', 'last_received_date' => 'Nhập gần nhất'];
require dirname(__DIR__) . '/products/index.php';
