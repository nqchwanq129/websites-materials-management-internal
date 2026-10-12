<section class="card"><details class="receipt-print-details" data-receipt-print
    data-settings="<?= escapeHtml(json_encode((object)($managementData['printSettings'] ?? []),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?>"
    data-warehouses="<?= escapeHtml(json_encode((object)($managementData['printWarehouses'] ?? []),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?>"
    data-versions="<?= escapeHtml(json_encode((object)($managementData['printVersions'] ?? []),JSON_THROW_ON_ERROR)) ?>">
    <summary>Thông tin in phiếu <span>Đơn vị, địa chỉ và người ký trên Excel</span></summary>
    <div class="import-section-body"><p class="form-preview-note">Tự điền theo kho đã chọn. Có thể chọn lại người ký cho riêng phiếu này; tên được lưu theo thời điểm lập phiếu.</p><input name="print_version" type="hidden" disabled>
    <div class="account-form-grid">
        <?php foreach (['unit_name'=>'Đơn vị','department_name'=>'Bộ phận','department_address'=>'Địa chỉ bộ phận','location'=>'Địa chỉ kho'] as $field=>$label): ?><label><?= $label ?><input data-print-value="<?= $field ?>" readonly placeholder="<?= $field==='location'?'Chưa cập nhật địa chỉ kho':'Chưa khai báo trong cấu hình kho' ?>"></label><?php endforeach; ?>
        <label>Người lập phiếu<input readonly value="<?= escapeHtml($user['full_name'] ?? $user['username']) ?>"></label>
        <?php foreach (['keeper'=>'Thủ kho','accountant'=>'Phụ trách kế toán','director'=>'Giám đốc'] as $role=>$label): ?><label><?= $label ?><select name="print_signers[<?= $role ?>_employee_id]" data-print-signer="<?= $role ?>_employee_id"><option value="">Để trống tên người ký</option><?php foreach ($managementData['printEmployees'] ?? [] as $employee): ?><option value="<?= escapeHtml((string)$employee['id']) ?>" data-active="<?= $employee['status']==='active'?'true':'false' ?>"><?= escapeHtml($employee['code'].' · '.$employee['full_name'].($employee['status']==='active'?'':' · Ngừng hoạt động')) ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
    </div><p class="form-preview-note">Sửa địa chỉ kho tại Kho hàng → Chỉnh sửa → Thông tin kho. Đơn vị và địa chỉ bộ phận sửa tại Thông tin in phiếu. Chọn người ký không cấp quyền truy cập hệ thống.</p></div>
</details></section>
