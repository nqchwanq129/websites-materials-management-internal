<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#29235c">
    <title>Đăng nhập · Phần mềm quản lý kho VISHIPEL</title>
    <link rel="icon" href="assets/images/company-logo.png" type="image/png">
    <link rel="stylesheet" href="assets/css/login.css?v=<?= substr(hash_file('sha256', dirname(__DIR__,3) . '/public/assets/css/login.css'),0,12) ?>">
    <script src="assets/js/login.js" defer></script>
</head>
<body>
<svg class="icon-definitions" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="arrow" viewBox="0 0 24 24"><path d="M5 12h14m-5-5 5 5-5 5"/></symbol>
    <symbol id="eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="eye-off" viewBox="0 0 24 24"><path d="m3 3 18 18M10.5 5.1 12 5c6.5 0 10 7 10 7a19 19 0 0 1-3 4M6 6a19 19 0 0 0-4 6s3.5 7 10 7a12 12 0 0 0 5-1M10 10a3 3 0 0 0 4 4"/></symbol>
    <symbol id="lock" viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/></symbol>
    <symbol id="check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></symbol>
    <symbol id="help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4m0 3h.01"/></symbol>
</svg>
<main class="login-layout">
    <section class="login-panel" aria-labelledby="login-title">
        <div class="login-content">
            <a class="brand" href="./" aria-label="VISHIPEL — Trang đăng nhập">
                <span class="brand-logo"><img src="assets/images/company-logo.png" alt="VISHIPEL" width="500" height="500"></span>
                <strong class="brand-title">PHẦN MỀM QUẢN LÝ KHO VISHIPEL</strong>
                <span class="brand-company">CÔNG TY TNHH MTV THÔNG TIN ĐIỆN TỬ HÀNG HẢI VIỆT NAM</span>
            </a>
            <h1 id="login-title">Chào mừng trở lại<span>.</span></h1>
            <p class="intro">Đăng nhập để tiếp tục công việc của bạn.</p>

            <form id="login-form" method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
                <div class="form-field">
                    <label for="username">Tên đăng nhập</label>
                    <input id="username" name="username" type="text" placeholder="Nhập tên đăng nhập" value="<?= escapeHtml($loginUsername) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="100" required>
                </div>
                <div class="form-field">
                    <div class="label-row"><label for="password">Mật khẩu</label><button class="text-button" id="help-open" type="button" aria-haspopup="dialog" aria-controls="login-help">Quên mật khẩu?</button></div>
                    <div class="password-field">
                        <input id="password" name="password" type="password" placeholder="Nhập mật khẩu" autocomplete="current-password" required>
                        <button class="password-toggle" id="password-toggle" type="button" aria-label="Hiện mật khẩu" aria-pressed="false" aria-controls="password" hidden><svg class="icon"><use href="#eye"/></svg></button>
                    </div>
                </div>
                <button class="submit-button" type="submit">Đăng nhập<svg class="icon"><use href="#arrow"/></svg></button>
                <p id="login-notice" class="form-notice" role="alert" <?= $loginError !== '' ? '' : 'hidden' ?>><?= escapeHtml($loginError) ?></p>
            </form>

            <p class="account-note"><svg class="icon"><use href="#lock"/></svg>Tài khoản được cấp bởi quản trị viên.</p>
            <div class="support-line"><span>Cần hỗ trợ đăng nhập?</span><button class="text-button" id="support-open" type="button" aria-haspopup="dialog" aria-controls="login-help">Hướng dẫn hỗ trợ<svg class="icon"><use href="#arrow"/></svg></button></div>
        </div>

        <footer class="login-footer"><span>© <?= date('Y') ?> VISHIPEL</span><span class="internal-label"><span class="status-dot"></span> Dành cho nhân viên thuộc Vishipel</span></footer>
    </section>

    <aside class="showcase" aria-labelledby="showcase-title">
        <div class="welcome-copy">
            <span class="showcase-kicker">HỆ THỐNG NỘI BỘ</span>
            <h2 id="showcase-title">Quản lý vật tư<br><span>gọn gàng, hiệu quả.</span></h2>
            <p>Theo dõi hàng hóa, phiếu nhập xuất và báo cáo trên cùng một hệ thống.</p>
        </div>
        <div class="scene" aria-hidden="true">
            <div class="scene-orbit"></div><div class="scene-orbit orbit-inner"></div>
            <div class="scene-card card-back"><i></i><i></i><i></i></div>
            <div class="scene-card card-front"><b><svg class="icon"><use href="#check"/></svg></b><i></i><i></i><i></i></div>
            <div class="scene-box box-one"></div><div class="scene-box box-two"></div>
        </div>
        <div class="showcase-footer">VISHIPEL · Quản lý vật tư</div>
    </aside>
</main>

<dialog id="login-help" aria-labelledby="help-title" aria-describedby="help-description">
    <div class="help-icon"><svg class="icon"><use href="#help"/></svg></div>
    <h2 id="help-title">Hỗ trợ đăng nhập</h2>
    <p id="help-description">Nếu bạn chưa có tài khoản hoặc quên mật khẩu, hãy liên hệ quản trị viên của đơn vị để được cấp tài khoản hoặc đặt lại mật khẩu.</p>
    <form method="dialog"><button class="submit-button" type="submit" autofocus>Đã hiểu</button></form>
</dialog>
</body>
</html>
