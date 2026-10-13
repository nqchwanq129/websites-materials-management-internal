<?php
declare(strict_types=1);

require_once __DIR__ . '/management.php';

function searchRecords(PDO $pdo, string $userId, array $input): array
{
    $access = userAccess($pdo, $userId);
    $query = managementText($input, 'q', 120);
    $page = $input['offset_page'] ?? '0';
    if (!is_string($page) || !preg_match('/^[0-9]{1,4}$/D', $page)) throw new DomainException('Trang tìm kiếm không hợp lệ.');
    if ($query === '') return ['items'=>[], 'next_page'=>null];
    $warehouseIds = array_keys($access['warehouses']);
    $placeholders = implode(',', array_fill(0, count($warehouseIds), '?'));
    $scope = $access['admin'] ? '1=1' : ($warehouseIds ? "r.warehouse_id IN ($placeholders)" : '0=1');
    $scopeParams = $access['admin'] ? [] : $warehouseIds;
    $productScope = $access['admin'] ? '1=1' : ($warehouseIds ? "(EXISTS(SELECT 1 FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id WHERE d.product_id=r.id AND b.warehouse_id IN ($placeholders))
        OR EXISTS(SELECT 1 FROM export_bill_details d JOIN export_bill b ON b.id=d.export_bill_id WHERE d.product_id=r.id AND b.warehouse_id IN ($placeholders)))" : '0=1');
    $supplierScope = $access['admin'] ? '1=1' : ($warehouseIds ? "EXISTS(SELECT 1 FROM import_bill b WHERE b.supplier_id=r.id AND b.warehouse_id IN ($placeholders))" : '0=1');
    // Search display fields only; never expose passwords, session tokens or raw audit JSON.
    $sources = [
        'imports'=>['Phiếu nhập kho','r.number',"CONCAT_WS(' · ',r.invoice_number,w.name,DATE_FORMAT(r.received_date,'%d/%m/%Y'))",'import_bill r JOIN warehouses w ON w.id=r.warehouse_id',
            'r.number,r.invoice_number,r.invoice_series,r.supplier_name,r.sender_name,r.received_date,w.name',$scope,$scopeParams],
        'exports'=>['Phiếu xuất kho','r.number',"CONCAT_WS(' · ',r.receiver_name,w.name,DATE_FORMAT(r.issued_date,'%d/%m/%Y'))",'export_bill r JOIN warehouses w ON w.id=r.warehouse_id',
            'r.number,r.receiver_name,r.reason,r.issued_date,w.name',$scope,$scopeParams],
        'products'=>['Hàng hóa','r.name',"CONCAT_WS(' · ',r.code,r.unit)",'products r','r.code,r.name,r.unit',$productScope,$access['admin'] ? [] : [...$warehouseIds,...$warehouseIds]],
        'employees'=>['Nhân viên','r.full_name',"CONCAT_WS(' · ',r.code,w.name,r.email)",'employees r JOIN warehouses w ON w.id=r.warehouse_id','r.code,r.full_name,r.email,r.phone,w.name','1=1',[]],
        'warehouses'=>['Kho hàng','r.name',"CONCAT_WS(' · ',r.code,r.address)",'warehouses r','r.code,r.name,r.address','1=1',[]],
        'suppliers'=>['Nhà cung cấp','r.name',"CONCAT_WS(' · ',r.tax_code,r.address)",'suppliers r','r.name,r.tax_code,r.address,r.phone,r.email',$supplierScope,$scopeParams],
        'decisions'=>['Quyết định','r.number',"CONCAT_WS(' · ',w.name,DATE_FORMAT(r.issued_date,'%d/%m/%Y'))",'decisions r JOIN warehouses w ON w.id=r.warehouse_id','r.number,r.content,r.issued_date,w.name',$scope,$scopeParams],
        'accounts'=>['Tài khoản','r.username',"CONCAT_WS(' · ',e.full_name,e.code)",'users r LEFT JOIN employees e ON e.id=r.employee_id','r.username,e.full_name,e.code','1=1',[]],
        'audit'=>['Nhật ký thao tác',"CONCAT('#',r.id,' · ',r.action)","CONCAT_WS(' · ',u.username,r.entity_type,r.created_at)",'audit_logs r LEFT JOIN users u ON u.id=r.user_id',
            "r.id,r.action,r.entity_type,r.entity_id,r.created_at,u.username,JSON_UNQUOTE(JSON_EXTRACT(COALESCE(r.after_data,r.before_data),'$.number')),JSON_UNQUOTE(JSON_EXTRACT(COALESCE(r.after_data,r.before_data),'$.code')),JSON_UNQUOTE(JSON_EXTRACT(COALESCE(r.after_data,r.before_data),'$.name'))",'1=1',[]],
    ];
    $pattern = '%' . strtr(str_replace(['đ','Đ'], ['d','D'], $query), ['!'=>'!!','%'=>'!%','_'=>'!_']) . '%';
    $items = []; $more = false; $offset = (int)$page * 5;
    // ponytail: substring matching scans display fields; add a search index when database volume warrants it.
    foreach ($sources as $target=>[$label,$title,$description,$from,$fields,$where,$params]) {
        if (!canAccessPage($access, $target)) continue;
        $statement = $pdo->prepare("SELECT r.id,$title AS title,$description AS description FROM $from
            WHERE $where AND REPLACE(REPLACE(CONCAT_WS(' ',$fields),'đ','d'),'Đ','D') COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!' ORDER BY r.id DESC LIMIT 6 OFFSET $offset");
        $statement->execute([...$params,$pattern]); $rows = $statement->fetchAll();
        $more = $more || count($rows) > 5;
        foreach (array_slice($rows,0,5) as $row) $items[] = ['id'=>(string)$row['id'],'type'=>$target,'label'=>$label,
            'title'=>$row['title'],'description'=>$row['description'] ?? '', 'href'=>'index.php?page='.$target.'&id='.$row['id']];
    }
    return ['items'=>$items,'next_page'=>$more ? (int)$page + 1 : null];
}
