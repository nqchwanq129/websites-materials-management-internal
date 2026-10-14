<?php
declare(strict_types=1);

function loadOwnProfile(PDO $pdo, string $userId): array
{
    $account = findUser($pdo, 'id', $userId);
    if (!userCanSignIn($account)) throw new DomainException('Tài khoản không còn được phép truy cập.');
    $employee = $account['employee_id'] === null ? null : managementSnapshot($pdo, 'employees', (string) $account['employee_id']);
    $roles = [];
    if ((int) $account['is_super_admin'] === 1) $roles[] = 'Quản trị viên';
    else {
        $statement = $pdo->prepare('SELECT DISTINCT role FROM user_warehouses WHERE user_id=? ORDER BY role');
        $statement->execute([$userId]);
        foreach ($statement as $grant) $roles[] = $grant['role'] === 'keeper' ? 'Thủ kho' : 'Người xem';
    }
    return ['username' => $account['username'], 'full_name' => $employee['full_name'] ?? $account['username'],
        'email' => $employee['email'] ?? '', 'phone' => $employee['phone'] ?? '', 'code' => $employee['code'] ?? 'Chưa liên kết',
        'warehouse' => $account['employee_warehouse_name'] ?? 'Chưa gán kho', 'role' => implode(' · ', $roles) ?: 'Chưa phân quyền',
        'avatar_url' => ownAvatarUrl($account),
        'editable' => $employee !== null, 'version' => hash('sha256', implode(':', [$userId, $account['employee_id'] ?? '', $account['updated_at'], $employee['updated_at'] ?? '']))];
}

function ownAvatarUrl(array $account): string
{
    return validOwnAvatarPath($account['avatar_path'] ?? null, (string)$account['id'])
        ? 'index.php?page=avatar&v=' . rawurlencode(basename($account['avatar_path'])) : '';
}

function validOwnAvatarPath(mixed $path, string $userId): bool
{
    return is_string($path) && preg_match('~^avatars/AVATAR_USER'.preg_quote($userId,'~').'_[a-f0-9]{16}\.jpg$~D', $path) === 1;
}

function prepareOwnAvatar(array $files): string
{
    $file = $files['avatar'] ?? null;
    if (array_diff(array_keys($files), ['avatar']) || !is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
        || filesize($file['tmp_name']) > 10*1024*1024 || !is_string($file['name'] ?? null)) {
        throw new DomainException('Chọn ảnh JPG, PNG hoặc WebP hợp lệ, tối đa 10 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $types = ['image/jpeg'=>['jpg','jpeg'], 'image/png'=>['png'], 'image/webp'=>['webp']];
    $size = @getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !in_array(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)), $types[$mime], true)
        || !$size || $size['mime'] !== $mime || $size[0]*$size[1] > 12000000) {
        throw new DomainException('Ảnh phải là JPG, PNG hoặc WebP, tối đa 12 triệu điểm ảnh.');
    }
    $source = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if ($source === false) throw new DomainException('Ảnh bị hỏng hoặc không đọc được.');
    $target = imagecreatetruecolor(400,400);
    try {
        imagefill($target,0,0,imagecolorallocate($target,255,255,255));
        $side = min($size[0],$size[1]);
        // A centered square keeps avatars consistent; re-encoding discards metadata and uploaded payloads.
        if (!imagecopyresampled($target,$source,0,0,(int)(($size[0]-$side)/2),(int)(($size[1]-$side)/2),400,400,$side,$side)) throw new RuntimeException('Cannot resize avatar.');
        ob_start();
        try { if (!imagejpeg($target,null,88)) throw new RuntimeException('Cannot encode avatar.'); return ob_get_contents(); }
        finally { ob_end_clean(); }
    } finally { imagedestroy($source); imagedestroy($target); }
}

function removeOwnAvatarFile(?string $path, string $userId): void
{
    if (!validOwnAvatarPath($path,$userId)) return;
    $file = dirname(__DIR__).'/storage/uploads/'.$path;
    if (is_file($file) && !@unlink($file)) error_log('QLVT avatar: cannot remove obsolete image.');
}

