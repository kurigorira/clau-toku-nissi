<?php
/**
 * 日次実績をCSVから取り込む。過去データの移行に使う。
 *
 * CSVの形式:  hizuke,item_code,value
 * 入力項目（calc_type='input'）だけを取り込む。導出項目は計算で出すため
 * 取り込まない（取り込むと二重管理になり、Excelと同じ壊れ方をする）。
 *
 * 使い方:  php db/tools/import_daily_csv.php <csv> [取り込み者ID]
 */

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/master.php';

$csvPath = $argv[1] ?? null;
$actor   = $argv[2] ?? 'IMPORT';
if ($csvPath === null || !is_file($csvPath)) {
    fwrite(STDERR, "使い方: php db/tools/import_daily_csv.php <csv> [取り込み者ID]\n");
    exit(1);
}

$items = all_items();
$fp    = fopen($csvPath, 'r');
$head  = fgetcsv($fp);
$now   = date('Y-m-d H:i:s');
$pdo   = db();

$ok = $skip = 0;
$unknown = [];
$pdo->beginTransaction();
$sel = $pdo->prepare('SELECT value_num FROM d_daily_value WHERE hizuke = ? AND item_code = ?');
$ins = $pdo->prepare('INSERT INTO d_daily_value (hizuke,item_code,value_num,created_by,created_at,updated_by,updated_at)
                      VALUES (?,?,?,?,?,?,?)');
$upd = $pdo->prepare('UPDATE d_daily_value SET value_num = ?, updated_by = ?, updated_at = ? WHERE hizuke = ? AND item_code = ?');
$aud = $pdo->prepare('INSERT INTO d_audit (hizuke,item_code,action,old_value,new_value,acted_by,acted_at,client_ip)
                      VALUES (?,?,?,?,?,?,?,?)');

while (($r = fgetcsv($fp)) !== false) {
    $row  = array_combine($head, $r);
    $code = $row['item_code'];
    $it   = $items[$code] ?? null;
    if ($it === null || $it['calc_type'] !== 'input') {
        $unknown[$code] = true;
        $skip++;
        continue;
    }
    $val = $row['value'] === '' ? null : (float)$row['value'];

    $sel->execute([$row['hizuke'], $code]);
    $cur = $sel->fetch();
    $old = $cur === false ? null : (string)(float)$cur['value_num'];
    $new = $val === null ? null : (string)$val;
    if ($cur !== false && (string)$old === (string)$new) {
        continue;
    }
    if ($cur === false) {
        $ins->execute([$row['hizuke'], $code, $val, $actor, $now, $actor, $now]);
        $aud->execute([$row['hizuke'], $code, 'insert', null, $new, $actor, $now, '']);
    } else {
        $upd->execute([$val, $actor, $now, $row['hizuke'], $code]);
        $aud->execute([$row['hizuke'], $code, 'update', $old, $new, $actor, $now, '']);
    }
    $ok++;
}
$pdo->commit();

echo "取り込み: {$ok}件  スキップ: {$skip}件\n";
if ($unknown) {
    echo "取り込まなかった項目コード（導出項目か未定義）: " . implode(', ', array_keys($unknown)) . "\n";
}
