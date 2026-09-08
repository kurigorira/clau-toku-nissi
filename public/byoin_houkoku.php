<?php
/**
 * 病院報告（患者票）。保健所への月次提出様式。
 *
 * 現行Excelの「病院報告（患者票）」シートに相当する。
 * 外来患者延数は「全外来患者数−在宅患者数（介護保険）」で算出する。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/report.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('病院報告は医事課と管理者のみ閲覧できます。', 'error');
    page_footer();
    exit;
}

$month = valid_month($_GET['month'] ?? null);
$from  = $month . '-01';
$to    = date('Y-m-t', strtotime($from));

$v = period_values($from, $to);
$g = fn(string $c) => $v[$c] ?? null;

// 月末在院患者数は残高。期間の最終日の在院患者数を採る
$lastDay = daily_values($to);
$monthEnd = $lastDay['byoto_zaiin_all'] ?? null;

// 外来患者延数 ＝ 全外来患者数 − 在宅患者数（介護保険）
$gairaiAll = $g('gairai_total');
$kaigo     = $g('kaigo_shoukei');
$gairaiNobe = $gairaiAll === null ? null : $gairaiAll - (float)($kaigo ?? 0);

page_header('病院報告（患者票）　' . $month, $user);
?>
<form method="get" class="datebar noprint">
  <label>対象月 <input type="month" name="month" value="<?= h($month) ?>"></label>
  <button type="submit">表示</button>
  <a href="export.php?type=byoin_houkoku&amp;month=<?= h($month) ?>">CSV出力</a>
</form>

<table class="report" style="margin-bottom:16px">
  <tr><th>都道府県</th><td>長崎県</td><th>保健所符号</th><td><?= h(config_value('hokenjo_fugou', $to, '')) ?></td></tr>
  <tr><th>市区町村</th><td>長崎市</td><th>整理番号</th><td><?= h(config_value('seiri_bangou', $to, '')) ?></td></tr>
  <tr><th>医療機関名</th><td colspan="3"><?= h(config_value('hospital_name', $to, '')) ?></td></tr>
  <tr><th>所在地</th><td colspan="3"><?= h(config_value('hospital_address', $to, '')) ?></td></tr>
</table>

<h2>入院</h2>
<table class="report">
  <tr><th>区分</th><th>在院患者延数</th><th>月末在院患者数</th><th>新入院患者数</th><th>退院患者数</th></tr>
  <tr><th>総数</th>
    <td class="n"><?= num($g('byoto_zaiin_all')) ?></td>
    <td class="n"><?= num($monthEnd) ?></td>
    <td class="n"><?= num($g('byoto_nyuin_all')) ?></td>
    <td class="n"><?= num($g('byoto_taiin_all')) ?></td></tr>
  <tr><th>一般病床</th>
    <td class="n"><?= num($g('byoto_zaiin_all')) ?></td>
    <td class="n"><?= num($monthEnd) ?></td>
    <td class="n"><?= num($g('byoto_nyuin_all')) ?></td>
    <td class="n"><?= num($g('byoto_taiin_all')) ?></td></tr>
</table>
<p class="note">
  当院は全床が一般病床のため、総数と一般病床は同じ値になります。
  病床区分が増えた場合は病棟ごとの区分を項目マスタに追加して分けます。
</p>

<h2>外来</h2>
<table class="report narrow">
  <tr><th>外来患者延数</th><td class="n"><?= num($gairaiNobe) ?></td></tr>
  <tr><th>　全外来患者数</th><td class="n"><?= num($gairaiAll) ?></td></tr>
  <tr><th>　△ 在宅患者数（介護保険）</th><td class="n"><?= num($kaigo) ?></td></tr>
</table>

<?php
// 未提出の日があると数字が不足するので、提出前に気づけるようにする
$status = entry_status($from, $to);
$bad = [];
foreach ($status as $d => $byDept) {
    foreach ($byDept as $deptId => $c) {
        if (in_array($c['status'], ['none', 'partial'], true)) {
            $bad[$deptId][] = $d;
        }
    }
}
if ($bad):
    $depts = all_depts(); ?>
  <div class="flash flash-warn">
    <strong>提出前に確認してください。</strong>この月には未提出・欠測のある日があります。
    <ul><?php foreach ($bad as $id => $ds): ?>
      <li><?= h($depts[$id]['dept_name'] ?? $id) ?>：<?= count($ds) ?>日</li>
    <?php endforeach; ?></ul>
  </div>
<?php else: ?>
  <p class="flash flash-ok">この月は全部署・全日が提出済みです。</p>
<?php endif;
page_footer();
