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
        // Edge を先に（Windows 10/11 の Edge は自動で更新されて新しい。Chrome は古い版のことがある）
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
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
    // Windows は終了したばかりのブラウザがファイルを掴んでいることがあるので、少し待って何回か試す
    for ($try = 0; $try < 10 && is_dir($dir); $try++) {
        if ($try > 0) {
            usleep(300000);
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
    if (is_dir($dir)) {
        error_log("病院日誌PDF：一時フォルダを消せなかった（{$dir}）");
    }
}

/** ブラウザを子プロセスごと終わらせる（Windows は taskkill /T。proc_terminate では子が残る）。 */
function pdf_kill($proc): void
{
    $st = proc_get_status($proc);
    if (!$st['running']) {
        return;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('taskkill /T /F /PID ' . (int)$st['pid'] . ' 2>NUL');
    } else {
        proc_terminate($proc, 9);
    }
    for ($i = 0; $i < 20 && proc_get_status($proc)['running']; $i++) {
        usleep(100000);
    }
}

/**
 * HTMLをPDFにする。
 *
 * Windows ではパイプを読むとブラウザが閉じるまで止まる（非ブロッキングが効かない）ため、
 * 出力はファイルへ書かせ、PDFのファイルができたかどうかを見て待つ。
 * PDFを書き終えたのに終わらないブラウザもあるので、PDFの大きさが変わらなくなったら完了として終わらせる。
 *
 * @param string $html    印刷用の画面のHTML（<base href> で CSS の場所を指しておく）
 * @param int    $timeout 待つ秒数
 * @return array ['pdf' => PDFのバイト列|null, 'error' => 理由|null, 'seconds' => かかった秒数]
 */
function html_to_pdf(string $html, int $timeout = 30): array
{
    $browser = pdf_browser();
    if ($browser === null) {
        $conf = trim((string)((cfg('pdf') ?? [])['browser'] ?? ''));
        return ['pdf' => null, 'seconds' => 0, 'error' => $conf !== ''
            ? "config.php の pdf.browser に書かれたブラウザが見つかりません（{$conf}）。"
            : 'サーバに Chrome も Edge も見つかりません。config.php の pdf.browser にパスを書いてください。'];
    }
    if (!function_exists('proc_open')) {
        return ['pdf' => null, 'seconds' => 0, 'error' => 'このサーバのPHPでは proc_open が使えないため、PDFを作れません（php.ini の disable_functions）。'];
    }

    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'nissi_pdf_' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700)) {
        return ['pdf' => null, 'seconds' => 0, 'error' => '一時フォルダを作れません（' . sys_get_temp_dir() . '）。'];
    }
    @set_time_limit($timeout + 15);
    $name  = basename($browser);
    $start = microtime(true);
    $proc  = null;
    try {
        $in  = $dir . DIRECTORY_SEPARATOR . 'page.html';
        $out = $dir . DIRECTORY_SEPARATOR . 'page.pdf';
        $log = $dir . DIRECTORY_SEPARATOR . 'stderr.txt';
        file_put_contents($in, $html);
        $cmd = [$browser, '--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                '--disable-extensions', '--disable-background-networking', '--disable-sync',
                '--disable-component-update', '--hide-scrollbars',
                // Apache はサービス（SYSTEM）で動くので、サンドボックスでは起動できないことがある。
                // 開くのは直前に作ったローカルのHTMLだけなので外す
                '--no-sandbox',
                '--no-pdf-header-footer', '--print-to-pdf-no-header',      // 新旧どちらのChromeでも日付・URLを入れない
                '--user-data-dir=' . $dir . DIRECTORY_SEPARATOR . 'profile',
                '--print-to-pdf=' . $out, pdf_file_url($in)];
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $proc = proc_open($cmd, [0 => ['file', $null, 'r'],
                                 1 => ['file', $dir . DIRECTORY_SEPARATOR . 'stdout.txt', 'w'],
                                 2 => ['file', $log, 'w']],
                          $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['pdf' => null, 'seconds' => 0, 'error' => "ブラウザを起動できません（{$browser}）。"];
        }
        // 終わるか、PDFの大きさが 0.6 秒変わらなくなるまで待つ
        $lastSize = -1;
        $stable   = 0;
        $end      = $start + $timeout;
        while (true) {
            $running = proc_get_status($proc)['running'];
            clearstatcache(true, $out);
            $size = is_file($out) ? (int)filesize($out) : 0;
            if ($size > 0 && $size === $lastSize) {
                $stable++;
            } else {
                $stable = 0;
            }
            $lastSize = $size;
            if (!$running || ($stable >= 3 && file_get_contents($out, false, null, 0, 4) === '%PDF')) {
                break;
            }
            if (microtime(true) > $end) {
                pdf_kill($proc);
                proc_close($proc);
                $proc = null;
                $msg = trim((string)@file_get_contents($log, false, null, 0, 500));
                error_log("病院日誌PDF：{$timeout}秒で終わらなかった（{$browser}）" . $msg);
                return ['pdf' => null, 'seconds' => $timeout,
                        'error' => "PDFの作成が {$timeout} 秒で終わりませんでした（{$name}）。"];
            }
            usleep(200000);
        }
        pdf_kill($proc);
        proc_close($proc);
        $proc = null;
        $sec = round(microtime(true) - $start, 1);
        clearstatcache(true, $out);
        $pdf = is_file($out) ? (string)file_get_contents($out) : '';
        if (strncmp($pdf, '%PDF', 4) !== 0) {
            $msg = trim((string)@file_get_contents($log, false, null, 0, 500));
            error_log("病院日誌PDF：作れなかった（{$browser}）" . $msg);
            return ['pdf' => null, 'seconds' => $sec, 'error' => "ブラウザがPDFを作れませんでした（{$name}）。"];
        }
        return ['pdf' => $pdf, 'seconds' => $sec, 'error' => null];
    } finally {
        if (is_resource($proc)) {
            pdf_kill($proc);
            proc_close($proc);
        }
        pdf_rmdir($dir);
    }
}
