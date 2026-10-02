<?php
/**
 * 入力状況ボード。このシステムのトップ画面。
 *
 * 縦＝部署、横＝直近14日。医事課は赤いセルだけ見れば督促でき、
 * 部署を1つずつ電話で確認する作業がなくなる。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();

$to    = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
$days  = 14;
$from  = date('Y-m-d', strtotime("$to -" . ($days - 1) . ' day'));
$depts = all_depts();
$board = entry_status($from, $to);

// 直近の入力対象日（＝集計の基準日）。当日は入力途中なので前日を既定にする
$focus = date('Y-m-d', strtotime("$to -1 day"));

$labels = [
    'off'       => ['対象外', 'st-off'],
    'none'      => ['未入力', 'st-none'],
    'partial'   => ['途中',   'st-partial'],
    'submitted' => ['提出済', 'st-submitted'],
    'confirmed' => ['確定',   'st-confirmed'],
];

// 基準日の未提出部署をまとめる
$pending = [];
foreach ($depts as $id => $d) {
    $st = $board[$focus][$id]['status'] ?? 'none';
    if (in_array($st, ['none', 'partial'], true)) {
        $pending[$id] = ['dept' => $d, 'status' => $st, 'missing' => $board[$focus][$id]['missing'] ?? []];
    }
}

page_header('入力状況', $user);
?>
<form method="get" class="datebar">
  <label>表示終了日 <input type="date" name="to" value="<?= h($to) ?>"></label>
  <button type="submit">表示</button>
</form>

<section class="pending">
  <h2><?= h($focus) ?>（<?= h(youbi($focus)) ?>）の未提出</h2>
  <?php if (!$pending): ?>
    <p class="flash flash-ok">全部署が提出済みです。</p>
  <?php else: ?>
    <ul class="pending-list">
      <?php foreach ($pending as $id => $p): ?>
        <li>
          <a href="entry.php?dept=<?= h($id) ?>&amp;hizuke=<?= h($focus) ?>"><?= h($p['dept']['dept_name']) ?></a>
          <span class="badge <?= h($labels[$p['status']][1]) ?>"><?= h($labels[$p['status']][0]) ?></span>
          <?php if ($p['missing']): ?>
            <span class="missing">未入力：<?= h(implode('、', array_slice($p['missing'], 0, 5)))
              . (count($p['missing']) > 5 ? ' ほか' . (count($p['missing']) - 5) . '件' : '') ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<div class="board-scroll">
<table class="board">
  <thead>
    <tr>
      <th class="dept-col">部署</th>
      <?php for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))): ?>
        <th class="<?= in_array(youbi($d), ['土', '日'], true) ? 'weekend' : '' ?>">
          <?= (int)substr($d, 8, 2) ?><br><small><?= h(youbi($d)) ?></small>
        </th>
      <?php endfor; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($depts as $id => $dept): ?>
    <tr>
      <th class="dept-col"><a href="entry.php?dept=<?= h($id) ?>&amp;hizuke=<?= h($focus) ?>"><?= h($dept['dept_name']) ?></a></th>
      <?php for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))):
          $cell = $board[$d][$id] ?? ['status' => 'none', 'missing' => []];
          [$lab, $cls] = $labels[$cell['status']];
          $tip = $lab . ($cell['missing'] ? '：' . implode('、', array_slice($cell['missing'], 0, 8)) : '');
      ?>
        <td class="<?= h($cls) ?>" title="<?= h("$d {$dept['dept_name']} $tip") ?>">
          <a href="entry.php?dept=<?= h($id) ?>&amp;hizuke=<?= h($d) ?>"><span class="sr"><?= h($lab) ?></span></a>
        </td>
      <?php endfor; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<p class="legend">
  <span class="badge st-none">未入力</span>
  <span class="badge st-partial">途中・欠測あり</span>
  <span class="badge st-submitted">提出済</span>
  <span class="badge st-confirmed">医事課確定</span>
  <span class="badge st-off">入力対象外</span>
</p>
<?php page_footer();
