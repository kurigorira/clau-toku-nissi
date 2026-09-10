<?php
/**
 * 職員マスタ保守（管理者のみ）。
 *
 * user_id は電子カルテの職員IDをそのまま使う。入力者の記録はこのIDで残るため、
 * 退職・異動時は削除ではなく「無効」にする（過去の記録の追跡を残すため）。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!has_role($user, 'admin')) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('職員マスタは管理者のみ利用できます。', 'error');
    page_footer();
    exit;
}

$roles    = ['entry' => '入力者', 'toutyoku' => '当直者', 'ijika' => '医事課', 'admin' => '管理者'];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $now    = date('Y-m-d H:i:s');

    if ($action === 'add') {
        $id   = trim((string)($_POST['user_id'] ?? ''));
        $name = trim((string)($_POST['user_name'] ?? ''));
        $dept = (string)($_POST['dept_id'] ?? '');
        $role = (string)($_POST['role'] ?? 'entry');
        $pw   = (string)($_POST['password'] ?? '');

        if ($id === '' || $name === '') {
            $messages[] = ['職員IDと氏名は必須です。', 'error'];
        } elseif (!preg_match('/^[\w.\-]{1,32}$/', $id)) {
            $messages[] = ['職員IDは半角英数字・ハイフン・ピリオド・アンダースコアで32文字までです。', 'error'];
        } elseif (!isset(all_depts(false)[$dept])) {
            $messages[] = ['部署の指定が不正です。', 'error'];
        } elseif (!isset($roles[$role])) {
            $messages[] = ['役割の指定が不正です。', 'error'];
        } elseif (db_row('SELECT user_id FROM m_user WHERE user_id = ?', [$id])) {
            $messages[] = ["職員ID「{$id}」は既に登録されています。", 'error'];
        } else {
            db_exec('INSERT INTO m_user (user_id,user_name,dept_id,role,password_hash,is_active,created_at,updated_at)
                     VALUES (?,?,?,?,?,1,?,?)',
                    [$id, $name, $dept, $role, $pw === '' ? null : password_hash($pw, PASSWORD_DEFAULT), $now, $now]);
            $messages[] = ["{$name}（{$id}）を登録しました。", 'ok'];
        }
    }

    if ($action === 'update') {
        $n = 0;
        foreach ($_POST['u'] ?? [] as $id => $u) {
            $dept = (string)($u['dept_id'] ?? '');
            $role = (string)($u['role'] ?? 'entry');
            if (!isset(all_depts(false)[$dept]) || !isset($roles[$role])) {
                continue;
            }
            db_exec('UPDATE m_user SET user_name = ?, dept_id = ?, role = ?, is_active = ?, updated_at = ?
                      WHERE user_id = ?',
                    [trim((string)($u['user_name'] ?? '')), $dept, $role,
                     empty($u['is_active']) ? 0 : 1, $now, $id]);
            $n++;
        }
        $messages[] = ["{$n}件を更新しました。", 'ok'];
    }

    if ($action === 'reset_pw') {
        $id = (string)($_POST['user_id'] ?? '');
        $pw = (string)($_POST['password'] ?? '');
        if ($pw === '') {
            // 空にすると予備ログインを無効にする（電子カルテ経由のみになる）
            db_exec('UPDATE m_user SET password_hash = NULL, updated_at = ? WHERE user_id = ?', [$now, $id]);
            $messages[] = ["{$id} の予備ログインを無効にしました。", 'ok'];
        } elseif (mb_strlen($pw) < 8) {
            $messages[] = ['パスワードは8文字以上にしてください。', 'error'];
        } else {
            db_exec('UPDATE m_user SET password_hash = ?, updated_at = ? WHERE user_id = ?',
                    [password_hash($pw, PASSWORD_DEFAULT), $now, $id]);
            $messages[] = ["{$id} のパスワードを変更しました。", 'ok'];
        }
    }
}

$depts = all_depts(false);
$users = db_all('SELECT u.*, (SELECT COUNT(*) FROM d_audit a WHERE a.acted_by = u.user_id) AS acts
                   FROM m_user u ORDER BY u.is_active DESC, u.dept_id, u.user_id');

page_header('職員マスタ', $user);
foreach ($messages as [$m, $k]) { flash($m, $k); }
?>
<p class="note">
  職員IDは電子カルテの職員IDをそのまま使います。入力者の記録はこのIDで残るため、
  <strong>退職・異動時は削除せず「有効」のチェックを外してください。</strong>
  削除すると過去の入力者が誰か分からなくなります。
</p>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="update">
  <table class="report">
    <tr><th>職員ID</th><th>氏名</th><th>部署</th><th>役割</th><th>有効</th><th>予備ログイン</th><th>操作件数</th><th></th></tr>
    <?php foreach ($users as $u): ?>
      <tr class="<?= $u['is_active'] ? '' : 'inactive' ?>">
        <td><code><?= h($u['user_id']) ?></code></td>
        <td><input type="text" name="u[<?= h($u['user_id']) ?>][user_name]" size="14" value="<?= h($u['user_name']) ?>"></td>
        <td><select name="u[<?= h($u['user_id']) ?>][dept_id]">
            <?php foreach ($depts as $id => $d): ?>
              <option value="<?= h($id) ?>"<?= $id === $u['dept_id'] ? ' selected' : '' ?>><?= h($d['dept_name']) ?></option>
            <?php endforeach; ?></select></td>
        <td><select name="u[<?= h($u['user_id']) ?>][role]">
            <?php foreach ($roles as $r => $lab): ?>
              <option value="<?= h($r) ?>"<?= $r === $u['role'] ? ' selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?></select></td>
        <td><input type="checkbox" name="u[<?= h($u['user_id']) ?>][is_active]" value="1" <?= $u['is_active'] ? 'checked' : '' ?>></td>
        <td><?= $u['password_hash'] ? '設定あり' : '—' ?></td>
        <td class="n"><?= (int)$u['acts'] ?></td>
        <td><a href="?pw=<?= h(urlencode($u['user_id'])) ?>">PW変更</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="actions"><button type="submit" class="primary">保存</button></p>
</form>

<?php if (!empty($_GET['pw'])): $pwId = (string)$_GET['pw']; ?>
<h2>予備ログインのパスワード変更：<?= h($pwId) ?></h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="reset_pw">
  <input type="hidden" name="user_id" value="<?= h($pwId) ?>">
  <p><label>新しいパスワード（8文字以上。空欄にすると予備ログインを無効化）<br>
    <input type="password" name="password" size="24"></label></p>
  <p class="actions"><button type="submit" class="primary">変更</button></p>
</form>
<?php endif; ?>

<h2>新規登録</h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <table class="report">
    <tr><th>職員ID</th><td><input type="text" name="user_id" size="20" required></td></tr>
    <tr><th>氏名</th><td><input type="text" name="user_name" size="20" required></td></tr>
    <tr><th>部署</th><td><select name="dept_id">
      <?php foreach ($depts as $id => $d): ?><option value="<?= h($id) ?>"><?= h($d['dept_name']) ?></option><?php endforeach; ?>
    </select></td></tr>
    <tr><th>役割</th><td><select name="role">
      <?php foreach ($roles as $r => $lab): ?><option value="<?= h($r) ?>"><?= h($lab) ?></option><?php endforeach; ?>
    </select></td></tr>
    <tr><th>予備ログインのパスワード</th><td><input type="password" name="password" size="20">
        <span class="note">空欄なら電子カルテ経由のみ</span></td></tr>
  </table>
  <p class="actions"><button type="submit" class="primary">登録</button></p>
</form>
<?php page_footer();
