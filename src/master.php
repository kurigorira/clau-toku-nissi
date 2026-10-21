<?php
/**
 * マスタの読み込み。1リクエスト内でキャッシュする。
 */

require_once __DIR__ . '/db.php';

/** 部署マスタを dept_id => 行 で返す。 */
function all_depts(bool $activeOnly = true): array
{
    static $cache = [];
    $k = $activeOnly ? 'a' : 'x';
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    $sql = 'SELECT * FROM m_dept' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_no';
    $rows = [];
    foreach (db_all($sql) as $r) {
        $rows[$r['dept_id']] = $r;
    }
    return $cache[$k] = $rows;
}

/**
 * 項目マスタを item_code => 行 で返す。
 * $date を渡すと、その日に有効だった項目だけに絞る。
 * 廃止した項目も過去の帳票では表示できるようにするため。
 */
function all_items(?string $date = null): array
{
    static $cache = [];
    $k = $date ?? 'all';
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    if ($date === null) {
        $rows = db_all('SELECT * FROM m_item ORDER BY sort_no');
    } else {
        $rows = db_all(
            'SELECT * FROM m_item WHERE valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY sort_no',
            [$date, $date]
        );
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['item_code']] = $r;
    }
    return $cache[$k] = $out;
}

/** ある部署が入力する項目（calc_type='input' のみ）を表示順で返す。 */
function input_items_of_dept(string $deptId, ?string $date = null): array
{
    $out = [];
    foreach (all_items($date) as $code => $it) {
        if ($it['dept_id'] === $deptId && $it['calc_type'] === 'input') {
            $out[$code] = $it;
        }
    }
    return $out;
}

/**
 * 設定値を返す。$date 時点で有効な最新のものを採る。
 * 定床のように途中で変わる値があるため、日付で引けるようにしている。
 * （2025年6月の病床再編で 4階27→39 / 5階51→39 に変わっている）
 */
function config_value(string $key, string $date, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT * FROM m_config ORDER BY config_key, valid_from') as $r) {
            $cache[$r['config_key']][] = $r;
        }
    }
    $best = $default;
    foreach ($cache[$key] ?? [] as $r) {
        if ($r['valid_from'] <= $date) {
            $best = $r['config_value'];
        }
    }
    return $best;
}

/** 目標値（年度単位）。 */
function target_value(string $itemCode, int $fiscalYear): ?float
{
    $r = db_row('SELECT target_avg FROM m_target WHERE item_code = ? AND fiscal_year = ?', [$itemCode, $fiscalYear]);
    return $r === null || $r['target_avg'] === null ? null : (float)$r['target_avg'];
}

/**
 * 期間の目標延数（項目ごと）。総括の「目標延数」と達成率に使う。
 *
 * 日ごとの目標＝その月の目標延数（m_target_month）÷その月の日数。期間に掛かる日数ぶん足す
 * （10/1〜10/20 なら 10月の目標×20/31。月・年度をまたいでもそのまま正しい）。
 * 月別が無い月は、年度の1日平均（m_target）×日数で補う。どちらも無い月がある項目は入れない。
 *
 * @return array item_code => 目標延数
 */
function targets_for_period(array $codes, string $from, string $to): array
{
    if (!$codes) {
        return [];
    }
    // 期間に掛かる月ごとの日数
    $months = [];
    for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
        $ym = $d->format('Y-m');
        $months[$ym] = ($months[$ym] ?? 0) + 1;
    }
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $monthly = [];
    try {
        foreach (db_all("SELECT item_code, ym, target_total FROM m_target_month
                          WHERE item_code IN ($ph) AND ym BETWEEN ? AND ?",
                        array_merge(array_values($codes), [substr($from, 0, 7), substr($to, 0, 7)])) as $r) {
            $monthly[$r['item_code']][$r['ym']] = (float)$r['target_total'];
        }
    } catch (Throwable $e) {
        // 004_target_month.sql を流していないサーバ。年度の1日平均だけで計算する
    }
    $annual = [];
    $fys = array_unique(array_map(fn($ym) => fiscal_year($ym . '-01'), array_keys($months)));
    foreach (db_all("SELECT item_code, fiscal_year, target_avg FROM m_target
                      WHERE item_code IN ($ph) AND fiscal_year IN (" . implode(',', array_map('intval', $fys)) . ')',
                    array_values($codes)) as $r) {
        if ($r['target_avg'] !== null) {
            $annual[$r['item_code']][(int)$r['fiscal_year']] = (float)$r['target_avg'];
        }
    }
    $out = [];
    foreach ($codes as $code) {
        // 期間のどこかの月に目標が無ければ、その項目は目標なし（一部の月だけで比べると達成率が狂う）
        $sum = 0.0;
        foreach ($months as $ym => $n) {
            if (isset($monthly[$code][$ym])) {
                $sum += $monthly[$code][$ym] / (int)date('t', strtotime($ym . '-01')) * $n;
            } elseif (isset($annual[$code][fiscal_year($ym . '-01')])) {
                $sum += $annual[$code][fiscal_year($ym . '-01')] * $n;
            } else {
                continue 2;
            }
        }
        $out[$code] = $sum;
    }
    return $out;
}

/** 日付が属する年度（4月始まり）。 */
function fiscal_year(string $date): int
{
    $y = (int)substr($date, 0, 4);
    $m = (int)substr($date, 5, 2);
    return $m >= 4 ? $y : $y - 1;
}
