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
    return (is_string($s) && preg_match('/^\d{4}-\d{2}$/', $s) && (int)substr($s, 5, 2) >= 1
            && (int)substr($s, 5, 2) <= 12)
        ? $s : date('Y-m', strtotime('-1 month'));
}
