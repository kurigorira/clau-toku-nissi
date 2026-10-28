<?php
/**
 * 帳票の共通描画。
 *
 * 患者数統計表は「縦=日付、横=項目」のクロス表がシートを変えて繰り返される。
 * 表ごとにHTMLを書くと項目追加のたびに直す箇所が増えるので、
 * 列の仕様（spec）を配列で渡して1つの関数で描く形にする。
 */

require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/view.php';

/**
 * 日別クロス表を描く。
 *
 * $spec は次の形。
 *   [
 *     ['group' => 'CT', 'cols' => [
 *         ['label' => '入院', 'code' => 'ct_nyuin'],
 *         ['label' => '外来', 'code' => 'ct_gairai'],
 *         ['label' => '合計', 'code' => 'ct_total'],
 *         ['label' => '累計', 'code' => 'ct_total', 'cum' => true],   // 月初からの累計
 *     ]],
 *   ]
 *
 * @param string $from   月初
 * @param string $to     月末
 * @param array  $spec   列の仕様
 * @param int    $dec    小数の桁数
 */
function render_daily_table(string $from, string $to, array $spec, int $dec = 0): void
{
    // 日ごとの値と、月初からの累計をあらかじめ用意する。
    // 累計は毎日 period_values を呼ぶと重いので、日次値を足しながら作る。
    $daily = [];
    $cum   = [];
    $run   = [];
    $dates = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $dates[] = $d;
        $daily[$d] = daily_values($d);
        foreach ($daily[$d] as $code => $v) {
            if ($v !== null) {
                $run[$code] = ($run[$code] ?? 0) + $v;
            }
        }
        $cum[$d] = $run;
    }
    $total = period_values($from, $to);

    $colCount = 0;
    foreach ($spec as $g) {
        $colCount += count($g['cols']);
    }
    ?>
    <div class="board-scroll">
    <table class="report daily">
      <thead>
        <tr>
          <th rowspan="2">日</th><th rowspan="2">曜</th>
          <?php foreach ($spec as $g): ?>
            <th colspan="<?= count($g['cols']) ?>"><?= h($g['group']) ?></th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <?php foreach ($spec as $g): foreach ($g['cols'] as $c): ?>
            <th><?= h($c['label']) ?></th>
          <?php endforeach; endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($dates as $d): ?>
        <tr class="<?= in_array(youbi($d), ['土','日'], true) ? 'weekend' : '' ?>">
          <td class="n"><?= (int)substr($d, 8, 2) ?></td>
          <td><?= h(youbi($d)) ?></td>
          <?php foreach ($spec as $g): foreach ($g['cols'] as $c):
              $v = !empty($c['cum']) ? ($cum[$d][$c['code']] ?? null) : ($daily[$d][$c['code']] ?? null); ?>
            <td class="n"><?= num($v, $dec) ?></td>
          <?php endforeach; endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="total-row">
          <th colspan="2">月計</th>
          <?php foreach ($spec as $g): foreach ($g['cols'] as $c): ?>
            <td class="n"><?= empty($c['cum']) ? num($total[$c['code']] ?? null, $dec) : '' ?></td>
          <?php endforeach; endforeach; ?>
        </tr>
        <tr>
          <th colspan="2">1日平均</th>
          <?php $days = period_days($from, $to);
          foreach ($spec as $g): foreach ($g['cols'] as $c):
              $t = $total[$c['code']] ?? null; ?>
            <td class="n"><?= empty($c['cum']) && $t !== null ? num($t / $days, 2) : '' ?></td>
          <?php endforeach; endforeach; ?>
        </tr>
      </tfoot>
    </table>
    </div>
    <?php
}

/** 入院・外来・合計・累計の4列をまとめて作る（よく出る形なので短縮する）。 */
function cols_nyuin_gairai(string $base, bool $withCum = true): array
{
    $cols = [
        ['label' => '入院', 'code' => $base . '_nyuin'],
        ['label' => '外来', 'code' => $base . '_gairai'],
        ['label' => '合計', 'code' => $base . '_total'],
    ];
    if ($withCum) {
        $cols[] = ['label' => '累計', 'code' => $base . '_total', 'cum' => true];
    }
    return $cols;
}

/** 当日・累計の2列。 */
function cols_touji(string $code): array
{
    return [
        ['label' => '当日', 'code' => $code],
        ['label' => '累計', 'code' => $code, 'cum' => true],
    ];
}

/** 対象月の妥当性を確認して YYYY-MM を返す。 */
function valid_month(?string $s): string
{
    return month_period($s)[0];
}

/**
 * 月ごとの画面（総括・平均在院日数・患者数統計表・病院報告）とCSVの集計期間。
 * 既定は「前日までの当月分」（今日が1日なら前月の1か月分）。
 * 今月を選んだら1日〜前日、過去の月は1日〜末日。月計・1日平均・稼働率などはこの期間の日数で計算する。
 * どの画面も同じ期間になるよう1か所にまとめる。
 *
 * @return array [対象月 'Y-m', 開始日, 終了日, まだ集計できる日が無いか]
 */
function month_period(?string $month, ?string $today = null): array
{
    $yesterday = date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' -1 day'));
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = substr($yesterday, 0, 7);
    }
    $from = $month . '-01';
    $to   = min(date('Y-m-t', strtotime($from)), $yesterday);
    if ($to < $from) {
        return [$month, $from, $from, true];   // 先の月・今日が1日の当月
    }
    return [$month, $from, $to, false];
}

