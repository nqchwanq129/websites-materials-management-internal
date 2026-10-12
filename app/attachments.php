<?php
declare(strict_types=1);
function receivePdf(array $files): ?array
{
    $file = $files['pdf'] ?? null;
    if ($file === null) return null;
    if (!is_array($file) || !is_int($file['error'] ?? null)) throw new DomainException('File tải lên không hợp lệ.');
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new DomainException('Không thể nhận PDF. Chọn file tối đa 2 MB.');
    $name = managementText(['name' => $file['name'] ?? null], 'name', 255, true);
    $temp = $file['tmp_name'] ?? null;
    if (!is_string($temp) || !is_uploaded_file($temp) || filesize($temp) > 2 * 1024 * 1024
        || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf' || (new finfo(FILEINFO_MIME_TYPE))->file($temp) !== 'application/pdf'
        || file_get_contents($temp, false, null, 0, 5) !== '%PDF-') throw new DomainException('Chỉ chấp nhận PDF hợp lệ, tối đa 2 MB.');
    return ['name' => $name, 'tmp_name' => $temp, 'mime_type' => 'application/pdf', 'extension' => 'pdf'];
}
function receiveImportImages(array $files, array $input, int $lineCount): array
{
    $groups = $files['images'] ?? null; $images = []; $count = 0; $bytes = 0;
    if ($groups !== null) {
        if (!is_array($groups) || !is_array($groups['error'] ?? null)) throw new DomainException('Danh sách ảnh không hợp lệ.');
        foreach ($groups['error'] as $row => $errors) {
            if (!ctype_digit((string)$row) || (string)$row !== (string)(int)$row || (int)$row >= $lineCount || !is_array($errors) || count($errors) > 5) throw new DomainException('Ảnh phải thuộc đúng dòng hàng, tối đa 5 ảnh mỗi dòng.');
            foreach ($errors as $key => $error) {
                if ($error !== UPLOAD_ERR_OK) throw new DomainException('Không nhận đủ ảnh. Hãy chọn lại ảnh, mỗi ảnh tối đa 5 MB.');
                if (++$count > 16) throw new DomainException('Tối đa 16 ảnh trong một phiếu nhập.');
                $name = managementText(['name'=>$groups['name'][$row][$key] ?? null], 'name', 255, true);
                $temp = $groups['tmp_name'][$row][$key] ?? null;
                if (!is_string($temp) || !is_uploaded_file($temp) || filesize($temp) > 5*1024*1024) throw new DomainException('Ảnh không hợp lệ hoặc vượt 5 MB.');
                $bytes += filesize($temp);
                if ($bytes > 32*1024*1024) throw new DomainException('Tổng ảnh của phiếu không được vượt 32 MB.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temp);
                $extensions = ['image/jpeg'=>['jpg','jpeg'], 'image/png'=>['png'], 'image/webp'=>['webp']];
                $size = @getimagesize($temp);
                if (!isset($extensions[$mime]) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions[$mime], true)
                    || !$size || $size['mime'] !== $mime || $size[0]*$size[1] > 20000000) throw new DomainException('Chọn ảnh JPG, PNG hoặc WebP hợp lệ, tối đa 20 triệu điểm ảnh.');
                $decoded = @imagecreatefromstring(file_get_contents($temp));
                if ($decoded === false) throw new DomainException('Ảnh bị hỏng hoặc không đọc được.');
                imagedestroy($decoded);
                $images[(int)$row][] = ['name'=>$name, 'tmp_name'=>$temp, 'mime_type'=>$mime, 'extension'=>$extensions[$mime][0]];
            }
        }
    }
    $expected = $input['image_count'] ?? '0';
    if (!is_string($expected) || !ctype_digit($expected) || (int)$expected !== $count
        || ($count && ($input['form_complete'] ?? null) !== '1')) throw new DomainException('Không nhận đủ ảnh của phiếu. Hãy giảm số ảnh hoặc tải lại trang.');
    return $images;
}
function attachmentFolder(string $owner): string
{
    return ['decision_id'=>'decisions','import_bill_id'=>'imports','export_bill_id'=>'exports','import_bill_detail_id'=>'import-items'][$owner]
        ?? throw new InvalidArgumentException('Invalid attachment owner.');
}
function attachmentStorageName(PDO $pdo, string $owner, string $ownerId, string $attachmentId, string $extension): string
{
    attachmentFolder($owner);
    $ownerId = managementId($ownerId); $attachmentId = managementId($attachmentId);
    $photo = $owner === 'import_bill_detail_id';
    if (!in_array($extension, $photo ? ['jpg','png','webp'] : ['pdf'], true)) throw new InvalidArgumentException('Invalid attachment extension.');
    if ($photo) $sql = 'SELECT b.id,b.number,b.warehouse_id,b.received_date AS date FROM import_bill_details d JOIN import_bill b ON b.id=d.import_bill_id WHERE d.id=?';
    else {
        [$table,$date] = ['decision_id'=>['decisions','issued_date'],'import_bill_id'=>['import_bill','received_date'],'export_bill_id'=>['export_bill','issued_date']][$owner];
        $sql = "SELECT id,number,warehouse_id,$date AS date FROM $table WHERE id=?";
    }
    $stmt = $pdo->prepare($sql); $stmt->execute([$ownerId]); $document = $stmt->fetch();
    if (!$document) throw new DomainException('Không tìm thấy hồ sơ để lưu tệp đính kèm.');
    $number = mb_strtoupper($document['number'], 'UTF-8');
    // Windows iconv transliteration drops some Vietnamese letters; fold them explicitly.
    foreach (['A'=>'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ','E'=>'ÈÉẸẺẼÊỀẾỆỂỄ','I'=>'ÌÍỊỈĨ','O'=>'ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ',
        'U'=>'ÙÚỤỦŨƯỪỨỰỬỮ','Y'=>'ỲÝỴỶỸ','D'=>'Đ'] as $letter=>$accents) $number = preg_replace('/['.$accents.']/u',$letter,$number);
    $number = trim(substr(preg_replace('/[^A-Z0-9-]+/','-',$number),0,48),'-') ?: 'KHONG-SO';
    $prefix = ['decision_id'=>'QD','import_bill_id'=>'PN','export_bill_id'=>'PX','import_bill_detail_id'=>'ANH'][$owner];
    $label = $photo ? 'PN' : ($owner === 'decision_id' ? 'QD' : 'PHIEU');
    return $prefix.'_KHO'.$document['warehouse_id'].'_'.$label.$document['id'].'_'.$number
        .($photo ? '_DONG'.$ownerId : '').'_'.str_replace('-','',$document['date']).'_TEP'.$attachmentId.'.'.$extension;
}
function validAttachmentStoragePath(array $file, string $owner): bool
{
    $photo = $owner === 'import_bill_detail_id';
    $types = $photo ? ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'] : ['application/pdf'=>'pdf'];
    $extension = $types[$file['mime_type']] ?? null;
    if ($extension === null || !is_string($file['storage_path'] ?? null)) return false;
    $id = '[1-9][0-9]{0,19}';
    $prefix = ['decision_id'=>'QD_KHO'.$id.'_QD'.$id,'import_bill_id'=>'PN_KHO'.$id.'_PHIEU'.$id,
        'export_bill_id'=>'PX_KHO'.$id.'_PHIEU'.$id,'import_bill_detail_id'=>'ANH_KHO'.$id.'_PN'.$id][$owner] ?? throw new InvalidArgumentException('Invalid attachment owner.');
    $structured = $prefix.'_[A-Za-z0-9-]{1,48}'.($photo ? '_DONG'.$id : '').'_[0-9]{8}_TEP'.$id;
    return preg_match('~^'.attachmentFolder($owner).'/(?:[a-f0-9]{32}|'.$structured.')\\.'.$extension.'$~D',$file['storage_path']) === 1;
}
function storeAttachment(PDO $pdo, ?array $pdf, string $owner, string $id, string $actor): ?string
{
    if ($pdf === null) return null;
    if (!$pdo->inTransaction()) throw new LogicException('Attachments must be stored inside the document transaction.');
    $folder = attachmentFolder($owner);
    $directory = dirname(__DIR__) . '/storage/uploads/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Cannot create upload directory.');
    $pdo->prepare("INSERT INTO attachments (original_name,storage_path,mime_type,$owner,created_by) VALUES (?,'',?,?,?)")->execute([$pdf['name'], $pdf['mime_type'], $id, $actor]);
    $attachmentId = $pdo->lastInsertId();
    $relative = $folder . '/' . attachmentStorageName($pdo, $owner, $id, $attachmentId, $pdf['extension']);
    $stored = dirname(__DIR__) . '/storage/uploads/' . $relative;
    $reservation = @fopen($stored, 'x');
    if ($reservation === false) throw new RuntimeException('Cannot reserve attachment path; existing files must not be overwritten.');
    fclose($reservation);
    try {
        if (!move_uploaded_file($pdf['tmp_name'], $stored)) throw new RuntimeException('Cannot store attachment.');
        $pdo->prepare('UPDATE attachments SET storage_path=? WHERE id=?')->execute([$relative,$attachmentId]);
    } catch (Throwable $error) { if (is_file($stored)) unlink($stored); throw $error; }
    return $stored;
}
function serveAttachment(PDO $pdo, mixed $attachmentId, string $owner, string $userId, bool $preview = false): never
{
    attachmentFolder($owner);
    try { $id = managementId($attachmentId); }
    catch (DomainException $error) { http_response_code(404); exit('Không tìm thấy file.'); }
    $join = $owner === 'import_bill_detail_id' ? 'JOIN import_bill_details d ON d.id=a.import_bill_detail_id JOIN import_bill b ON b.id=d.import_bill_id'
        : 'JOIN '.(['decision_id'=>'decisions','import_bill_id'=>'import_bill','export_bill_id'=>'export_bill'][$owner]).' b ON b.id=a.'.$owner;
    $stmt = $pdo->prepare("SELECT a.original_name,a.storage_path,a.mime_type,b.warehouse_id FROM attachments a $join WHERE a.id=?");
    $stmt->execute([$id]); $file = $stmt->fetch();
    if (!$file || !canViewWarehouse(userAccess($pdo, $userId), (string)$file['warehouse_id'])) { http_response_code(404); exit('Không tìm thấy file.'); }
    $types = $owner === 'import_bill_detail_id' ? ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'] : ['application/pdf'=>'pdf'];
    $extension = $file ? ($types[$file['mime_type']] ?? null) : null;
    if (!$extension || !validAttachmentStoragePath($file, $owner)) { http_response_code(404); exit('Không tìm thấy file.'); }
    $root = realpath(dirname(__DIR__) . '/storage/uploads');
    $path = realpath(dirname(__DIR__) . '/storage/uploads/' . $file['storage_path']);
    if ($root === false || $path === false || !is_file($path) || !is_readable($path)
        || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/')) { http_response_code(404); exit('Không tìm thấy file.'); }
    if ($preview && $extension === 'pdf') {
        session_write_close();
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Cannot read receipt PDF.');
        respondJson(['ok'=>true,'name'=>$file['original_name'],'data'=>base64_encode($contents)]);
    }
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Disposition: ' . ($extension === 'pdf' ? 'attachment' : 'inline') . '; filename="document.' . $extension . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Length: ' . filesize($path));
    session_write_close(); readfile($path); exit;
}
