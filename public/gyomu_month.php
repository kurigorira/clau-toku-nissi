<?php
/**
 * 各種業務量の月別グラフ。総括の「各種業務量」の項目ごとに、年度の4月〜3月の実績（棒）と事業計画の目標（線）を並べる。
 *
 * グラフはサーバで作る SVG（JavaScriptなし。院内の古いChromeでも表示できる）。棒にマウスを置くと値が出る（SVG の title）。
 * 当月は前日までの分なので薄い色の棒にし、達成率は前日までの目標と比べる。下に月別の表も出す。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/calc.php';
require_once __DIR__ . '/../src/report.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();   // ログインした人なら誰でも見られる

$yesterday = date('Y-m-d', strtotime('-1 day'));
$fyNow = fiscal_year($yesterday);
$fy    = (int)($_GET['fy'] ?? $fyNow);
if ($fy < 2000 || $fy > $fyNow + 1) {
    $fy = $fyNow;
}
$avg = ($_GET['mode'] ?? '') === 'avg';     // 1日平均で見る（月ごとの日数の違いをならす）

$blocks = gyomu_blocks();
$codes  = array_merge(...array_map(fn($r) => array_column($r, 1), array_values($blocks)));

// 月ごとの実績・目標。当月は前日まで、先の月は目標だけ
$months = [];
for ($i = 0; $i < 12; $i++) {
    $first = date('Y-m-01', strtotime(sprintf('%04d-04-01', $fy) . " +{$i} month"));
    $last  = date('Y-m-t', strtotime($first));
    $to    = min($last, $yesterday);
    $m = ['ym' => substr($first, 0, 7), 'mon' => (int)substr($first, 5, 2), 'days' => (int)date('t', strtotime($first)),
          'from' => $first, 'to' => $to, 'has' => $first <= $yesterday, 'partial' => $first <= $yesterday && $to < $last,
          'act' => [], 'tgt' => targets_for_period($codes, $first, $last), 'tgtToDate' => []];
    if ($m['has']) {
        $m['act']       = period_values($first, $to);
        $m['actDays']   = period_days($first, $to);
        $m['tgtToDate'] = $m['partial'] ? targets_for_period($codes, $first, $to) : $m['tgt'];
    }
    $months[] = $m;
}

/** グラフの目盛りの上限：値の最大を 1・2・2.5・5×10^n に切り上げる。 */
function nice_max(float $v): float
{
    if ($v <= 0) {
        return 1;
    }
    $e = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $f) {
        if ($v <= $f * $e) {
            return $f * $e;
        }
    }
    return 10 * $e;
}

/** 表示する値（延数 か 1日平均）。 */
$val = function (array $m, string $code, string $kind) use ($avg): ?float {
    if ($kind === 'act') {
        $v = $m['act'][$code] ?? null;
        return $v === null ? null : ($avg ? $v / $m['actDays'] : (float)$v);
    }
    $v = $m['tgt'][$code] ?? null;
    return $v === null ? null : ($avg ? $v / $m['days'] : (float)$v);
};

/** 1項目の小さなグラフ（SVG）。棒＝実績、横線＝目標。 */
function gyomu_chart(string $label, string $code, array $months, callable $val, bool $avg): string
{
    $W = 300; $H = 150; $L = 40; $R = 6; $T = 10; $B = 20;
    $pw = $W - $L - $R; $ph = $H - $T - $B;
    $max = 0.0;
    foreach ($months as $m) {
        $max = max($max, (float)$val($m, $code, 'act'), (float)$val($m, $code, 'tgt'));
    }
    $top = nice_max($max);
    $y   = fn(float $v) => $T + $ph - $v / $top * $ph;
    $slot = $pw / 12;
    $bw   = min(14, $slot * 0.62);
    $fmt  = fn(?float $v) => $v === null ? '－' : number_format($v, $avg ? 1 : ($v == floor($v) ? 0 : 1));
    $o = '<svg class="gm-svg" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img" aria-label="'
       . h($label) . ' 月別の実績と目標">';
    // 目盛り（0・半分・上限）
    foreach ([0, 0.5, 1] as $f) {
        $yy = round($y($top * $f), 1);
        $o .= '<line class="gm-grid' . ($f == 0 ? ' gm-base' : '') . '" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>';
        $o .= '<text class="gm-ylab" x="' . ($L - 4) . '" y="' . ($yy + 3) . '">' . h(number_format($top * $f, $top * $f < 10 && $f > 0 ? 1 : 0)) . '</text>';
    }
    foreach ($months as $i => $m) {
        $cx  = $L + $slot * $i + $slot / 2;
        $act = $val($m, $code, 'act');
        $tgt = $val($m, $code, 'tgt');
        $tip = "{$m['mon']}月" . ($m['partial'] ? '（' . (int)substr($m['to'], 8, 2) . '日まで）' : '')
             . '　実績 ' . $fmt($act) . '／目標 ' . $fmt($tgt) . ($avg ? '（1日平均）' : '');
        $o .= '<g><title>' . h($tip) . '</title>';
        $o .= '<rect class="gm-hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . $ph . '"/>';
        if ($act !== null && $act > 0) {
            $by = $y($act);
            $h  = $T + $ph - $by;
            $r  = min(3, $h / 2, $bw / 2);
            // 根元は四角、先端だけ丸める
            $x0 = round($cx - $bw / 2, 1); $x1 = round($cx + $bw / 2, 1); $yb = $T + $ph;
            $o .= '<path class="gm-bar' . ($m['partial'] ? ' gm-partial' : '') . '" d="M' . $x0 . ' ' . $yb . ' V' . round($by + $r, 1)
                . ' Q' . $x0 . ' ' . round($by, 1) . ' ' . round($x0 + $r, 1) . ' ' . round($by, 1)
                . ' H' . round($x1 - $r, 1) . ' Q' . $x1 . ' ' . round($by, 1) . ' ' . $x1 . ' ' . round($by + $r, 1) . ' V' . $yb . ' Z"/>';
        }
        if ($tgt !== null) {
            $ty = round($y($tgt), 1);
            $o .= '<line class="gm-tgt" x1="' . round($cx - $slot * 0.45, 1) . '" x2="' . round($cx + $slot * 0.45, 1) . '" y1="' . $ty . '" y2="' . $ty . '"/>';
        }
        $o .= '</g>';
        $o .= '<text class="gm-xlab" x="' . round($cx, 1) . '" y="' . ($H - 6) . '">' . $m['mon'] . '</text>';
    }
    return $o . '</svg>';
}

