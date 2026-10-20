<?php
/**
 * サーバでPDFを作る（病院日誌のPDFダウンロード）。
 *
 * PDFを作る部品（TCPDF など）は入れず、サーバに入っている Chrome / Edge を
 * ヘッドレス（画面を出さない）で起動して、印刷用の画面をそのままPDFにする。
 * 印刷（A4縦・左とじ、print.css）とまったく同じ見た目になり、日本語フォントもWindowsのものを使う。
 *
 * 使うブラウザは config.php の pdf.browser。空ならよくある場所を順に探す。
 */

/** ブラウザを探す場所（上から順）。 */
function pdf_browser_candidates(): array
{
    return [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\GoogleChromePortable\\App\\Chrome-bin\\chrome.exe',
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
    ];
}

/**
 * PDFを作るブラウザのパス。見つからなければ null。
 * config.php に書いたパスが無いときも null（別の場所を勝手に使わない。設定の間違いに気づけるように）。
 */
function pdf_browser(): ?string
{
    $conf = trim((string)((cfg('pdf') ?? [])['browser'] ?? ''));
    if ($conf !== '') {
        return is_file($conf) ? $conf : null;
    }
    foreach (pdf_browser_candidates() as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

/** ファイルのパスを file:/// のURLにする（Windows の C:\… も）。 */
function pdf_file_url(string $path): string
{
    $p = str_replace('\\', '/', $path);
    return 'file:///' . str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode(ltrim($p, '/')));
}

/** 一時フォルダを中身ごと消す（患者氏名を含むHTML・PDF、ブラウザのプロファイル）。 */
function pdf_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * HTMLをPDFにする。
 *
 * @param string $html    印刷用の画面のHTML（<base href> で CSS の場所を指しておく）
 * @param int    $timeout 待つ秒数
 * @return array ['pdf' => PDFのバイト列|null, 'error' => 理由|null]
 */
function html_to_pdf(string $html, int $timeout = 60): array
{
    $browser = pdf_browser();
    if ($browser === null) {
        $conf = trim((string)((cfg('pdf') ?? [])['browser'] ?? ''));
        return ['pdf' => null, 'error' => $conf !== ''
            ? "config.php の pdf.browser に書かれたブラウザが見つかりません（{$conf}）。"
            : 'サーバに Chrome も Edge も見つかりません。config.php の pdf.browser にパスを書いてください。'];
    }
    if (!function_exists('proc_open')) {
        return ['pdf' => null, 'error' => 'このサーバのPHPでは proc_open が使えないため、PDFを作れません（php.ini の disable_functions）。'];
    }

    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'nissi_pdf_' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700)) {
        return ['pdf' => null, 'error' => '一時フォルダを作れません（' . sys_get_temp_dir() . '）。'];
    }
    try {
        $in  = $dir . DIRECTORY_SEPARATOR . 'page.html';
        $out = $dir . DIRECTORY_SEPARATOR . 'page.pdf';
        file_put_contents($in, $html);
        $cmd = [$browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                '--disable-extensions', '--hide-scrollbars',
                '--no-pdf-header-footer', '--print-to-pdf-no-header',      // 新旧どちらのChromeでも日付・URLを入れない
                '--user-data-dir=' . $dir . DIRECTORY_SEPARATOR . 'profile',
                '--print-to-pdf=' . $out, pdf_file_url($in)];
        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_getuid') && posix_getuid() === 0) {
            $cmd[] = '--no-sandbox';       // Linux の root では必要（院内のWindowsサーバでは付けない）
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['pdf' => null, 'error' => "ブラウザを起動できません（{$browser}）。"];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $err = '';
        $end = time() + $timeout;
        while (true) {
            $err .= (string)stream_get_contents($pipes[2]);
            stream_get_contents($pipes[1]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            if (time() > $end) {
                proc_terminate($proc);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
                error_log("病院日誌PDF：{$timeout}秒で終わらなかった（{$browser}）");
                return ['pdf' => null, 'error' => "PDFの作成が {$timeout} 秒で終わりませんでした。"];
            }
            usleep(100000);
        }
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        $pdf = is_file($out) ? (string)file_get_contents($out) : '';
        if (strncmp($pdf, '%PDF', 4) !== 0) {
            error_log("病院日誌PDF：作れなかった（{$browser}）" . substr(trim($err), 0, 500));
            return ['pdf' => null, 'error' => 'ブラウザがPDFを作れませんでした（' . basename($browser) . '）。'];
        }
        return ['pdf' => $pdf, 'error' => null];
    } finally {
        pdf_rmdir($dir);
    }
}
