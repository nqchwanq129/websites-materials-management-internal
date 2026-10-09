<?php
$suppliers = [];
for ($i = 1; $i <= 12; $i++) {
    $receipts = [];
    if ($i % 3 !== 0) for ($bill = 1; $bill <= ($i === 1 ? 14 : 2); $bill++) {
        $receipts[] = ['id' => (string) ($i * 100 + $bill), 'number' => 'PN-' . $i . '-' . $bill,
            'received_date' => '2026-10-09', 'invoice_number' => 'HD-' . $bill, 'warehouse_name' => 'Kho trung tâm',
            'status' => $bill % 2 === 0 ? 'confirmed' : 'draft'];
    }
    $suppliers[] = ['id' => (string) $i, 'name' => 'Công ty thiết bị ' . $i, 'tax_code' => $i % 4 === 0 ? '' : sprintf('010000%04d', $i),
        'address' => $i . ' đường Nguyễn Trãi', 'phone' => '02412345678', 'email' => 'contact' . $i . '@example.com',
        'status' => $i % 4 === 0 ? 'inactive' : 'active', 'receipts' => $receipts, 'receipt_count' => count($receipts),
        'last_received_date' => $receipts ? '2026-10-09' : ''];
}
$managementData = compact('suppliers');