/** 画面に出す集計期間の文。月の途中なら「（前日まで）」を付ける。 */
function month_period_label(string $from, string $to): string
{
    $partial = $to < date('Y-m-t', strtotime($from));
    return '集計期間　' . date('Y年n月j日', strtotime($from)) . '〜' . date('n月j日', strtotime($to))
        . '（' . period_days($from, $to) . '日間' . ($partial ? '・前日まで' : '') . '）';
}

/**
 * 「何月何日〜何月何日」で指定した集計期間（総括）。
 *
 * 終了日が前日より先なら前日に詰める（まだ入力が揃わない日を集計しないため）。
 *
 * @return array [開始日, 終了日, 案内の文|null, エラーか]
 */
function range_period(?string $from, ?string $to, ?string $today = null): array
{
    $yesterday = date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' -1 day'));
    $from = valid_date($from);
    $to   = valid_date($to);
    if ($from === null || $to === null) {
        return [$from ?? $yesterday, $to ?? $yesterday, '開始日と終了日を正しく入れてください。', true];
    }
    $msg = null;
    if ($to > $yesterday) {
        $to  = $yesterday;
        $msg = '終了日は前日（' . date('Y年n月j日', strtotime($yesterday)) . '）までにしました。';
    }
    if ($from > $to) {
        return [$from, $to, '開始日が終了日より後になっています（集計できるのは前日まで）。', true];
    }
    if (period_days($from, $to) > 366) {
        return [$from, $to, '期間は1年（366日）以内にしてください。', true];
    }
    return [$from, $to, $msg, false];
}

/**
 * 総括の集計期間。from・to があれば期間指定、無ければ対象月（month_period）。画面とCSVで同じものを使う。
 *
 * @return array month, from, to, noData（まだ集計できる日が無い）, range（期間指定か）, message, error
 */
function soukatsu_period(array $get): array
{
    if (isset($get['from']) || isset($get['to'])) {
        [$from, $to, $msg, $err] = range_period($get['from'] ?? null, $get['to'] ?? null);
        return ['month' => substr($from, 0, 7), 'from' => $from, 'to' => $to, 'noData' => false,
                'range' => true, 'message' => $msg, 'error' => $err];
    }
    [$month, $from, $to, $noData] = month_period($get['month'] ?? null);
    return ['month' => $month, 'from' => $from, 'to' => $to, 'noData' => $noData,
            'range' => false, 'message' => null, 'error' => false];
}

/** 集計期間の「2026年10月5日〜10月20日」。年をまたぐときは終わりにも年を付ける。 */
function period_range_text(string $from, string $to): string
{
    return date('Y年n月j日', strtotime($from)) . '〜'
        . date(substr($from, 0, 4) === substr($to, 0, 4) ? 'n月j日' : 'Y年n月j日', strtotime($to));
}

/** まだ集計できる日が無い月を選んだときの案内を出して終える。 */
function month_period_nodata(string $from): void
{
    flash(date('Y年n月j日', strtotime($from)) . ' からの集計はまだできません（前日までの分を集計します）。', 'warn');
    page_footer();
    exit;
}

/**
 * 各種業務量の項目（総括の表・順位グラフと、月別のグラフ gyomu_month.php で共通）。
 * 区分 => [[表示名, 項目コード], ...]
 */
function gyomu_blocks(): array
{
    return [
        '入院' => [
            ['延患者数',   'byoto_zaiin_all'],
            ['新入院',     'byoto_nyuin_all'],
            ['退院',       'byoto_taiin_all'],
        ],
        '外来' => [
            ['一般外来',   'gairai_ippan'],
            ['訪問看護',   'iryou_houkan'],
            ['訪問診療',   'iryou_houshin'],
            ['訪問リハ',   'iryou_hourehab_jin'],
            ['ドック',     'dock_ninzu'],
            ['健診',       'kenshin_ninzu'],
        ],
        '介護' => [
            ['通所リハ',   'tsusho'],
            ['訪問看護',   'kaigo_houkan_total'],
            ['居宅指導',   'kaigo_kyotaku'],
            ['訪問リハ',   'kaigo_hourehab'],
            ['訪問介護',   'houkai_total'],
            ['介護合計',   'kaigo_shoukei'],
        ],
        '検査' => [
            ['心エコー',     'shin_echo_total'],
            ['腹部エコー',   'fuku_echo_total'],
            ['検体検査',     'kentai_total'],
        ],
        '画像' => [
            ['CT',           'ct_total'],
            ['MRI',          'mri_total'],
            ['一般単純',     'ippan_total'],
            ['胃透視',       'itoushi_total'],
            ['マンモ',       'mammo_total'],
            ['血管造影',     'cag_total'],
        ],
        '内視鏡' => [
            ['胃カメラ',        'egd_total'],
            ['大腸ﾌｧｲﾊﾞｰ',     'cf_total'],
        ],
        'リハビリ' => [
            ['理学療法',   'pt_total'],
            ['作業療法',   'ot_total'],
            ['言語療法',   'st_total'],
        ],
        'その他' => [
            ['手術',       'ope_total'],
            ['服薬指導',   'fukuyaku'],
            ['栄養指導',   'eiyou_sum12'],
            ['入院透析',   'touseki_nyuin'],
            ['外来透析',   'touseki_gairai'],
            ['救急搬入',   'qq_kanja'],
            ['紹介患者',   'shoukai'],
        ],
        ];
}
