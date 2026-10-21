<?php
/**
 * 各種業務量他総括（月次）。現行Excelの「各種業務量他総括」シートに相当する。
 *
 * 表示する値はすべて calc.php が入力層から算出する。
 * Excelのように導出値を転記しないので、参照ズレは起こらない。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/calc.php';
require_once __DIR__ . '/../src/report.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('総括表は医事課と管理者のみ閲覧できます。', 'error');
    page_footer();
    exit;
}

// 集計期間。既定は「前日までの当月分」。「何月何日〜何月何日」でも指定できる（from・to）。
// 実日数・平均・目標の延数はこの期間の日数で計算する（src/report.php）
$print = isset($_GET['print']);
$per   = soukatsu_period($_GET);
['month' => $month, 'from' => $from, 'to' => $to, 'noData' => $noData] = $per;
$days = period_days($from, $to);
$fy   = fiscal_year($to);
// CSV・印刷用に今の期間を引き継ぐ
$q = $per['range'] ? 'from=' . rawurlencode($from) . '&to=' . rawurlencode($to) : 'month=' . rawurlencode($month);

$v = period_values($from, $to);
$g = fn(string $c) => $v[$c] ?? null;

// 各種業務量。左が表示名、右が項目コード
$blocks = [
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
// 期間の目標延数（月別の目標÷月の日数×期間の日数。無い月は年度の1日平均。src/master.php）
$targets = targets_for_period(array_merge(...array_map(fn($r) => array_column($r, 1), array_values($blocks))), $from, $to);
// 年度累計（4月1日〜前日）。年度の進捗を見るためのリンク
$fyFrom = sprintf('%04d-04-01', fiscal_year(date('Y-m-d', strtotime('-1 day'))));
$fyTo   = date('Y-m-d', strtotime('-1 day'));

$wards = ['3' => '3階', '4' => '4階', '5' => '5階', 'all' => '総合計'];

page_header('各種業務量他総括', $user);
// 印刷はいつでも A4縦・左とじ（print.css）。「印刷用」で開いたときは画面でも同じ見た目にする
echo '<link rel="stylesheet" href="assets/print.css" media="' . ($print ? 'all' : 'print') . '">';
$ymd = fn(string $d) => date('Y年n月j日', strtotime($d));
?>
<div class="datebar">
  <form method="get" class="datebar-row">
    <label>対象月 <input type="month" name="month" value="<?= h($month) ?>"></label>
    <button type="submit">表示</button>
    <a href="export.php?type=soukatsu&amp;<?= h($q) ?>">CSV出力</a>
    <a href="soukatsu.php?<?= h($q) ?>&amp;print=1" target="_blank">印刷用</a>
  </form>
  <form method="get" class="datebar-row">
    <label>期間指定 <input type="date" name="from" value="<?= h($from) ?>"></label>〜<input type="date" name="to" value="<?= h($to) ?>">
    <button type="submit">表示</button>
    <span class="note">前日まで・1年以内</span>
    <a href="soukatsu.php?from=<?= h($fyFrom) ?>&amp;to=<?= h($fyTo) ?>">年度累計（<?= h(date('n月j日', strtotime($fyFrom))) ?>〜前日）</a>
  </form>
</div>
<?php if ($per['message'] !== null): ?>
  <p class="flash <?= $per['error'] ? 'flash-error' : 'flash-warn' ?>"><?= h($per['message']) ?></p>
<?php endif;
if ($per['error']) { page_footer(); exit; }
if ($noData): ?>
  <p class="flash flash-warn"><?= h($ymd($from)) ?> からの集計はまだできません（前日までの分を集計します）。</p>
<?php page_footer(); exit; endif; ?>
<p class="soukatsu-period"><strong>集計期間　<?= h(period_range_text($from, $to)) ?>（<?= (int)$days ?>日間）</strong>
  <span class="note"><?= (int)$fy ?>年度<?= !$per['range'] && $to < date('Y-m-t', strtotime($from)) ? '・前日までの当月分' : '' ?>　作成 <?= h(date('Y年n月j日 H:i')) ?></span></p>

<h2>病棟別 在院日数・稼働率・回転率</h2>
<table class="report">
  <thead>
    <tr><th>病棟</th><th>定床</th><th>延患者数</th><th>算定対象<br>延患者数</th><th>新入院</th><th>退院</th>
        <th>転入</th><th>転出</th><th>1日平均<br>患者数</th><th>平均在院<br>日数</th><th>病床<br>稼働率</th><th>病床<br>回転率</th></tr>
  </thead>
  <tbody>
  <?php foreach ($wards as $w => $label):
      $sfx  = $w === 'all' ? '_all' : '';
      $pre  = $w === 'all' ? 'byoto' : "byoto{$w}";
      $beds = 0;
      foreach ($w === 'all' ? ['3','4','5'] : [$w] as $x) {
          $beds += (int)config_value("teisho_byoto{$x}", $to, 0);
      }
      $key = fn(string $k) => $w === 'all' ? "byoto_{$k}_all" : "byoto{$w}_{$k}";
  ?>
    <tr<?= $w === 'all' ? ' class="total-row"' : '' ?>>
      <th><?= h($label) ?></th>
      <td class="n"><?= $beds ?: '' ?></td>
      <td class="n"><?= num($g($w === 'all' ? 'byoto_zaiin_all' : "byoto{$w}_zaiin")) ?></td>
      <td class="n"><?= num($g($w === 'all' ? 'byoto_zaiin_total_all' : "byoto{$w}_zaiin_total")) ?></td>
      <td class="n"><?= num($g($key('nyuin'))) ?></td>
      <td class="n"><?= num($g($key('taiin'))) ?></td>
      <td class="n"><?= num($g($key('tennyu'))) ?></td>
      <td class="n"><?= num($g($key('tenshutsu'))) ?></td>
      <td class="n"><?= num($g($w === 'all' ? 'byoto_zaiin_avg_all' : "byoto{$w}_zaiin_avg"), 2) ?></td>
      <td class="n"><?= num($g($w === 'all' ? 'byoto_avg_days_all' : "byoto{$w}_avg_days"), 2) ?></td>
      <td class="n"><?= num($g($w === 'all' ? 'byoto_kadou_all' : "byoto{$w}_kadou"), 2) ?></td>
      <td class="n"><?= num($g($w === 'all' ? 'byoto_kaiten_all' : "byoto{$w}_kaiten"), 2) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="note">
  病床稼働率＝(延患者数＋退院)×100÷(実日数×定床)　／　平均在院日数＝算定対象延患者数÷((新入院＋転入＋退院＋転出)÷2)　／　病床回転率＝実日数÷平均在院日数×100
</p>

<h2>各種業務量</h2>
<?php
// 目標達成率（実績延数÷目標延数）。右のグラフで高い順に並べる。目標が無い項目は入れない
$labelCount = array_count_values(array_merge(...array_map(fn($r) => array_column($r, 0), array_values($blocks))));
$rank = [];
$noTarget = 0;
?>
<div class="split">
<div class="split-main">
<table class="report">
  <thead>
    <tr><th>区分</th><th>項目</th><th>目標<br>平均</th><th>目標<br>延数</th><th>実績<br>平均</th><th>実績<br>延数</th><th>目標差<br>平均</th><th>目標差<br>延数</th></tr>
  </thead>
  <tbody>
  <?php foreach ($blocks as $blockName => $rows): $first = true; ?>
    <?php foreach ($rows as [$label, $code]):
        $act    = $v[$code] ?? null;
        $actAvg = $act === null ? null : $act / $days;
        $tgtSum = $targets[$code] ?? null;
        $tgtAvg = $tgtSum === null ? null : $tgtSum / $days;
        if ($tgtSum === null || $tgtSum <= 0) {
            $noTarget++;
        } elseif ($act !== null) {
            // 外来と介護に同じ名前（訪問看護・訪問リハ）があるので、重なる名前には区分を付ける
            $rank[] = ['label' => $labelCount[$label] > 1 ? "{$label}（{$blockName}）" : $label,
                       'rate' => $act / $tgtSum * 100, 'act' => $act, 'tgt' => $tgtSum];
        }
    ?>
    <tr>
      <?php if ($first): ?><th rowspan="<?= count($rows) ?>" class="block"><?= h($blockName) ?></th><?php $first = false; endif; ?>
      <th class="itemname"><?= h($label) ?></th>
      <td class="n"><?= num($tgtAvg, 2) ?></td>
      <td class="n"><?= num($tgtSum, 1) ?></td>
      <td class="n"><?= num($actAvg, 2) ?></td>
      <td class="n"><?= num($act) ?></td>
      <td class="n"><?= $tgtAvg === null || $actAvg === null ? '' : num($actAvg - $tgtAvg, 2) ?></td>
      <td class="n"><?= $tgtSum === null || $act === null ? '' : num($act - $tgtSum, 1) ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="split-side">
<?php
usort($rank, fn($a, $b) => $b['rate'] <=> $a['rate']);
// 目盛りの右端：120％〜200％（20％刻み）。200％を超える棒は右端まで伸ばし、数字で本当の値を出す
$scale = $rank ? min(200, max(120, (int)ceil(max(array_column($rank, 'rate')) / 20) * 20)) : 120;
$pct = fn(float $x) => round(min($x, $scale) / $scale * 100, 2);
// 差（実績−目標の延数）。マイナスは「−」で示す（色ではなく符号で）
$diff = fn(float $x) => ($x >= 0 ? '+' : '−') . number_format(abs($x), 1);
?>
  <h3 class="rank-title">目標達成率の順位</h3>
  <?php if (!$rank): ?>
    <p class="note">この期間の目標値が登録されていないため、表示できません。<br>
      マスタ →「年度目標値」で事業計画の Excel を取り込む（または1日平均の目標を登録する）と、ここに達成率の高い順に並びます。</p>
  <?php else: ?>
    <p class="note rank-note">達成率＝実績延数÷目標延数（<?= (int)$days ?>日分）。点線が100％。差＝実績−目標</p>
    <table class="rank" aria-label="目標達成率の順位">
      <thead><tr><th class="rk">順位</th><th>項目</th><th class="bar"></th><th class="v">達成率</th><th class="v">差</th></tr></thead>
      <tbody>
      <?php foreach ($rank as $i => $r): ?>
        <tr title="<?= h($r['label']) ?>：達成率 <?= h(number_format($r['rate'], 1)) ?>％（実績 <?= h(num($r['act'])) ?>／目標 <?= h(number_format($r['tgt'], 1)) ?>／差 <?= h($diff($r['act'] - $r['tgt'])) ?>）">
          <td class="rk"><?= $i + 1 ?></td>
          <th><?= h($r['label']) ?></th>
          <td class="bar"><div class="track">
            <span class="fill" style="width:<?= $pct($r['rate']) ?>%"></span>
            <span class="goal" style="left:<?= $pct(100) ?>%"></span>
          </div></td>
          <td class="v"><?= h(number_format($r['rate'], 1)) ?>%</td>
          <td class="v"><?= h($diff($r['act'] - $r['tgt'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($noTarget): ?><p class="note rank-note">目標が未登録の <?= (int)$noTarget ?> 項目は入っていません。</p><?php endif; ?>
  <?php endif; ?>
</div>
</div>
<p class="note">目標＝事業計画の月別の目標÷月の日数×期間の日数（月別が無い月は年度の1日平均）。
  空欄の項目は目標が登録されていません（マスタ →「年度目標値」で事業計画の Excel を取り込みます）。</p>

<h2>救急搬入・紹介</h2>
<table class="report narrow">
  <tr><th>搬入件数</th><td class="n"><?= num($g('qq_kensu')) ?></td>
      <th>患者数</th><td class="n"><?= num($g('qq_kanja')) ?></td>
      <th>入院数</th><td class="n"><?= num($g('qq_nyuin')) ?></td>
      <th>入院率</th><td class="n"><?= num($g('qq_nyuinritsu'), 2) ?>%</td></tr>
  <tr><th>断り件数</th><td class="n"><?= num($g('qq_kotowari')) ?></td>
      <th>搬入依頼数</th><td class="n"><?= num($g('qq_irai')) ?></td>
      <th>受入率</th><td class="n"><?= num($g('qq_ukeireritsu'), 2) ?>%</td>
      <th>紹介率</th><td class="n"><?= num($g('shoukairitsu'), 2) ?>%</td></tr>
</table>

<?php
// 未提出のまま集計していないかを示す。数字が足りない原因に最初に気づけるようにする
$status  = entry_status($from, $to);
$notdone = [];
foreach ($status as $d => $byDept) {
    foreach ($byDept as $deptId => $c) {
        if (in_array($c['status'], ['none', 'partial'], true)) {
            $notdone[$deptId][] = $d;
        }
    }
}
if ($notdone):
    $depts = all_depts(); ?>
  <div class="flash flash-warn noprint">
    <strong>この期間には未提出・欠測のある日があります。</strong>集計値が不足している可能性があります。
    <ul>
      <?php foreach ($notdone as $deptId => $ds): ?>
        <li><?= h($depts[$deptId]['dept_name'] ?? $deptId) ?>：<?= count($ds) ?>日
          （<?= h(implode('、', array_map(fn($x) => (int)substr($x, 8, 2) . '日', array_slice($ds, 0, 10)))) ?><?= count($ds) > 10 ? ' ほか' : '' ?>）</li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php // 印刷ではA4に収めるため1段落にまとめる（紙でも数字が足りない理由が分かるように残す） ?>
  <p class="printonly flash-warn"><strong>未提出・欠測のある日がある部署</strong>（集計値が不足している可能性があります）：
    <?= h(implode('、', array_map(fn($id) => ($depts[$id]['dept_name'] ?? $id) . ' ' . count($notdone[$id]) . '日', array_keys($notdone)))) ?></p>
<?php endif;
page_footer();
