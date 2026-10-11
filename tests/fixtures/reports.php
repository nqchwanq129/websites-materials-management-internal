<?php
// Sample business data only; never inserted into MySQL.
$month = new DateTimeImmutable('first day of this month',new DateTimeZone('Asia/Ho_Chi_Minh'));
$warehouses = ['1' => 'Kho trung tâm', '2' => 'Kho Hải Phòng', '3' => 'Kho Đà Nẵng'];
$names = [
    'consumable' => ['Cáp tín hiệu', 'Dây điện nguồn', 'Dầu bôi trơn', 'Băng keo cách điện'],
    'spare_part' => ['Bộ nguồn dự phòng', 'Quạt làm mát', 'Cầu chì bảo vệ', 'Bo mạch thu phát'],
    'ccdc' => ['Đồng hồ đo điện', 'Bộ dụng cụ sửa chữa', 'Máy khoan cầm tay', 'Kìm bấm mạng'],
    'fixed_asset' => ['Máy thu phát VHF', 'Máy chủ thông tin', 'Thiết bị AIS', 'Bộ lưu điện UPS'],
    'other' => ['Tài liệu kỹ thuật', 'Thiết bị hỗ trợ', 'Bộ phụ kiện', 'Vật phẩm chuyên dụng'],
];
$products = $movements = [];
foreach ($names as $category => $group) for ($i = 0; $i < 12; $i++) {
    $id = (string) (count($products) + 1); $warehouse = (string) ($i % 3 + 1);
    $untracked = $category === 'other' && $i % 3 === 0;
    $unit = $category === 'consumable' ? ($i % 2 ? 'Lít' : 'Mét') : ($category === 'ccdc' ? 'Bộ' : 'Chiếc');
    $price = (string) (($category === 'fixed_asset' ? 18000000 : ($category === 'consumable' ? 25000 : 850000)) + $i * 5000);
    $products[$id] = ['id' => $id, 'code' => strtoupper(substr($category, 0, 3)) . '-' . str_pad($id, 3, '0', STR_PAD_LEFT),
        'name' => $group[$i % 4] . ' ' . ($i + 1), 'category' => $category, 'unit' => $unit,
        'tracking_mode' => $untracked ? 'none' : 'quantity', 'specifications' => 'Thiết bị phục vụ thông tin hàng hải; quy cách mẫu ' . ($i + 1)];
    $zero = $i % 5 === 0;
    foreach ([['import', '-2 months', 5, 100, 'confirmed'], ['export', '-1 month', 8, 20, 'confirmed'],
        ['import', '', 5, 40, 'confirmed'], ['export', '', 9, $zero ? 120 : 15, 'confirmed'],
        ['import', '', 12, 999, 'draft'], ['export', '', 13, 999, 'cancelled']] as [$direction, $offset, $day, $quantity, $status]) {
        $date = ($offset ? $month->modify($offset) : $month)->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
        $movements[] = ['id' => (string) (count($movements) + 1), 'product_id' => $id, 'warehouse_id' => $warehouse,
            'direction' => $direction, 'date' => $date, 'quantity' => $untracked ? null : (string) $quantity, 'unit_price' => $price,
            'amount_before_tax' => bcmul((string) $quantity, $price, 6), 'status' => $status,
            'number' => ($direction === 'import' ? 'PN-' : 'PX-') . str_replace('-', '', substr($date, 0, 7)) . '-' . $id . '-' . $day,
            'decision_number' => $status === 'confirmed' && $i % 2 ? 'QĐ-MẪU/' . $id : '', 'note' => 'Dữ liệu mẫu để duyệt báo cáo'];
    }
}
return ['warehouses' => $warehouses, 'products' => $products, 'movements' => $movements,
    'accounts' => ['Quản trị viên' => 2, 'Thủ kho' => 5, 'Người xem' => 8]];
