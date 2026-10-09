<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/reports.php';
function ensureReport(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$data = require dirname(__DIR__) . '/resources/data/reports-preview.php';
$filters = reportFilters([], $data); $report = buildReport($data, $filters);
ensureReport(count($report['rows']) === 60, 'All five categories should be present.');
$material = array_values(array_filter($report['rows'], fn($row) => $row['id'] === '1'))[0];
ensureReport($material['opening'] === '80.000000' && $material['incoming'] === '40.000000' && $material['outgoing'] === '120.000000' && $material['closing'] === '0.000000', 'Opening/period/closing calculations incorrect or unconfirmed receipts included.');
ensureReport(count($material['movements']) === 2, 'Detail should contain only confirmed movements in period.');
foreach (['materials', 'parts', 'tools', 'assets'] as $kind) {
    $result = buildReport($data, reportFilters(['kind' => $kind], $data));
    ensureReport(count($result['rows']) === 12 && count($result['categories']) === 1, 'Category report leaks other categories.');
}
$warehouseReport = buildReport($data, reportFilters(['warehouse' => '2'], $data));
ensureReport(count($warehouseReport['rows']) === 20 && count(array_unique(array_column($warehouseReport['rows'], 'warehouse_id'))) === 1, 'Warehouse filter incorrect.');
$untracked = buildReport($data, reportFilters(['stock' => 'untracked'], $data));
ensureReport(count($untracked['rows']) === 4 && $untracked['rows'][0]['closing'] === null && !$untracked['units'], 'Untracked quantities must not be interpreted as zero or summed.');
ensureReport(count($report['units']) === 4, 'Different units must be summarized independently.');
ensureReport(count(buildReport($data, reportFilters(['q' => 'CON-001'], $data))['rows']) === 1, 'Search incorrect.');
foreach ([['from' => '2026-02-30'], ['from' => '2026-10-31', 'to' => '2026-10-01'], ['kind' => 'invalid'], ['warehouse' => '999'], ['q' => ['bad']], ['stock' => 'invalid']] as $invalid) {
    try { reportFilters($invalid, $data); throw new RuntimeException('Invalid report filter accepted.'); } catch (DomainException $error) {}
}
$precise = ['warehouses' => ['1' => 'Warehouse'], 'products' => ['1' => ['id' => '1', 'code' => 'A', 'name' => 'Same name', 'category' => 'consumable', 'unit' => 'Piece', 'tracking_mode' => 'quantity', 'specifications' => '']],
    'movements' => [['id' => '1', 'product_id' => '1', 'warehouse_id' => '1', 'direction' => 'import', 'date' => '2026-09-01', 'quantity' => '0.000001', 'unit_price' => '1', 'amount_before_tax' => '9007199254740991.123456', 'status' => 'confirmed', 'number' => 'PN-1', 'decision_number' => '']]];
$preciseReport = buildReport($precise, reportFilters(['from' => '2026-10-01', 'to' => '2026-10-31'], $precise));
ensureReport($preciseReport['summary']['closing_value'] === '9007199254740991.123456' && $preciseReport['rows'][0]['opening'] === '0.000001' && !$preciseReport['rows'][0]['movements'], 'DECIMAL precision or opening-only detail lost.');
ensureReport(reportNumber('9007199254740991.123456') === '9.007.199.254.740.991,123456', 'Display must preserve precision.');
$precise['products']['2'] = $precise['products']['1']; $precise['products']['2']['id'] = '2'; $precise['products']['2']['code'] = 'B';
$precise['movements'][] = array_replace($precise['movements'][0], ['id' => '2', 'product_id' => '2']);
ensureReport(count(buildReport($precise, reportFilters(['from' => '2026-10-01', 'to' => '2026-10-31'], $precise))['rows']) === 2, 'Same product name must not merge distinct product IDs.');
echo "PASS: five reports, stock arithmetic, confirmed-only flows, warehouse/category/search filters, untracked quantities, units, dates and exact DECIMAL precision.\n";
