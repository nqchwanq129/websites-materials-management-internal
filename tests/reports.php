<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/reports.php';
function ensureReport(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$data = require __DIR__ . '/fixtures/reports.php';
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
$history=$precise;
$history['warehouses']['2']='Second warehouse';
$history['movements']=array_reverse([
    array_replace($precise['movements'][0],['id'=>'1','unit'=>'Piece','quantity'=>null]),
    array_replace($precise['movements'][0],['id'=>'2','date'=>'2026-10-01','unit'=>'Piece','quantity'=>'3','unit_price'=>'7','amount_before_tax'=>'21']),
    array_replace($precise['movements'][0],['id'=>'3','date'=>'2026-10-31','direction'=>'export','unit'=>'Piece','quantity'=>'1','unit_price'=>'99','amount_before_tax'=>'7']),
    array_replace($precise['movements'][0],['id'=>'4','date'=>'2026-11-01','quantity'=>'9','unit_price'=>'999']),
    array_replace($precise['movements'][0],['id'=>'5','date'=>'2026-10-31','status'=>'draft','unit_price'=>'999']),
    array_replace($precise['movements'][0],['id'=>'6','warehouse_id'=>'2','unit'=>'Box','quantity'=>'2']),
]);
$history['receipts']=[['id'=>'1','direction'=>'import','number'=>'MISMATCH','date'=>'2026-09-01','warehouse_id'=>'1','header'=>'1.000001','details'=>'1.000000','line_count'=>1],
    ['id'=>'2','direction'=>'import','number'=>'EMPTY','date'=>'2026-10-01','warehouse_id'=>'1','header'=>'0','details'=>'0','line_count'=>0],
    ['id'=>'3','direction'=>'import','number'=>'MATCH','date'=>'2026-10-01','warehouse_id'=>'1','header'=>'21','details'=>'21','line_count'=>1],
    ['id'=>'4','direction'=>'import','number'=>'FUTURE','date'=>'2026-11-01','warehouse_id'=>'1','header'=>'10','details'=>'0','line_count'=>1]];
$checks=buildReport($history,reportFilters(['from'=>'2026-10-01','to'=>'2026-10-31'],$history));
ensureReport(count($checks['rows'])===2 && $checks['unknown_count']===2 && !$checks['units'],'Missing quantities or differing units must not yield false stock totals; warehouses must stay separate.');
$checked=$checks['rows'][0];
ensureReport($checked['opening']===null && $checked['incoming']==='3.000000' && $checked['outgoing']==='1.000000' && $checked['closing']===null,'Unknown opening must not hide known period flows.');
ensureReport($checked['unit_price']==='7' && array_column($checked['movements'],'id')===['2','3'],'Latest confirmed import price, inclusive period boundaries or chronological movements incorrect.');
ensureReport(count($checks['reconciliation'])===2,'Mismatch and empty receipts must be flagged, future and balanced receipts excluded.');
$scoped=buildReport($history,reportFilters(['from'=>'2026-10-01','to'=>'2026-10-31','warehouse'=>'2','stock'=>'unknown'],$history));
ensureReport(count($scoped['rows'])===1 && !$scoped['reconciliation'],'Unknown stock and reconciliation warehouse filters incorrect.');
ensureReport(reportNumber(null)==='Chưa đủ dữ liệu' && reportNumber(null,6,'none')==='Không theo dõi','Unknown data must differ from intentionally untracked stock.');
echo "PASS: five reports, exact stock/value arithmetic, inclusive dates, confirmed-only flows, warehouse/category/search filters, missing quantities, historical units, reference prices and reconciliation.\n";
