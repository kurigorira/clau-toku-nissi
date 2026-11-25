<?php
/**
 * 例外と致命的エラーの受け止め。
 *
 * 受け止めないと、たとえば「DBにはつながるがテーブルがまだ無い」ときに
 * 真っ白な500だけが返り、現場では何が起きたのか分からない。
 * 原因は必ずサーバのエラーログへ出し、画面には定型文を出す。
 * config.php の debug が true のときだけ、画面にも理由を添える。
 */

/** 二重に出力しないための印。 */
$GLOBALS['__fatal_rendered'] = false;

/**
 * 500を返し、利用者向けの短い説明を出す。
 * $detail はログ用の技術的な内容。debug が true のときだけ画面にも出す。
 */
function render_fatal(string $detail): void
{
    if (!empty($GLOBALS['__fatal_rendered'])) {
        return;
    }
    $GLOBALS['__fatal_rendered'] = true;

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    // cfg() は config.php を読む。設定自体が壊れている場合もあるので保険をかける
    $debug = false;
    if (function_exists('cfg')) {
        try {
            $debug = (bool)cfg('debug');
        } catch (Throwable $ignore) {
            $debug = false;
        }
    }

    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8">'
       . '<title>エラー｜病院日誌・医事統計</title></head><body>'
       . '<h1>エラーが発生しました</h1>'
       . '<p>管理者に連絡してください。</p>'
       . '<p>原因はサーバのエラーログに記録されています。'
       . 'Windows / Apache24 なら <code>C:\\Apache24\\logs\\error.log</code> の末尾を見てください。</p>'
       . '<p>設置直後であれば、<code>php tools/check_env.php</code> で'
       . 'テーブルやマスタが作られているかを確認できます。</p>';

    if ($debug) {
        echo '<hr><pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo '</body></html>';
}

/**
 * 例外と致命的エラーのハンドラを登録する。
 * コマンドラインではPHP既定の表示のほうが読みやすいので何もしない。
 */
function install_error_handlers(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    set_exception_handler(function (Throwable $e) {
        error_log(sprintf('未処理の例外: %s: %s @ %s:%d',
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        render_fatal(sprintf("%s: %s\n%s:%d",
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    });

    // パースエラーやメモリ不足など、例外にならない致命的エラーを拾う
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (in_array($e['type'], $fatal, true)) {
            error_log(sprintf('致命的エラー: %s @ %s:%d', $e['message'], $e['file'], $e['line']));
            render_fatal(sprintf("%s\n%s:%d", $e['message'], $e['file'], $e['line']));
        }
    });
}
