<?php
/**
 * 救急搬入受入統計（当直者８時会・朝礼報告）。
 * 毎朝の報告に使うため、日次で当日と累計を並べる。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/calc.php';

$user  = require_login();
$date  = valid_date($_GET['hizuke'] ?? null) ?? date('Y-m-d', strtotime('-1 day'));
$month = substr($date, 0, 7);
$from  = $month . '-01';
$to    = date('Y-m-t', strtotime($from));

$rows = [];
for ($d = $from; $d <= $to && $d <= $date; $d = date('Y-m-d', strtotime("$d +1 day"))) {
    $day = daily_values($d);
    $cum = period_values($from, $d);
    $rows[] = ['date' => $d, 'day' => $day, 'cum' => $cum];
}
$last = end($rows) ?: null;

page_header('救急搬入受入統計（８時会・朝礼報告）', $user);
?>
<form method="get" class="datebar noprint">
  <label>報告日 <input type="date" name="hizuke" value="<?= h($date) ?>"></label>
  <button type="submit">表示</button>
</form>

<?php if ($last): $c = $last['cum']; $t = $last['day']; ?>
<h2><?= h($date) ?>（<?= h(youbi($date)) ?>）の報告</h2>
<table class="report narrow">
  <tr><th></th><th>当日</th><th>累計</th></tr>
  <tr><th>搬入件数</th><td class="n"><?= num($t['qq_kensu'] ?? null) ?></td><td class="n"><?= num($c['qq_kensu'] ?? null) ?></td></tr>
  <tr><th>患者数</th><td class="n"><?= num($t['qq_kanja'] ?? null) ?></td><td class="n"><?= num($c['qq_kanja'] ?? null) ?></td></tr>
  <tr><th>入院数</th><td class="n"><?= num($t['qq_nyuin'] ?? null) ?></td><td class="n"><?= num($c['qq_nyuin'] ?? null) ?></td></tr>
  <tr><th>入院率</th><td class="n"><?= num($t['qq_nyuinritsu'] ?? null, 1) ?>%</td><td class="n"><?= num($c['qq_nyuinritsu'] ?? null, 1) ?>%</td></tr>
  <tr><th>断り件数</th><td class="n"><?= num($t['qq_kotowari'] ?? null) ?></td><td class="n"><?= num($c['qq_kotowari'] ?? null) ?></td></tr>
  <tr><th>搬入依頼数</th><td class="n"><?= num($t['qq_irai'] ?? null) ?></td><td class="n"><?= num($c['qq_irai'] ?? null) ?></td></tr>
  <tr class="total-row"><th>受入率</th><td class="n"><?= num($t['qq_ukeireritsu'] ?? null, 1) ?>%</td><td class="n"><?= num($c['qq_ukeireritsu'] ?? null, 1) ?>%</td></tr>
</table>
<p class="note">受入率＝患者数累計÷搬入依頼数累計　／　搬入依頼数＝患者数＋断り件数　／　入院率＝入院数÷患者数</p>
<?php endif; ?>

<h2><?= h($month) ?> の推移</h2>
<table class="report">
  <thead><tr><th>日</th><th>曜</th><th>件数</th><th>累計</th><th>患者数</th><th>累計</th>
    <th>入院数</th><th>累計</th><th>入院率</th><th>断り</th><th>累計</th><th>依頼数</th><th>累計</th><th>受入率</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $d = $r['day']; $c = $r['cum']; ?>
    <tr>
      <td class="n"><?= (int)substr($r['date'], 8, 2) ?></td>
      <td><?= h(youbi($r['date'])) ?></td>
      <td class="n"><?= num($d['qq_kensu'] ?? null) ?></td><td class="n"><?= num($c['qq_kensu'] ?? null) ?></td>
      <td class="n"><?= num($d['qq_kanja'] ?? null) ?></td><td class="n"><?= num($c['qq_kanja'] ?? null) ?></td>
      <td class="n"><?= num($d['qq_nyuin'] ?? null) ?></td><td class="n"><?= num($c['qq_nyuin'] ?? null) ?></td>
      <td class="n"><?= num($d['qq_nyuinritsu'] ?? null, 1) ?></td>
      <td class="n"><?= num($d['qq_kotowari'] ?? null) ?></td><td class="n"><?= num($c['qq_kotowari'] ?? null) ?></td>
      <td class="n"><?= num($d['qq_irai'] ?? null) ?></td><td class="n"><?= num($c['qq_irai'] ?? null) ?></td>
      <td class="n"><?= num($c['qq_ukeireritsu'] ?? null, 1) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php page_footer();
