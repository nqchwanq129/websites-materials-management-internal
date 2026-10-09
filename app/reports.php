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
function reportNumber(?string $value, int $decimals = 6): string
{
    if ($value === null) return 'Không theo dõi';
    $negative = str_starts_with($value, '-');
    [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
    $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
    $fraction = rtrim(substr($fraction, 0, $decimals), '0');
    return ($negative ? '-' : '') . $integer . ($fraction !== '' ? ',' . $fraction : '');
}
function reportFilters(array $input, array $data): array
{
    $month = new DateTimeImmutable('first day of this month');
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
    if (!in_array($filters['stock'], ['', 'stock', 'empty', 'untracked', 'negative'], true)) throw new DomainException('Trạng thái tồn kho không hợp lệ.');
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
            'opening' => '0', 'incoming' => '0', 'outgoing' => '0', 'closing' => '0', 'opening_value' => '0', 'incoming_value' => '0', 'outgoing_value' => '0', 'closing_value' => '0', 'unit_price' => $movement['unit_price'], 'movements' => []];
        $row = &$rows[$key]; $before = $movement['date'] < $filters['from']; $incoming = $movement['direction'] === 'import';
        $field = $before ? 'opening' : ($incoming ? 'incoming' : 'outgoing');
        $sign = $before && !$incoming ? '-1' : '1';
        $row[$field] = bcadd($row[$field], bcmul($movement['quantity'] ?? '0', $sign, 6), 6);
        $row[$field . '_value'] = bcadd($row[$field . '_value'], bcmul($movement['amount_before_tax'], $sign, 6), 6);
        if (!$before) $row['movements'][] = $movement;
        unset($row);
    }
    $query = mb_strtolower($filters['q']);
    foreach ($rows as $key => &$row) {
        $row['closing'] = bcsub(bcadd($row['opening'], $row['incoming'], 6), $row['outgoing'], 6);
        $row['closing_value'] = bcsub(bcadd($row['opening_value'], $row['incoming_value'], 6), $row['outgoing_value'], 6);
        $row['stock'] = $row['tracking_mode'] === 'none' ? 'untracked' : (bccomp($row['closing'], '0', 6) === 0 ? 'empty' : (bccomp($row['closing'], '0', 6) > 0 ? 'stock' : 'negative'));
        if ($row['tracking_mode'] === 'none') foreach (['opening', 'incoming', 'outgoing', 'closing'] as $field) $row[$field] = null;
        if (($filters['stock'] !== '' && $row['stock'] !== $filters['stock']) || ($query !== '' && !str_contains(mb_strtolower($row['code'] . ' ' . $row['name'] . ' ' . $row['specifications']), $query))) unset($rows[$key]);
    } unset($row);
    usort($rows, static fn($a, $b) => [$a['code'], $a['warehouse_id']] <=> [$b['code'], $b['warehouse_id']]);
    $summary = ['opening_value' => '0', 'incoming_value' => '0', 'outgoing_value' => '0', 'closing_value' => '0'];
    $categories = $months = $units = [];
    $chartEnd = new DateTimeImmutable(substr($filters['to'], 0, 7) . '-01');
    $chartStart = max($filters['from'], $chartEnd->modify('-11 months')->format('Y-m-d'));
    $chartDate = new DateTimeImmutable(substr($chartStart, 0, 7) . '-01');
    while ($chartDate <= $chartEnd) { $months[$chartDate->format('Y-m')] = ['import' => '0', 'export' => '0']; $chartDate = $chartDate->modify('+1 month'); }
    foreach ($rows as $row) {
        foreach ($summary as $field => $value) $summary[$field] = bcadd($value, $row[$field], 6);
        $categories[$row['category']] = ($categories[$row['category']] ?? 0) + 1;
        if ($row['closing'] !== null) $units[$row['unit']] = bcadd($units[$row['unit']] ?? '0', $row['closing'], 6);
        foreach ($row['movements'] as $movement) {
            $month = substr($movement['date'], 0, 7);
            if (isset($months[$month])) $months[$month][$movement['direction']] = bcadd($months[$month][$movement['direction']], $movement['amount_before_tax'], 6);
        }
    }
    return ['rows' => $rows, 'summary' => $summary, 'categories' => $categories, 'months' => $months, 'units' => $units];
}
