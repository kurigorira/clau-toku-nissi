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
require_once __DIR__ . '/../src/tokki.php';

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
        $res      = save_dept_entries($date, $deptId, $_POST['v'] ?? [], $user['user_id']);
        $warnings = $res['warnings'];
        // 病棟は定数超過報告（オーバーベッドの患者・最大6名）も一緒に保存する
        if ($deptId === 'byoto' && isset($_POST['tokki']) && is_array($_POST['tokki'])) {
            db()->beginTransaction();
            try {
                $tr = tokki_save($date, $_POST['tokki'], $user['user_id']);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            $warnings = array_merge($warnings, $tr['warnings']);
        }
        if (($_POST['action'] ?? '') === 'submit') {
            touch_submission($date, $deptId, 'submitted', $user['user_id']);
            $messages[] = ["{$res['saved']}件を保存し、提出しました。", 'ok'];
        } else {
            $messages[] = ["{$res['saved']}件を保存しました（まだ提出されていません）。", 'ok'];
        }
        $sub = submission($date, $deptId);
    }
}

$items   = input_items_of_dept($deptId, $date);
$entries = dept_entries($date, $deptId);
$last    = last_update_of_dept($date, $deptId);

// 表示順のまま group_code でまとめる
$groups = [];
foreach ($items as $code => $it) {
    $groups[$it['group_code']]['name'] = $it['group_name'];
    $groups[$it['group_code']]['items'][$code] = $it;
}

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

<?php if (!is_entry_day($dept, $date)): ?>
  <p class="flash flash-info"><?= h($dept['dept_name']) ?>は<?= h(youbi($date)) ?>曜日が入力対象外に設定されています。必要であれば入力できます。</p>
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

  <?php foreach ($groups as $gcode => $g): ?>
    <fieldset>
      <legend><?= h($g['name'] ?: $gcode) ?></legend>
      <table class="entry-table">
        <?php foreach ($g['items'] as $code => $it):
            $cur  = $entries[$code] ?? null;
            $isMl = $it['value_type'] === 'multiline';
            $isTx = $it['value_type'] === 'text' || $isMl;
            $val  = $cur === null ? '' : ($isTx ? (string)$cur['value_text'] : num_plain($cur['value_num']));
        ?>
        <tr>
          <th<?= (int)$it['required'] === 1 ? ' class="req"' : '' ?>><?= h($it['item_name']) ?></th>
          <td>
            <?php if ($isMl): ?>
              <textarea name="v[<?= h($code) ?>]" rows="3" cols="40"<?= $locked ? ' readonly' : '' ?>><?= h($val) ?></textarea>
            <?php elseif ($isTx): ?>
              <input type="text" name="v[<?= h($code) ?>]" value="<?= h($val) ?>" size="24"<?= $locked ? ' readonly' : '' ?>>
            <?php else: ?>
              <input type="text" inputmode="numeric" name="v[<?= h($code) ?>]" value="<?= h($val) ?>"
                     size="6" class="num"<?= $locked ? ' readonly' : '' ?>>
            <?php endif; ?>
            <span class="unit"><?= h($it['unit']) ?></span>
            <?php if ($it['note']): ?><span class="note"><?= h($it['note']) ?></span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </fieldset>
  <?php endforeach; ?>

  <?php if ($deptId === 'byoto'):
      // 定数超過報告（オーバーベッド）。紙の様式どおり最大6名。在院が定床を超えた日は入力を促す
      $tokkiNow = tokki_rows($date);
      $zaiin = [];
      foreach (['3', '4', '5'] as $w) {
          $e = $entries["byoto{$w}_zaiin"] ?? null;
          $zaiin[$w] = $e === null || $e['value_num'] === null ? null : (int)$e['value_num'];
      }
      $over = tokki_overbed($zaiin, $date); ?>
    <fieldset>
      <legend>定数超過報告（近隣受入不可のため）</legend>
      <?php if ($over !== ''): ?>
        <p class="flash flash-warn">在院患者数が定床を超えています（<?= h($over) ?>）。オーバーベッドの患者を入力してください。</p>
      <?php endif; ?>
      <table class="entry-table tokki-table">
        <tr><th></th><th>病棟</th><th>ＩＤ</th><th>氏名</th><th>入院経路</th><th>病名</th></tr>
        <?php for ($i = 0; $i < TOKKI_MAX; $i++):
            $r = $tokkiNow[$i] ?? []; $f = fn(string $k) => h($r[$k] ?? ''); $ro = $locked ? ' readonly' : ''; ?>
          <tr>
            <th><?= $i + 1 ?></th>
            <td><select name="tokki[<?= $i ?>][ward]"<?= $locked ? ' disabled' : '' ?>>
                  <option value=""></option>
                  <?php foreach (tokki_wards() as $wn): ?>
                    <option<?= ($r['ward'] ?? '') === $wn ? ' selected' : '' ?>><?= h($wn) ?></option>
                  <?php endforeach; ?>
                  <?php if (($r['ward'] ?? '') !== '' && !in_array($r['ward'], tokki_wards(), true)): ?>
                    <option selected><?= $f('ward') ?></option>
                  <?php endif; ?>
                </select></td>
            <td><input type="text" name="tokki[<?= $i ?>][patient_id]" value="<?= $f('patient_id') ?>" size="9" maxlength="16"<?= $ro ?>></td>
            <td><input type="text" name="tokki[<?= $i ?>][patient_name]" value="<?= $f('patient_name') ?>" size="12" maxlength="64"<?= $ro ?>></td>
            <td><input type="text" name="tokki[<?= $i ?>][route]" value="<?= $f('route') ?>" size="9" maxlength="32" list="tokki-routes"<?= $ro ?>></td>
            <td><input type="text" name="tokki[<?= $i ?>][diagnosis]" value="<?= $f('diagnosis') ?>" size="20" maxlength="128"<?= $ro ?>></td>
          </tr>
        <?php endfor; ?>
      </table>
      <datalist id="tokki-routes"><?php foreach (tokki_routes() as $rt): ?><option value="<?= h($rt) ?>"><?php endforeach; ?></datalist>
      <p class="note">最大6名。行を消すときは、その行の欄を空にして保存します。病院日誌の最下段「特記事項等」に出ます。</p>
    </fieldset>
  <?php endif; ?>

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
