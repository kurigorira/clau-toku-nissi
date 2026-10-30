<?php
/**
 * まとめて入力（1日分）。1つの日付について、全部署の入力欄を1画面に並べて保存する（医事課・管理者）。
 *
 * 過去の日でまだ入力していない分を、部署の画面を開き直さずに入れるためのもの。
 * 入力欄・保存のしくみは部署の入力画面（entry.php）と同じ（src/entry_form.php）。
 * 外来は電子カルテ日報の転記画面（Excel読み込み・照合あり）で入れるので、ここにはリンクだけ出す。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/entry_form.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('まとめて入力は医事課と管理者のみ利用できます。', 'error');
    page_footer();
    exit;
}

$yesterday = date('Y-m-d', strtotime('-1 day'));
$date  = valid_date($_REQUEST['hizuke'] ?? null) ?? $yesterday;
$depts = array_filter(all_depts(), fn($id) => $id !== 'gairai', ARRAY_FILTER_USE_KEY);
$label = ['none' => '未入力', 'draft' => '入力中', 'submitted' => '提出済', 'confirmed' => '確定'];
$messages = [];
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $submit = ($_POST['action'] ?? '') === 'submit';
    $saved = $savedDepts = 0;
    foreach ($depts as $id => $d) {
        // 値を入れた部署と、前から入力のある部署だけ保存する（何も入れていない部署を「入力中」にしない）
        if (!entry_form_has_input($id, $_POST) && !dept_entries($date, $id) && !($id === 'byoto' && tokki_rows($date))) {
            continue;
        }
        $res = entry_form_save($date, $id, $_POST, $user['user_id']);
        $saved += $res['saved'];
        $savedDepts++;
        foreach ($res['warnings'] as $w) {
            $warnings[] = "{$d['dept_name']}：{$w}";
        }
        if ($submit) {
            touch_submission($date, $id, 'submitted', $user['user_id']);
        }
    }
    $messages[] = $savedDepts === 0
        ? ['入力された値がありません（保存した部署はありません）。', 'info']
        : ["{$savedDepts}部署・{$saved}件を保存しました" . ($submit ? '（入力のある部署を提出しました）。' : '（まだ提出されていません）。'), 'ok'];
}

// 入力が1件も無い日（直近60日・前日まで）
$from60 = date('Y-m-d', strtotime($yesterday . ' -59 day'));
$hasDay = [];
foreach (db_all('SELECT hizuke, COUNT(*) AS c FROM d_daily_value WHERE hizuke BETWEEN ? AND ? GROUP BY hizuke', [$from60, $yesterday]) as $r) {
    $hasDay[$r['hizuke']] = (int)$r['c'];
}
$emptyDays = [];
for ($d = $yesterday; $d >= $from60; $d = date('Y-m-d', strtotime($d . ' -1 day'))) {
    if (!isset($hasDay[$d])) {
        $emptyDays[] = $d;
    }
}

$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
page_header('まとめて入力（1日分）', $user);
?>
<form method="get" class="datebar">
  <a href="bulk_entry.php?hizuke=<?= h($prev) ?>">＜前日</a>
  <label>日付 <input type="date" name="hizuke" value="<?= h($date) ?>" max="<?= h($yesterday) ?>"></label>
  <span class="youbi">（<?= h(youbi($date)) ?>）</span>
  <button type="submit">表示</button>
  <?php if ($next <= $yesterday): ?><a href="bulk_entry.php?hizuke=<?= h($next) ?>">翌日＞</a><?php endif; ?>
  <a href="nissi.php?hizuke=<?= h($date) ?>">この日の病院日誌</a>
</form>

<?php if ($emptyDays): ?>
  <p class="bulk-empty"><strong>入力が1件も無い日</strong>（直近60日）：
    <?php foreach ($emptyDays as $d): ?>
      <a href="bulk_entry.php?hizuke=<?= h($d) ?>"<?= $d === $date ? ' class="cur"' : '' ?>><?= h(date('n/j', strtotime($d))) ?>（<?= h(youbi($d)) ?>）</a>
    <?php endforeach; ?>
  </p>
<?php else: ?>
  <p class="note">直近60日に、入力が1件も無い日はありません。</p>
<?php endif; ?>

<?php foreach ($messages as [$m, $k]) { flash($m, $k); } ?>
<?php if ($warnings): ?>
  <div class="flash flash-warn">
    <strong>確認してください</strong>
    <ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<p class="note">この日の全部署の入力欄です。入れた部署だけが保存されます（何も入れていない部署は「未入力」のまま）。
  確定済みの部署も医事課として訂正でき、変更履歴に残ります。</p>

<p class="bulk-jump">部署へ移動：
  <a href="#d-gairai">外来</a>
  <?php foreach ($depts as $id => $d): ?><a href="#d-<?= h($id) ?>"><?= h($d['dept_name']) ?></a><?php endforeach; ?>
</p>

<form method="post" class="entry-form">
  <?= csrf_field() ?>
  <input type="hidden" name="hizuke" value="<?= h($date) ?>">

  <section class="bulk-dept" id="d-gairai">
    <h2>外来</h2>
    <p class="note">外来は電子カルテ日報の転記画面で入力します（Excelから読み込めます）。
      <a href="nippo.php?hizuke=<?= h($date) ?>">日報転記でこの日を開く</a>
      <?php $gs = submission($date, 'gairai'); ?>　状況：<?= h($label[$gs['status'] ?? 'none'] ?? '') ?></p>
  </section>

  <?php foreach ($depts as $id => $d):
      $sub = submission($date, $id); ?>
    <section class="bulk-dept" id="d-<?= h($id) ?>">
      <h2><?= h($d['dept_name']) ?>
        <span class="badge <?= h(['none' => 'st-none', 'draft' => 'st-partial', 'submitted' => 'st-submitted', 'confirmed' => 'st-confirmed'][$sub['status'] ?? 'none'] ?? 'st-none') ?>"><?= h($label[$sub['status'] ?? 'none'] ?? '') ?></span>
      </h2>
      <?php entry_form_fields($id, $date, dept_entries($date, $id), false); ?>
    </section>
  <?php endforeach; ?>

  <p class="actions bulk-actions">
    <button type="submit" name="action" value="save">一時保存</button>
    <button type="submit" name="action" value="submit" class="primary">提出する（入力のある部署）</button>
  </p>
</form>
<?php page_footer();
