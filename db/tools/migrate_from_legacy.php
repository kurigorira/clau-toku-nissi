<?php
/**
 * 旧 nissi テーブル（1日1行・約110列・CHARSET=sjis）から
 * 新しい縦持ち（d_daily_value）へ移行する。
 *
 * 対応づけは項目マスタの legacy_column 列を使う。
 * 旧テーブルに無い列（den3/gas3/sui3/qq4/tokki3a〜tokki6e など、
 * 画面は参照していたが CREATE TABLE に無かった列）は自動で読み飛ばし、
 * 最後にまとめて報告する。
 *
 * 文字コード：旧テーブルは sjis なので、接続時に sjis を指定して読み、
 * PHP側で UTF-8 に変換してから書き込む。日本語が入る列（天候・術名・
 * 会議名・行事・人事）は移行後に必ず目視で確認すること。
 *
 * 使い方:
 *   php db/tools/migrate_from_legacy.php --dsn=... --user=... --pass=... [--dry-run] [--from=YYYY-MM-DD]
 *
 *   --dry-run  書き込まずに件数だけ数える。まずこれで確認する
 */

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/master.php';

// ---- 引数 ----------------------------------------------------------------
$opt = getopt('', ['dsn:', 'user:', 'pass:', 'dry-run', 'from:', 'to:', 'charset:']);
$dsn = $opt['dsn'] ?? null;
if ($dsn === null) {
    fwrite(STDERR, <<<TXT
旧 nissi テーブルからの移行ツール

使い方:
  php db/tools/migrate_from_legacy.php \
      --dsn="mysql:host=localhost;dbname=nissi_old" --user=読み取り用 --pass=... [--dry-run]

オプション:
  --dry-run       書き込まずに件数だけ表示する（まずこれで確認する）
  --from / --to   移行する日付の範囲（省略すると全件）
  --charset       旧テーブルの文字コード。既定 sjis

TXT);
    exit(1);
}
$dryRun  = isset($opt['dry-run']);
$charset = $opt['charset'] ?? 'sjis';

