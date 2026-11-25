<?php
/**
 * 操作マニュアルをPDFにまとめて作る（docs/マニュアル/01_全職員共通.pdf …）。
 *
 * 中身はシステムの「マニュアル」タブ（src/manual.php）と同じ。PDFはサーバの Chrome / Edge で作る（src/pdf.php。
 * config.php の pdf.browser）。部署別の項目の表は項目マスタから作るので、マスタを流し込んだあとに実行する。
 *
 * 使い方:  php tools/build_manual_pdf.php [出力フォルダ]     （省略時は docs/マニュアル）
 */
if (PHP_SAPI !== 'cli') {
    exit("コマンドラインから実行してください。\n");
}
require_once __DIR__ . '/../src/manual.php';
require_once __DIR__ . '/../src/pdf.php';

$out = $argv[1] ?? dirname(__DIR__) . '/docs/マニュアル';
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "フォルダを作れません：{$out}\n");
    exit(1);
}
// 前に作ったPDF（部署名が変わって名前が変わったもの）を残さない
foreach (glob($out . '/*.pdf') ?: [] as $old) {
    unlink($old);
}
$ng = 0;
foreach (array_keys(manual_list()) as $key) {
    $r    = html_to_pdf((string)manual_print_html($key), 60);
    $file = $out . '/' . manual_file_name($key);
    if ($r['pdf'] === null) {
        fwrite(STDERR, "  作れませんでした {$key}：{$r['error']}\n");
        $ng++;
        continue;
    }
    file_put_contents($file, $r['pdf']);
    printf("  %-40s %4d KB\n", basename($file), (int)ceil(strlen($r['pdf']) / 1024));
}
exit($ng > 0 ? 1 : 0);
