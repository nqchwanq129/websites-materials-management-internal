<?php
declare(strict_types=1);

function findUser(PDO $pdo, string $column, string|int $value): ?array
{
    if (!in_array($column, ['username', 'id'], true)) {
        throw new InvalidArgumentException('Invalid user lookup.');
    }
    $statement = $pdo->prepare("SELECT u.*, e.full_name, e.status AS employee_status, w.name AS employee_warehouse_name
        FROM users u LEFT JOIN employees e ON e.id = u.employee_id
        LEFT JOIN warehouses w ON w.id=e.warehouse_id WHERE u.$column = ? LIMIT 1");
    $statement->execute([$value]);
    return $statement->fetch() ?: null;
}

function userCanSignIn(?array $user): bool
{
    return $user !== null && $user['status'] === 'active'
        && ($user['employee_id'] === null || $user['employee_status'] === 'active');
}

function userSessionMatches(?array $user,array $session): bool
{
    return userCanSignIn($user) && (string)($session['user_id'] ?? '')===(string)$user['id']
        && is_string($session['login_token'] ?? null) && strlen($session['login_token'])===64
        && is_string($user['active_session_hash'] ?? null)
        && hash_equals($user['active_session_hash'],hash('sha256',$session['login_token']))
        && is_string($session['password_version'] ?? null)
        && hash_equals(hash('sha256',$user['password_hash']),$session['password_version']);
}
function userCanAct(?array $user): bool
{
    // Trusted CLI jobs have no browser session; HTTP actors must retain the active login even under a write lock.
    return PHP_SAPI==='cli' && !isset($_SESSION['user_id']) ? userCanSignIn($user) : userSessionMatches($user,$_SESSION ?? []);
}
function activateUserSession(PDO $pdo,string $userId,string $passwordVersion): string
{
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$stmt->execute([$userId]);
        $user=findUser($pdo,'id',$userId);
        if(!userCanSignIn($user) || !hash_equals(hash('sha256',$user['password_hash']),$passwordVersion)) throw new DomainException('Thông tin tài khoản đã thay đổi. Vui lòng đăng nhập lại.');
        $token=bin2hex(random_bytes(32));
        $pdo->prepare('UPDATE users SET active_session_hash=?,updated_at=updated_at WHERE id=?')->execute([hash('sha256',$token),$userId]);
        $pdo->commit();return $token;
    } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function revokeUserSession(PDO $pdo,string $userId,string $token): void
{
    // A replaced browser may log out later; revoke only its own fingerprint, never the newer login.
    $pdo->prepare('UPDATE users SET active_session_hash=NULL,updated_at=updated_at WHERE id=? AND active_session_hash=?')->execute([$userId,hash('sha256',$token)]);
}

function warehousePermission(PDO $pdo, string $userId, string $warehouseId): ?string
{
    $user = findUser($pdo, 'id', $userId);
    if (!userCanSignIn($user)) return null;
    $statement = $pdo->prepare('SELECT status FROM warehouses WHERE id=?');
    $statement->execute([$warehouseId]);
    if ($statement->fetchColumn() !== 'active') return null;
    if ((int) $user['is_super_admin'] === 1) return 'keeper';
    $statement = $pdo->prepare('SELECT role FROM user_warehouses WHERE user_id=? AND warehouse_id=?');
    $statement->execute([$userId, $warehouseId]);
    return $statement->fetchColumn() ?: null;
}

function userAccess(PDO $pdo, string $userId): array
{
    $user = findUser($pdo, 'id', $userId);
    if (!userCanAct($user)) throw new DomainException('Tài khoản không còn quyền truy cập hoặc phiên đăng nhập đã bị thay thế.');
    $access = ['admin'=>(int)$user['is_super_admin'] === 1, 'warehouses'=>[], 'warehouse_names'=>[]];
    if (!$access['admin']) {
        $stmt = $pdo->prepare("SELECT g.warehouse_id,g.role,w.name FROM user_warehouses g JOIN warehouses w ON w.id=g.warehouse_id WHERE g.user_id=? AND w.status='active'");
        $stmt->execute([$userId]);
        foreach ($stmt as $grant) { $access['warehouses'][(string)$grant['warehouse_id']] = $grant['role']; $access['warehouse_names'][(string)$grant['warehouse_id']] = $grant['name']; }
    }
    return $access;
}
function canViewWarehouse(?array $access, string $warehouseId): bool
{
    return $access === null || $access['admin'] || isset($access['warehouses'][$warehouseId]);
}
function canManageWarehouse(?array $access, string $warehouseId): bool
{
    return $access === null || $access['admin'] || ($access['warehouses'][$warehouseId] ?? null) === 'keeper';
}
function canAccessPage(array $access, string $page, bool $write = false): bool
{
    if ($access['admin'] || in_array($page, ['home','profile'], true)) return true;
    if (in_array($page, ['accounts','employees','warehouses','audit'], true)) return false;
    $keeper = in_array('keeper', $access['warehouses'], true);
    if (in_array($page, ['import-create','export-create'], true)) return $keeper;
    if ($write) return $keeper && in_array($page, ['imports','decisions'], true);
    return (bool)$access['warehouses'] && in_array($page, ['products','materials','spare-parts','tools','fixed-assets','other-products','imports','exports','reports','suppliers','decisions',
        'import-file','import-image','export-file','decision-file','import-excel','export-excel','product-excel'], true);
}

function authenticateUser(PDO $pdo, string $username, string $password, string $address, bool $rehash = true): array
{
    $directory = dirname(__DIR__) . '/storage/login-attempts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create login attempt storage.');
    }
    // ponytail: per-IP file lock; use a shared rate-limit store if the app runs on multiple servers.
    $file = fopen($directory . '/' . hash('sha256', $address) . '.json', 'c+');
    if ($file === false) throw new RuntimeException('Cannot open login attempt storage.');
    try {
        if (!flock($file, LOCK_EX)) throw new RuntimeException('Cannot lock login attempt storage.');
        $contents = stream_get_contents($file);
        $attempts = $contents === '' ? ['started' => time(), 'failures' => 0] : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (time() - $attempts['started'] >= 300) $attempts = ['started' => time(), 'failures' => 0];
        if ($attempts['failures'] >= 10) throw new DomainException('Bạn đã thử đăng nhập quá nhiều lần. Vui lòng thử lại sau 5 phút.');

        $user = findUser($pdo, 'username', $username);
        $matches = password_verify($password, $user['password_hash'] ?? '$2y$10$J46D0UXWYwUWE8qRS.RY6.9SQL87DprYfBowahUaJqVKJF0/W2rtO');
        $allowed = $matches && userCanSignIn($user);
        if (!$allowed) $attempts['failures']++;
        $encoded = json_encode($attempts, JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new RuntimeException('Cannot save login attempts.');
        }
        if (!$allowed) throw new DomainException('Tên đăng nhập hoặc mật khẩu không đúng, hoặc tài khoản chưa được phép truy cập.');
        if ($rehash && password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $statement = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?');
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $statement->execute([$newHash, $user['id'], $user['password_hash']]);
            if ($statement->rowCount() === 1) $user['password_hash'] = $newHash;
        }
        $user['password_version'] = hash('sha256', $user['password_hash']);
        unset($user['password_hash'],$user['active_session_hash']);
        return $user;
    } finally {
        fclose($file);
    }
}
