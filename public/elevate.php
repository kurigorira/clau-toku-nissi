<?php
/**
 * 医事課・管理者の権限を使う前に、パスワードで本人かを確かめる。
 *
 * 電子カルテから職員IDだけで入った医事課・管理者は、入力者として扱われている
 * （URLのIDは書き換えれば誰でも名乗れるため。src/auth.php 参照）。
 * ここでパスワードが合えば、このセッションの間だけ本来の役割に戻る。
 */
require_once __DIR__ . '/../src/auth.php';

$user = require_login();

/** 戻り先。このシステムの画面（xxx.php?…）だけを許す。よそのサイトへは飛ばさない。 */
$next = (string)($_REQUEST['next'] ?? 'index.php');
if (!preg_match('/^[a-z_]+\.php(\?[^\s<>"\']*)?$/', $next)) {
    $next = 'index.php';
}

if (!in_array($user['role_real'], ROLES_NEED_PASSWORD, true)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('医事課・管理者として登録されている職員だけが使えます。', 'error');
    page_footer();
    exit;
}
if ($user['elevated']) {
    header('Location: ' . $next);
    exit;
}

$error  = '';
$lockMinutes = 15;
$fails  = (int)($_SESSION['elevate_fail'] ?? 0);
$lockAt = (int)($_SESSION['elevate_lock'] ?? 0);
$locked = $lockAt > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    if ($user['password_hash'] && password_verify($pw, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['elevated'] = true;
        unset($_SESSION['elevate_fail'], $_SESSION['elevate_lock']);
        error_log("権限の確認に成功: user_id={$user['user_id']} role={$user['role_real']} ip=" . client_ip());
        header('Location: ' . $next);
        exit;
    }
    $fails++;
    $_SESSION['elevate_fail'] = $fails;
    error_log("権限の確認に失敗（{$fails}回目）: user_id={$user['user_id']} ip=" . client_ip());
    if ($fails >= 5) {
        $_SESSION['elevate_lock'] = time() + $lockMinutes * 60;
        $locked = true;
    }
    $error = 'パスワードが違います。';
}

$roleName = ['ijika' => '医事課', 'admin' => '管理者'][$user['role_real']];
page_header($roleName . 'として作業する', $user);
if ($error !== '') {
    flash($error, 'error');
}
?>
<?php if (!$user['password_hash']): ?>
  <p class="flash flash-warn">
    パスワードがまだ設定されていません。管理者にパスワードの設定を依頼してください。
    それまでは入力者として、自部署（<?= h($user['dept_name'] ?? $user['dept_id']) ?>）の入力だけができます。
  </p>
<?php elseif ($locked): ?>
  <p class="flash flash-error">
    パスワードを続けて間違えたため、しばらく受け付けません（<?= (int)$lockMinutes ?>分）。
    心当たりが無い場合は管理者に連絡してください。
  </p>
<?php else: ?>
  <p>
    電子カルテから開いたときは、<?= h($roleName) ?>の画面（全部署の訂正・確定・統計表<?= $user['role_real'] === 'admin' ? '・マスタ' : '' ?>）を使う前に、
    本人であることをパスワードで確かめます。この画面を閉じるか「終了」するまで有効です。
  </p>
  <form method="post" class="login-form">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <p>職員：<?= h($user['user_name']) ?>（<?= h($user['user_id']) ?>）</p>
    <p><label>パスワード<br><input type="password" name="password" autofocus required></label></p>
    <p><button type="submit" class="primary">確定</button></p>
  </form>
<?php endif; ?>
<?php page_footer();
