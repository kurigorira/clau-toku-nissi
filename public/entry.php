<?php
/**
 * 部署別入力画面。
 *
 * 入力欄は項目マスタ（m_item）から自動生成する。
 * 項目が増えてもこのファイルは変えない。マスタに1行足すだけで欄が増える。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';
require_once __DIR__ . '/../src/calc.php';
require_once __DIR__ . '/../src/entry_form.php';

$user = require_login();

$date   = valid_date($_REQUEST['hizuke'] ?? null) ?? date('Y-m-d', strtotime('-1 day'));
$deptId = (string)($_REQUEST['dept'] ?? $user['dept_id']);
$depts  = all_depts();

if (!isset($depts[$deptId]) && $deptId === $user['dept_id']) {
    // 日誌に関係しない部署（その他・閲覧のみ）の職員
    page_header('入力する項目はありません', $user);
    flash('この部署には入力する項目がありません（閲覧のみ）。入力状況・病院日誌・救急搬入は上のメニューから見られます。', 'info');
    page_footer();
    exit;
}
if (!isset($depts[$deptId])) {
    http_response_code(404);
    exit('部署が見つかりません。');
}
$dept = $depts[$deptId];

// 外来は電子カルテ日報の転記画面で入力する（科別×時間帯の表で、紙と同じ並び）
if ($deptId === 'gairai') {
    header('Location: nippo.php?hizuke=' . urlencode($date));
    exit;
}

if (!can_edit_dept($user, $deptId)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash("{$dept['dept_name']}の入力権限がありません。自部署の画面を開いてください。", 'error');
    page_footer();
    exit;
}

$sub       = submission($date, $deptId);
$confirmed = ($sub['status'] ?? '') === 'confirmed';
$locked    = $confirmed && !is_ijika($user);   // 確定後は部署は編集不可、医事課のみ訂正可
$messages  = [];
$warnings  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($locked) {
        $messages[] = ['この日付は医事課が確定済みです。訂正が必要な場合は医事課へ連絡してください。', 'error'];
    } else {
        $res      = entry_form_save($date, $deptId, $_POST, $user['user_id']);
        $warnings = $res['warnings'];
        if (($_POST['action'] ?? '') === 'submit') {
            touch_submission($date, $deptId, 'submitted', $user['user_id']);
            $messages[] = ["{$res['saved']}件を保存し、提出しました。", 'ok'];
        } else {
            $messages[] = ["{$res['saved']}件を保存しました（まだ提出されていません）。", 'ok'];
        }
        $sub = submission($date, $deptId);
    }
}

$entries = dept_entries($date, $deptId);
$last    = last_update_of_dept($date, $deptId);


page_header($dept['dept_name'] . '　入力', $user);
?>
<form method="get" class="datebar">
  <input type="hidden" name="dept" value="<?= h($deptId) ?>">
  <label>日付 <input type="date" name="hizuke" value="<?= h($date) ?>"></label>
  <span class="youbi">（<?= h(youbi($date)) ?>）</span>
  <button type="submit">表示</button>
  <?php if (is_ijika($user)): ?>
    <select name="dept" onchange="this.form.submit()">
      <?php foreach ($depts as $id => $d): ?>
        <option value="<?= h($id) ?>"<?= $id === $deptId ? ' selected' : '' ?>><?= h($d['dept_name']) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
</form>

<?php foreach ($messages as [$m, $k]) { flash($m, $k); } ?>
<?php if ($warnings): ?>
  <div class="flash flash-warn">
    <strong>確認してください</strong>
    <ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>


<?php if ($confirmed): ?>
  <p class="flash flash-<?= $locked ? 'error' : 'warn' ?>">
    この日付は医事課が確定済みです<?= $locked ? '。編集できません。' : '。医事課として訂正できますが、変更履歴に残ります。' ?>
  </p>
<?php endif; ?>

<form method="post" class="entry-form">
  <?= csrf_field() ?>
  <input type="hidden" name="hizuke" value="<?= h($date) ?>">
  <input type="hidden" name="dept" value="<?= h($deptId) ?>">

  <?php entry_form_fields($deptId, $date, $entries, $locked); ?>

  <?php if (!$locked): ?>
  <p class="actions">
    <button type="submit" name="action" value="save">一時保存</button>
    <button type="submit" name="action" value="submit" class="primary">提出する</button>
  </p>
  <?php endif; ?>
</form>

<p class="lastupdate">
  <?php if ($last && $last['updated_at']): ?>
    最終更新：<?= h($last['updated_at']) ?>　<?= h($last['user_name'] ?? $last['updated_by']) ?>
  <?php else: ?>
    最終更新：まだ入力がありません
  <?php endif; ?>
  <?php if ($sub && $sub['submitted_at']): ?>
    ／ 提出：<?= h($sub['submitted_at']) ?>
  <?php endif; ?>
</p>
<?php page_footer();
