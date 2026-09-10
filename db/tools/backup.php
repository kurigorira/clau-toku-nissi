<?php
/**
 * データベースをSQLファイルに書き出す（バックアップ）。
 *
 * mysqldump が入っていないサーバでも動くよう、PDO経由で作る。
 * 出力は素のSQLなので、復旧は同じリポジトリの run_sql.php でそのまま流せる。
 *
 * 使い方:
 *   php db/tools/backup.php
 *   php db/tools/backup.php --out=C:/backup/nissi --keep=30
 *   php db/tools/backup.php --copy-to=//サーバ名/共有/backup/nissi
 *
 * 復旧:
 *   php db/tools/run_sql.php C:/backup/nissi/nissi_20260910_2200.sql --user=root --pass=...
 *
 * ※ 出力にはd_tokkiの患者ID・氏名と、m_userのパスワードハッシュが入る。
 *   保存先はブラウザから到達できない場所にし、アクセス権を絞ること。
 */

require_once dirname(__DIR__, 2) . '/src/db.php';

const END_MARK = '-- ここまでで終わり（この行が無いファイルは不完全）';

/**
 * 保存先が公開フォルダの中かどうか。
 * バックアップは個人情報を含むので、ブラウザから落とせる場所には置かせない。
 */
function in_public_dir(string $dir): bool
{
    $p = str_replace('\\', '/', $dir) . '/';
    foreach (['/htdocs/', '/www/', '/public_html/', '/wwwroot/', '/webroot/', '/public/'] as $m) {
        if (stripos($p, $m) !== false) {
            return true;
        }
    }
    return false;
}

/** 値をSQLのリテラルにする。 */
function lit(PDO $pdo, $v): string
{
    return $v === null ? 'NULL' : $pdo->quote((string)$v);
}

/** 失敗して終了する。タスクスケジューラが失敗と分かるよう終了コードを1にする。 */
function die_with(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

// ---- 引数（run_sql.php と同じ理由で getopt() は使わない） ------------------
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--') === 0) {
        $kv = explode('=', substr($a, 2), 2);
        $opt[$kv[0]] = $kv[1] ?? true;
    }
}

if (isset($opt['help'])) {
    fwrite(STDERR, <<<TXT
データベースをSQLファイルに書き出す

  php db/tools/backup.php [--out=<フォルダ>] [--copy-to=<フォルダ>] [--keep=<世代数>]
                          [--user=<ユーザ>] [--pass=<パスワード>] [--dsn=<DSN>]

既定値は config/config.php の backup 設定。復旧は run_sql.php で流す。

TXT);
    exit(1);
}

$bk      = cfg('backup') ?? [];
$conf    = cfg('db');
$dsn     = $opt['dsn']       ?? $conf['dsn'];
$user    = $opt['user']      ?? ($conf['user'] ?? null);
$pass    = $opt['pass']      ?? ($conf['pass'] ?? null);
$outDir  = rtrim((string)($opt['out']     ?? ($bk['dir']     ?? '')), '/\\');
$copyTo  = rtrim((string)($opt['copy-to'] ?? ($bk['copy_to'] ?? '')), '/\\');
$keep    = (int)($opt['keep'] ?? ($bk['keep'] ?? 30));

if ($outDir === '') {
    die_with("保存先が決まっていません。--out=<フォルダ> を指定するか、"
           . "config/config.php に backup.dir を書いてください。");
}
if (in_public_dir($outDir)) {
    die_with("保存先が公開フォルダの中です: $outDir\n"
           . "バックアップには患者ID・氏名が含まれます。ブラウザから到達できない場所にしてください。");
}
if (!is_dir($outDir) && !@mkdir($outDir, 0700, true)) {
    die_with("保存先フォルダを作れません: $outDir");
}

// ---- 接続 ----------------------------------------------------------------
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die_with("DBに接続できません: " . $e->getMessage());
}
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

// テーブル一覧
if ($driver === 'sqlite') {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'
                           AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}
if (!$tables) {
    die_with("テーブルが1つもありません。接続先が違う可能性があります: $dsn");
}

// ---- 書き出し ------------------------------------------------------------
// 途中で落ちたファイルを正常なものと区別するため、一時名で書いて最後に改名する。
$stamp = date('Ymd_Hi');
$final = $outDir . '/nissi_' . $stamp . '.sql';
$tmp   = $final . '.writing';

