<?php
/**
 * 病院日誌をPDFでダウンロードする。
 *
 * 印刷用の日誌（nissi.php?print=1、A4縦・左とじ）をそのまま作り、サーバの Chrome / Edge で PDF にする（src/pdf.php）。
 * 特記事項（患者氏名）は、画面と同じく見られる人のときだけ入る。作業用の一時ファイルは返す前に消す。
 * PDFを作れないとき（ブラウザが無いなど）は、理由と「印刷用から PDF に保存」の案内を出す。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/pdf.php';

$user = require_login();
$date = valid_date($_GET['hizuke'] ?? null) ?? date('Y-m-d', strtotime('-1 day'));

// 印刷用の日誌のHTMLを作る（画面に出さずに受け取る）
$_GET = ['hizuke' => $date, 'print' => '1'];
ob_start();
(function () {
    include __DIR__ . '/nissi.php';
})();
$html = (string)ob_get_clean();

// CSS（assets/print.css など）をファイルから読めるよう、公開フォルダの場所を base にする
$html = preg_replace('/<head>/i', '<head><base href="' . h(pdf_file_url(__DIR__) . '/') . '">', $html, 1);

$r = html_to_pdf($html);
if ($r['pdf'] !== null) {
    $name = '病院日誌_' . $date . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="nissi_' . $date . '.pdf"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . strlen($r['pdf']));
    header('Cache-Control: no-store');        // 患者氏名を含むことがあるので残さない
    echo $r['pdf'];
    exit;
}

page_header('病院日誌のPDF', $user);
flash('PDFを作れませんでした：' . $r['error'], 'error');
?>
<p>印刷用の画面から、PDFとして保存できます。</p>
<ol>
  <li><a href="nissi.php?hizuke=<?= h($date) ?>&amp;print=1" target="_blank">印刷用を開く</a></li>
  <li>Ctrl+P を押し、送信先を「PDFに保存」にして「保存」</li>
</ol>
<p class="note">サーバでPDFを作るには、サーバに Chrome か Edge が入っている必要があります（README の「病院日誌のPDF」）。</p>
<p><a href="nissi.php?hizuke=<?= h($date) ?>">病院日誌に戻る</a></p>
<?php page_footer();
