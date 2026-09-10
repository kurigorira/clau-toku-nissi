<?php
/**
 * db/master/*.csv から db/seed_master.sql と docs/項目マスタ対応表.md を生成する。
 *
 * マスタをCSVで持つ理由：
 *   医事課がExcelで開いてレビュー・修正できるようにするため。
 *   SQLを直接編集させると、レビューできる人が限られてしまう。
 *
 * 使い方:  php db/tools/build_seed.php
 */

$root      = dirname(__DIR__, 2);
$masterDir = $root . '/db/master';
$outSql    = $root . '/db/seed_master.sql';
$outDoc    = $root . '/docs/項目マスタ対応表.md';

/** CSVを連想配列の配列として読む。1行目をヘッダとして扱う。 */
function read_csv(string $path): array
{
    $fp = fopen($path, 'r');
    if ($fp === false) {
        fwrite(STDERR, "読めません: $path\n");
        exit(1);
    }
    $head = fgetcsv($fp);
    // Excelで保存するとBOMが付くことがあるので先頭列名から除去する
    if ($head && isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    }
    $rows = [];
    $line = 1;
    while (($r = fgetcsv($fp)) !== false) {
        $line++;
        if (count($r) === 1 && trim((string)$r[0]) === '') {
            continue; // 空行は読み飛ばす
        }
        if (count($r) !== count($head)) {
            fwrite(STDERR, basename($path) . " {$line}行目: 列数が " . count($r)
                . " です（ヘッダは " . count($head) . " 列）。カンマを含む値は \"\" で囲ってください\n");
            exit(1);
        }
        $rows[] = array_combine($head, $r);
    }
    fclose($fp);
    return $rows;
}

/** SQLリテラル。値が空文字なら NULL にするかどうかを $nullable で切り替える。 */
function q($v, bool $nullable = false): string
{
    $v = trim((string)$v);
    if ($v === '') {
        return $nullable ? 'NULL' : "''";
    }
    return "'" . str_replace("'", "''", $v) . "'";
}

/** 数値リテラル。空なら NULL。 */
function n($v, $default = 'NULL'): string
{
    $v = trim((string)$v);
    return $v === '' ? $default : (string)(0 + $v);
}

$depts  = read_csv("$masterDir/depts.csv");
$items  = read_csv("$masterDir/items.csv");
$config = read_csv("$masterDir/config.csv");

// ---- 整合性チェック（不正なマスタで帳票が狂うのを防ぐ） -------------------
$errors   = [];
$deptSet  = array_flip(array_column($depts, 'dept_id'));
$itemSet  = array_flip(array_column($items, 'item_code'));

$codes = array_column($items, 'item_code');
foreach (array_count_values($codes) as $code => $cnt) {
    if ($cnt > 1) {
        $errors[] = "item_code が重複: {$code}（{$cnt}回）";
    }
}

foreach ($items as $it) {
    $code = $it['item_code'];
    if (!isset($deptSet[$it['dept_id']])) {
        $errors[] = "{$code}: dept_id '{$it['dept_id']}' が depts.csv にありません";
    }
    if (!in_array($it['calc_type'], ['input', 'sum', 'func'], true)) {
        $errors[] = "{$code}: calc_type '{$it['calc_type']}' は input/sum/func のいずれかです";
    }
    if ($it['calc_type'] === 'sum') {
        if (trim($it['calc_source']) === '') {
            $errors[] = "{$code}: calc_type=sum なのに calc_source が空です";
        }
        foreach (explode(',', $it['calc_source']) as $src) {
            $src = trim($src);
            if ($src !== '' && !isset($itemSet[$src])) {
                $errors[] = "{$code}: calc_source の '{$src}' が未定義です";
            }
        }
    }
    if ($it['calc_type'] === 'func') {
        if (trim($it['calc_source']) === '') {
            $errors[] = "{$code}: calc_type=func なのに calc_source（関数名）が空です";
        }
        // ratio:A/B は A と B が実在する項目であること
        if (strpos($it['calc_source'], 'ratio:') === 0) {
            foreach (explode('/', substr($it['calc_source'], 6)) as $src) {
                $src = trim($src);
                if (!isset($itemSet[$src])) {
                    $errors[] = "{$code}: ratio の '{$src}' が未定義です";
                }
            }
        }
    }
    if ($it['calc_type'] !== 'input' && trim((string)$it['required']) === '1') {
        $errors[] = "{$code}: 導出項目に required=1 は付けられません（入力欄が無いため必ず欠測になる）";
    }
}

