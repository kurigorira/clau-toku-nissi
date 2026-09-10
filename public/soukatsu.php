<?php
/**
 * 各種業務量他総括（月次）。現行Excelの「各種業務量他総括」シートに相当する。
 *
 * 表示する値はすべて calc.php が入力層から算出する。
 * Excelのように導出値を転記しないので、参照ズレは起こらない。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/calc.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('総括表は医事課と管理者のみ閲覧できます。', 'error');
    page_footer();
    exit;
}

$month = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m', strtotime('-1 month'));
}
$from = $month . '-01';
$to   = date('Y-m-t', strtotime($from));
$days = period_days($from, $to);
$fy   = fiscal_year($to);

$v = period_values($from, $to);
$g = fn(string $c) => $v[$c] ?? null;

// 各種業務量。左が表示名、右が項目コード
$blocks = [
    '外来' => [
        ['一般外来',   'gairai_total'],
        ['訪問看護',   'iryou_houkan'],
        ['訪問診療',   'iryou_houshin'],
        ['訪問リハ',   'iryou_hourehab_jin'],
        ['ドック',     'dock_ninzu'],
        ['健診',       'kenshin_ninzu'],
    ],
    '介護' => [
        ['通所リハ',   'tsusho'],
        ['訪問看護',   'houkan_kaigo_disp'],
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
        ['透析',       'touseki_nyuin'],
        ['救急搬入',   'qq_kanja'],
    ],
];
// 介護の訪問看護は介護分（30分以上＋以内）で見る。Excel総括のG列と同じ括り
$v['houkan_kaigo_disp'] = ($g('kaigo_houkan_over30') ?? 0) + ($g('kaigo_houkan_under30') ?? 0);

$wards = ['3' => '3階', '4' => '4階', '5' => '5階', 'all' => '総合計'];

page_header('各種業務量他総括　' . $month, $user);
?>
<form method="get" class="datebar">
  <label>対象月 <input type="month" name="month" value="<?= h($month) ?>"></label>
  <button type="submit">表示</button>
  <span class="note">実日数 <?= (int)$days ?>日／<?= (int)$fy ?>年度</span>
  <a href="export.php?type=soukatsu&amp;month=<?= h($month) ?>">CSV出力</a>
</form>

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
<table class="report">
  <thead>
    <tr><th>区分</th><th>項目</th><th>目標<br>平均</th><th>目標<br>延数</th><th>実績<br>平均</th><th>実績<br>延数</th><th>目標差<br>平均</th><th>目標差<br>延数</th></tr>
  </thead>
  <tbody>
  <?php foreach ($blocks as $blockName => $rows): $first = true; ?>
    <?php foreach ($rows as [$label, $code]):
        $act    = $v[$code] ?? null;
        $actAvg = $act === null ? null : $act / $days;
        $tgtAvg = target_value($code, $fy);
        $tgtSum = $tgtAvg === null ? null : $tgtAvg * $days;
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
<p class="note">目標値が空欄の項目は、まだ <code>m_target</code> に年度の目標が登録されていません（管理画面から登録します）。</p>

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
  <div class="flash flash-warn">
    <strong>この月には未提出・欠測のある日があります。</strong>集計値が不足している可能性があります。
    <ul>
      <?php foreach ($notdone as $deptId => $ds): ?>
        <li><?= h($depts[$deptId]['dept_name'] ?? $deptId) ?>：<?= count($ds) ?>日
          （<?= h(implode('、', array_map(fn($x) => (int)substr($x, 8, 2) . '日', array_slice($ds, 0, 10)))) ?><?= count($ds) > 10 ? ' ほか' : '' ?>）</li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif;
page_footer();
