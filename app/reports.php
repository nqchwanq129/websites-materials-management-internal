<?php
declare(strict_types=1);

function reportTypes(): array
{
    return ['inventory' => ['Nhập · xuất · tồn', ''], 'materials' => ['Báo cáo vật tư', 'consumable'],
        'parts' => ['Báo cáo phụ tùng', 'spare_part'], 'tools' => ['Báo cáo công cụ, dụng cụ', 'ccdc'], 'assets' => ['Báo cáo tài sản cố định', 'fixed_asset']];
}
function reportCategories(): array
{
    return ['consumable' => 'Vật tư', 'spare_part' => 'Phụ tùng thay thế', 'ccdc' => 'Công cụ, dụng cụ', 'fixed_asset' => 'Tài sản cố định', 'other' => 'Khác'];
}
function loadReportData(PDO $pdo, string $userId): array
{
    $access=userAccess($pdo,$userId);
    if (!canAccessPage($access,'reports')) throw new DomainException('Bạn không có quyền xem báo cáo.');
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $data=['warehouses'=>[],'products'=>[],'movements'=>[],'receipts'=>[],'accounts'=>[]];
        foreach($pdo->query('SELECT id,name FROM warehouses ORDER BY name,id') as $row) if(canViewWarehouse($access,(string)$row['id'])) $data['warehouses'][(string)$row['id']]=$row['name'];
        foreach($pdo->query('SELECT id,code,name,category,unit,tracking_mode FROM products ORDER BY code,id') as $row) {
            $row['id']=(string)$row['id']; $data['products'][$row['id']]=$row;
        }
        // ponytail: read the confirmed ledger once; use SQL aggregation/on-demand details when receipt history grows.
        foreach(['import'=>['import_bill_details','import_bill','import_bill_id','received_date','received_quantity'],
            'export'=>['export_bill_details','export_bill','export_bill_id','issued_date','issued_quantity']] as $direction=>[$details,$bills,$billId,$date,$quantity]) {
            foreach($pdo->query("SELECT d.id,d.product_id,d.product_name,d.unit,d.note,d.$quantity AS quantity,d.unit_price,d.amount_before_tax,d.decision_status,
                q.number AS decision_number,b.id AS bill_id,b.number,b.$date AS date,b.status,b.warehouse_id,b.total_before_tax AS bill_total
                FROM $bills b LEFT JOIN $details d ON d.$billId=b.id LEFT JOIN decisions q ON q.id=d.decision_id
                WHERE b.status='confirmed' ORDER BY b.$date,b.id,d.id") as $movement) {
                if(!canViewWarehouse($access,(string)$movement['warehouse_id'])) continue;
                $key=$direction.':'.$movement['bill_id'];
                $data['receipts'][$key]??=['id'=>(string)$movement['bill_id'],'direction'=>$direction,'number'=>$movement['number'],'date'=>$movement['date'],
                    'warehouse_id'=>(string)$movement['warehouse_id'],'header'=>$movement['bill_total'],'details'=>'0.000000','line_count'=>0];
                if($movement['id']===null) continue;
                $data['receipts'][$key]['details']=bcadd($data['receipts'][$key]['details'],$movement['amount_before_tax'],6);
                $data['receipts'][$key]['line_count']++;
                $movement['id']=(string)$movement['id']; $movement['product_id']=(string)$movement['product_id']; $movement['warehouse_id']=(string)$movement['warehouse_id'];
                $movement['direction']=$direction; unset($movement['bill_total']); $data['movements'][]=$movement;
            }
        }
        if (!$access['admin']) $data['products']=array_intersect_key($data['products'],array_fill_keys(array_column($data['movements'],'product_id'),true));
        if ($access['admin']) foreach($pdo->query("SELECT CASE WHEN u.is_super_admin=1 THEN 'Quản trị viên'
            WHEN EXISTS(SELECT 1 FROM user_warehouses g WHERE g.user_id=u.id AND g.role='keeper') THEN 'Thủ kho'
            WHEN EXISTS(SELECT 1 FROM user_warehouses g WHERE g.user_id=u.id AND g.role='viewer') THEN 'Người xem'
            ELSE 'Chưa phân quyền' END AS role,COUNT(*) AS count FROM users u GROUP BY role") as $row) $data['accounts'][$row['role']]=(int)$row['count'];
        if ($ownTransaction) $pdo->commit(); return $data;
    } catch(Throwable $error) { if($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function reportNumber(?string $value, int $decimals = 6, string $trackingMode = 'quantity'): string
{
    if ($value === null) return $trackingMode==='none'?'Không theo dõi':'Chưa đủ dữ liệu';
    $negative = str_starts_with($value, '-');
    [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
    $fraction = rtrim(substr($fraction, 0, $decimals), '0');
    return ($negative ? '-' : '') . $integer . ($fraction !== '' ? ',' . $fraction : '');
}
function reportFilters(array $input, array $data): array
{
    $month = new DateTimeImmutable('first day of this month',new DateTimeZone('Asia/Ho_Chi_Minh'));
    $defaults = ['kind' => 'inventory', 'from' => $month->format('Y-m-d'), 'to' => $month->modify('last day of this month')->format('Y-m-d'), 'warehouse' => '', 'category' => '', 'q' => '', 'stock' => ''];
    $filters = [];
    foreach ($defaults as $key => $default) {
        $value = $input[$key] ?? $default;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 200) throw new DomainException('Bộ lọc báo cáo không hợp lệ.');
        $filters[$key] = trim($value);
    }
    if (!isset(reportTypes()[$filters['kind']])) throw new DomainException('Loại báo cáo không tồn tại.');
    foreach (['from', 'to'] as $key) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
        if (!$date || $date->format('Y-m-d') !== $filters[$key] || $filters[$key] < '1000-01-01') throw new DomainException('Vui lòng chọn ngày báo cáo hợp lệ.');
    }
    if ($filters['from'] > $filters['to']) throw new DomainException('Ngày bắt đầu phải trước hoặc bằng ngày kết thúc.');
    if ($filters['warehouse'] !== '' && !isset($data['warehouses'][$filters['warehouse']])) throw new DomainException('Kho được chọn không tồn tại.');
    if ($filters['category'] !== '' && !isset(reportCategories()[$filters['category']])) throw new DomainException('Nhóm hàng hóa không hợp lệ.');
    if (!in_array($filters['stock'], ['', 'stock', 'empty', 'untracked', 'negative', 'unknown'], true)) throw new DomainException('Trạng thái tồn kho không hợp lệ.');
    $filters['category'] = reportTypes()[$filters['kind']][1] ?: $filters['category'];
    return $filters;
}
function buildReport(array $data, array $filters): array
{
    $rows = [];
    foreach ($data['movements'] as $movement) {
        if ($movement['status'] !== 'confirmed' || $movement['date'] > $filters['to']) continue;
        $product = $data['products'][$movement['product_id']];
        if (($filters['warehouse'] !== '' && (string) $movement['warehouse_id'] !== $filters['warehouse'])
            || ($filters['category'] !== '' && $product['category'] !== $filters['category'])) continue;
        $key = $movement['warehouse_id'] . ':' . $product['id'];
        if (!isset($rows[$key])) $rows[$key] = $product + ['key' => $key, 'warehouse_id' => $movement['warehouse_id'], 'warehouse_name' => $data['warehouses'][$movement['warehouse_id']],
            'opening' => '0', 'incoming' => '0', 'outgoing' => '0', 'closing' => '0', 'opening_value' => '0', 'incoming_value' => '0', 'outgoing_value' => '0', 'closing_value' => '0', 'unit_price' => null, 'price_reference' => '',
            'unknown' => ['opening'=>false,'incoming'=>false,'outgoing'=>false], 'movements' => []];
        $row = &$rows[$key]; $before = $movement['date'] < $filters['from']; $incoming = $movement['direction'] === 'import';
        $field = $before ? 'opening' : ($incoming ? 'incoming' : 'outgoing');
        $sign = $before && !$incoming ? '-1' : '1';
        $row[$field] = bcadd($row[$field], bcmul($movement['quantity'] ?? '0', $sign, 6), 6);
        $row[$field . '_value'] = bcadd($row[$field . '_value'], bcmul($movement['amount_before_tax'], $sign, 6), 6);
        if($row['tracking_mode']==='quantity' && ($movement['quantity']===null || (array_key_exists('unit',$movement) && $movement['unit']!==$row['unit']))) $row['unknown'][$field]=true;
        $priceReference=$movement['date'].':'.str_pad((string)$movement['id'],20,'0',STR_PAD_LEFT);
        if($incoming && strcmp($priceReference,$row['price_reference'])>0) { $row['unit_price']=$movement['unit_price']; $row['price_reference']=$priceReference; }
        if (!$before) $row['movements'][] = $movement;
        unset($row);
    }
    $query = mb_strtolower($filters['q']);
    foreach ($rows as $key => &$row) {
        $row['closing'] = bcsub(bcadd($row['opening'], $row['incoming'], 6), $row['outgoing'], 6);
        $row['closing_value'] = bcsub(bcadd($row['opening_value'], $row['incoming_value'], 6), $row['outgoing_value'], 6);
        $row['stock'] = $row['tracking_mode'] === 'none' ? 'untracked' : (in_array(true,$row['unknown'],true)?'unknown':(bccomp($row['closing'], '0', 6) === 0 ? 'empty' : (bccomp($row['closing'], '0', 6) > 0 ? 'stock' : 'negative')));
        if ($row['tracking_mode'] === 'none') foreach (['opening', 'incoming', 'outgoing', 'closing'] as $field) $row[$field] = null;
        elseif($row['stock']==='unknown') { foreach($row['unknown'] as $field=>$unknown) if($unknown) $row[$field]=null; $row['closing']=null; }
        if (($filters['stock'] !== '' && $row['stock'] !== $filters['stock']) || ($query !== '' && !str_contains(mb_strtolower($row['code'] . ' ' . $row['name']), $query))) unset($rows[$key]);
    } unset($row);
    usort($rows, static fn($a, $b) => [$a['code'], $a['warehouse_id']] <=> [$b['code'], $b['warehouse_id']]);
    $summary = ['opening_value' => '0', 'incoming_value' => '0', 'outgoing_value' => '0', 'closing_value' => '0'];
    $categories = $months = $units = [];
    $chartEnd = new DateTimeImmutable(substr($filters['to'], 0, 7) . '-01');
    $chartStart = max($filters['from'], $chartEnd->modify('-11 months')->format('Y-m-d'));
    $chartDate = new DateTimeImmutable(substr($chartStart, 0, 7) . '-01');
    while ($chartDate <= $chartEnd) { $months[$chartDate->format('Y-m')] = ['import' => '0', 'export' => '0']; $chartDate = $chartDate->modify('+1 month'); }
    foreach ($rows as &$row) {
        foreach ($summary as $field => $value) $summary[$field] = bcadd($value, $row[$field], 6);
        $categories[$row['category']] = ($categories[$row['category']] ?? 0) + 1;
        if ($row['closing'] !== null) $units[$row['unit']] = bcadd($units[$row['unit']] ?? '0', $row['closing'], 6);
        usort($row['movements'],static fn($a,$b)=>strcmp($a['date'].str_pad($a['id'],20,'0',STR_PAD_LEFT).$a['direction'],$b['date'].str_pad($b['id'],20,'0',STR_PAD_LEFT).$b['direction']));
        foreach ($row['movements'] as $movement) {
            $month = substr($movement['date'], 0, 7);
            if (isset($months[$month])) $months[$month][$movement['direction']] = bcadd($months[$month][$movement['direction']], $movement['amount_before_tax'], 6);
        }
    }
    unset($row);
    $reconciliation=[];
    foreach($data['receipts']??[] as $receipt) {
        if($receipt['date']>$filters['to'] || ($filters['warehouse']!=='' && $receipt['warehouse_id']!==$filters['warehouse'])) continue;
        if($receipt['line_count']===0 || bccomp($receipt['header'],$receipt['details'],6)!==0) $reconciliation[]=$receipt;
    }
    return ['rows' => $rows, 'summary' => $summary, 'categories' => $categories, 'months' => $months, 'units' => $units,
        'unknown_count'=>count(array_filter($rows,static fn($row)=>$row['stock']==='unknown')),'reconciliation'=>$reconciliation];
}