if ($errors) {
    fwrite(STDERR, "マスタに問題があります:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

// ---- seed_master.sql の生成 ----------------------------------------------
$sql   = [];
$sql[] = '-- 自動生成ファイル。直接編集しないこと。';
$sql[] = '-- db/master/*.csv を編集し、php db/tools/build_seed.php で再生成する。';
$sql[] = '-- 生成日時: ' . date('Y-m-d H:i:s');
$sql[] = '';
$sql[] = 'DELETE FROM m_item;';
$sql[] = 'DELETE FROM m_config;';
$sql[] = 'DELETE FROM m_dept;';
$sql[] = '';

foreach ($depts as $d) {
    $sql[] = 'INSERT INTO m_dept (dept_id,dept_name,sort_no,deadline_time,entry_days,is_active) VALUES ('
        . q($d['dept_id']) . ',' . q($d['dept_name']) . ',' . n($d['sort_no'], '0') . ','
        . q($d['deadline_time']) . ',' . q($d['entry_days']) . ',' . n($d['is_active'], '1') . ');';
}
$sql[] = '';

foreach ($items as $i) {
    $sql[] = 'INSERT INTO m_item (item_code,item_name,dept_id,group_code,group_name,calc_type,calc_source,'
        . 'value_type,agg_type,unit,sort_no,required,min_value,max_value,valid_from,valid_to,legacy_column,excel_ref,note) VALUES ('
        . q($i['item_code']) . ',' . q($i['item_name']) . ',' . q($i['dept_id']) . ','
        . q($i['group_code']) . ',' . q($i['group_name']) . ',' . q($i['calc_type']) . ','
        . q($i['calc_source'], true) . ',' . q($i['value_type']) . ',' . q($i['agg_type']) . ',' . q($i['unit']) . ','
        . n($i['sort_no'], '0') . ',' . n($i['required'], '0') . ','
        . n($i['min_value']) . ',' . n($i['max_value']) . ","
        . "'2000-01-01',NULL,"
        . q($i['legacy_column'], true) . ',' . q($i['excel_ref'], true) . ',' . q($i['note']) . ');';
}
$sql[] = '';

foreach ($config as $c) {
    $sql[] = 'INSERT INTO m_config (config_key,valid_from,config_value,note) VALUES ('
        . q($c['config_key']) . ',' . q($c['valid_from']) . ',' . q($c['config_value']) . ',' . q($c['note']) . ');';
}

file_put_contents($outSql, implode("\n", $sql) . "\n");

// ---- 対応表ドキュメントの生成 ---------------------------------------------
$doc   = [];
$doc[] = '# 項目マスタ対応表';
$doc[] = '';
$doc[] = '自動生成ファイル。`db/master/items.csv` を編集し `php db/tools/build_seed.php` で再生成する。';
$doc[] = '';
$doc[] = '- **旧列名** … 現行 `nissi` テーブルの列名。空欄は病院日誌に無かった項目';
$doc[] = '- **Excel** … 現行の医事統計表ブックでの位置（シート!列）。空欄はExcelに無かった項目';
$doc[] = '- **区分** … `入力` は現場が手入力する項目。`合算`・`計算` はシステムが自動算出し、入力欄を作らない項目';
$doc[] = '';

$totals = array_count_values(array_column($items, 'calc_type'));
$doc[] = sprintf('全 %d 項目（入力 %d / 合算 %d / 計算 %d）',
    count($items), $totals['input'] ?? 0, $totals['sum'] ?? 0, $totals['func'] ?? 0);
$doc[] = '';

$label = ['input' => '入力', 'sum' => '合算', 'func' => '計算'];
foreach ($depts as $d) {
    $mine = array_values(array_filter($items, fn($i) => $i['dept_id'] === $d['dept_id']));
    if (!$mine) {
        continue;
    }
    usort($mine, fn($a, $b) => (int)$a['sort_no'] <=> (int)$b['sort_no']);
    $doc[] = '## ' . $d['dept_name'] . '（' . $d['dept_id'] . '）　' . count($mine) . '項目';
    $doc[] = '';
    $doc[] = '| 項目コード | 項目名 | 区分 | 単位 | 必須 | 旧列名 | Excel | 算出元・備考 |';
    $doc[] = '|---|---|---|---|---|---|---|---|';
    foreach ($mine as $i) {
        $src = $i['calc_type'] === 'input' ? '' : $i['calc_source'];
        $memo = trim($src . ($src && $i['note'] ? ' / ' : '') . $i['note']);
        $doc[] = '| `' . $i['item_code'] . '` | ' . $i['item_name'] . ' | ' . $label[$i['calc_type']]
            . ' | ' . $i['unit'] . ' | ' . ($i['required'] === '1' ? '○' : '')
            . ' | ' . ($i['legacy_column'] ? '`' . $i['legacy_column'] . '`' : '')
            . ' | ' . $i['excel_ref'] . ' | ' . str_replace('|', '\\|', $memo) . ' |';
    }
    $doc[] = '';
}

file_put_contents($outDoc, implode("\n", $doc) . "\n");

printf("生成しました\n  %s  (%d部署 / %d項目 / 設定%d件)\n  %s\n",
    $outSql, count($depts), count($items), count($config), $outDoc);
