<?php
declare(strict_types=1);

// Run against local XAMPP: php tests/auth.php. Temporary users are removed in finally.
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/auth.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$base = getenv('LOGIN_URL') ?: 'http://localhost/qlvt-remake/public/';
$cookie = '';
function request(string $path = '', ?array $fields = null): array
{
    global $base, $cookie;
    $context = stream_context_create(['http' => [
        'method' => $fields === null ? 'GET' : 'POST',
        'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => $fields === null ? '' : http_build_query($fields),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    if ($body === false) throw new RuntimeException('Cannot reach XAMPP.');
    $headers = $http_response_header;
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: (qlvt_session=[^;]*)/i', $header, $match)) $cookie = $match[1];
    }
    preg_match('/\s(\d{3})\s/', $headers[0], $match);
    return [(int) $match[1], $body, $headers];
}

function csrf(string $body): string
{
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $body, $match);
    check(isset($match[1]), 'Missing CSRF token.');
    return $match[1];
}

$pdo = connectDatabase();
$username = 'auth-check-' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$address = 'auth-check-' . bin2hex(random_bytes(8));
$rateFile = dirname(__DIR__) . '/storage/login-attempts/' . hash('sha256', $address) . '.json';
$userId = null;

try {
    $statement = $pdo->prepare('INSERT INTO users (username,password_hash,is_super_admin) VALUES (?,?,1)');
    $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = $pdo->lastInsertId();
    $user = authenticateUser($pdo, $username, $password, $address);
    check((string) $user['id'] === $userId && !isset($user['password_hash']), 'Authentication must return the right user without a password hash.');
    for ($i = 0; $i < 10; $i++) {
        try {
            authenticateUser($pdo, $username, 'wrong-password', $address);
            throw new RuntimeException('Invalid password accepted.');
        } catch (DomainException $error) {
            check(!str_contains($error->getMessage(), '5 phút'), 'Rate limit applied too early.');
        }
    }
    try {
        authenticateUser($pdo, $username, $password, $address);
        throw new RuntimeException('Rate limit failed.');
    } catch (DomainException $error) {
        check(str_contains($error->getMessage(), '5 phút'), 'Rate limit should reject attempts after 10 failures.');
    }
    file_put_contents($rateFile, json_encode(['started' => time() - 301, 'failures' => 10]));
    check(authenticateUser($pdo, $username, $password, $address)['username'] === $username, 'Expired rate limit should allow login.');
    check(!userCanSignIn(['status' => 'active', 'employee_id' => 1, 'employee_status' => 'inactive']), 'Inactive employees must not sign in.');

    [$status, $body, $headers] = request();
    check($status === 200, 'Login page failed.');
    $token = csrf($body);
    $beforeLogin = $cookie;
    check(str_contains(implode('\n', $headers), 'HttpOnly') && str_contains(implode('\n', $headers), 'SameSite=Lax'), 'Session cookie must be protected.');
    check(request('index.php?page=home')[0] === 303, 'Guests must not access the protected page.');
    check(request('index.php?page=accounts')[0] === 303, 'Guests must not access account management.');
    check(request('index.php?page=employees')[0] === 303, 'Guests must not access employee management.');
    check(request('index.php?page=warehouses')[0] === 303, 'Guests must not access warehouse management.');
    check(request('index.php?page=suppliers')[0] === 303, 'Guests must not access suppliers.');
    check(request('index.php?page=decisions')[0] === 303, 'Guests must not access decisions.');
    check(request('index.php?page=reports')[0] === 303, 'Guests must not access reports.');
    check(request('index.php?page=products')[0] === 303, 'Guests must not access products.');
    check(request('index.php?page=imports')[0] === 303, 'Guests must not access imports.');
    check(request('index.php?page=import-create')[0] === 303, 'Guests must not create imports.');
    foreach (['materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products'] as $route) check(request('index.php?page=' . $route)[0] === 303, 'Guests must not access category pages.');
    check(request('index.php?page=audit')[0] === 303, 'Guests must not access audit logs.');
    check(request('index.php', ['username' => $username, 'password' => $password])[0] === 303, 'Missing CSRF token should redirect to login.');
    [$status, $body] = request('index.php?page=home');
    check($status === 303, 'Missing CSRF token must not create a login session.');
    request('index.php', ['username' => $username, 'password' => $password, 'csrf_token' => $token]);
    check($cookie !== $beforeLogin, 'Successful login must rotate the session ID.');
    [$status, $body] = request('index.php?page=home');
    check($status === 200 && str_contains($body, 'TỔNG QUAN HOẠT ĐỘNG') && str_contains($body, $username) && !str_contains($body, $password), 'Protected dashboard did not authenticate correctly.');
    check(str_contains($body,'Dữ liệu thực tế') && !str_contains($body,'Số liệu minh họa'),'Dashboard must read real database data.');
    $logoutToken = csrf($body);
    check(request('index.php?page=accounts')[0] === 200, 'Super admin should access account UI.');
    check(request('index.php?page=employees')[0] === 200, 'Super admin should access employee UI.');
    check(request('index.php?page=warehouses')[0] === 200, 'Super admin should access warehouse UI.');
    check(request('index.php?page=suppliers')[0] === 200, 'Super admin should access suppliers.');
    check(request('index.php?page=decisions')[0] === 200, 'Super admin should access decisions.');
    check(request('index.php?page=products')[0] === 200, 'Admin should access products.');
    check(request('index.php?page=imports')[0] === 200, 'Admin should access imports.');
    foreach (['exports','export-create'] as $route) check(request('index.php?page='.$route)[0]===200,'Admin should access export pages.');
    check(request('index.php?page=exports',['csrf_token'=>$logoutToken])[0]===405,'Export list must reject writes.');
    check(request('index.php?page=export-file&id=999999999')[0]===404,'Missing export PDF must return 404.');
    check(request('index.php?page=import-create')[0] === 200, 'Admin should access receipt creation.');
    check(request('index.php?page=imports', ['csrf_token' => $logoutToken])[0] === 405, 'Import list must reject writes.');
    foreach (['materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products'] as $route) check(request('index.php?page=' . $route)[0] === 200, 'Admin should access category pages.');
    check(request('index.php?page=products', ['csrf_token' => $logoutToken])[0] === 422, 'Invalid product edit must fail.');
    foreach (['inventory', 'materials', 'parts', 'tools', 'assets'] as $kind) {
        [$reportStatus, $reportBody] = request('index.php?page=reports&kind=' . $kind);
        check($reportStatus === 200 && str_contains($reportBody, 'Dữ liệu thực tế') && str_contains($reportBody, 'id="report-data"') && !str_contains($reportBody, 'Dữ liệu mẫu'), 'Real report route failed.');
    }
    check(request('index.php?page=reports', ['csrf_token' => $logoutToken])[0] === 405, 'Reports must reject writes.');
    check(request('index.php?page=decisions', ['csrf_token' => $logoutToken])[0] === 422, 'Invalid decision writes must be rejected.');
    check(request('index.php?page=decision-file&id=1')[0] === 404, 'Missing PDF must return 404.');
    check(request('index.php?page=suppliers', ['csrf_token' => $logoutToken])[0] === 405, 'Suppliers must be read-only.');
    check(request('index.php?page=audit')[0] === 200, 'Super admin should access audit UI.');
    check(request('index.php?page=accounts', ['csrf_token' => $logoutToken])[0] === 422, 'Invalid account writes must be rejected.');
    check(request('index.php?page=employees', ['csrf_token' => $logoutToken])[0] === 422, 'Invalid employee writes must be rejected.');
    check(request('index.php?page=audit', ['csrf_token' => $logoutToken])[0] === 405, 'Audit history must reject writes.');
    $pdo->prepare('UPDATE users SET is_super_admin=0 WHERE id=?')->execute([$userId]);
    [, $homeBody]=request('index.php?page=home');
    check(!str_contains($homeBody,'data-receipt ') && !str_contains($homeBody,'class="heading-actions"'),'Home must not expose records or create shortcuts without warehouse grants.');
    check(request('index.php?page=accounts')[0] === 403, 'Non-admin users must not access account management.');
    check(request('index.php?page=employees')[0] === 403, 'Non-admin users must not access employee management.');
    check(request('index.php?page=warehouses')[0] === 403, 'Non-admin users must not access warehouse management.');
    check(request('index.php?page=suppliers')[0] === 403, 'Non-admin users must not access suppliers.');
    check(request('index.php?page=decisions')[0] === 403, 'Non-admin users must not access decisions.');
    check(request('index.php?page=reports')[0] === 403, 'Non-admin report access must be blocked.');
    check(request('index.php?page=products')[0] === 403, 'Non-admin product access must be blocked.');
    check(request('index.php?page=imports')[0] === 403, 'Non-admin import access must be blocked.');
    foreach (['exports','export-create','export-file&id=999999999'] as $route) check(request('index.php?page='.$route)[0]===403,'Non-admin export access must be blocked.');
    check(request('index.php?page=import-create')[0] === 403, 'Non-admin receipt creation must be blocked.');
    foreach (['materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products'] as $route) check(request('index.php?page=' . $route)[0] === 403, 'Non-admin category access must be blocked.');
    check(request('index.php?page=warehouses', ['csrf_token' => $logoutToken, 'action' => 'save'])[0] === 403, 'Non-admin users must not change warehouses.');
    check(request('index.php?page=audit')[0] === 403, 'Non-admin users must not access audit logs.');
    $pdo->prepare('UPDATE users SET is_super_admin=1 WHERE id=?')->execute([$userId]);
    check(request('index.php?page=logout')[0] === 403, 'GET must not log out.');
    check(request('index.php?page=logout', ['csrf_token' => 'invalid'])[0] === 403, 'Invalid CSRF must not log out.');

    $statement = $pdo->prepare("UPDATE users SET status='locked' WHERE id=?");
    $statement->execute([$userId]);
    check(request('index.php?page=home')[0] === 303, 'Locked users must lose access immediately.');
    request('index.php', ['username' => $username, 'password' => $password, 'csrf_token' => $logoutToken]);
    check(request('index.php?page=home')[0] === 303, 'Locked account must not sign in.');
    $statement = $pdo->prepare("UPDATE users SET status='active' WHERE id=?");
    $statement->execute([$userId]);
    [$status, $body] = request();
    request('index.php', ['username' => $username, 'password' => $password, 'csrf_token' => csrf($body)]);
    [$status, $body] = request('index.php?page=home');
    check($status === 200, 'Active account should sign in again.');
    check(request('index.php?page=logout', ['csrf_token' => csrf($body)])[0] === 303, 'Valid logout failed.');
    check(request('index.php?page=home')[0] === 303, 'Logged-out session must not access the protected page.');
    check(request('../.env')[0] === 403, 'Environment file must not be accessible over HTTP.');
    check(request('../config/database.php')[0] === 403, 'Configuration must not be accessible over HTTP.');
    echo "PASS: database authentication, rate limits, CSRF, session rotation, locked users, logout, private files.\n";
} finally {
    if ($userId !== null) {
        $statement = $pdo->prepare('DELETE FROM users WHERE id=? AND username=?');
        $statement->execute([$userId, $username]);
    }
    if (is_file($rateFile)) unlink($rateFile);
}
