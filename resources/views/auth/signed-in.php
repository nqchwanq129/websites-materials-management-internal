<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập thành công · VISHIPEL</title>
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>
<main class="signed-in-panel">
    <div class="brand"><img src="assets/images/company-logo.png" alt="VISHIPEL" width="500" height="500"></div>
    <h1>Đăng nhập thành công<span>.</span></h1>
    <p class="intro">Xin chào, <?= escapeHtml($user['full_name'] ?? $user['username']) ?>.</p>
    <p class="account-note"><?= (int) $user['is_super_admin'] === 1 ? 'Quản trị viên hệ thống' : 'Nhân viên' ?></p>
    <form action="index.php?page=logout" method="post">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
        <button class="submit-button" type="submit">Đăng xuất</button>
    </form>
</main>
</body>
</html>
