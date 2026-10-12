<?php
$activePage = 'materials'; $pageTitle = 'Vật tư'; $selectedCategory = 'consumable';
$catalogueDescription = 'Theo dõi số lượng nhập xuất và vật tư còn trong kho.';
$catalogueColumns = ['unit' => 'Đơn vị', 'incoming' => 'Đã nhập', 'outgoing' => 'Đã xuất', 'closing' => 'Còn trong kho'];
require dirname(__DIR__) . '/products/index.php';
