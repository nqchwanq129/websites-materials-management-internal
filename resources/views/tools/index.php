<?php
$activePage = 'tools'; $pageTitle = 'Công cụ, dụng cụ'; $selectedCategory = 'ccdc';
$catalogueDescription = 'Quản lý danh mục công cụ, vị trí kho và số lượng có thể sử dụng.';
$catalogueColumns = ['unit' => 'Đơn vị', 'warehouses' => 'Kho có hồ sơ', 'closing' => 'Số lượng trong kho', 'last_received_date' => 'Ngày nhập gần nhất'];
require dirname(__DIR__) . '/products/index.php';
