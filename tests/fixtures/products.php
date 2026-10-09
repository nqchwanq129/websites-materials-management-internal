<?php
require_once dirname(__DIR__, 2) . '/app/products.php';
$managementData = ['warehouses' => ['1' => 'Kho trung tâm', '2' => 'Kho Hải Phòng'], 'products' => []];
for ($i = 1; $i <= 12; $i++) {
    $untracked = $i % 4 === 0;
    $movements = [];
    for ($j = 1; $j <= ($i === 1 ? 14 : 2); $j++) $movements[] = ['id' => (string) $j, 'product_name' => 'Thiết bị ' . $i, 'unit' => 'Chiếc', 'quantity' => $untracked ? null : '1.000000', 'date' => '2026-10-09', 'number' => 'PN-' . $i . '-' . $j, 'warehouse_id' => '1', 'warehouse_name' => 'Kho trung tâm', 'direction' => 'import', 'status' => 'confirmed', 'decision_number' => '', 'decision_status' => 'pending'];
    $managementData['products'][] = ['id' => (string) $i, 'version' => '2026-10-09 10:00:00.000000', 'code' => 'HH-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'name' => 'Thiết bị hàng hải ' . $i,
        'category' => array_keys(productCategories())[($i - 1) % 5], 'unit' => 'Chiếc', 'specifications' => 'Quy cách <script>', 'tracking_mode' => $untracked ? 'none' : 'quantity', 'status' => $i % 3 === 0 ? 'inactive' : 'active',
        'incoming' => $untracked ? null : '10.000000', 'outgoing' => $untracked ? null : '2.000000', 'closing' => $untracked ? null : '8.000000', 'stock' => $untracked ? 'untracked' : 'stock', 'last_received_date' => '2026-10-09',
        'stocks' => [['warehouse_id' => '1', 'warehouse_name' => 'Kho trung tâm', 'incoming' => $untracked ? null : '10.000000', 'outgoing' => $untracked ? null : '2.000000', 'closing' => $untracked ? null : '8.000000']], 'movements' => $movements];
}
