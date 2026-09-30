<?php
/**
 * 予備ログイン画面。
 *
 * 通常は電子カルテから職員IDが引き継がれるため、この画面は出ない。
 * 引き継ぎが使えない端末と、保守作業のために用意している。
 */
require_once __DIR__ . '/../src/auth.php';

$authCfg = cfg('auth') ?? [];
$emrMode = ($authCfg['mode'] ?? 'emr') === 'emr';

// 電子カルテのボタンがこの画面を開いた（?staffId=… 付き）なら、入口（index.php）に回してそのまま入る
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $emrMode && auth_emr_param_in_url() !== null) {
    header('Location: index.php?' . http_build_query($_GET));
    exit;
}
// 電子カルテからIDが渡されたのに、設定が local で使えなかった（require_login から来た）
$emrIgnored = !$emrMode && (($_GET['emr'] ?? '') === 'ignored' || auth_emr_param_in_url() !== null);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = trim((string)($_POST['user_id'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $u  = db_row('SELECT * FROM m_user WHERE user_id = ? AND is_active = 1', [$id]);

    if ($u && $u['password_hash'] && password_verify($pw, $u['password_hash'])) {
        start_session();
        session_regenerate_id(true);
        $_SESSION = [];                          // 前にこの端末を使った人の状態は持ち越さない
        $_SESSION['user_id'] = $u['user_id'];
        $_SESSION['via']       = 'local';      // パスワードで入った。医事課・管理者も最初から本来の役割
        $_SESSION['last_seen'] = time();
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
  <?php if ($emrIgnored): ?>
  <p class="flash flash-warn">電子カルテから職員IDが渡されましたが、config/config.php の auth.mode が
    「<?= h($authCfg['mode'] ?? '') ?>」のため使っていません。電子カルテから開いて入るには 'emr' にしてください。</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="flash flash-error"><?= h($error) ?></p><?php endif; ?>
  <p><label>職員ID<br><input type="text" name="user_id" autofocus required></label></p>
  <p><label>パスワード<br><input type="password" name="password" required></label></p>
  <p><button type="submit">ログイン</button></p>
  <p class="note">通常は電子カルテの画面から開くとログイン不要です。</p>
</form>
<?php page_footer();
