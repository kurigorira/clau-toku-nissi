<?php
/**
 * 終了。共用の電子カルテ端末で、次の人が前の人のまま入力しないようにする。
 */
require_once __DIR__ . '/../src/auth.php';

start_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

$mode = (cfg('auth') ?? [])['mode'] ?? 'emr';
page_header('終了しました');
?>
<p>このシステムを終了しました。</p>
<?php if ($mode === 'emr'): ?>
  <p>続けて使うときは、電子カルテのメニューからもう一度開いてください。</p>
  <p class="note"><a href="login.php">予備ログイン（職員ID＋パスワード）</a></p>
<?php else: ?>
  <p><a href="login.php">ログイン画面へ</a></p>
<?php endif; ?>
<?php page_footer();
