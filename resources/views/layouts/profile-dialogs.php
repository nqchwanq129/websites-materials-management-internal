<?php if (isset($ownProfilePreview)): ?><script type="application/json" id="own-profile-preview"><?= json_encode($ownProfilePreview,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) ?></script><?php endif; ?>
<div id="user-menu" class="user-menu" popover="auto" aria-label="Tài khoản của tôi">
    <div class="user-menu-heading"><span class="avatar" data-user-initial><?= $avatarContent ?></span><div><strong data-user-name><?= escapeHtml($displayName) ?></strong><small><?= escapeHtml($user['username']) ?></small></div></div>
    <button type="button" data-open-profile="profile"><?= $icon('users') ?>Thông tin cá nhân</button>
    <button type="button" data-open-profile="password"><?= $icon('shield') ?>Đổi mật khẩu</button>
    <form action="index.php?page=logout" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><button type="submit"><?= $icon('logout') ?>Đăng xuất</button></form>
</div>
<dialog id="profile-dialog" class="account-dialog personal-dialog" aria-labelledby="profile-title">
    <form id="profile-form" action="index.php?page=profile" method="post" data-preview="<?= ($previewOnly ?? false) ? 'true' : 'false' ?>">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="profile"><input type="hidden" name="version">
        <header class="account-dialog-heading"><div><span class="eyebrow">TÀI KHOẢN CỦA TÔI</span><h2 id="profile-title">Thông tin cá nhân</h2><p>Cập nhật thông tin liên hệ của bạn.</p></div><button class="icon-button" type="button" data-close-personal aria-label="Đóng"><?= $icon('close') ?></button></header>
        <div class="account-dialog-body">
            <p data-profile-loading role="status">Đang tải thông tin cá nhân…</p>
            <p class="form-error" data-form-error role="alert" hidden></p><a class="button" href="index.php?page=login" data-profile-login hidden>Đăng nhập lại</a>
            <div class="personal-identity"><span class="avatar" data-profile-initial><?= $avatarContent ?></span><div><strong data-profile-name><?= escapeHtml($displayName) ?></strong><small data-profile-role></small><div class="personal-avatar-actions"><button class="button" type="button" data-change-avatar disabled>Đổi ảnh</button><button class="button" type="button" data-remove-avatar hidden>Gỡ ảnh</button><input id="personal-avatar-file" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Chọn ảnh đại diện" hidden></div><small>JPG, PNG hoặc WebP · tối đa 10 MB. Ảnh được lưu ngay.</small><span data-avatar-status role="status"></span></div></div>
            <div class="account-form-grid personal-readonly"><label>Tên đăng nhập<input data-profile-username readonly aria-readonly="true"></label><label>Mã nhân viên<input data-profile-code readonly aria-readonly="true"></label><label class="personal-wide">Kho làm việc<input data-profile-warehouse readonly aria-readonly="true"></label></div>
            <fieldset id="profile-fields" disabled>
                <section class="personal-contact"><h3>Thông tin liên hệ</h3><div class="account-form-grid"><label class="personal-wide">Họ và tên <span>*</span><input name="full_name" required maxlength="200" autocomplete="name"></label><label>Email<input name="email" type="email" maxlength="254" autocomplete="email" placeholder="ten@example.com"></label><label>Số điện thoại<input name="phone" type="tel" maxlength="32" autocomplete="tel" placeholder="Nhập số điện thoại"></label></div></section>
            </fieldset>
            <p class="personal-note" data-profile-unlinked hidden>Tài khoản chưa liên kết nhân viên. Vui lòng liên hệ quản trị viên để bổ sung hồ sơ. Bạn vẫn có thể đổi ảnh đại diện và mật khẩu.</p>
            <p class="personal-note">Kho làm việc, mã nhân viên, tên đăng nhập và quyền truy cập do quản trị viên quản lý.</p>
        </div>
        <footer class="account-dialog-footer"><button class="button" type="button" data-close-personal>Hủy</button><button class="button button-primary" type="submit" disabled>Lưu thay đổi</button></footer>
    </form>
</dialog>
<dialog id="password-dialog" class="account-dialog personal-dialog" aria-labelledby="password-title">
    <form id="password-form" action="index.php?page=profile" method="post" data-preview="<?= ($previewOnly ?? false) ? 'true' : 'false' ?>">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="password">
        <header class="account-dialog-heading"><div><span class="eyebrow">BẢO MẬT TÀI KHOẢN</span><h2 id="password-title">Đổi mật khẩu</h2><p>Bảo vệ tài khoản và thông tin của bạn.</p></div><button class="icon-button" type="button" data-close-personal aria-label="Đóng"><?= $icon('close') ?></button></header>
        <div class="account-dialog-body">
            <p class="form-error" data-form-error role="alert" hidden></p><a class="button" href="index.php?page=login" data-profile-login hidden>Đăng nhập lại</a>
            <div class="personal-security-note"><?= $icon('shield') ?><p>Mật khẩu mới phải có ít nhất 8 ký tự và khác mật khẩu hiện tại. Sau khi đổi, các phiên đăng nhập khác sẽ cần đăng nhập lại.</p></div>
            <div class="account-form-grid personal-password-grid">
                <?php foreach (['current_password'=>['Mật khẩu hiện tại','current-password',4096], 'password'=>['Mật khẩu mới','new-password',72], 'password_confirmation'=>['Nhập lại mật khẩu mới','new-password',72]] as $field=>[$label,$autocomplete,$maximum]): ?>
                <label><?= $label ?> <span>*</span><span class="personal-password-control"><input id="personal-<?= $field ?>" name="<?= $field ?>" type="password" required<?= $field==='current_password'?'':' minlength="8"' ?> maxlength="<?= $maximum ?>" autocomplete="<?= $autocomplete ?>"><button type="button" data-personal-password-toggle aria-controls="personal-<?= $field ?>" aria-label="Hiện <?= mb_strtolower($label) ?>" aria-pressed="false">Hiện</button></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <footer class="account-dialog-footer"><button class="button" type="button" data-close-personal>Hủy</button><button class="button button-primary" type="submit">Đổi mật khẩu</button></footer>
    </form>
</dialog>
