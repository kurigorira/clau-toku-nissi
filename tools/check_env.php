<?php
/**
 * 稼働サーバがこのシステムの要件を満たしているかを確認する。
 *
 * このファイル自体は PHP 5.2 でも動くように書いてある
 * （型宣言・アロー関数・短縮配列を使っていない）。
 * サーバが古すぎる場合でも「古すぎる」と表示できるようにするため。
 *
 * 使い方:
 *   コマンドラインから  php tools/check_env.php
 *   ブラウザから        このファイルを公開領域に置いて開く（確認後は必ず削除すること）
 */

$isCli = (php_sapi_name() === 'cli');
$nl    = $isCli ? "\n" : "<br>\n";
if (!$isCli) { echo "<pre style='font-family:monospace'>"; }

$ng = 0;
$warn = 0;

function line($status, $label, $detail) {
    global $nl, $ng, $warn;
    if ($status === 'NG')   { $ng++; }
    if ($status === '注意') { $warn++; }
    printf("  [%-4s] %-34s %s%s", $status, $label, $detail, $nl);
}

echo "病院日誌・医事統計表 入力システム　環境チェック" . $nl;
echo str_repeat("=", 68) . $nl;

// ---- PHP本体 ----
echo $nl . "PHP" . $nl;
$v = PHP_VERSION;
if (version_compare($v, '7.4.0', '>=')) {
    line('OK', 'PHPバージョン', $v . '（要件 7.4 以上）');
} elseif (version_compare($v, '7.1.0', '>=')) {
    line('注意', 'PHPバージョン', $v . ' … 7.4未満です。アロー関数を使わない形に'
        . '書き換えれば動きます。開発者に連絡してください');
} else {
    line('NG', 'PHPバージョン', $v . ' … 古すぎます。7.4以上への更新が必要です');
}
line(PHP_INT_SIZE >= 8 ? 'OK' : '注意', '整数のビット数', (PHP_INT_SIZE * 8) . 'bit');

// ---- 拡張モジュール ----
echo $nl . "拡張モジュール" . $nl;
$need = array(
    'pdo'       => 'データベース接続に必須',
    'pdo_mysql' => 'MySQL/MariaDB接続に必須',
    'mbstring'  => '日本語の扱いに必須',
    'session'   => 'ログイン状態の保持に必須',
    'json'      => '内部処理で使用',
);
foreach ($need as $ext => $why) {
    line(extension_loaded($ext) ? 'OK' : 'NG', $ext, extension_loaded($ext) ? $why : '未導入 … ' . $why);
}

// ---- 設定 ----
echo $nl . "PHPの設定" . $nl;
$enc = ini_get('default_charset');
line(($enc === '' || strtolower($enc) === 'utf-8') ? 'OK' : '注意',
     'default_charset', $enc === '' ? '(未設定。UTF-8として扱われます)' : $enc);
$mbi = function_exists('mb_internal_encoding') ? mb_internal_encoding() : '-';
line((strtoupper($mbi) === 'UTF-8' || $mbi === '-') ? 'OK' : '注意', 'mb_internal_encoding', $mbi);
line(ini_get('display_errors') ? '注意' : 'OK', 'display_errors',
     ini_get('display_errors') ? 'オン … 本番ではオフを推奨（エラー内容が利用者に見えます）' : 'オフ');
$log = ini_get('log_errors');
line($log ? 'OK' : '注意', 'log_errors',
     $log ? 'オン（出力先: ' . (ini_get('error_log') ? ini_get('error_log') : 'サーバ既定') . '）'
          : 'オフ … 障害時に原因を追えないためオンを推奨');

// ---- データベース ----
echo $nl . "データベース" . $nl;
$confPath = dirname(dirname(__FILE__)) . '/config/config.php';
if (!is_file($confPath)) {
    line('注意', 'config/config.php', '未作成 … config.sample.php をコピーして作成してください');
} else {
    $conf = include $confPath;
    try {
        $pdo = new PDO($conf['db']['dsn'], $conf['db']['user'], $conf['db']['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        line('OK', 'DB接続', '成功');

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sv     = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        line('OK', 'DBの種類とバージョン', $driver . ' ' . $sv);

        if ($driver === 'mysql') {
            // utf8mb4 が使えるか。使えないと絵文字や一部の漢字で問題が出る
            $row = $pdo->query("SHOW CHARACTER SET LIKE 'utf8mb4'")->fetch(PDO::FETCH_ASSOC);
            line($row ? 'OK' : 'NG', 'utf8mb4', $row ? '利用可能' : '未対応 … MySQL 5.5.3 以上が必要です');

            $r2 = $pdo->query("SELECT @@character_set_database AS c")->fetch(PDO::FETCH_ASSOC);
            $cs = $r2 ? $r2['c'] : '?';
            line($cs === 'utf8mb4' ? 'OK' : '注意', 'データベースの文字コード',
                 $cs . ($cs === 'utf8mb4' ? '' : ' … utf8mb4 を推奨（現行の nissi テーブルは sjis）'));
        }

        // テーブルが作られているか
        $tables = array('m_dept', 'm_item', 'd_daily_value', 'd_submission', 'd_audit');
        $missing = array();
        foreach ($tables as $t) {
            try { $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1'); }
            catch (Exception $e) { $missing[] = $t; }
        }
        line(count($missing) === 0 ? 'OK' : '注意', 'テーブル',
             count($missing) === 0 ? '作成済み'
             : '未作成: ' . implode(', ', $missing) . ' … db/schema.sql を流してください');

        if (count($missing) === 0) {
            $c = $pdo->query('SELECT COUNT(*) FROM m_item')->fetchColumn();
            line($c > 0 ? 'OK' : '注意', '項目マスタ',
                 $c > 0 ? $c . '項目' : '空 … db/seed_master.sql を流してください');
        }
    } catch (Exception $e) {
        line('NG', 'DB接続', '失敗: ' . $e->getMessage());
    }
}

// ---- ファイル配置 ----
echo $nl . "ファイル配置" . $nl;
$root = dirname(dirname(__FILE__));
line(is_dir($root . '/public') ? 'OK' : 'NG', 'public/ ディレクトリ',
     is_dir($root . '/public') ? 'あり（Webの公開先はここを指すこと）' : 'なし');
$docroot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
if (!$isCli && $docroot !== '') {
    $exposed = (strpos(realpath($root . '/src'), realpath($docroot)) === 0);
    line($exposed ? 'NG' : 'OK', 'src/ の公開状態',
         $exposed ? 'Webから見える位置にあります … 公開先を public/ に変えてください' : '公開領域の外');
}

echo $nl . str_repeat("=", 68) . $nl;
if ($ng > 0) {
    echo "NG が {$ng} 件あります。解消しないと動きません。" . $nl;
} elseif ($warn > 0) {
    echo "NG はありません。注意 {$warn} 件を確認してください。" . $nl;
} else {
    echo "すべて問題ありません。" . $nl;
}
if (!$isCli) { echo "</pre>"; }
