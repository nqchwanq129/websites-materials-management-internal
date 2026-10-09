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
    return ['name' => $name, 'tmp_name' => $temp];
}
function storePdfAttachment(PDO $pdo, ?array $pdf, string $owner, string $id, string $actor): ?string
{
    if ($pdf === null) return null;
    $folder = ['decision_id' => 'decisions', 'import_bill_id' => 'imports', 'export_bill_id' => 'exports'][$owner] ?? throw new InvalidArgumentException('Invalid PDF owner.');
    $directory = dirname(__DIR__) . '/storage/uploads/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('Cannot create upload directory.');
    $relative = $folder . '/' . bin2hex(random_bytes(16)) . '.pdf';
    $stored = dirname(__DIR__) . '/storage/uploads/' . $relative;
    if (!move_uploaded_file($pdf['tmp_name'], $stored)) throw new RuntimeException('Cannot store PDF.');
    try { $pdo->prepare("INSERT INTO attachments (original_name,storage_path,mime_type,$owner,created_by) VALUES (?,?,'application/pdf',?,?)")->execute([$pdf['name'], $relative, $id, $actor]); }
    catch (Throwable $error) { unlink($stored); throw $error; }
    return $stored;
}
function downloadPdfAttachment(PDO $pdo, mixed $attachmentId, string $owner): never
{
    $folder = ['decision_id' => 'decisions', 'import_bill_id' => 'imports', 'export_bill_id' => 'exports'][$owner] ?? throw new InvalidArgumentException('Invalid PDF owner.');
    try { $id = managementId($attachmentId); }
    catch (DomainException $error) { http_response_code(404); exit('Không tìm thấy file.'); }
    $stmt = $pdo->prepare("SELECT original_name,storage_path FROM attachments WHERE id=? AND $owner IS NOT NULL AND mime_type='application/pdf'");
    $stmt->execute([$id]); $file = $stmt->fetch();
    if (!$file || !preg_match('~^' . $folder . '/[a-f0-9]{32}\.pdf$~D', $file['storage_path'])) { http_response_code(404); exit('Không tìm thấy file.'); }
    $path = dirname(__DIR__) . '/storage/uploads/' . $file['storage_path'];
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Không tìm thấy file.'); }
    header('Content-Type: application/pdf');
    header("Content-Disposition: attachment; filename=\"document.pdf\"; filename*=UTF-8''" . rawurlencode($file['original_name']));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Length: ' . filesize($path));
    session_write_close(); readfile($path); exit;
}
