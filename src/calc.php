<?php
/**
 * 導出項目の算出。現行Excelが数式でやっていたことをここに集約する。
 *
 * 【なぜ保存せず毎回計算するか】
 * 現行Excelは導出値を各シートに転記していたため、参照がずれても
 * 誰も気づかず、同じ項目が集計シートと帳票シートで違う数字になっていた
 * （検体検査が総括では2,418、患者数統計表では286になっていた）。
 * 数字の出どころを1つにすれば、この壊れ方は起こらない。
 *
 * 【期間の扱い】
 * すべての関数は開始日〜終了日を受け取る。日次帳票は from=to で呼ぶ。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';

/**
 * 期間内の全項目の値を item_code => float|null で返す。
 *
 * 入力項目は agg_type に従って集計する。
 *   sum  … 期間内を合算する（新入院・検査件数などフロー）
 *   last … 期間内で最後に入力された値を採る（登録人数などストック）
 */
function period_values(string $from, string $to): array
{
    $items = all_items($to);
    $vals  = [];

    // --- 入力項目をまとめて集計（項目ごとにSQLを撃たない） ---
    foreach (db_all(
        'SELECT item_code, SUM(value_num) AS s FROM d_daily_value
          WHERE hizuke BETWEEN ? AND ? AND value_num IS NOT NULL
          GROUP BY item_code',
        [$from, $to]
    ) as $r) {
        $vals[$r['item_code']] = $r['s'] === null ? null : (float)$r['s'];
    }

    // ストック項目は合算ではなく最終日の値を採る
    $stock = [];
    foreach ($items as $code => $it) {
        if ($it['calc_type'] === 'input' && $it['agg_type'] === 'last') {
            $stock[] = $code;
        }
    }
    if ($stock) {
        $ph = implode(',', array_fill(0, count($stock), '?'));
        foreach (db_all(
            "SELECT item_code, value_num FROM d_daily_value
              WHERE hizuke BETWEEN ? AND ? AND item_code IN ($ph) AND value_num IS NOT NULL
              ORDER BY hizuke",
            array_merge([$from, $to], $stock)
        ) as $r) {
            // 日付順に上書きするので、最後に残るのが最終日の値になる
            $vals[$r['item_code']] = (float)$r['value_num'];
        }
    }

    // --- 導出項目を解決 ---
    $resolving = [];
    foreach ($items as $code => $it) {
        if ($it['calc_type'] !== 'input') {
            $vals[$code] = resolve_item($code, $items, $vals, $from, $to, $resolving);
        }
    }
    return $vals;
}

/** 1日ぶんの値。病院日誌はこれを使う。 */
function daily_values(string $date): array
{
    return period_values($date, $date);
}

/** 文字項目（天候・術名・会議名など）をまとめて取る。 */
function daily_texts(string $date): array
{
    $out = [];
    foreach (db_all(
        'SELECT item_code, value_text FROM d_daily_value WHERE hizuke = ? AND value_text IS NOT NULL',
        [$date]
    ) as $r) {
        $out[$r['item_code']] = $r['value_text'];
    }
    return $out;
}

/**
 * 1項目を解決する。sum は再帰、func は名前で分岐。
 * $resolving は循環参照の検出用（マスタの設定ミスで無限ループにしない）。
 */
function resolve_item(string $code, array $items, array &$vals, string $from, string $to, array &$resolving): ?float
{
    if (array_key_exists($code, $vals) && !isset($resolving[$code])) {
        return $vals[$code];
    }
    $it = $items[$code] ?? null;
    if ($it === null) {
        return null;
    }
    if (isset($resolving[$code])) {
        error_log("項目マスタが循環参照しています: {$code}");
        return null;
    }
    $resolving[$code] = true;

    $v = null;
    if ($it['calc_type'] === 'sum') {
        $sum  = 0.0;
        $any  = false;
        foreach (explode(',', (string)$it['calc_source']) as $src) {
            $src = trim($src);
            if ($src === '') {
                continue;
            }
            $x = resolve_item($src, $items, $vals, $from, $to, $resolving);
            if ($x !== null) {
                $sum += $x;
                $any  = true;
            }
        }
        // ひとつも値が無い日は 0 ではなく「未入力」として空にする
        $v = $any ? $sum : null;
    } elseif ($it['calc_type'] === 'func') {
        $v = call_calc_func((string)$it['calc_source'], $items, $vals, $from, $to, $resolving);
    }

    unset($resolving[$code]);
    return $vals[$code] = $v;
}

/** 期間の実日数。Excelの $F$5（実日数）に相当する。 */
function period_days(string $from, string $to): int
{
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    return (int)$a->diff($b)->days + 1;
}

