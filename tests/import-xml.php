<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/management.php';
// Same envelope and rounded monetary fields as the supplied invoice; no seller/customer personal data or certificates.
$xml = '<TDiep><TTChung><SLuong>9</SLuong><MST>MESSAGE-TAX</MST></TTChung><DLieu><HDon><DLHDon Id="invoice"><TTChung><KHHDon>C26TNA</KHHDon><SHDon>00000548</SHDon><NLap>2026-09-29</NLap><DVTTe>VND</DVTTe></TTChung><NDHDon><NBan><Ten>Test supplier</Ten><MST>SELLER-TAX</MST></NBan><NMua><Ten>Test buyer</Ten><MST>BUYER-TAX</MST></NMua><DSHHDVu><HHDVu><TChat>1</TChat><THHDVu>Test kettle</THHDVu><DVTinh>Cái</DVTinh><SLuong>1</SLuong><DGia>2212962.96</DGia><TLCKhau>0</TLCKhau><STCKhau>0</STCKhau><ThTien>2212963</ThTien><TSuat>8%</TSuat><TThue>177037</TThue></HHDVu></DSHHDVu><TToan><THTTLTSuat><LTSuat><TSuat>8%</TSuat><ThTien>2212963</ThTien><TThue>177037</TThue></LTSuat></THTTLTSuat><TgTCThue>2212963</TgTCThue><TgTThue>177037</TgTThue><TgTTTBSo>2390000</TgTTTBSo></TToan></NDHDon></DLHDon><DSCKS><NBan><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><Object><MST>SIGNATURE-TAX</MST></Object></Signature></NBan></DSCKS></HDon></DLieu></TDiep>';
if (isset($argv[1])) $xml = file_get_contents($argv[1]);
$result = parseImportXml($xml);
$item = $result['items'][0];
foreach (['invoice_number' => '00000548', 'invoice_series' => 'C26TNA', 'invoice_date' => '2026-09-29', 'invoice_before_tax' => '2212963', 'invoice_tax' => '177037', 'invoice_after_tax' => '2390000'] as $key => $expected) {
    if ($result[$key] !== $expected) throw new RuntimeException('Incorrect invoice field: ' . $key);
}
foreach (['quantity' => '1', 'unit_price' => '2212962.96', 'amount_before_tax' => '2212963', 'tax_code' => '8%', 'tax_amount' => '177037', 'code' => ''] as $key => $expected) {
    if ($item[$key] !== $expected) throw new RuntimeException('Incorrect line field: ' . $key);
}
if (count($result['items']) !== 1 || bcadd($item['amount_before_tax'], $item['tax_amount'], 6) !== '2390000.000000') throw new RuntimeException('Rounded invoice total changed.');
if (!isset($argv[1]) && $result['tax_code'] !== 'SELLER-TAX') throw new RuntimeException('Seller must be read from invoice, not message or signature.');
echo "PASS: wrapped XML, invoice identity, seller context and rounded invoice/line totals.\n";
