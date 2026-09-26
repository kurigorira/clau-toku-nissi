<?php
/**
 * SQLファイルをPDO経由で流し込む。
 *
 * mysql / mariadb のコマンドライン版が入っていないサーバがあるため用意した。
 * PHPは PDO（mysqlnd）でネットワーク越しに接続するので、
 * クライアントプログラムが無くてもテーブル作成とマスタ投入ができる。
 *
 * 使い方:
 *   php db/tools/run_sql.php db/schema.sql --user=root --pass=＜rootのパスワード＞
 *   php db/tools/run_sql.php db/seed_master.sql
 *   php db/tools/run_sql.php db/schema.sql --dry-run
 *
 * 接続先は既定で config/config.php の db 設定。--dsn / --user / --pass で上書きできる。
 *
 * ※ schema.sql は CREATE / DROP TABLE を含むため、
 *   SELECT/INSERT/UPDATE/DELETE しか持たない nissi ユーザでは権限不足になる。
 *   --user=root のように、DDLを実行できるユーザを指定すること。
 *   seed_master.sql は DELETE と INSERT だけなので nissi ユーザのままでよい。
 */

require_once dirname(__DIR__, 2) . '/src/db.php';

/**
 * SQLをひとつずつの文に切り分ける。
 *
 * 単純に「;」で切ると、値の中に「;」があったときに壊れる。
 * 引用符の中とコメントを見ながら進める。
 *   '...'  文字列。'' は文字列中のシングルクォート
 *   "..."  文字列（MySQLの既定モード）
 *   `...`  識別子
 *   --     行末までコメント（-- のあとに空白か行末が要る）
 *   #      行末までコメント（MySQL）
 *   / * … * /  ブロックコメント
 */
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $i   = 0;

    while ($i < $len) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // 行コメント
        if (($c === '-' && $next === '-' && (($i + 2 >= $len) || strpos(" \t\r\n", $sql[$i + 2]) !== false))
            || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            $i  = ($nl === false) ? $len : $nl + 1;
            $buf .= "\n";
            continue;
        }

        // ブロックコメント
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i   = ($end === false) ? $len : $end + 2;
            continue;
        }

        // 文字列・識別子。閉じるまでそのまま写す
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf  .= $c;
            $i++;
            while ($i < $len) {
                $d = $sql[$i];
                if ($d === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $d . $sql[$i + 1];   // バックスラッシュによるエスケープ
                    $i   += 2;
                    continue;
                }
                if ($d === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buf .= $d . $d;          // '' や "" は文字そのもの
                        $i   += 2;
                        continue;
                    }
                    $buf .= $d;
                    $i++;
                    break;                        // 閉じた
                }
                $buf .= $d;
                $i++;
            }
            continue;
        }

        if ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = trim($buf);
            }
            $buf = '';
            $i++;
            continue;
        }

        $buf .= $c;
        $i++;
    }

    if (trim($buf) !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

// ---- 引数（解き方は src/cli.php を参照） -------------------------------------
require_once dirname(__DIR__, 2) . '/src/cli.php';
['opt' => $opt, 'extra' => $extra] = cli_args($argv);
$files = array_map(fn($e) => $e[1], $extra);   // -- で始まらない引数＝SQLファイル

if (isset($opt['help']) || !$files) {
    fwrite(STDERR, <<<TXT
SQLファイルをPDO経由で流し込む（mysql コマンドが無いサーバ用）

  php db/tools/run_sql.php <SQLファイル> [--user=<ユーザ>] [--pass=<パスワード>] [--dsn=<DSN>] [--dry-run]

例:
  php db/tools/run_sql.php db/schema.sql --user=root --pass=xxxx
  php db/tools/run_sql.php db/seed_master.sql
  php db/tools/run_sql.php db/schema.sql --dry-run     流さずに文の数だけ見る

schema.sql は CREATE / DROP TABLE を含むので、DDLを実行できるユーザ（root など）で流すこと。

TXT);
    exit(1);
}

$conf = cfg('db');
$dsn  = $opt['dsn']  ?? $conf['dsn'];
$user = $opt['user'] ?? ($conf['user'] ?? null);
$pass = $opt['pass'] ?? ($conf['pass'] ?? null);
$dry  = isset($opt['dry-run']);

foreach ($files as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "ファイルがありません: $f\n");
        exit(1);
    }
}

// ---- 接続 ----------------------------------------------------------------
if (!$dry) {
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        fwrite(STDERR, "DBに接続できません: " . $e->getMessage() . "\n");
        fwrite(STDERR, "  接続先: $dsn  ユーザ: " . ($user === null ? '(未指定)' : $user) . "\n");
        exit(1);
    }
}

// ---- 実行 ----------------------------------------------------------------
foreach ($files as $f) {
    $stmts = split_sql((string)file_get_contents($f));
    printf("%s … %d 文\n", $f, count($stmts));

    if ($dry) {
        foreach ($stmts as $n => $s) {
            printf("  %3d: %s\n", $n + 1, mb_strimwidth(preg_replace('/\s+/', ' ', $s), 0, 90, '…'));
        }
        continue;
    }

    foreach ($stmts as $n => $s) {
        try {
            $rows = $pdo->exec($s);
        } catch (PDOException $e) {
            fwrite(STDERR, "\n" . ($n + 1) . "文目で失敗しました。\n");
            fwrite(STDERR, "  " . mb_strimwidth(preg_replace('/\s+/', ' ', $s), 0, 200, '…') . "\n");
            fwrite(STDERR, "  " . $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo "  完了\n";
}

if ($dry) {
    echo "\n--dry-run のため、何も書き込んでいません。\n";
} else {
    echo "\n流し込みが終わりました。 php tools/check_env.php で確認してください。\n";
}
