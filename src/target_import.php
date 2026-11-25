<?php
/**
 * 事業計画（年度の月別の目標）の Excel を読み、月別の目標値（m_target_month）にする。
 *
 * Excel は「R8年度」のようなシートで、行＝項目、列＝4月〜3月（見出し行に「4月」…「3月」）。
 * 多くの項目は「平均」の行と「延数」の行の2行組で、見出しは上の行（B列。無ければA列）にだけ書いてある。
 * どの行をどの項目にするかは db/master/target_map.csv（見出し・何番目か・項目コード）で決める。
 * 位置（何行目か）ではなく見出しで探すので、年度ごとに行が増減しても読める。
 */
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/master.php';

/** 見出しの比べ方：全角英数・カナをそろえ、空白（全角も）を除く。 */
function target_norm(string $s): string
{
    return preg_replace('/[\s　]+/u', '', mb_convert_kana($s, 'asKV'));
}

/** 対応表（db/master/target_map.csv）。 */
function target_map(): array
{
    $path = dirname(__DIR__) . '/db/master/target_map.csv';
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException('対応表（db/master/target_map.csv）を読めません。');
    }
    $head = fgetcsv($fh, 0, ',', '"', '');
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    $map = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($r === [null] || trim((string)$r[0]) === '') {
            continue;
        }
        $row = array_combine($head, array_pad($r, count($head), ''));
        $map[] = ['label' => $row['label'], 'key' => target_norm($row['label']),
                  'nth' => max(1, (int)$row['nth']), 'item_code' => $row['item_code'], 'note' => $row['note']];
    }
    fclose($fh);
    return $map;
}

/**
 * Excel のシート一覧から月別の目標を読む。
 *
 * @return array fy（年度）, sheet（シート名）,
 *               items（item_code => ['months' => ['2026-04' => 延数, …], 'from' => ['見出し（行）', …]]）,
 *               skipped（取り込まない行：['row','label','total']）, notfound（対応表にあるのに見つからない見出し）
 */
