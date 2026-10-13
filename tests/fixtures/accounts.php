<?php
// Sample accounts only. Forms never write to the database.
$pageTitle = 'Tài khoản & phân quyền';
$activePage = 'accounts';
$warehouses = ['central' => 'Kho trung tâm', 'haiphong' => 'Kho Hải Phòng', 'danang' => 'Kho Đà Nẵng'];
$employees = ['NV001' => 'Nguyễn Minh Anh', 'NV002' => 'Trần Quốc Huy', 'NV003' => 'Lê Hoàng Nam', 'NV004' => 'Phạm Thu Hà', 'NV005' => 'Đỗ Thanh Tùng'];
$accounts = [
    ['username' => 'admin.demo', 'employee' => '', 'admin' => true, 'status' => 'active', 'warehouses' => []],
    ['username' => 'minhanh', 'employee' => 'NV001', 'admin' => false, 'status' => 'active', 'warehouses' => ['central' => 'keeper', 'haiphong' => 'viewer']],
    ['username' => 'quochuy', 'employee' => 'NV002', 'admin' => false, 'status' => 'active', 'warehouses' => ['haiphong' => 'keeper']],
    ['username' => 'hoangnam', 'employee' => 'NV003', 'admin' => false, 'status' => 'active', 'warehouses' => ['danang' => 'keeper']],
    ['username' => 'thuha', 'employee' => 'NV004', 'admin' => false, 'status' => 'active', 'warehouses' => ['central' => 'viewer', 'danang' => 'viewer']],
    ['username' => 'thanhtung', 'employee' => 'NV005', 'admin' => false, 'status' => 'locked', 'warehouses' => ['central' => 'viewer']],
];
foreach ($accounts as $index => &$account) $account['id'] = (string) ($index + 1); unset($account);
$employeeOptions = []; foreach ($employees as $key => $name) $employeeOptions[$key] = $key . ' · ' . $name;
$managementData = compact('warehouses', 'employees', 'employeeOptions', 'accounts');
