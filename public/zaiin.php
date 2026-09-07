<?php
/**
 * 平均在院日数統計。現行Excelの「H」シートに相当する。
 * 病棟別の増減内訳と、そこから算出する在院日数・回転率を日別に並べる。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/calc.php';

$user = require_login();
if (!is_ijika($user) && $user['dept_id'] !== 'byoto') {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('平均在院日数統計は病棟・医事課・管理者のみ閲覧できます。', 'error');
    page_footer();
    exit;
}

$month = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m', strtotime('-1 month'));
}
$from  = $month . '-01';
$to    = date('Y-m-t', strtotime($from));
$wards = ['3' => '3階', '4' => '4階', '5' => '5階', 'all' => '合計'];

page_header('平均在院日数統計　' . $month, $user);
?>
<form method="get" class="datebar noprint">
  <label>対象月 <input type="month" name="month" value="<?= h($month) ?>"></label>
  <button type="submit">表示</button>
</form>
<p class="note">
  ※ 当日退院在院日数＝当日入院しその日のうちに退院した患者（実日数1日）<br>
  ※ 転入・転出は新規のみを計上し、同一患者による再転入・再転出は計上しない
</p>

<div class="board-scroll">
<table class="report">
  <thead>
    <tr><th rowspan="2">日</th><th rowspan="2">曜</th>
      <?php foreach ($wards as $lab): ?><th colspan="7"><?= h($lab) ?></th><?php endforeach; ?></tr>
    <tr><?php foreach ($wards as $w => $lab): ?>
      <th>入院</th><th>転入</th><th>退院</th><th>転出</th><th>延患者数</th><th>延患者数<br>合計</th><th>平均在院<br>日数</th>
    <?php endforeach; ?></tr>
  </thead>
  <tbody>
  <?php for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))):
      $v = daily_values($d); ?>
    <tr>
      <td class="n"><?= (int)substr($d, 8, 2) ?></td><td><?= h(youbi($d)) ?></td>
      <?php foreach ($wards as $w => $lab):
          $k = fn(string $s) => $w === 'all' ? "byoto_{$s}_all" : "byoto{$w}_{$s}";
          $z = $w === 'all' ? 'byoto_zaiin_all' : "byoto{$w}_zaiin";
          $zt= $w === 'all' ? 'byoto_zaiin_total_all' : "byoto{$w}_zaiin_total";
          $ad= $w === 'all' ? 'byoto_avg_days_all' : "byoto{$w}_avg_days"; ?>
        <td class="n"><?= num($v[$k('nyuin')] ?? null) ?></td>
        <td class="n"><?= num($v[$k('tennyu')] ?? null) ?></td>
        <td class="n"><?= num($v[$k('taiin')] ?? null) ?></td>
        <td class="n"><?= num($v[$k('tenshutsu')] ?? null) ?></td>
        <td class="n"><?= num($v[$z] ?? null) ?></td>
        <td class="n"><?= num($v[$zt] ?? null) ?></td>
        <td class="n"><?= num($v[$ad] ?? null, 2) ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endfor; ?>
  </tbody>
  <tfoot>
    <?php $mv = period_values($from, $to); ?>
    <tr class="total-row">
      <th colspan="2">月計</th>
      <?php foreach ($wards as $w => $lab):
          $k = fn(string $s) => $w === 'all' ? "byoto_{$s}_all" : "byoto{$w}_{$s}";
          $z = $w === 'all' ? 'byoto_zaiin_all' : "byoto{$w}_zaiin";
          $zt= $w === 'all' ? 'byoto_zaiin_total_all' : "byoto{$w}_zaiin_total";
          $ad= $w === 'all' ? 'byoto_avg_days_all' : "byoto{$w}_avg_days"; ?>
        <td class="n"><?= num($mv[$k('nyuin')] ?? null) ?></td>
        <td class="n"><?= num($mv[$k('tennyu')] ?? null) ?></td>
        <td class="n"><?= num($mv[$k('taiin')] ?? null) ?></td>
        <td class="n"><?= num($mv[$k('tenshutsu')] ?? null) ?></td>
        <td class="n"><?= num($mv[$z] ?? null) ?></td>
        <td class="n"><?= num($mv[$zt] ?? null) ?></td>
        <td class="n"><?= num($mv[$ad] ?? null, 2) ?></td>
      <?php endforeach; ?>
    </tr>
  </tfoot>
</table>
</div>
<?php page_footer();