/**
 * 名前付きの計算関数。Excelの数式をそのまま移したもの。
 *
 *  nichi_avg:W   1日平均患者数   = 延患者数 ÷ 実日数                      （総括 N列）
 *  avg_days:W    平均在院日数     = 延患者数合計 ÷ ((新入院+転入+退院+転出)÷2)（総括 O列 / H!K）
 *  kadou:W       病床稼働率       = (延患者数+退院) × 100 ÷ (実日数 × 定床)  （総括 P列）
 *  kaiten:W      病床回転率       = 実日数 ÷ 平均在院日数 × 100             （H!L = ($F$5/K)*100）
 *  ratio:A/B     A ÷ B × 100
 *  shoukairitsu  紹介率 = (紹介患者数+救急搬入患者数) × 100 ÷ (初診 − 外休深6歳未満)（⑦!X）
 *  genzai_balance 現入院 = 期首在院数 + 期間新入院 − 期間退院               （②!O の検算用）
 */
function call_calc_func(string $spec, array $items, array &$vals, string $from, string $to, array &$resolving): ?float
{
    [$name, $arg] = array_pad(explode(':', $spec, 2), 2, null);

    // 引数付きで参照する項目値のショートカット
    $g = function (string $code) use ($items, &$vals, $from, $to, &$resolving) {
        return resolve_item($code, $items, $vals, $from, $to, $resolving);
    };
    // 病棟 'all' は3階・4階・5階の合計
    $wards = fn(?string $w) => ($w === 'all') ? ['3', '4', '5'] : [$w];
    $sumW  = function (string $suffix, ?string $w) use ($g, $wards) {
        $t = null;
        foreach ($wards($w) as $x) {
            $v = $g("byoto{$x}_{$suffix}");
            if ($v !== null) {
                $t = ($t ?? 0) + $v;
            }
        }
        return $t;
    };

    $days = period_days($from, $to);

    switch ($name) {
        case 'nichi_avg':
            $z = $sumW('zaiin', $arg);
            return ($z === null || $days === 0) ? null : $z / $days;

        case 'avg_days':
            $total = $sumW('zaiin_total', $arg);
            $moves = 0.0;
            foreach (['nyuin', 'tennyu', 'taiin', 'tenshutsu'] as $k) {
                $moves += (float)($sumW($k, $arg) ?? 0);
            }
            return ($total === null || $moves <= 0) ? null : $total / ($moves / 2);

        case 'kadou':
            $z = $sumW('zaiin', $arg);
            $t = $sumW('taiin', $arg);
            if ($z === null) {
                return null;
            }
            $beds = 0;
            foreach ($wards($arg) as $x) {
                $beds += (int)config_value("teisho_byoto{$x}", $to, 0);
            }
            return ($beds === 0 || $days === 0) ? null : ($z + (float)($t ?? 0)) * 100 / ($days * $beds);

        case 'kaiten':
            $avg = call_calc_func("avg_days:{$arg}", $items, $vals, $from, $to, $resolving);
            return ($avg === null || $avg == 0.0) ? null : $days / $avg * 100;

        case 'ratio':
            [$a, $b] = array_pad(explode('/', (string)$arg, 2), 2, null);
            $av = $g(trim((string)$a));
            $bv = $g(trim((string)$b));
            return ($av === null || $bv === null || $bv == 0.0) ? null : $av * 100 / $bv;

        case 'shoukairitsu':
            $sh  = $g('shoukai');
            $qq  = $g('qq_kanja');
            $sho = $g('shoshin');
            $ex  = $g('gaikyuushin6');
            $den = (float)($sho ?? 0) - (float)($ex ?? 0);
            if ($sho === null || $den <= 0) {
                return null;
            }
            return ((float)($sh ?? 0) + (float)($qq ?? 0)) * 100 / $den;

        case 'genzai_balance':
            // 期首（開始日の前日）の在院数に、期間内の増減を足し引きする
            $prev  = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            $seed  = db_row(
                'SELECT SUM(value_num) AS s FROM d_daily_value
                  WHERE hizuke = ? AND item_code IN (?,?,?)',
                [$prev, 'byoto3_zaiin', 'byoto4_zaiin', 'byoto5_zaiin']
            );
            $base  = ($seed && $seed['s'] !== null) ? (float)$seed['s'] : null;
            $nyuin = $sumW('nyuin', 'all');
            $taiin = $sumW('taiin', 'all');
            if ($base === null && $nyuin === null && $taiin === null) {
                return null;
            }
            return (float)($base ?? 0) + (float)($nyuin ?? 0) - (float)($taiin ?? 0);
    }

    error_log("項目マスタに未知の計算関数が指定されています: {$spec}");
    return null;
}
