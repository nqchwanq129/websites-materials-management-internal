<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'login';
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($page, ['login', 'home', 'accounts', 'employees', 'audit', 'logout'], true)) {
    http_response_code(404);
    exit('Trang không tồn tại.');
}
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Phương thức không được hỗ trợ.');
}
if ($page === 'logout') {
    if ($method !== 'POST' || !validCsrfToken()) {
        http_response_code(403);
        exit('Yêu cầu đăng xuất không hợp lệ.');
    }
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'],
        'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => $cookie['samesite']]);
    session_destroy();
    redirectTo('login');
}
if ($page === 'login' && $method === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    try {
        if (!validCsrfToken()) throw new DomainException('Phiên xác nhận đã hết hạn. Vui lòng thử lại.');
        if ($username === '' || mb_strlen($username) > 100 || $password === '' || strlen($password) > 4096) {
            throw new DomainException('Vui lòng kiểm tra tên đăng nhập và mật khẩu.');
        }
        $user = authenticateUser(connectDatabase(), $username, $password, $_SERVER['REMOTE_ADDR']);
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $user['id'], 'csrf_token' => bin2hex(random_bytes(32)), 'last_activity' => time()];
        redirectTo('home');
    } catch (DomainException $error) {
        $_SESSION['login_error'] = $error->getMessage();
    } catch (Throwable $error) {
        error_log('QLVT login: ' . $error->getMessage());
        $_SESSION['login_error'] = 'Không thể đăng nhập lúc này. Vui lòng thử lại sau.';
    }
    $_SESSION['login_username'] = mb_substr($username, 0, 100);
    redirectTo('login');
}
$user = null;
if (isset($_SESSION['user_id'])) {
    try {
        if (time() - ($_SESSION['last_activity'] ?? 0) < 1800) {
            $user = findUser(connectDatabase(), 'id', $_SESSION['user_id']);
        }
        if (!userCanSignIn($user)) {
            unset($_SESSION['user_id'], $_SESSION['last_activity']);
            $_SESSION['login_error'] = 'Phiên đăng nhập đã kết thúc. Vui lòng đăng nhập lại.';
            $user = null;
        } else {
            $_SESSION['last_activity'] = time();
        }
    } catch (Throwable $error) {
        error_log('QLVT session: ' . $error->getMessage());
        http_response_code(503);
        exit('Hệ thống tạm thời không khả dụng. Vui lòng thử lại sau.');
    }
}
if (in_array($page, ['home', 'accounts', 'employees', 'audit'], true)) {
    if ($user === null) redirectTo('login');
    if ($page !== 'home' && (int) $user['is_super_admin'] !== 1) {
        http_response_code(403);
        exit('Bạn không có quyền truy cập trang quản lý này.');
    }
    if ($page !== 'home' && $method !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        exit('Trang giao diện mẫu chưa hỗ trợ lưu dữ liệu.');
    }
    require dirname(__DIR__) . '/resources/views/' . ($page === 'home' ? 'dashboard/home.php' : $page . '/index.php');
    exit;
}
if ($user !== null) redirectTo('home');
$loginError = $_SESSION['login_error'] ?? '';
$loginUsername = $_SESSION['login_username'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_username']);
require dirname(__DIR__) . '/resources/views/auth/login.php';