// ---- 旧DBに接続（読み取り専用のつもりで扱う） -----------------------------
try {
    $old = new PDO($dsn, $opt['user'] ?? null, $opt['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "旧DBに接続できません: " . $e->getMessage() . "\n");
    exit(1);
}
// 旧テーブルは sjis。そのバイト列のまま受け取り、PHP側で変換する
if (strpos($dsn, 'mysql:') === 0) {
    $old->exec("SET NAMES " . ($charset === 'sjis' ? 'sjis' : $charset));
}

// ---- 対応表を作る --------------------------------------------------------
$items  = all_items();
$byCol  = [];        // 旧列名 => 項目マスタの行
foreach ($items as $code => $it) {
    if ($it['calc_type'] === 'input' && !empty($it['legacy_column'])) {
        $byCol[$it['legacy_column']] = $it;
    }
}

// 旧テーブルに実在する列を調べる
$cols = [];
foreach ($old->query('SELECT * FROM nissi LIMIT 1') as $row) {
    $cols = array_keys($row);
}
if (!$cols) {
    // 1行も無い場合はメタ情報から取る
    $st = $old->query('SELECT * FROM nissi LIMIT 0');
    for ($i = 0; $i < $st->columnCount(); $i++) {
        $cols[] = $st->getColumnMeta($i)['name'];
    }
}
$present = array_flip($cols);

$mapped  = [];
$missing = [];
foreach ($byCol as $col => $it) {
    if (isset($present[$col])) {
        $mapped[$col] = $it;
    } else {
        $missing[$col] = $it['item_code'];
    }
}
// 未対応の列を理由別に分ける。黙って捨てると、後から「移行できていない」と気づけないため。
//   derived … 新システムでは導出項目にあたる列。旧日誌は粒度が粗く（例: CTを1件としか持たない）、
//             入院・外来に分けられないため移行できない
//   tokki   … 特記事項。d_tokki へ別途移行する
//   unknown … 対応先が決まっていない列
$derivedCols = [];
foreach ($items as $code => $it) {
    if ($it['calc_type'] !== 'input' && !empty($it['legacy_column'])) {
        $derivedCols[$it['legacy_column']] = $code;
    }
}
$unmapped = $unknown = [];
foreach (array_diff($cols, array_keys($mapped), ['hizuke']) as $c) {
    if (isset($derivedCols[$c]))            { $unmapped[$c] = $derivedCols[$c]; }
    elseif (strpos($c, 'tokki') === 0)      { /* d_tokki へ移行する */ }
    else                                    { $unknown[] = $c; }
}

echo "旧テーブルの列数        : " . count($cols) . "\n";
echo "対応づいた列（移行する）: " . count($mapped) . "\n";
echo "マスタにあるが旧に無い  : " . count($missing) . ($missing ? "（" . implode(', ', array_keys($missing)) . "）" : '') . "\n";
echo "\n";
if ($unmapped) {
    echo "■ 移行できない列 " . count($unmapped) . " 件\n";
    echo "  新システムではこれらを入院・外来などに分けて持つため、旧日誌の粗い値は取り込めない。\n";
    echo "  移行後、これらの項目は空欄になる（新しく入力した日以降は自動で算出される）。\n";
    foreach ($unmapped as $c => $code) {
        echo "    {$c} → {$code}（" . ($items[$code]['item_name'] ?? '') . "）\n";
    }
    echo "\n";
}
if ($unknown) {
    echo "■ 対応先が決まっていない列 " . count($unknown) . " 件: " . implode(', ', $unknown) . "\n\n";
}

// ---- 移行 ----------------------------------------------------------------
$where  = [];
$params = [];
if (!empty($opt['from'])) { $where[] = 'hizuke >= ?'; $params[] = $opt['from']; }
if (!empty($opt['to']))   { $where[] = 'hizuke <= ?'; $params[] = $opt['to']; }
$sql = 'SELECT * FROM nissi' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY hizuke';
$st  = $old->prepare($sql);
$st->execute($params);

$new = db();
$now = date('Y-m-d H:i:s');
$ins = $new->prepare('INSERT INTO d_daily_value (hizuke,item_code,value_num,value_text,created_by,created_at,updated_by,updated_at)
                      VALUES (?,?,?,?,?,?,?,?)');
$sel = $new->prepare('SELECT 1 FROM d_daily_value WHERE hizuke = ? AND item_code = ?');
$aud = $new->prepare('INSERT INTO d_audit (hizuke,item_code,action,old_value,new_value,acted_by,acted_at,client_ip)
                      VALUES (?,?,?,?,?,?,?,?)');

$days = $written = $skipped = $exists = 0;
$textSamples = [];

if (!$dryRun) { $new->beginTransaction(); }
foreach ($st as $row) {
    $date = $row['hizuke'];
    if (!$date) { continue; }
    $days++;
    foreach ($mapped as $col => $it) {
        $raw = $row[$col] ?? null;
        if ($raw === null || $raw === '') { $skipped++; continue; }

        // sjis → UTF-8。既にUTF-8なら二重変換しない
        $val = is_string($raw) && $charset !== 'utf8'
            ? mb_convert_encoding($raw, 'UTF-8', $charset === 'sjis' ? 'SJIS-win' : $charset)
            : $raw;

        $isText = in_array($it['value_type'], ['text', 'multiline'], true);
        $num  = null;
        $text = null;
        if ($isText) {
            $text = $val;
            if (count($textSamples) < 12) {
                $textSamples[$it['item_code']] = mb_substr((string)$val, 0, 24);
            }
        } else {
            if (!is_numeric($val)) {
                // 数値列に文字が入っている。捨てずに文字として残し、後で確認できるようにする
                $text = $val;
            } else {
                $num = (float)$val;
            }
        }

        $sel->execute([$date, $it['item_code']]);
        if ($sel->fetch()) { $exists++; continue; }   // 既にある行は上書きしない

        if (!$dryRun) {
            $ins->execute([$date, $it['item_code'], $num, $text, 'MIGRATION', $now, 'MIGRATION', $now]);
            $aud->execute([$date, $it['item_code'], 'insert', null,
                           $num === null ? $text : (string)$num, 'MIGRATION', $now, '']);
        }
        $written++;
    }
}
// ---- 特記事項（定員超過報告）を d_tokki へ ---------------------------------
// 旧テーブルは tokki1a〜tokki6e の固定6行。新システムは可変行の別テーブル。
$tokkiRows = 0;
$insTokki  = $new->prepare('INSERT INTO d_tokki (hizuke,ward,patient_id,patient_name,route,diagnosis,sort_no,
                            created_by,created_at,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$selTokki  = $new->prepare('SELECT 1 FROM d_tokki WHERE hizuke = ? AND sort_no = ?');

$st2 = $old->prepare($sql);
$st2->execute($params);
foreach ($st2 as $row) {
    $date = $row['hizuke'];
    if (!$date) { continue; }
    for ($i = 1; $i <= 6; $i++) {
        $cells = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $suffix) {
            $col = "tokki{$i}{$suffix}";
            $raw = $row[$col] ?? null;
            $cells[$suffix] = ($raw === null || $raw === '') ? ''
                : (is_string($raw) && $charset !== 'utf8'
                    ? mb_convert_encoding($raw, 'UTF-8', $charset === 'sjis' ? 'SJIS-win' : $charset)
                    : (string)$raw);
        }
        // 5項目すべて空の行は登録しない
        if (implode('', $cells) === '') { continue; }
        $selTokki->execute([$date, $i]);
        if ($selTokki->fetch()) { continue; }
        if (!$dryRun) {
            $insTokki->execute([$date, $cells['a'], $cells['b'], $cells['c'], $cells['d'], $cells['e'],
                                $i, 'MIGRATION', $now, 'MIGRATION', $now]);
        }
        $tokkiRows++;
    }
}

if (!$dryRun) { $new->commit(); }

echo ($dryRun ? "【下見】書き込みはしていません\n" : "移行しました\n");
echo "  対象日数    : {$days}\n";
echo "  書き込み    : {$written}\n";
echo "  空欄で除外  : {$skipped}\n";
echo "  既存のため除外: {$exists}\n";
echo "  特記事項      : {$tokkiRows} 件\n";

if ($textSamples) {
    echo "\n文字化けの確認（先頭24文字）。読めない場合は --charset を見直すこと:\n";
    foreach ($textSamples as $code => $s) {
        echo "  {$code}: {$s}\n";
    }
}
