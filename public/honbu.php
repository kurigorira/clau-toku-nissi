<?php
/**
 * 本部報告（徳洲会本部へ毎日出す「日報」）。本部の入力画面と同じ並びで、当日数と累計数（その月の1日〜その日）を出す。
 *
 * 値はすべてシステムに入力された項目から計算する（src/honbu.php）。システムに無い項目は空欄で並べる。
 * 登録数（在宅登録人数など）は累計の代わりに参考値として前日の値を出す。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/report.php';
require_once __DIR__ . '/../src/honbu.php';

$user = require_login();   // ログインした人なら誰でも見られる

$yesterday = date('Y-m-d', strtotime('-1 day'));
$date  = valid_date($_GET['hizuke'] ?? null) ?? $yesterday;
$print = isset($_GET['print']);
$first = substr($date, 0, 8) . '01';
$prevD = date('Y-m-d', strtotime("$date -1 day"));

$day  = honbu_kouza_split(daily_values($date), $date, $date);
$cum  = honbu_kouza_split(period_values($first, $date), $first, $date);
$prev = daily_values($prevD);

/** 1行を出す。$ref は参考値の列を持つ表か。 */
function honbu_row(array $r, array $day, array $cum, array $prev, bool $ref): void
{
    [$label, $spec] = $r;
    $o   = $r[2] ?? [];
    $dec = $o['dec'] ?? 0;
    $sys = $spec !== null;
    $fmt = fn(?float $x) => $sys ? num($x ?? 0, $dec) : '';
    ?>
    <tr<?= !empty($o['head']) ? ' class="hb-head"' : '' ?>>
      <?php if (isset($o['sub'])): ?>
        <th class="hb-label"><?= h($label) ?></th><th class="hb-sub"><?= h($o['sub']) ?></th>
      <?php else: ?>
        <th class="hb-label" colspan="2"><?= h($label) ?></th>
      <?php endif; ?>
      <?php if (!empty($o['head'])): ?>
        <td colspan="<?= $ref ? 3 : 2 ?>"></td>
      <?php else: ?>
        <td class="n<?= $sys ? '' : ' hb-none' ?>"><?= $fmt(honbu_value($spec, $day)) ?></td>
        <?php if ($ref): ?>
          <td class="n hb-ref"><?= !empty($o['stock']) ? '前日 ' . h(num(honbu_value($spec, $prev) ?? 0)) : '' ?></td>
        <?php endif; ?>
        <td class="n<?= $sys ? '' : ' hb-none' ?>"><?= !empty($o['stock']) ? '' : $fmt(honbu_value($spec, $cum)) ?></td>
      <?php endif; ?>
    </tr>
    <?php
}

/** 見出し＋行の表。 */
function honbu_table(string $title, array $rows, array $day, array $cum, array $prev, bool $ref = false, string $note = ''): void
{
    ?>
    <table class="report hb">
      <thead><tr><th colspan="2"><?= h($title) ?></th><th>当日数</th><?php if ($ref): ?><th>参考値</th><?php endif; ?><th>累計数</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r) { honbu_row($r, $day, $cum, $prev, $ref); } ?>
      </tbody>
    </table>
    <?php if ($note !== ''): ?><p class="hb-note"><?= h($note) ?></p><?php endif;
}

page_header('本部報告', $user, false);
echo '<link rel="stylesheet" href="' . h(asset_url('assets/print.css')) . '" media="' . ($print ? 'all' : 'print') . '">';
$prevLink = date('Y-m-d', strtotime("$date -1 day"));
$nextLink = date('Y-m-d', strtotime("$date +1 day"));
?>
<form method="get" class="datebar noprint">
  <a href="honbu.php?hizuke=<?= h($prevLink) ?>">＜前日</a>
  <label>日付 <input type="date" name="hizuke" value="<?= h($date) ?>"></label>
  <span class="youbi">（<?= h(youbi($date)) ?>）</span>
  <button type="submit">表示</button>
  <?php if ($nextLink <= $yesterday): ?><a href="honbu.php?hizuke=<?= h($nextLink) ?>">翌日＞</a><?php endif; ?>
  <a href="honbu.php?hizuke=<?= h($date) ?>&amp;print=1" target="_blank">印刷用</a>
</form>

