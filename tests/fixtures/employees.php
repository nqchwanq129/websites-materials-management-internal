<?php
// Sample employees only; no employee queries or writes.
$pageTitle = 'Nhân viên';
$activePage = 'employees';
$warehouses = ['central' => 'Kho trung tâm', 'haiphong' => 'Kho Hải Phòng', 'danang' => 'Kho Đà Nẵng'];
$sampleEmployees = [
    ['Nguyễn Minh Anh', 'central', 'active', 'minhanh'],
    ['Trần Quốc Huy', 'haiphong', 'active', 'quochuy'],
    ['Lê Hoàng Nam', 'danang', 'active', 'hoangnam'],
    ['Phạm Thu Hà', 'central', 'active', 'thuha'],
    ['Đỗ Thanh Tùng', 'central', 'inactive', 'thanhtung'],
    ['Vũ Ngọc Linh', 'haiphong', 'active', ''],
    ['Hoàng Đức Long', 'danang', 'active', ''],
    ['Bùi Mai Phương', 'central', 'active', ''],
    ['Đặng Quang Hải', 'haiphong', 'active', ''],
    ['Đinh Thảo Nguyên', 'danang', 'active', ''],
    ['Ngô Tuấn Kiệt', 'central', 'active', ''],
    ['Phan Hồng Nhung', 'haiphong', 'inactive', ''],
];
$employees = [];
foreach ($sampleEmployees as $index => [$name, $warehouse, $status, $account]) {
    $employees[] = ['id' => (string) ($index + 1), 'code' => 'NV' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'full_name' => $name,
        'warehouse' => $warehouse, 'status' => $status, 'account' => $account,
        'email' => ($account ?: 'nhanvien' . ($index + 1)) . '@example.com', 'phone' => ''];
}
$managementData = compact('warehouses', 'employees');
