<?php
$warehouseRecords = [];
for ($i = 1; $i <= 12; $i++) {
    $warehouseRecords[] = ['id' => (string) $i, 'version' => '2026-10-09 12:00:00.000000',
        'code' => sprintf('KHO%02d', $i), 'name' => 'Kho ' . $i, 'address' => $i . ' đường Nguyễn Trãi',
        'status' => in_array($i, [3, 9], true) ? 'inactive' : 'active',
        'employee_count' => $i % 4, 'active_employee_count' => $i % 4, 'access_count' => $i % 3];
}
$managementData = compact('warehouseRecords');
