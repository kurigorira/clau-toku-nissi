<?php
/**
 * 予備ログイン画面。
 *
 * 通常は電子カルテから職員IDが引き継がれるため、この画面は出ない。
 * 引き継ぎが使えない端末と、保守作業のために用意している。
 */
require_once __DIR__ . '/../src/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = trim((string)($_POST['user_id'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $u  = db_row('SELECT * FROM m_user WHERE user_id = ? AND is_active = 1', [$id]);

    if ($u && $u['password_hash'] && password_verify($pw, $u['password_hash'])) {
        start_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['user_id'];
        header('Location: index.php');
        exit;
    }
    // 職員IDが存在しないのか、パスワードが違うのかは区別して見せない
    $error = '職員IDまたはパスワードが違います。';
    error_log("ログイン失敗: user_id={$id} ip=" . client_ip());
}

page_header('ログイン');
?>
<form method="post" class="login-form">
  <?= csrf_field() ?>
  <?php if ($error): ?><p class="flash flash-error"><?= h($error) ?></p><?php endif; ?>
  <p><label>職員ID<br><input type="text" name="user_id" autofocus required></label></p>
  <p><label>パスワード<br><input type="password" name="password" required></label></p>
  <p><button type="submit">ログイン</button></p>
  <p class="note">通常は電子カルテの画面から開くとログイン不要です。</p>
</form>
<?php page_footer();
