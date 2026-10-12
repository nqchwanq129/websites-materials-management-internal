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
function storeAttachment(PDO $pdo, ?array $pdf, string $owner, string $id, string $actor): ?string
{
    if ($pdf === null) return null;
    $folder = ['decision_id' => 'decisions', 'import_bill_id' => 'imports', 'export_bill_id' => 'exports', 'import_bill_detail_id'=>'import-items'][$owner] ?? throw new InvalidArgumentException('Invalid attachment owner.');
    $directory = dirname(__DIR__) . '/storage/uploads/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Cannot create upload directory.');
    $relative = $folder . '/' . bin2hex(random_bytes(16)) . '.' . $pdf['extension'];
    $stored = dirname(__DIR__) . '/storage/uploads/' . $relative;
    if (!move_uploaded_file($pdf['tmp_name'], $stored)) throw new RuntimeException('Cannot store PDF.');
    try { $pdo->prepare("INSERT INTO attachments (original_name,storage_path,mime_type,$owner,created_by) VALUES (?,?,?,?,?)")->execute([$pdf['name'], $relative, $pdf['mime_type'], $id, $actor]); }
    catch (Throwable $error) { unlink($stored); throw $error; }
    return $stored;
}
function serveAttachment(PDO $pdo, mixed $attachmentId, string $owner, string $userId, bool $preview = false): never
{
    $folder = ['decision_id' => 'decisions', 'import_bill_id' => 'imports', 'export_bill_id' => 'exports', 'import_bill_detail_id'=>'import-items'][$owner] ?? throw new InvalidArgumentException('Invalid attachment owner.');
    try { $id = managementId($attachmentId); }
    catch (DomainException $error) { http_response_code(404); exit('Không tìm thấy file.'); }
    $join = $owner === 'import_bill_detail_id' ? 'JOIN import_bill_details d ON d.id=a.import_bill_detail_id JOIN import_bill b ON b.id=d.import_bill_id'
        : 'JOIN '.(['decision_id'=>'decisions','import_bill_id'=>'import_bill','export_bill_id'=>'export_bill'][$owner]).' b ON b.id=a.'.$owner;
    $stmt = $pdo->prepare("SELECT a.original_name,a.storage_path,a.mime_type,b.warehouse_id FROM attachments a $join WHERE a.id=?");
    $stmt->execute([$id]); $file = $stmt->fetch();
    if (!$file || !canViewWarehouse(userAccess($pdo, $userId), (string)$file['warehouse_id'])) { http_response_code(404); exit('Không tìm thấy file.'); }
    $types = $owner === 'import_bill_detail_id' ? ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'] : ['application/pdf'=>'pdf'];
    $extension = $file ? ($types[$file['mime_type']] ?? null) : null;
    if (!$extension || !preg_match('~^' . $folder . '/[a-f0-9]{32}\.' . $extension . '$~D', $file['storage_path'])) { http_response_code(404); exit('Không tìm thấy file.'); }
    $path = dirname(__DIR__) . '/storage/uploads/' . $file['storage_path'];
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Không tìm thấy file.'); }
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
