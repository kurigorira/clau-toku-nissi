<?php
/**
 * 入力状況ボード。このシステムのトップ画面。
 *
 * 縦＝部署、横＝表示日までの14日。医事課は赤いセルだけ見れば督促でき、
 * 部署を1つずつ電話で確認する作業がなくなる。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();

// 表示日。開いたときは前日（当日は入力途中なので）。未提出の一覧と、表の右端の列はこの日
$to    = valid_date($_REQUEST['to'] ?? null) ?? date('Y-m-d', strtotime('-1 day'));

// 医事課の確定・確定解除（日付の列の下のボタン）。確定すると、その日の部署の画面は編集できなくなる
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $day = valid_date($_POST['hizuke'] ?? null);
    if (!is_ijika($user)) {
        $messages[] = ['確定は医事課・管理者が行います。', 'error'];
    } elseif ($day === null || $day > date('Y-m-d')) {
        $messages[] = ['日付が正しくありません。', 'error'];
    } elseif (($_POST['action'] ?? '') === 'confirm') {
        $r = confirm_date($day, $user['user_id']);
        $messages[] = [$r['done']
            ? sprintf('%s（%s）：%d部署を確定しました。', $day, youbi($day), count($r['done']))
            : sprintf('%s（%s）：確定できる提出済みの部署がありません。', $day, youbi($day)), $r['done'] ? 'ok' : 'warn'];
        if ($r['left']) {
            $messages[] = ['未提出・欠測ありのため確定していない部署：' . implode('、', $r['left']), 'warn'];
        }
    } elseif (($_POST['action'] ?? '') === 'unconfirm') {
        $n = unconfirm_date($day);
        $messages[] = [sprintf('%s（%s）：%d部署の確定を外し、提出済みに戻しました。', $day, youbi($day), $n), 'ok'];
    }
}

$days  = 14;
$from  = date('Y-m-d', strtotime("$to -" . ($days - 1) . ' day'));
$depts = all_depts();
$board = entry_status($from, $to);

// 未提出をまとめる日＝表示日
$focus = $to;

$labels = [
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
foreach ($messages as [$m, $k]) {
    flash($m, $k);
}
?>
<form method="get" class="datebar">
  <label>表示日 <input type="date" name="to" value="<?= h($to) ?>"></label>
  <button type="submit">表示</button>
  <?php if (is_ijika($user)): ?><a href="bulk_entry.php">過去の日をまとめて入力（1日分・全部署）</a><?php endif; ?>
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
      <th class="dept-col"><a href="entry.php?dept=<?= h($id) ?>&amp;hizuke=<?= h($focus) ?>"><?= h($dept['dept_name']) ?></a>
        <?php if (in_array($id, TOUTYOKU_DEPTS, true)): ?><span class="by-toutyoku">当直者が記載</span><?php endif; ?>
        <?php if ($id === 'naishikyo'): ?><span class="by-toutyoku">外来も記載</span><?php endif; ?></th>
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
  <?php if (is_ijika($user)): ?>
  <tfoot>
    <tr>
      <th class="dept-col">医事課の確定</th>
      <?php for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))):
          $sts = array_column($board[$d] ?? [], 'status');
          $canConfirm = in_array('submitted', $sts, true);
          $canUndo    = in_array('confirmed', $sts, true);
      ?>
        <td class="confirm-cell">
          <?php if ($canConfirm || $canUndo): ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="hizuke" value="<?= h($d) ?>">
            <input type="hidden" name="to" value="<?= h($to) ?>">
            <?php if ($canConfirm): ?><button type="submit" name="action" value="confirm" title="<?= h($d) ?> の提出済みの部署を確定">確定</button><?php endif; ?>
            <?php if ($canUndo): ?><button type="submit" name="action" value="unconfirm" class="undo" title="<?= h($d) ?> の確定を外して提出済みに戻す">外す</button><?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
      <?php endfor; ?>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<p class="legend">
  <span class="badge st-none">未入力</span>
  <span class="badge st-partial">途中・欠測あり</span>
  <span class="badge st-submitted">提出済</span>
  <span class="badge st-confirmed">医事課確定</span>
</p>
<p class="note">外来（日報転記）・病棟・救急・当直は、当直者が記載します。内視鏡室は外来の職員も記載します。</p>
<?php if (is_ijika($user)): ?>
<p class="note">表のいちばん下の「確定」で、その日の提出済み（緑）の部署をまとめて確定します（濃い緑になり、部署は直せなくなります）。
  未入力・途中・欠測ありの部署は確定しません。「外す」で確定を外すと、部署がまた直せます。確定後の医事課の訂正は変更履歴に残ります。</p>
<?php endif; ?>
<?php page_footer();
