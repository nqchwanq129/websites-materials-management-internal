<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'login';
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($page, ['login', 'home', 'profile', 'notifications', 'exports', 'export-create', 'export-file', 'export-excel', 'imports', 'import-create', 'import-file', 'import-image', 'import-excel', 'product-excel', 'products', 'materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products', 'accounts', 'employees', 'warehouses', 'suppliers', 'decisions', 'decision-file', 'reports', 'audit', 'logout'], true)) {
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
    if(isset($_SESSION['user_id']) && is_string($_SESSION['login_token'] ?? null)) {
        try {revokeUserSession(connectDatabase(),(string)$_SESSION['user_id'],$_SESSION['login_token']);}
        catch(Throwable $error) {error_log('QLVT logout: '.$error->getMessage());}
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
        if(!session_regenerate_id(true)) throw new RuntimeException('Cannot rotate login session.');
        $loginToken=activateUserSession(connectDatabase(),(string)$user['id'],$user['password_version']);
        $_SESSION = ['user_id' => $user['id'], 'csrf_token' => bin2hex(random_bytes(32)), 'last_activity' => time(), 'password_version' => $user['password_version'], 'login_token'=>$loginToken];
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
        $sessionError='Phiên đăng nhập đã kết thúc. Vui lòng đăng nhập lại.';
        if (time() - ($_SESSION['last_activity'] ?? 0) < 1800) {
            $user = findUser(connectDatabase(), 'id', $_SESSION['user_id']);
            if ($user!==null && is_string($_SESSION['login_token'] ?? null) && is_string($user['active_session_hash'] ?? null)
                && !hash_equals($user['active_session_hash'],hash('sha256',$_SESSION['login_token']))) $sessionError='Tài khoản đã được đăng nhập ở nơi khác. Vui lòng đăng nhập lại để sử dụng trên máy này.';
            if(!userSessionMatches($user,$_SESSION))$user=null;
        }
        if (!userCanSignIn($user)) {
            unset($_SESSION['user_id'], $_SESSION['last_activity'],$_SESSION['login_token'],$_SESSION['password_version']);
            $_SESSION['login_error'] = $sessionError;
            $user = null;
        } else {
            if ($page !== 'notifications' || $method === 'POST') $_SESSION['last_activity'] = time();
        }
    } catch (Throwable $error) {
        error_log('QLVT session: ' . $error->getMessage());
        http_response_code(503);
        exit('Hệ thống tạm thời không khả dụng. Vui lòng thử lại sau.');
    }
}
if ($page === 'notifications') {
    if ($user === null) respondJson(['ok'=>false,'message'=>$_SESSION['login_error'] ?? 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'],401);
    if ($method === 'POST' && !validCsrfToken()) respondJson(['ok'=>false,'message'=>'Phiên xác nhận không hợp lệ. Hãy tải lại trang.'],403);
    session_write_close();
    require_once dirname(__DIR__).'/app/activity-notifications.php';
    try {
        if ($method === 'POST') {
            if (($_POST['action'] ?? '') !== 'read') throw new DomainException('Thao tác thông báo không hợp lệ.');
            readActivityNotification(connectDatabase(),(string)$user['id'],$_POST['id'] ?? null);
            respondJson(['ok'=>true]);
        }
        respondJson(['ok'=>true]+loadActivityNotifications(connectDatabase(),(string)$user['id'],$_GET));
    } catch (DomainException $error) { respondJson(['ok'=>false,'message'=>$error->getMessage()],422); }
    catch (Throwable $error) { error_log('QLVT notifications: '.$error->getMessage()); respondJson(['ok'=>false,'message'=>'Không tải được thông báo. Vui lòng thử lại.'],503); }
}
if ($page === 'profile') {
    if ($user === null) respondJson(['ok'=>false,'message'=>'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'],401);
    if ($method === 'POST' && !validCsrfToken()) respondJson(['ok'=>false,'message'=>'Phiên xác nhận không hợp lệ. Hãy tải lại trang.'],403);
    try {
        if ($method === 'GET') respondJson(['ok'=>true,'profile'=>loadOwnProfile(connectDatabase(),(string)$user['id'])]);
        $saved = saveOwnProfile(connectDatabase(),(string)$user['id'],$_POST,$_SERVER['REMOTE_ADDR']);
        if (($_POST['action'] ?? '') === 'password') {
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['password_version'] = $saved['password_version'];
        }
        respondJson(['ok'=>true,'message'=>$saved['message'],'csrf_token'=>$_SESSION['csrf_token'],'profile'=>loadOwnProfile(connectDatabase(),(string)$user['id'])]);
    } catch (DomainException $error) { respondJson(['ok'=>false,'message'=>$error->getMessage()],422); }
    catch (Throwable $error) { error_log('QLVT profile: '.$error->getMessage()); respondJson(['ok'=>false,'message'=>'Không thể cập nhật lúc này. Vui lòng tải lại trang và kiểm tra trước khi thử lại.'],503); }
}
if (in_array($page, ['home', 'exports', 'export-create', 'export-file', 'export-excel', 'imports', 'import-create', 'import-file', 'import-image', 'import-excel', 'product-excel', 'products', 'materials', 'spare-parts', 'tools', 'fixed-assets', 'other-products', 'accounts', 'employees', 'warehouses', 'suppliers', 'decisions', 'decision-file', 'reports', 'audit'], true)) {
    if ($user === null) {
        if ($method === 'POST' && $page !== 'home') respondJson(['ok' => false, 'message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], 401);
        redirectTo('login');
    }
    try { $access = userAccess(connectDatabase(), (string)$user['id']); }
    catch (Throwable $error) { error_log('QLVT access: '.$error->getMessage()); http_response_code(503); exit('Không thể kiểm tra quyền truy cập lúc này.'); }
    if (!canAccessPage($access, $page, $method === 'POST')) {
        if ($method === 'POST') respondJson(['ok' => false, 'message' => 'Bạn không có quyền quản lý dữ liệu này.'], 403);
        http_response_code(403);
        exit('Bạn không có quyền truy cập trang quản lý này.');
    }
    if ($page === 'home' && $method === 'POST') { header('Allow: GET'); respondJson(['ok'=>false,'message'=>'Tổng quan chỉ hỗ trợ tra cứu.'],405); }
    if ($page !== 'home' && $method === 'POST') {
        if ($page === 'reports') { header('Allow: GET'); respondJson(['ok' => false, 'message' => 'Báo cáo chỉ hỗ trợ tra cứu.'], 405); }
        if ($page === 'exports' || ($page === 'imports' && !in_array($_POST['action'] ?? '', ['update','delete','cancel','confirm'], true))) { header('Allow: GET'); respondJson(['ok' => false, 'message' => 'Thao tác phiếu không được hỗ trợ.'], 405); }
        if ($page === 'audit') { header('Allow: GET'); respondJson(['ok' => false, 'message' => 'Nhật ký chỉ được đọc.'], 405); }
        if (in_array($page, ['decision-file', 'import-file', 'import-image', 'export-file', 'import-excel', 'export-excel', 'product-excel'], true)) { header('Allow: GET'); respondJson(['ok' => false, 'message' => 'Chỉ hỗ trợ tải file.'], 405); }
        if ($page === 'suppliers') { header('Allow: GET'); respondJson(['ok' => false, 'message' => 'Thông tin nhà cung cấp được cập nhật từ phiếu nhập kho.'], 405); }
        if (!validCsrfToken()) respondJson(['ok' => false, 'message' => 'Phiên xác nhận không hợp lệ. Hãy tải lại trang.'], 403);
        try {
            if ($page === 'import-create' && ($_POST['action'] ?? '') === 'xml') {
                $file = $_FILES['xml'] ?? null;
                if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
                    || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > 2 * 1024 * 1024
                    || strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)) !== 'xml') throw new DomainException('Chọn file XML hợp lệ, tối đa 2 MB.');
                respondJson(['ok' => true, 'invoice' => parseImportXml(file_get_contents($file['tmp_name']))]);
            }
            $message = saveManagement(connectDatabase(), $page, (string) $user['id'], $_POST, $_FILES);
            $_SESSION['management_notice'] = $message;
            respondJson(['ok' => true, 'message' => $message]);
        } catch (DomainException $error) {
            respondJson(['ok' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('QLVT management: ' . $error->getMessage());
            respondJson(['ok' => false, 'message' => 'Không thể lưu lúc này. Dữ liệu chưa bị thay đổi. Vui lòng thử lại.'], 503);
        }
    }
    if ($page === 'product-excel') {
        require_once dirname(__DIR__) . '/app/stock-book-excel.php';
        try { downloadStockBookExcel(connectDatabase(),$_GET,(string)$user['id']); }
        catch (DomainException $error) { http_response_code(400); exit(escapeHtml($error->getMessage())); }
        catch (Throwable $error) { error_log('QLVT stock book Excel: '.$error->getMessage()); http_response_code(503); exit('Không thể xuất sổ Excel lúc này. Vui lòng thử lại sau.'); }
    }
    if (in_array($page, ['import-excel', 'export-excel'], true)) {
        require_once dirname(__DIR__) . '/app/receipt-excel.php';
        try { downloadReceiptExcel(connectDatabase(), $page === 'import-excel' ? 'import' : 'export', $_GET['id'] ?? null, (string)$user['id']); }
        catch (DomainException $error) { http_response_code(400); exit(escapeHtml($error->getMessage())); }
        catch (Throwable $error) { error_log('QLVT receipt Excel: ' . $error->getMessage()); http_response_code(503); exit('Không thể xuất Excel lúc này. Vui lòng thử lại sau.'); }
    }
    if ($page === 'import-image') {
        try { serveAttachment(connectDatabase(), $_GET['id'] ?? null, 'import_bill_detail_id', (string)$user['id']); }
        catch (Throwable $error) { error_log('QLVT import image: '.$error->getMessage()); http_response_code(503); exit('Không thể tải ảnh lúc này.'); }
    }
    if ($page === 'import-file') {
        try { serveAttachment(connectDatabase(), $_GET['id'] ?? null, 'import_bill_id', (string)$user['id'], ($_GET['preview'] ?? '') === '1'); }
        catch (Throwable $error) { error_log('QLVT import PDF: ' . $error->getMessage()); http_response_code(503); exit('Không thể tải file lúc này.'); }
    }
    if ($page === 'export-file') {
        try { serveAttachment(connectDatabase(), $_GET['id'] ?? null, 'export_bill_id', (string)$user['id']); }
        catch (Throwable $error) { error_log('QLVT export PDF: ' . $error->getMessage()); http_response_code(503); exit('Không thể tải file lúc này.'); }
    }
    if ($page === 'decision-file') {
        try { downloadDecisionPdf(connectDatabase(), $_GET['id'] ?? null, (string)$user['id']); }
        catch (Throwable $error) { error_log('QLVT PDF: ' . $error->getMessage()); http_response_code(503); exit('Không thể tải file lúc này.'); }
    }
    if ($page === 'reports') {
        require_once dirname(__DIR__) . '/app/reports.php';
        try { $reportData=loadReportData(connectDatabase(),(string)$user['id']); }
        catch(Throwable $error) { error_log('QLVT reports read: '.$error->getMessage()); http_response_code(503); exit('Không thể tải số liệu báo cáo lúc này. Vui lòng thử lại sau.'); }
        $reportError = '';
        try { $filters = reportFilters($_GET, $reportData); $report = buildReport($reportData, $filters); }
        catch (DomainException $error) {
            $reportError = $error->getMessage(); $filters = reportFilters([], $reportData);
            $report = ['rows' => [], 'summary' => array_fill_keys(['opening_value', 'incoming_value', 'outgoing_value', 'closing_value'], '0'), 'categories' => [], 'months' => [], 'units' => [], 'unknown_count'=>0, 'reconciliation'=>[]];
            http_response_code(422);
        }
        require dirname(__DIR__) . '/resources/views/reports/index.php';
        exit;
    }
    if ($page === 'home') {
        require_once dirname(__DIR__) . '/app/dashboard.php';
        try { $dashboardData = loadDashboard(connectDatabase(), (string)$user['id']); }
        catch (Throwable $error) {
            error_log('QLVT dashboard read: '.$error->getMessage());
            http_response_code(503); exit('Không thể tải số liệu tổng quan lúc này. Vui lòng thử lại sau.');
        }
    } else {
        try { $managementData = loadManagement(connectDatabase(), $page, (string)$user['id']); }
        catch (Throwable $error) {
            error_log('QLVT management read: ' . $error->getMessage());
            http_response_code(503);
            exit('Không thể tải dữ liệu lúc này. Vui lòng thử lại sau.');
        }
        $managementNotice = $_SESSION['management_notice'] ?? '';
        unset($_SESSION['management_notice']);
    }
    require dirname(__DIR__) . '/resources/views/' . ($page === 'home' ? 'dashboard/home.php' : $page . '/index.php');
    exit;
}
if ($user !== null) redirectTo('home');
$loginError = $_SESSION['login_error'] ?? '';
$loginUsername = $_SESSION['login_username'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_username']);
require dirname(__DIR__) . '/resources/views/auth/login.php';
