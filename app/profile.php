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
        'editable' => $employee !== null, 'version' => hash('sha256', implode(':', [$userId, $account['employee_id'] ?? '', $account['updated_at'], $employee['updated_at'] ?? '']))];
}

function saveOwnProfile(PDO $pdo, string $userId, array $input, string $address): array
{
    $action = managementText($input, 'action', 20, true);
    $allowed = match ($action) {
        'profile' => ['csrf_token', 'action', 'version', 'full_name', 'email', 'phone'],
        'password' => ['csrf_token', 'action', 'current_password', 'password', 'password_confirmation'],
        default => throw new DomainException('Thao tác cá nhân không hợp lệ.'),
    };
    if (array_diff(array_keys($input), $allowed)) throw new DomainException('Chỉ được thay đổi thông tin cá nhân cho phép.');
    if ($action === 'profile') {
        $name = managementText($input, 'full_name', 200, true);
        $email = managementText($input, 'email', 254);
        $phone = managementText($input, 'phone', 32);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('Email không hợp lệ.');
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
        if (!userCanSignIn($account)) throw new DomainException('Tài khoản không còn được phép truy cập.');
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
        return ['message'=>$action === 'profile' ? 'Đã cập nhật thông tin cá nhân.' : 'Đã đổi mật khẩu. Các phiên đăng nhập khác sẽ cần đăng nhập lại.',
            'password_version'=>$action === 'password' ? hash('sha256',$newHash) : null];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