function serveOwnAvatar(array $account): never
{
    if (!validOwnAvatarPath($account['avatar_path'] ?? null,(string)$account['id'])) { http_response_code(404); exit; }
    $file = dirname(__DIR__).'/storage/uploads/'.$account['avatar_path'];
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Disposition: inline; filename="avatar.jpg"');
    header('Content-Length: '.filesize($file));
    readfile($file); exit;
}

function saveOwnProfile(PDO $pdo, string $userId, array $input, string $address, array $files = []): array
{
    $action = managementText($input, 'action', 20, true);
    $allowed = match ($action) {
        'profile' => ['csrf_token', 'action', 'version', 'full_name', 'email', 'phone'],
        'password' => ['csrf_token', 'action', 'current_password', 'password', 'password_confirmation'],
        'avatar' => ['csrf_token', 'action', 'version', 'remove_avatar'],
        default => throw new DomainException('Thao tác cá nhân không hợp lệ.'),
    };
    if (array_diff(array_keys($input), $allowed)) throw new DomainException('Chỉ được thay đổi thông tin cá nhân cho phép.');
    if ($action !== 'avatar' && $files) throw new DomainException('Tệp tải lên chỉ dùng để đổi ảnh đại diện.');
    $newAvatar = null; $oldAvatar = null;
    if ($action === 'profile') {
        $name = managementText($input, 'full_name', 200, true);
        $email = managementText($input, 'email', 254);
        $phone = managementText($input, 'phone', 32);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Email không hợp lệ.');
    } elseif ($action === 'avatar') {
        $removeAvatar = managementText($input,'remove_avatar',1,true);
        if (!in_array($removeAvatar,['0','1'],true) || ($removeAvatar === '1' && $files)) throw new DomainException('Thao tác ảnh đại diện không hợp lệ.');
        $avatarBytes = $removeAvatar === '1' ? null : prepareOwnAvatar($files);
    } else {
        foreach (['current_password', 'password', 'password_confirmation'] as $key) {
            if (!is_string($input[$key] ?? null) || $input[$key] === '' || strlen($input[$key]) > 4096
                || str_contains($input[$key], "\0") || !mb_check_encoding($input[$key], 'UTF-8')) throw new DomainException('Vui lòng kiểm tra các ô mật khẩu.');
        }
        $password = $input['password'];
        if (mb_strlen($password) < 8) throw new DomainException('Mật khẩu mới phải có ít nhất 8 ký tự.');
        if (strlen($password) > 72) throw new DomainException('Mật khẩu mới quá dài. Hãy dùng mật khẩu ngắn hơn.');
        if (!hash_equals($password, $input['password_confirmation'])) throw new DomainException('Nhập lại mật khẩu mới chưa khớp.');
        $account = findUser($pdo, 'id', $userId);
        if (!userCanSignIn($account)) throw new DomainException('Tài khoản không còn được phép truy cập.');
        // Reuse login verification/rate limiting, then recheck under the transaction lock.
        try { authenticateUser($pdo, $account['username'], $input['current_password'], $address, false); }
        catch (DomainException $error) {
            if (str_contains($error->getMessage(), '5 phút')) throw $error;
            throw new DomainException('Mật khẩu hiện tại không đúng.');
        }
    }
    $pdo->beginTransaction();
    try {
        // ponytail: share management's users lock; narrow both write paths together if management traffic grows.
        $pdo->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        $account = findUser($pdo, 'id', $userId);
        if (!userCanAct($account)) throw new DomainException('Tài khoản không còn được phép truy cập.');
        if ($account['employee_id'] !== null) {
            $statement = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE');
            $statement->execute([$account['employee_id']]);
        }
        $profile = loadOwnProfile($pdo, $userId);
        if ($action === 'profile') {
            if (!$profile['editable']) throw new DomainException('Tài khoản chưa liên kết nhân viên. Vui lòng liên hệ quản trị viên để cập nhật hồ sơ.');
            if (!hash_equals($profile['version'], managementText($input, 'version', 64, true))) throw new DomainException('Hồ sơ đã được cập nhật. Hãy đóng và mở lại cửa sổ trước khi lưu.');
            $id = (string) $account['employee_id']; $entity = 'employees';
            $before = managementSnapshot($pdo, 'employees', $id);
            $pdo->prepare('UPDATE employees SET full_name=?,email=?,phone=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')
                ->execute([$name, $email ?: null, $phone ?: null, $id]);
            $after = managementSnapshot($pdo, 'employees', $id);
            unset($before['updated_at'], $after['updated_at']);
            if ($before === $after) { $pdo->rollBack(); return ['message'=>'Thông tin cá nhân không có thay đổi.']; }
        } elseif ($action === 'avatar') {
            if (!hash_equals($profile['version'],managementText($input,'version',64,true))) throw new DomainException('Hồ sơ đã thay đổi. Hãy đóng và mở lại cửa sổ trước khi đổi ảnh.');
            $oldAvatar = $account['avatar_path'] ?? null;
            if ($avatarBytes === null && $oldAvatar === null) { $pdo->rollBack(); return ['message'=>'Bạn đang dùng ảnh đại diện mặc định.']; }
            if ($avatarBytes !== null) {
                $directory = dirname(__DIR__).'/storage/uploads/avatars';
                if (!is_dir($directory) && !mkdir($directory,0770,true) && !is_dir($directory)) throw new RuntimeException('Cannot create avatar directory.');
                $relativeAvatar = 'avatars/AVATAR_USER'.$userId.'_'.bin2hex(random_bytes(8)).'.jpg';
                $stream = fopen(dirname(__DIR__).'/storage/uploads/'.$relativeAvatar,'x');
                if ($stream === false) throw new RuntimeException('Cannot reserve avatar file.');
                $newAvatar = $relativeAvatar;
                try { if (fwrite($stream,$avatarBytes) !== strlen($avatarBytes)) throw new RuntimeException('Cannot store complete avatar.'); }
                finally { fclose($stream); }
            }
            $id = $userId; $entity = 'users';
            $before = ['username'=>$account['username'],'avatar_path'=>$oldAvatar];
            $after = ['username'=>$account['username'],'avatar_path'=>$newAvatar];
            $pdo->prepare('UPDATE users SET avatar_path=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')->execute([$newAvatar,$userId]);
        } else {
            if (!password_verify($input['current_password'], $account['password_hash'])) throw new DomainException('Mật khẩu đã thay đổi. Hãy nhập lại mật khẩu hiện tại.');
            if (password_verify($password, $account['password_hash'])) throw new DomainException('Mật khẩu mới phải khác mật khẩu hiện tại.');
            $id = $userId; $entity = 'users';
            $before = ['username' => $account['username']];
            $after = $before + ['password_changed' => true];
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash=?,updated_at=GREATEST(CURRENT_TIMESTAMP(6),updated_at + INTERVAL 1 MICROSECOND) WHERE id=?')
                ->execute([$newHash, $userId]);
        }
        $pdo->prepare('INSERT INTO audit_logs (user_id,warehouse_id,action,entity_type,entity_id,before_data,after_data,created_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6) + INTERVAL 7 HOUR)')
            ->execute([$userId, $entity === 'employees' ? $after['warehouse_id'] : null, 'update', $entity, $id,
                json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        if ($action === 'avatar') removeOwnAvatarFile($oldAvatar,$userId);
        return ['message'=>match($action) { 'profile'=>'Đã cập nhật thông tin cá nhân.', 'avatar'=>$newAvatar === null ? 'Đã gỡ ảnh đại diện.' : 'Đã cập nhật ảnh đại diện.', default=>'Đã đổi mật khẩu. Các phiên đăng nhập khác sẽ cần đăng nhập lại.' },
            'password_version'=>$action === 'password' ? hash('sha256',$newHash) : null];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        removeOwnAvatarFile($newAvatar,$userId);
        throw $error;
    }
}
