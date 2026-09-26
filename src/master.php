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

/** 日付が属する年度（4月始まり）。 */
function fiscal_year(string $date): int
{
    $y = (int)substr($date, 0, 4);
    $m = (int)substr($date, 5, 2);
    return $m >= 4 ? $y : $y - 1;
}
