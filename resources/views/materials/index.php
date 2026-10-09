<?php
$activePage = 'materials'; $pageTitle = 'Vật tư'; $selectedCategory = 'consumable';
$catalogueDescription = 'Theo dõi quy cách, số lượng nhập xuất và vật tư còn trong kho.';
$catalogueColumns = ['unit' => 'Đơn vị', 'specifications' => 'Quy cách vật tư', 'incoming' => 'Đã nhập', 'outgoing' => 'Đã xuất', 'closing' => 'Còn trong kho'];
require dirname(__DIR__) . '/products/index.php';