<div class="hb-page">
<h1 class="hb-title">本部報告（日報）<span class="hb-date">日付：<?= h(date('Y/m/d', strtotime($date))) ?>（<?= h(youbi($date)) ?>）
  累計は <?= h(date('n/j', strtotime($first))) ?>〜<?= h(date('n/j', strtotime($date))) ?></span></h1>

<table class="hb-cols"><tr>
  <td><?php honbu_table('患者数項目名', honbu_patients(), $day, $cum, $prev, true); ?></td>
  <td>
    <?php honbu_table('時間外ウォークイン項目', honbu_walkin(), $day, $cum, $prev, false, '※救急車搬入患者含まない'); ?>
    <?php honbu_table('検査項目名', honbu_kensa(), $day, $cum, $prev); ?>
  </td>
  <td>
    <?php honbu_table('手術項目名（手術室のみ）', honbu_ope(), $day, $cum, $prev); ?>
    <?php honbu_table('営業活動報告', honbu_eigyo(), $day, $cum, $prev, false,
        '健康講座の参加人数は、その日に院外があれば院外、無ければ院内として数えます。'); ?>
  </td>
</tr></table>

<table class="hb-cols hb-page2"><tr>
  <td class="hb-narrow"><?php honbu_table('放射線など', honbu_housha(), $day, $cum, $prev); ?></td>
  <td>
    <h2 class="hb-h2">COVID-19 関連報告</h2>
    <?php $cv = honbu_covid(); ?>
    <table class="hb-cols"><tr>
      <td><?php honbu_table('外来及び検査数', $cv['gairai'], $day, $cum, $prev); ?></td>
      <td><?php honbu_table('入院', $cv['nyuin'], $day, $cum, $prev); ?></td>
      <td><?php honbu_table('退院', $cv['taiin'], $day, $cum, $prev); ?></td>
    </tr></table>
    <table class="hb-cols"><tr>
      <td>
        <table class="report hb">
          <thead><tr><th>病床数</th><th>当日数</th><th>前日数</th></tr></thead>
          <tbody><?php foreach (honbu_covid_beds() as $b): ?><tr><th class="hb-label"><?= h($b) ?></th><td class="n hb-none"></td><td class="n hb-none"></td></tr><?php endforeach; ?></tbody>
        </table>
        <p class="hb-note">※COVID-19対応で行政に届けている病床数（システムに無いので空欄）</p>
      </td>
      <td>
        <?php
        // 陽性者入院数の確認：前日の陽性者入院数＋新入院＋疑似症から陽性−退院（軽快・転院・死亡）＝当日の陽性者入院数
        $c = fn(array $v, string $k) => (float)($v[$k] ?? 0);
        $calc = $c($prev, 'corona_yousei_nyuin') + $c($day, 'corona_yousei_shin_nyuin')
              - $c($day, 'corona_taiin_keikai') - $c($day, 'corona_taiin_tenin') - $c($day, 'corona_taiin_shibou');
        $input = $c($day, 'corona_yousei_nyuin');
        $chk = [['前日の陽性者入院数', '', $c($prev, 'corona_yousei_nyuin')], ['陽性者新入院', '＋', $c($day, 'corona_yousei_shin_nyuin')],
                ['疑似症から陽性', '＋', null], ['陽性者退院 軽快', '−', $c($day, 'corona_taiin_keikai')],
                ['陽性者退院 転院', '−', $c($day, 'corona_taiin_tenin')], ['陽性者退院 死亡', '−', $c($day, 'corona_taiin_shibou')],
                ['（計算値）当日陽性者入院数', '＝', $calc], ['（入力値）当日陽性者入院数', '', $input], ['差異', '', $input - $calc]]; ?>
        <table class="report hb">
          <thead><tr><th colspan="3">※陽性者入院数の確認</th></tr></thead>
          <tbody><?php foreach ($chk as [$l, $op, $x]): ?>
            <tr><th class="hb-label"><?= h($l) ?></th><td class="hb-op"><?= h($op) ?></td><td class="n<?= $x === null ? ' hb-none' : '' ?>"><?= $x === null ? '' : h(num($x)) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      </td>
    </tr></table>
  </td>
</tr></table>
<p class="hb-note">空欄はシステムに入力欄の無い項目です（本部の画面では手で入れます）。訪問診療（再掲）は往診・居宅指導を含みます。</p>
</div>
<?php page_footer();
