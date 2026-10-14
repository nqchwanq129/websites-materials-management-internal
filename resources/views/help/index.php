<?php
$pageTitle = 'Hướng dẫn sử dụng'; $activePage = 'help';
require dirname(__DIR__).'/layouts/dashboard-start.php';
$canWriteReceipts = canAccessPage($access,'import-create');
$guideTopics = [
    'start' => ['Bắt đầu sử dụng', 'grid', 'home',
        ['Kiểm tra tên kho ở góc phải topbar. “Toàn hệ thống” là phạm vi của quản trị viên; tài khoản khác chỉ xem dữ liệu trong các kho được cấp quyền.',
         'Dùng menu bên trái để mở chức năng. Bấm tên nhóm để mở hoặc thu gọn các mục bên dưới; Ctrl + / đưa con trỏ vào ô tìm chức năng.',
         'Trang Tổng quan hiển thị hàng hóa, phiếu nhập/xuất, hoạt động theo tháng và 10 phiếu gần đây. Bấm mã phiếu hoặc mũi tên để xem chi tiết.'],
        'Nếu thiếu một chức năng hoặc chưa được cấp kho, hãy liên hệ quản trị viên. Quyền có thể khác nhau giữa các kho.'],
    'search' => ['Tìm kiếm và tra cứu', 'search', '',
        ['Ô tìm kiếm trên topbar dùng để tìm dữ liệu: số phiếu nhập/xuất, hàng hóa, kho, nhà cung cấp, nhân viên và quyết định trong phạm vi tài khoản.',
         'Nhập từ khóa, chọn kết quả để mở đúng hồ sơ. Bấm “Hiện thêm” nếu có nhiều kết quả.',
         'Trong từng danh sách, dùng bộ lọc và ô tìm kiếm riêng để thu hẹp kết quả; dùng “Trước” / “Sau” để chuyển trang. Đưa chuột lên chữ bị rút gọn để đọc đầy đủ.'],
        'Ô tìm kiếm trong sidebar chỉ tìm chức năng, không tìm hồ sơ hay số phiếu.'],
    'profile' => ['Thông tin cá nhân và ảnh đại diện', 'users', '',
        ['Bấm ảnh đại diện ở góc phải topbar, chọn “Thông tin cá nhân”.',
         'Bấm “Đổi ảnh” và chọn JPG, PNG hoặc WebP tối đa 10 MB. Ảnh được cắt vuông ở giữa và lưu ngay; bấm “Gỡ ảnh” để trở lại chữ cái đại diện.',
         'Nếu tài khoản đã liên kết nhân viên, sửa họ tên, email hoặc số điện thoại rồi bấm “Lưu thay đổi”. Thay đổi ảnh không làm mất các ô liên hệ đang nhập.',
         'Chọn “Đổi mật khẩu” trong menu tài khoản, nhập mật khẩu hiện tại và nhập lại mật khẩu mới. Mật khẩu mới cần ít nhất 8 ký tự và khác mật khẩu hiện tại.'],
        'Mã nhân viên, tên đăng nhập, kho và quyền do quản trị viên quản lý. Tài khoản chưa liên kết nhân viên vẫn đổi được ảnh và mật khẩu.'],
    'imports' => [$canWriteReceipts ? 'Lập và quản lý phiếu nhập' : 'Tra cứu phiếu nhập', 'down', 'imports',
        $canWriteReceipts ? ['Vào Nhập hàng hóa → Nhập hóa đơn. Có thể nhập tay hoặc chọn hóa đơn XML (VND, tối đa 2 MB) để điền thông tin bên bán và các dòng hàng.',
         'Chọn kho nhận, kiểm tra ngày nhập, số hóa đơn và nhà cung cấp. XML chỉ hỗ trợ điền nhanh; hãy đối chiếu hóa đơn trước khi lưu.',
         'Ở mỗi dòng, chọn hàng có sẵn hoặc tạo hàng mới. Kiểm tra nhóm hàng, loại nhập, đơn vị, số lượng, đơn giá, tiền trước thuế và thuế. Với hàng đã có lịch sử, một số thông tin danh mục có thể bị giới hạn.',
         'Có thể thêm PDF hóa đơn (tối đa 2 MB) và ảnh theo từng dòng hàng. CCDC/TSCĐ có thể nhập serial từng chiếc, chiếc chưa có serial để trống.',
         'Chọn “Lưu nháp” nếu cần kiểm tra thêm hoặc “Xác nhận nhập kho” để ghi nhận vào tồn. Vào DS phiếu nhập kho → Xem để kiểm tra, sửa các thông tin được phép, xem ảnh/PDF hoặc tải Excel.',
         'Phiếu nháp có nút xác nhận trong cửa sổ chi tiết và có thể xóa. Phiếu đã xác nhận dùng thao tác hủy kèm lý do khi đủ điều kiện; hệ thống gỡ các liên kết quyết định của dòng nhập bị hủy.']
        : ['Vào Nhập hàng hóa → DS phiếu nhập kho; tìm số phiếu, chọn kho hoặc trạng thái cần xem.',
           'Bấm “Xem” để đọc thông tin hóa đơn, các dòng hàng, quyết định liên kết và tệp đính kèm.',
           'Trong chi tiết, bấm ảnh hoặc “Xem PDF” để mở ngay trên web; dùng nút tải tệp hoặc Excel khi cần lưu bản sao.'],
        'Phiếu nháp và phiếu đã hủy không làm tăng tồn. Ảnh nhập kho tối đa 5 MB/ảnh, 5 ảnh/dòng, 16 ảnh/phiếu và 32 MB tổng cộng.'],
    'exports' => [$canWriteReceipts ? 'Lập và quản lý phiếu xuất' : 'Tra cứu phiếu xuất', 'up', 'exports',
        $canWriteReceipts ? ['Vào Xuất hàng hóa → Xuất hóa đơn. Chọn kho xuất, ngày xuất, loại xuất, người nhận và lý do.',
         'Bấm “Thêm hàng”, chọn lô từ phiếu nhập đã xác nhận rồi nhập số lượng xuất. Có thể tìm lô bằng tên hàng, mã hàng hoặc số phiếu nhập.',
         'Đối chiếu số lượng còn trong từng lô; giá trị xuất được phân bổ từ giá trị trước thuế của lô nhập. Lô không theo dõi số lượng không dùng cho thao tác xuất này.',
         'Chọn “Lưu nháp” hoặc “Xác nhận xuất kho”, có thể đính kèm PDF tối đa 2 MB. Phiếu nháp chưa trừ tồn.',
         'Vào DS phiếu xuất kho → Xem. Phiếu nháp có thể “Xác nhận xuất kho” khi tồn và quyền vẫn hợp lệ; tải mẫu Excel trong cửa sổ chi tiết.',
         'Nếu chọn điều chuyển, chọn thêm kho nhận. Khi xác nhận xuất, hệ thống đồng thời tạo phiếu nhập tại kho nhận; cần quyền phù hợp ở cả hai kho.']
        : ['Vào Xuất hàng hóa → DS phiếu xuất kho, tìm số phiếu hoặc lọc theo kho và trạng thái.',
           'Bấm “Xem” để kiểm tra người nhận, lý do xuất, lô nhập nguồn, số lượng và giá trị.',
           'Tải Excel trong chi tiết khi cần bản theo mẫu; mở hoặc tải chứng từ đính kèm nếu có.'],
        'Phiếu xuất nháp chưa giữ chỗ số lượng. Khi xác nhận, hệ thống kiểm tra lại tồn nên có thể báo thiếu hàng nếu một phiếu khác đã xuất trước.'],
    'products' => ['Hàng hóa, tồn kho và sổ Excel', 'box', 'products',
        ['Vào Danh Sách Hàng Hóa, chọn Tất Cả Hàng Hóa hoặc một nhóm: Vật tư, Phụ tùng thay thế, Công cụ dụng cụ, Tài sản cố định, Hàng hóa khác.',
         'Bấm “Xem” để đọc danh mục, tồn theo từng kho và lịch sử nhập/xuất. Tồn được tính từ phiếu đã xác nhận; dữ liệu thiếu số lượng có nhãn riêng.',
         'Tại Vật tư, Phụ tùng thay thế, Công cụ dụng cụ hoặc Tài sản cố định, bấm “Xuất Excel”, chọn kho và khoảng tháng rồi tải sổ theo mẫu.',
         'Sổ CCDC/TSCĐ hiển thị hóa đơn, quyết định và serial theo dữ liệu liên kết. Hàng có nhiều chiếc được tách dòng theo mẫu; serial chưa nhập sẽ để trống.'],
        'Quản trị viên sửa thông tin danh mục. Thủ kho và người xem tra cứu dữ liệu trong kho được cấp; số lượng tồn được cập nhật qua phiếu, không sửa trực tiếp ở danh sách.'],
    'decisions' => ['Quyết định và liên kết hàng hóa', 'file', 'decisions',
        $access['admin'] ? ['Vào Quyết định → Danh sách quyết định, bấm “Tạo quyết định”. Nhập kho, số hiệu, ngày ban hành, loại quyết định và cơ quan ban hành.',
         'Đính kèm PDF quyết định nếu có (tối đa 2 MB mỗi lần lưu). Có thể lập quyết định sau khi đã mua và nhập hàng.',
         'Bấm “Sửa / Gắn”, tìm và chọn các dòng nhập/xuất đã xác nhận của cùng kho đang chờ quyết định. Bỏ chọn để gỡ liên kết, rồi lưu.',
         'Bấm “Xem” để đối chiếu thông tin ban hành, dòng hàng đã gắn và PDF.']
        : ['Vào Quyết định → Danh sách quyết định và bấm “Xem” để đối chiếu thông tin ban hành, các dòng hàng và PDF.',
           'Thủ kho có thể bấm “Gắn hàng hóa” để chọn hoặc gỡ liên kết các dòng nhập/xuất đã xác nhận thuộc kho được quản lý, rồi “Lưu liên kết”.',
           'Người xem chỉ tra cứu. Thông tin ban hành và PDF quyết định do quản trị viên cập nhật.'],
        'Các dòng liên kết phải thuộc cùng kho với quyết định. Phiếu nháp hoặc đã hủy không dùng để gắn mới.'],
    'suppliers' => ['Nhà cung cấp', 'truck', 'suppliers',
        ['Mở Danh mục → Nhà cung cấp để tìm theo tên hoặc mã số thuế và bấm “Xem” thông tin, lịch sử liên quan.',
         'Nhà cung cấp được ghi nhận khi lập phiếu nhập, qua dữ liệu XML hoặc các ô thông tin bên bán. Không thêm trực tiếp trong tab Nhà cung cấp.',
         'Khi lập phiếu nhập mới, có thể chọn nhà cung cấp có sẵn và đối chiếu thông tin hóa đơn.'],
        'Kiểm tra mã số thuế và tên bên bán trước khi lưu để tránh nhầm nhà cung cấp.'],
    'reports' => ['Báo cáo và thống kê', 'chart', 'reports',
        ['Vào Báo cáo & thống kê, chọn Nhập · xuất · tồn hoặc báo cáo nhóm hàng cần theo dõi.',
         'Chọn kho, khoảng ngày và các bộ lọc, rồi bấm “Áp dụng”. Có thể chọn nhanh tháng, quý hoặc năm hiện tại.',
         'Đọc tồn đầu kỳ, nhập, xuất và tồn cuối kỳ. Bấm “Xem” tại một dòng để đối chiếu các phiếu phát sinh trong kỳ.',
         'Kiểm tra phần đối soát và cảnh báo dữ liệu nếu có; dùng “Xuất Excel” hoặc “In báo cáo” để lưu hoặc in kết quả theo bộ lọc đang áp dụng.'],
        'Báo cáo dùng dữ liệu thực tế và phiếu đã xác nhận. Không cộng chung số lượng của các đơn vị khác nhau; giá trị tiền trong báo cáo tồn là trước thuế.'],
    'notifications' => ['Thông báo', 'bell', '',
        ['Bấm biểu tượng chuông ở topbar để xem các thông báo mới nhất; số trên chuông là số thông báo chưa đọc.',
         'Thông báo chưa đọc có chữ đậm. Bấm “Đánh dấu đã đọc” để chuyển sang chữ nhạt; thông báo vẫn ở trong danh sách.',
         'Bấm thông báo về phiếu để mở đúng phiếu trong danh sách và đánh dấu đã đọc. Thông báo chung của admin chỉ để xem.',
         'Bấm “Hiện thêm thông báo cũ hơn” để xem lịch sử; dùng “Âm báo” để bật/tắt âm khi đang đăng nhập.'],
        'Bạn nhận thông báo phiếu theo kho được cấp và thông báo chung về thay đổi kho, danh mục của admin. Trình duyệt có thể cần một lần tương tác trước khi phát âm thanh.'],
];
if ($access['admin']) {
    $guideTopics['warehouses'] = ['Kho và thông tin trên mẫu Excel', 'warehouse', 'warehouses',
        ['Vào Kho hàng → Danh sách kho, thêm mã kho, tên, địa chỉ và trạng thái. Có thể tạo kho riêng cho từng đài hoặc từng cơ sở cần theo dõi tồn độc lập.',
         'Bấm “Xem” để kiểm tra thông tin; các nút sửa, ngừng hoạt động hoặc kích hoạt nằm trong cửa sổ chi tiết.',
         'Trong chỉnh sửa kho, cấu hình đơn vị, bộ phận, địa chỉ bộ phận và các nhân viên ký chứng từ. Địa chỉ kho dùng cho địa điểm kho trên mẫu; ngăn/lô có thể ghi bổ sung.',
         'Đối chiếu thông tin in khi lập phiếu. Excel lấy cấu hình theo kho và dữ liệu lưu trên phiếu thay cho các địa chỉ, người ký cố định.'],
        'Kho ngừng hoạt động sẽ không được chọn để lập phiếu mới. Việc xóa kho còn hồ sơ liên quan bị giới hạn để giữ lịch sử.'];
    $guideTopics['employees'] = ['Nhân viên', 'users', 'employees',
        ['Vào Danh mục → Nhân viên, bấm “Thêm nhân viên”, nhập mã nhân viên, họ tên và kho làm việc; bổ sung email, số điện thoại nếu có.',
         'Dùng mã có tiền tố đơn vị để phân biệt giữa các đài. Tìm tên hoặc mã trong danh sách để mở đúng hồ sơ.',
         'Cột thao tác có “Xem” và “Xóa”. Trong chi tiết có chỉnh sửa và ngừng/kích hoạt nhân viên.',
         'Liên kết nhân viên với tài khoản để người đó tự cập nhật thông tin cá nhân và để chọn người ký chứng từ.'],
        'Nhân viên ngừng hoạt động không còn được đăng nhập qua tài khoản liên kết. Xóa hồ sơ có liên kết hoặc lịch sử có thể bị từ chối.'];
    $guideTopics['accounts'] = ['Tài khoản và phân quyền', 'shield', 'accounts',
        ['Vào Hệ thống → Tài khoản & phân quyền, thêm tài khoản, nhập tên đăng nhập và mật khẩu; chọn nhân viên liên kết bằng ô vừa nhập vừa chọn.',
         'Chọn quản trị viên nếu cần quyền toàn hệ thống, hoặc cấp quyền riêng theo từng kho: Thủ kho để lập/quản lý phiếu, Người xem để tra cứu.',
         'Có thể cấp một tài khoản cho nhiều kho với quyền khác nhau. Kiểm tra đúng kho và quyền trước khi lưu.',
         'Chỉnh sửa tài khoản để thay đổi quyền, trạng thái hoặc mật khẩu theo nhu cầu.'],
        'Mỗi tài khoản chỉ có một phiên đăng nhập hoạt động; đăng nhập ở nơi mới sẽ kết thúc phiên ở nơi cũ. Không dùng chung tài khoản.'];
    $guideTopics['audit'] = ['Nhật ký thao tác', 'history', 'audit',
        ['Vào Hệ thống → Nhật ký thao tác, dùng bộ lọc thời gian, người thao tác, kho và loại thao tác để tìm lịch sử.',
         'Bấm “Xem” để đối chiếu dữ liệu trước và sau thay đổi, người thực hiện và thời điểm.',
         'Dùng nhật ký khi cần kiểm tra việc lập/xác nhận/hủy phiếu, thay đổi danh mục hoặc cấp quyền.'],
        'Nhật ký chỉ được đọc; mật khẩu không xuất hiện trong nội dung đối chiếu.'];
}
// Accounts without warehouse grants still need a usable account/help page.
$guideTopics = array_filter($guideTopics, static fn($topic) => !$topic[2] || canAccessPage($access,$topic[2]));
$faqs = [
    'Tại sao không thấy nút thêm, sửa hoặc một chức năng?' => 'Các nút hiển thị theo quyền và trạng thái hồ sơ. Người xem chỉ tra cứu; thủ kho quản lý phiếu trong kho được cấp; danh mục và tài khoản do quản trị viên quản lý. Liên hệ quản trị viên nếu quyền chưa đúng.',
    'Đã lưu phiếu nhưng tồn kho chưa thay đổi?' => 'Kiểm tra trạng thái: chỉ phiếu đã xác nhận mới tính vào tồn. Phiếu nháp và phiếu hủy không được tính. Kiểm tra lại kho và kỳ báo cáo đang chọn.',
    'Báo hồ sơ đã thay đổi hoặc số lượng không còn đủ thì làm gì?' => 'Đóng và mở lại chi tiết hoặc tải lại trang để lấy dữ liệu mới nhất, rồi kiểm tra và nhập lại thay đổi. Một người khác có thể đã cập nhật hồ sơ hoặc xuất cùng lô hàng.',
    'Đăng nhập ở nơi khác hoặc phiên đã kết thúc?' => 'Mỗi tài khoản chỉ đăng nhập trên một máy tại một thời điểm. Đăng nhập lại trên máy cần sử dụng; nếu không do bạn đăng nhập, đổi mật khẩu và báo quản trị viên. Phiên không hoạt động khoảng 30 phút cũng cần đăng nhập lại.',
    'XML không đọc được hoặc chưa đúng nhóm hàng?' => 'Chọn đúng file hóa đơn XML, tiền tệ VND, tối đa 2 MB. Sau khi điền nhanh, kiểm tra nhóm hàng của từng dòng; có thể nhập tay nếu cấu trúc hóa đơn không được hỗ trợ. Không lưu trước khi đối chiếu thông tin.',
    'Xem PDF bị trắng hoặc phần mềm tải xuống tự mở?' => 'Với hóa đơn phiếu nhập, dùng “Xem PDF” trong chi tiết để xem trên web. Thử tải lại trang; nếu phần mềm tải xuống chặn việc xem, kiểm tra thiết lập của phần mềm hoặc dùng “Tải PDF” để mở bản tải về.',
];
?>
<section class="page-heading"><div><p class="eyebrow">TRỢ GIÚP</p><h1>Hướng dẫn sử dụng<span>.</span></h1><p>Các bước thao tác và lưu ý khi quản lý kho VISHIPEL.</p></div><span class="subtle-badge"><?= $access['admin'] ? 'Dành cho quản trị viên' : ($canWriteReceipts ? 'Dành cho thủ kho' : ($access['warehouses'] ? 'Dành cho người xem' : 'Chưa được cấp quyền kho')) ?></span></section>
<section class="card help-intro"><div><span class="icon-tile purple"><?= $icon('help') ?></span><div><h2>Bạn cần hỗ trợ thao tác nào?</h2><p>Chọn chủ đề bên dưới hoặc tìm theo từ khóa. Nội dung phù hợp với quyền của bạn.</p></div></div><label class="help-search"><?= $icon('search') ?><input id="help-search" type="search" maxlength="120" placeholder="Ví dụ: nhập XML, đổi ảnh, xuất Excel…" aria-label="Tìm trong hướng dẫn sử dụng" aria-controls="help-topics help-faq"></label><p id="help-search-status" role="status" hidden></p></section>
<div class="help-layout"><nav class="card help-index" aria-label="Chủ đề hướng dẫn"><h2>Nội dung hướng dẫn</h2><?php foreach ($guideTopics as $key=>$topic): ?><a href="#help-<?= $key ?>" data-help-index="help-<?= $key ?>"><?= $icon($topic[1]) ?><span><?= escapeHtml($topic[0]) ?></span></a><?php endforeach; ?><a href="#help-faq" data-help-index="help-faq"><?= $icon('help') ?><span>Câu hỏi thường gặp</span></a></nav>
<div class="help-content"><div id="help-topics"><?php foreach ($guideTopics as $key=>$topic): ?><article class="card help-topic" id="help-<?= $key ?>" data-help-topic><header><span class="icon-tile purple"><?= $icon($topic[1]) ?></span><h2><?= escapeHtml($topic[0]) ?></h2></header><ol><?php foreach ($topic[3] as $step): ?><li><?= escapeHtml($step) ?></li><?php endforeach; ?></ol><p class="help-note"><?= escapeHtml($topic[4]) ?></p><?php if ($topic[2]): ?><a class="button" href="index.php?page=<?= $topic[2] ?>">Mở <?= escapeHtml(mb_strtolower($topic[0])) ?><?= $icon('arrow') ?></a><?php elseif ($key === 'profile'): ?><button class="button" type="button" data-open-profile="profile">Mở thông tin cá nhân<?= $icon('arrow') ?></button><?php endif; ?></article><?php endforeach; ?></div>
<section class="card help-faq" id="help-faq"><h2>Câu hỏi thường gặp</h2><?php foreach ($faqs as $question=>$answer): ?><details data-help-faq><summary><?= escapeHtml($question) ?></summary><p><?= escapeHtml($answer) ?></p></details><?php endforeach; ?></section><p class="help-empty empty-state" id="help-empty" hidden>Không tìm thấy nội dung phù hợp. Hãy thử từ khóa khác.</p></div></div>
<?php require dirname(__DIR__).'/layouts/dashboard-end.php'; ?>