page_header('各種業務量の月別', $user);
?>
<form method="get" class="datebar">
  <label>年度 <select name="fy">
    <?php for ($y = $fyNow + 1; $y >= $fyNow - 4; $y--): ?>
      <option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= $y ?>年度</option>
    <?php endfor; ?>
  </select></label>
  <label><input type="radio" name="mode" value=""<?= $avg ? '' : ' checked' ?>> 月の延数</label>
  <label><input type="radio" name="mode" value="avg"<?= $avg ? ' checked' : '' ?>> 1日平均</label>
  <button type="submit">表示</button>
  <a href="soukatsu.php">総括へ</a>
</form>

<p class="gm-legend">
  <span class="gm-key gm-key-bar"></span>実績（<?= $avg ? '1日平均' : '月の延数' ?>）
  <span class="gm-key gm-key-partial"></span>当月（前日まで）
  <span class="gm-key gm-key-tgt"></span>目標（事業計画の月別の目標<?= $avg ? '÷月の日数' : '' ?>）
  <span class="note">　棒にマウスを置くと値が出ます。達成率は年度の初めから前日までの実績÷同じ期間の目標。</span>
</p>

<?php foreach ($blocks as $blockName => $rows): ?>
  <h2 class="gm-block"><?= h($blockName) ?></h2>
  <div class="gm-cards">
  <?php foreach ($rows as [$label, $code]):
      // 年度の初めから前日までの実績と、同じ期間の目標
      $actSum = $tgtSum = 0.0; $hasT = false;
      foreach ($months as $m) {
          if (!$m['has']) { continue; }
          $actSum += (float)($m['act'][$code] ?? 0);
          if (isset($m['tgtToDate'][$code])) { $tgtSum += $m['tgtToDate'][$code]; $hasT = true; }
      }
      $rate = $hasT && $tgtSum > 0 ? $actSum / $tgtSum * 100 : null; ?>
    <div class="gm-card">
      <div class="gm-title"><?= h($label) ?>
        <span class="gm-sum">年度累計 <?= h(num($actSum)) ?><?= $rate !== null ? '／目標 ' . h(num($tgtSum, 1)) . '（' . h(number_format($rate, 1)) . '％）' : '（目標なし）' ?></span></div>
      <?= gyomu_chart($blockName . ' ' . $label, $code, $months, $val, $avg) ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<h2>月別の表（<?= $avg ? '1日平均' : '月の延数' ?>）</h2>
<div class="table-scroll">
<table class="report gm-table">
  <thead><tr><th>区分</th><th>項目</th><th></th>
    <?php foreach ($months as $m): ?><th><?= $m['mon'] ?>月<?= $m['partial'] ? '<br><small>' . (int)substr($m['to'], 8, 2) . '日まで</small>' : '' ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($blocks as $blockName => $rows): $first = true; ?>
    <?php foreach ($rows as [$label, $code]): ?>
      <tr>
        <?php if ($first): ?><th rowspan="<?= count($rows) * 2 ?>" class="block"><?= h($blockName) ?></th><?php $first = false; endif; ?>
        <th rowspan="2" class="itemname"><?= h($label) ?></th><th class="gm-kind">実績</th>
        <?php foreach ($months as $m): ?><td class="n"><?= $m['has'] ? h(num($val($m, $code, 'act'), $avg ? 1 : 0)) : '' ?></td><?php endforeach; ?>
      </tr>
      <tr class="gm-tgtrow"><th class="gm-kind">目標</th>
        <?php foreach ($months as $m): ?><td class="n"><?= h(num($val($m, $code, 'tgt'), 1)) ?></td><?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="note">目標はマスタ →「年度目標値」で取り込んだ事業計画の月別の目標（無い月は年度の1日平均）。空欄は目標が登録されていない月です。</p>
<?php page_footer();