$fh = @fopen($tmp, 'wb');
if ($fh === false) {
    die_with("書き出せません: $tmp");
}

$counts = [];
foreach ($tables as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}

fwrite($fh, "-- 病院日誌・医事統計表 入力システム バックアップ\n");
fwrite($fh, '-- 取得日時: ' . date('Y-m-d H:i:s') . "\n");
fwrite($fh, '-- 接続先  : ' . preg_replace('/;?(user|password)=[^;]*/i', '', $dsn) . "\n");
fwrite($fh, '-- DB      : ' . $driver . ' ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n");
fwrite($fh, "-- 件数    :\n");
foreach ($counts as $t => $n) {
    fwrite($fh, sprintf("--   %-16s %8d\n", $t, $n));
}
fwrite($fh, "--\n-- 復旧: php db/tools/run_sql.php <このファイル> --user=root --pass=...\n\n");

if ($driver === 'mysql') {
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
}

foreach ($tables as $t) {
    fwrite($fh, "-- ---------- $t ({$counts[$t]}件) ----------\n");
    fwrite($fh, "DROP TABLE IF EXISTS $t;\n");

    if ($driver === 'mysql') {
        $row = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_NUM);
        fwrite($fh, $row[1] . ";\n");
    } else {
        $st = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $st->execute([$t]);
        fwrite($fh, $st->fetchColumn() . ";\n");
    }

    // 1000行ずつ取り出す。d_daily_value は入力211項目×365日＝約77,000行/年に育つので、
    // 全件をメモリに載せない。LIMIT/OFFSET はMySQLでもSQLiteでも同じに書ける。
    $cols   = null;
    $n      = 0;
    $offset = 0;
    while (true) {
        $rows = $pdo->query("SELECT * FROM $t LIMIT 1000 OFFSET $offset")
                    ->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            if ($cols === null) {
                $cols = array_keys($r);
                fwrite($fh, 'INSERT INTO ' . $t . ' (' . implode(',', $cols) . ") VALUES\n");
            }
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = lit($pdo, $r[$c]);
            }
            $n++;
            fwrite($fh, ($n > 1 ? ",\n" : '') . '(' . implode(',', $vals) . ')');
        }
        $offset += count($rows);
        if (count($rows) < 1000) {
            break;
        }
    }
    if ($n > 0) {
        fwrite($fh, ";\n");
    }
    if ($n !== $counts[$t]) {
        @unlink($tmp);
        die_with("$t の件数が合いません（見出し {$counts[$t]} / 書き出し {$n}）。"
               . "取得中に更新された可能性があります。");
    }
    fwrite($fh, "\n");
}

if ($driver === 'mysql') {
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
}
fwrite($fh, END_MARK . "\n");
fclose($fh);

if (!@rename($tmp, $final)) {
    @unlink($tmp);
    die_with("書き出したファイルの名前を変えられません: $tmp");
}

$size = filesize($final);
printf("%s  バックアップ完了 %s (%s KB)\n",
    date('Y-m-d H:i:s'), basename($final), number_format($size / 1024, 1));
foreach ($counts as $t => $n) {
    printf("    %-16s %8d\n", $t, $n);
}

// ---- 共有フォルダへ複製（失敗しても本体は成功扱い） -----------------------
$warn = 0;
if ($copyTo !== '') {
    if (!is_dir($copyTo) && !@mkdir($copyTo, 0700, true)) {
        fwrite(STDERR, "  警告: 複製先フォルダが作れません: $copyTo\n");
        $warn++;
    } elseif (!@copy($final, $copyTo . '/' . basename($final))) {
        fwrite(STDERR, "  警告: 複製できません: $copyTo\n");
        $warn++;
    } else {
        echo "    複製先        $copyTo\n";
    }
}

// ---- 古い世代を消す ------------------------------------------------------
foreach ([$outDir, $copyTo] as $dir) {
    if ($dir === '' || !is_dir($dir) || $keep <= 0) {
        continue;
    }
    $files = glob($dir . '/nissi_*.sql') ?: [];
    sort($files);                       // 名前に日時が入るので名前順＝古い順
    $over = count($files) - $keep;
    for ($i = 0; $i < $over; $i++) {
        @unlink($files[$i]);
        echo '    削除（' . $keep . "世代を超過） " . basename($files[$i]) . "\n";
    }
}

if ($warn > 0) {
    fwrite(STDERR, "本体のバックアップは成功しましたが、警告が {$warn} 件あります。\n");
}
exit(0);
