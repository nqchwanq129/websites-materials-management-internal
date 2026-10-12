<?php
// Read-only UI samples reflecting audit_logs. No audit queries or writes.
$pageTitle = 'Nhật ký thao tác';
$activePage = 'audit';
$warehouses = ['central' => 'Kho trung tâm', 'haiphong' => 'Kho Hải Phòng', 'danang' => 'Kho Đà Nẵng'];
$actions = ['create' => 'Tạo mới', 'update' => 'Cập nhật', 'confirm' => 'Xác nhận', 'cancel' => 'Hủy phiếu', 'assign_access' => 'Phân quyền', 'lock' => 'Khóa tài khoản', 'unlock' => 'Mở khóa', 'deactivate' => 'Ngừng hoạt động'];
$entityTypes = ['import_bill' => 'Phiếu nhập', 'export_bill' => 'Phiếu xuất', 'products' => 'Hàng hóa', 'users' => 'Tài khoản', 'employees' => 'Nhân viên', 'decisions' => 'Quyết định', 'suppliers' => 'Nhà cung cấp'];
$sampleLogs = [
    ['2026-10-09 14:35:22', 'admin.demo', 'Quản trị hệ thống', null, 'assign_access', 'users', 2, 'minhanh', 'Cập nhật quyền truy cập kho cho tài khoản minhanh', ['Kho Hải Phòng' => 'Không truy cập'], ['Kho Hải Phòng' => 'Chỉ xem']],
    ['2026-10-09 14:12:05', 'minhanh', 'Nguyễn Minh Anh', 'central', 'confirm', 'import_bill', 48, 'PN-2026-0048', 'Xác nhận phiếu nhập thiết bị thông tin hàng hải', ['status' => 'Bản nháp'], ['status' => 'Đã xác nhận']],
    ['2026-10-09 13:50:10', 'quochuy', 'Trần Quốc Huy', 'haiphong', 'create', 'export_bill', 32, 'PX-2026-0032', 'Tạo phiếu xuất vật tư bảo dưỡng', null, ['number' => 'PX-2026-0032', 'status' => 'Bản nháp', 'reason' => 'Cấp phát vật tư bảo dưỡng', 'total_before_tax' => '24.800.000 ₫']],
    ['2026-10-09 11:28:46', 'admin.demo', 'Quản trị hệ thống', null, 'update', 'employees', 4, 'NV004', 'Cập nhật thông tin liên hệ của Phạm Thu Hà', ['full_name' => 'Phạm Thu Hà', 'email' => null, 'phone' => ''], ['full_name' => 'Phạm Thu Hà', 'email' => 'thuha@example.com', 'phone' => '']],
    ['2026-10-09 10:15:03', 'admin.demo', 'Quản trị hệ thống', null, 'lock', 'users', 6, 'thanhtung', 'Khóa tài khoản thanhtung', ['status' => 'Đang hoạt động'], ['status' => 'Đã khóa']],
    ['2026-10-09 09:42:18', 'minhanh', 'Nguyễn Minh Anh', 'central', 'update', 'products', 12, 'VT-0012', 'Cập nhật tên vật tư', ['name' => 'Cáp tín hiệu', 'unit' => 'Cuộn'], ['name' => 'Cáp tín hiệu', 'unit' => 'Mét']],
    ['2026-10-09 08:30:00', 'admin.demo', 'Quản trị hệ thống', null, 'create', 'users', 7, 'linh.demo', 'Tạo tài khoản nhân viên mới', null, ['username' => 'linh.demo', 'status' => 'Đang hoạt động', 'is_super_admin' => false]],
    ['2026-10-08 16:45:31', 'quochuy', 'Trần Quốc Huy', 'haiphong', 'cancel', 'import_bill', 46, 'PN-2026-0046', 'Hủy phiếu nhập công cụ, dụng cụ', ['status' => 'Bản nháp', 'cancel_reason' => null], ['status' => 'Đã hủy', 'cancel_reason' => 'Lập lại phiếu theo hóa đơn điều chỉnh']],
    ['2026-10-08 15:20:10', 'minhanh', 'Nguyễn Minh Anh', 'central', 'confirm', 'export_bill', 31, 'PX-2026-0031', 'Xác nhận phiếu điều chuyển thiết bị', ['status' => 'Bản nháp'], ['status' => 'Đã xác nhận']],
    ['2026-10-08 13:05:22', 'admin.demo', 'Quản trị hệ thống', 'danang', 'create', 'decisions', 8, 'QD-2026-0008', 'Tạo quyết định cấp phát vật tư', null, ['number' => 'QD-2026-0008', 'decision_type' => 'Cấp phát', 'issued_date' => '08/10/2026']],
    ['2026-10-08 10:10:44', 'admin.demo', 'Quản trị hệ thống', null, 'update', 'suppliers', 3, 'NCC-0003', 'Cập nhật email nhà cung cấp', ['name' => 'Nhà cung cấp mẫu', 'email' => 'contact@example.com'], ['name' => 'Nhà cung cấp mẫu', 'email' => 'sales@example.com']],
    ['2026-10-07 16:40:05', 'admin.demo', 'Quản trị hệ thống', null, 'deactivate', 'employees', 5, 'NV005', 'Ngừng hoạt động nhân viên Đỗ Thanh Tùng', ['status' => 'Đang hoạt động'], ['status' => 'Ngừng hoạt động']],
    ['2026-10-07 15:05:10', 'admin.demo', 'Quản trị hệ thống', null, 'unlock', 'users', 3, 'quochuy', 'Mở khóa tài khoản quochuy', ['status' => 'Đã khóa'], ['status' => 'Đang hoạt động']],
    ['2026-10-07 08:00:00', null, 'Hệ thống', 'central', 'create', 'products', 15, 'VT-0015', 'Khởi tạo danh mục vật tư mẫu', null, ['code' => 'VT-0015', 'name' => 'Vật tư mẫu', 'unit' => 'Cái', 'status' => 'Đang hoạt động']],
];
$logs = [];
foreach ($sampleLogs as $index => [$time, $username, $name, $warehouse, $action, $entity, $entityId, $reference, $description, $before, $after]) {
    $logs[] = ['id' => 1048 - $index, 'created_at' => $time, 'username' => $username, 'full_name' => $name,
        'warehouse' => $warehouse, 'warehouse_name' => $warehouse ? $warehouses[$warehouse] : 'Toàn hệ thống',
        'action' => $action, 'action_name' => $actions[$action], 'entity_type' => $entity, 'entity_name' => $entityTypes[$entity],
        'entity_id' => $entityId, 'reference' => $reference, 'description' => $description, 'before_data' => $before, 'after_data' => $after];
}
$managementData = compact('warehouses', 'logs');
