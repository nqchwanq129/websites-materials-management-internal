<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/management.php';

function activityNotificationScope(array $access): array
{
    $warehouses=array_keys($access['warehouses']);
    $receiptScope=$access['admin']?'1=1':($warehouses?'a.warehouse_id IN ('.implode(',',array_fill(0,count($warehouses),'?')).')':'0=1');
    return ["((a.action='create' AND a.entity_type IN ('import_bill','export_bill') AND $receiptScope)
        OR (a.notification_admin=1 AND a.entity_type IN ('warehouses','products','suppliers','employees','decisions') AND a.action IN ('create','update','delete','activate','deactivate')))",
        $access['admin']?[]:$warehouses];
}
function loadActivityNotifications(PDO $pdo,string $userId,array $input=[]): array
{
    // ponytail: unread counts scan eligible audit history; use a dedicated event archive if audit volume grows.
    $access=userAccess($pdo,$userId);[$scope,$params]=activityNotificationScope($access);
    $before=managementId($input['before'] ?? null,true);
    $known=$input['known'] ?? [];
    if(!is_array($known) || !array_is_list($known) || count($known)>2000)throw new DomainException('Danh sách thông báo không hợp lệ.');
    $known=array_values(array_unique(array_map(static fn($id)=>managementId($id),$known)));
    $readJoin=' LEFT JOIN notification_reads r ON r.audit_log_id=a.id AND r.user_id=? ';
    $stmt=$pdo->prepare("SELECT COUNT(IF(r.audit_log_id IS NULL,1,NULL)) AS unread_count,COALESCE(MAX(a.id),0) AS latest_id FROM audit_logs a $readJoin WHERE $scope");
    $stmt->execute([$userId,...$params]);$summary=$stmt->fetch();
    $alive=[];$readIds=[];
    if($known){$stmt=$pdo->prepare("SELECT a.id,(r.audit_log_id IS NOT NULL) AS is_read FROM audit_logs a $readJoin WHERE $scope AND a.id IN (".implode(',',array_fill(0,count($known),'?')).')');
        $stmt->execute([$userId,...$params,...$known]);$knownRows=$stmt->fetchAll();$alive=array_map('strval',array_column($knownRows,'id'));$readIds=array_map('strval',array_column(array_filter($knownRows,static fn($row)=>(bool)$row['is_read']),'id'));}
    $stmt=$pdo->prepare("SELECT a.id,a.entity_type,a.entity_id,a.action,a.created_at,a.before_data,a.after_data,(r.audit_log_id IS NOT NULL) AS is_read,w.name AS warehouse_name,
        i.id AS import_id,e.id AS export_id FROM audit_logs a $readJoin LEFT JOIN warehouses w ON w.id=a.warehouse_id
        LEFT JOIN import_bill i ON a.entity_type='import_bill' AND i.id=a.entity_id AND i.warehouse_id=a.warehouse_id
        LEFT JOIN export_bill e ON a.entity_type='export_bill' AND e.id=a.entity_id AND e.warehouse_id=a.warehouse_id
        WHERE $scope".($before?' AND a.id<?':'').' ORDER BY a.id DESC LIMIT 6');
    $stmt->execute([$userId,...$params,...($before?[$before]:[])]);$rows=$stmt->fetchAll();$more=count($rows)>5;$rows=array_slice($rows,0,5);$items=[];
    $names=['warehouses'=>'kho','products'=>'hàng hóa','suppliers'=>'nhà cung cấp','employees'=>'nhân viên','decisions'=>'quyết định'];
    $actions=['create'=>'Thêm mới','update'=>'Cập nhật','delete'=>'Xóa','activate'=>'Kích hoạt','deactivate'=>'Ngừng hoạt động'];
    foreach($rows as $row){
        $snapshot=json_decode($row['after_data'] ?? $row['before_data'] ?? '{}',true,512,JSON_THROW_ON_ERROR);
        $receipt=in_array($row['entity_type'],['import_bill','export_bill'],true);$href=null;
        if($receipt){$import=$row['entity_type']==='import_bill';$title='Phiếu '.($import?'nhập':'xuất').' mới: '.($snapshot['number'] ?? '#'.$row['entity_id']);
            if($row[$import?'import_id':'export_id']!==null)$href='index.php?page='.($import?'imports':'exports').'&id='.$row['entity_id'];
            $description=$row['warehouse_name'] ?? 'Kho đã ngừng sử dụng';
            if($href===null)$description.=' · Phiếu đã được xóa';
        }else{$title=$actions[$row['action']].' '.$names[$row['entity_type']].': '.($snapshot['name'] ?? $snapshot['full_name'] ?? $snapshot['number'] ?? $snapshot['code'] ?? '#'.$row['entity_id']);$description='Thông báo quản trị · Toàn hệ thống';}
        $items[]=['id'=>(string)$row['id'],'title'=>$title,'description'=>$description,'created_at'=>str_replace(' ','T',substr($row['created_at'],0,19)).'+07:00','href'=>$href,'kind'=>$receipt?'receipt':'admin','is_read'=>(bool)$row['is_read']];
    }
    ksort($access['warehouses']);
    return ['items'=>$items,'unread_count'=>(int)$summary['unread_count'],'latest_id'=>(string)$summary['latest_id'],
        'next_before'=>$more?(string)end($rows)['id']:null,'alive_ids'=>$alive,'read_ids'=>$readIds,'scope'=>hash('sha256',json_encode([$access['admin'],$access['warehouses']],JSON_THROW_ON_ERROR))];
}
function readActivityNotification(PDO $pdo,string $userId,mixed $id): void
{
    $id=managementId($id);$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$stmt->execute([$userId]);
        [$scope,$params]=activityNotificationScope(userAccess($pdo,$userId));
        $stmt=$pdo->prepare("SELECT a.id FROM audit_logs a WHERE $scope AND a.id=?");$stmt->execute([...$params,$id]);
        if(!$stmt->fetchColumn())throw new DomainException('Thông báo không tồn tại hoặc không thuộc phạm vi của bạn.');
        $pdo->prepare('INSERT IGNORE INTO notification_reads (user_id,audit_log_id) VALUES (?,?)')->execute([$userId,$id]);
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
