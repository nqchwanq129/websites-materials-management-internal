<?php
$activePage = 'fixed-assets'; $pageTitle = 'Tài sản cố định'; $selectedCategory = 'fixed_asset';
$catalogueDescription = 'Theo dõi hồ sơ tài sản, đặc điểm kỹ thuật, kho và quyết định liên quan.';
$catalogueColumns = ['specifications' => 'Đặc điểm tài sản', 'warehouses' => 'Kho có hồ sơ', 'last_received_date' => 'Ngày nhập gần nhất', 'decisions' => 'Quyết định liên quan'];
require dirname(__DIR__) . '/products/index.php';