function target_parse(array $sheets, ?int $fy = null): array
{
    // 「4月」〜「3月」が並ぶ見出し行のあるシートを探す
    foreach ($sheets as $name => $cells) {
        $grid = xlsx_grid($cells);
        foreach ($grid as $r => $row) {
            $cols = [];
            foreach ($row as $c => $v) {
                if (preg_match('/^(\d{1,2})月$/u', target_norm((string)$v), $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12) {
                    $cols[$c] = (int)$m[1];
                }
            }
            if (count($cols) === 12) {
                return target_parse_grid($grid, $r, $cols, (string)$name, $fy);
            }
        }
    }
    throw new RuntimeException('「4月」〜「3月」の見出しが並ぶ行が見つかりません。事業計画の Excel か確かめてください。');
}

/** 見出し行（$headRow）と月の列（列番号 => 月）が分かったシートを読む。 */
function target_parse_grid(array $grid, int $headRow, array $cols, string $sheet, ?int $fy): array
{
    // 年度：「（2026年度）」の書き方を上の方から探す。見つからなければ画面で選んだ年度
    if ($fy === null) {
        foreach ($grid as $r => $row) {
            if ($r >= $headRow) {
                break;
            }
            foreach ($row as $v) {
                if (preg_match('/(20\d{2})年度/u', target_norm((string)$v), $m)) {
                    $fy = (int)$m[1];
                    break 2;
                }
            }
        }
    }
    if ($fy === null) {
        throw new RuntimeException('年度が読めません（「2026年度」のような書き方が見つかりません）。年度を選んでから読み込んでください。');
    }

    // 値のある行を順に見て、見出しと「何番目の同じ見出しか」を付ける
    $rows  = [];          // ['row', 'label', 'key', 'nth', 'months' => [ym => 値]]
    $seen  = [];
    $label = '';
    $avgOf = null;        // 直前の「平均」の行の見出し。次の「延数」の行はこの見出しのもの
    foreach ($grid as $r => $row) {
        if ($r <= $headRow) {
            continue;
        }
        $a = trim((string)($row[1] ?? ''));
        $b = trim((string)($row[2] ?? ''));
        $c = target_norm((string)($row[3] ?? ''));
        // 見出しは B列。B が空で A に2文字以上あれば A（A の1文字は縦書きの区分なので見出しにしない）
        if ($c === '延数' && $avgOf !== null) {
            $label = $avgOf;     // 「延数」の行のB列に別の文字があっても、上の「平均」の行の見出しで読む
        } elseif ($b !== '') {
            $label = $b;
        } elseif (mb_strlen(target_norm($a)) >= 2) {
            $label = $a;
        }
        $avgOf = null;
        if ($c === '平均') {
            $avgOf = $label;
            continue;      // 平均の行は使わない（延数の行を使う）
        }
        if ($label === '') {
            continue;
        }
        $months = [];
        foreach ($cols as $col => $mon) {
            $v = $row[$col] ?? '';
            if ($v !== '' && is_numeric($v)) {
                $months[sprintf('%04d-%02d', $mon >= 4 ? $fy : $fy + 1, $mon)] = (float)$v;
            }
        }
        if (!$months) {
            continue;
        }
        ksort($months);
        $key = target_norm($label);
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        $rows[] = ['row' => $r, 'label' => $label, 'key' => $key, 'nth' => $seen[$key], 'months' => $months];
    }

    $items    = [];
    $used     = [];
    $notfound = [];
    foreach (target_map() as $m) {
        $hit = null;
        foreach ($rows as $i => $x) {
            if ($x['key'] === $m['key'] && $x['nth'] === $m['nth']) {
                $hit = $i;
                break;
            }
        }
        if ($hit === null) {
            $notfound[] = $m['label'] . ($m['nth'] > 1 ? "（{$m['nth']}番目）" : '') . ' → ' . $m['item_code'];
            continue;
        }
        $used[$hit] = true;
        $x = $rows[$hit];
        foreach ($x['months'] as $ym => $v) {
            $items[$m['item_code']]['months'][$ym] = ($items[$m['item_code']]['months'][$ym] ?? 0) + $v;
        }
        $items[$m['item_code']]['from'][] = $x['label'] . "（{$x['row']}行目）";
    }
    $skipped = [];
    foreach ($rows as $i => $x) {
        if (!isset($used[$i])) {
            $skipped[] = ['row' => $x['row'], 'label' => $x['label'], 'total' => array_sum($x['months'])];
        }
    }
    return ['fy' => $fy, 'sheet' => $sheet, 'items' => $items, 'skipped' => $skipped, 'notfound' => $notfound];
}

/**
 * 読んだ目標を登録する。その年度の月別の目標を項目ごとに入れ替え、
 * 年度の1日平均（m_target）も年間合計÷年度の日数で更新する（月別が無い月の補いと、目標値の画面に使う）。
 *
 * @param array $items item_code => ['ym' => 延数, …]
 * @return int 登録した項目数
 */
function target_save(int $fy, array $items, string $userId): int
{
    $known = all_items();
    $days  = (int)(new DateTimeImmutable("{$fy}-04-01"))->diff(new DateTimeImmutable(($fy + 1) . '-04-01'))->days;
    $now   = date('Y-m-d H:i:s');
    $n = 0;
    db()->beginTransaction();
    try {
        foreach ($items as $code => $months) {
            if (!isset($known[$code])) {
                continue;
            }
            db_exec('DELETE FROM m_target_month WHERE item_code = ? AND ym BETWEEN ? AND ?',
                    [$code, sprintf('%04d-04', $fy), sprintf('%04d-03', $fy + 1)]);
            foreach ($months as $ym => $v) {
                if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym) || fiscal_year($ym . '-01') !== $fy || !is_numeric($v)) {
                    continue;
                }
                db_exec('INSERT INTO m_target_month (item_code, ym, target_total, updated_by, updated_at) VALUES (?,?,?,?,?)',
                        [$code, $ym, round((float)$v, 2), $userId, $now]);
            }
            db_exec('DELETE FROM m_target WHERE item_code = ? AND fiscal_year = ?', [$code, $fy]);
            db_exec('INSERT INTO m_target (item_code, fiscal_year, target_avg) VALUES (?,?,?)',
                    [$code, $fy, round(array_sum(array_map('floatval', $months)) / $days, 2)]);
            $n++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $n;
}
