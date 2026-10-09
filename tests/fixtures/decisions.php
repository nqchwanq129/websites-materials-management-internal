<?php
$managementData = ['warehouses' => ['1' => 'Kho Hà Nội', '2' => 'Kho Hải Phòng'], 'warehouseStatuses' => ['1' => 'active', '2' => 'active'], 'decisions' => [], 'decisionLines' => []];
for ($i = 1; $i <= 12; $i++) {
    $items = [];
    if ($i % 3) for ($j = 1; $j <= ($i === 1 ? 14 : 2); $j++) {
        $items[] = ['id' => (string) ($i * 100 + $j), 'version' => '2026-10-09 10:00:00.000000', 'decision_id' => (string) $i, 'decision_status' => 'linked', 'warehouse_id' => (string) ($i % 2 + 1), 'direction' => $j % 2 ? 'import' : 'export', 'product_id' => '1', 'product_name' => 'Thiết bị hàng hải ' . $j,
            'unit' => 'Chiếc', 'quantity' => $j === 1 ? '12345678901234.123456' : '2.000000', 'number' => 'P-' . $j,
            'date' => '2026-10-09', 'status' => $j % 2 ? 'confirmed' : 'draft', 'warehouse_name' => 'Kho Hà Nội'];
    }
    $managementData['decisions'][] = ['id' => (string) $i, 'number' => 'QĐ-2026/' . $i, 'issued_date' => '2026-10-09',
        'decision_type' => ['increase', 'allocation', 'transfer', 'decrease'][($i - 1) % 4], 'issuing_authority' => 'VISHIPEL',
        'version' => '2026-10-09 10:00:00.000000', 'links_version' => str_repeat('a', 64), 'attachments' => [], 'content' => 'Quyết định vật tư <script>', 'warehouse_id' => (string) ($i % 2 + 1),
        'warehouse_name' => $managementData['warehouses'][(string) ($i % 2 + 1)], 'items' => $items,
        'import_count' => count($items) / 2, 'export_count' => count($items) / 2];
    array_push($managementData['decisionLines'], ...$items);
}
$managementData['decisionLines'][] = ['id' => '9999', 'version' => '2026-10-09 10:00:00.000000', 'decision_id' => null, 'decision_status' => 'pending', 'warehouse_id' => '1', 'direction' => 'import', 'product_name' => 'Thiết bị chờ quyết định', 'quantity' => '1.000000', 'unit' => 'Chiếc', 'number' => 'PN-NEW', 'date' => '2026-10-09', 'status' => 'confirmed', 'warehouse_name' => 'Kho Hà Nội'];
