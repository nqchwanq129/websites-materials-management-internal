<?php
declare(strict_types=1);

function findUser(PDO $pdo, string $column, string|int $value): ?array
{
    if (!in_array($column, ['username', 'id'], true)) {
        throw new InvalidArgumentException('Invalid user lookup.');
    }
    $statement = $pdo->prepare("SELECT u.*, e.full_name, e.status AS employee_status
        FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.$column = ? LIMIT 1");
    $statement->execute([$value]);
    return $statement->fetch() ?: null;
}

function userCanSignIn(?array $user): bool
{
    return $user !== null && $user['status'] === 'active'
        && ($user['employee_id'] === null || $user['employee_status'] === 'active');
}

function authenticateUser(PDO $pdo, string $username, string $password, string $address): array
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
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $statement = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?');
            $statement->execute([password_hash($password, PASSWORD_DEFAULT), $user['id'], $user['password_hash']]);
        }
        unset($user['password_hash']);
        return $user;
    } finally {
        fclose($file);
    }
}
