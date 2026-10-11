<?php
declare(strict_types=1);

function loadDashboard(PDO $pdo, string $userId, ?DateTimeImmutable $now = null): array
{
    $user = findUser($pdo, 'id', $userId);
    if (!userCanSignIn($user)) throw new DomainException('Tài khoản không có quyền xem tổng quan.');
    $admin = (int)$user['is_super_admin'] === 1;
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    $month = $now->modify('first day of this month')->setTime(0,0);
    $months = [];
    for ($i=5;$i>=0;$i--) $months[$month->modify('-'.$i.' months')->format('Y-m')] = ['import'=>0,'export'=>0];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT w.id,w.name,w.status FROM warehouses w '.($admin?'':'JOIN user_warehouses g ON g.warehouse_id=w.id WHERE g.user_id=? AND w.status=\'active\'').' ORDER BY w.name,w.id');
        $stmt->execute($admin?[]:[$userId]); $warehouses = []; $active = 0;
        foreach ($stmt as $row) { $warehouses[(string)$row['id']]=$row['name']; if($row['status']==='active') $active++; }
        $ids = array_keys($warehouses);
        $scope = $admin?'1=1':($ids?'b.warehouse_id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'0=1');
        $params = $admin?[]:$ids;
        $productScope = $admin?'1=1':"EXISTS(SELECT 1 FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id WHERE d.product_id=p.id AND $scope)
            OR EXISTS(SELECT 1 FROM export_bill_details d JOIN export_bill b ON b.id=d.export_bill_id WHERE d.product_id=p.id AND $scope)";
        $stmt = $pdo->prepare("SELECT p.category,COUNT(*) AS count FROM products p WHERE $productScope GROUP BY p.category");
        $stmt->execute([...$params,...$params]); $categories = array_fill_keys(array_keys(productCategories()),0);
        foreach ($stmt as $row) $categories[$row['category']] = (int)$row['count'];
        $sql = "SELECT b.id,b.number,b.received_date AS date,b.total_before_tax AS amount,b.status,b.warehouse_id,w.name AS warehouse_name,
            COALESCE(NULLIF(b.supplier_name,''),NULLIF(b.sender_name,''),'') AS description,b.created_at,'import' AS direction
            FROM import_bill b JOIN warehouses w ON w.id=b.warehouse_id WHERE $scope
            UNION ALL SELECT b.id,b.number,b.issued_date AS date,b.total_before_tax AS amount,b.status,b.warehouse_id,w.name AS warehouse_name,
            CONCAT_WS(' · ',NULLIF(b.reason,''),NULLIF(b.receiver_name,'')) AS description,b.created_at,'export' AS direction
            FROM export_bill b JOIN warehouses w ON w.id=b.warehouse_id WHERE $scope";
        $stmt = $pdo->prepare("SELECT direction,SUBSTRING(date,1,7) AS month,status,COUNT(*) AS count FROM ($sql) bills WHERE date>=? AND date<? GROUP BY direction,month,status");
        $stmt->execute([...$params,...$params,array_key_first($months).'-01',$month->modify('+1 month')->format('Y-m-d')]);
        $counts = ['import'=>['total'=>0,'confirmed'=>0],'export'=>['total'=>0,'confirmed'=>0]];
        foreach ($stmt as $row) {
            $months[$row['month']][$row['direction']] += (int)$row['count'];
            if ($row['month']===$month->format('Y-m')) {
                $counts[$row['direction']]['total'] += (int)$row['count'];
                if ($row['status']==='confirmed') $counts[$row['direction']]['confirmed'] += (int)$row['count'];
            }
        }
        $stmt = $pdo->prepare("SELECT * FROM ($sql) bills ORDER BY date DESC,created_at DESC,id DESC,direction ASC LIMIT 10");
        $stmt->execute([...$params,...$params]); $receipts = $stmt->fetchAll();
        $pdo->commit();
        return ['products'=>array_sum($categories),'categories'=>$categories,'counts'=>$counts,'active_warehouses'=>$active,
            'warehouses'=>$warehouses,'months'=>$months,'receipts'=>$receipts,'updated_at'=>$now->format('d/m/Y H:i'),'admin'=>$admin];
    } catch (Throwable $error) { if($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
