<?php
/**
 * 画面まわりの共通処理。エスケープ・CSRF・ヘッダ／フッタ。
 */

/**
 * HTMLエスケープ。画面に文字を出すときは必ずこれを通す。
 * 特記事項には患者氏名が入るため、素通しにはできない。
 */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 数値を表示用に整える。null は空文字、小数は指定桁で丸める。 */
function num($v, int $decimals = 0): string
{
    if ($v === null || $v === '') {
        return '';
    }
    return number_format((float)$v, $decimals);
}

/** セッションを開始する（未開始のときだけ）。 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/** CSRFトークンを返す（無ければ作る）。 */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** フォームに埋める hidden タグ。 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** POSTのCSRFトークンを検証する。不正なら処理を止める。 */
function csrf_check(): void
{
    start_session();
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('不正な送信です。画面を開き直してから、もう一度お試しください。');
    }
}

/**
 * 日付文字列を検証して Y-m-d で返す。不正なら null。
 * 画面から来る日付は必ずここを通し、SQLへは常にプレースホルダで渡す。
 */
function valid_date(?string $s): ?string
{
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y) ? $s : null;
}

/** 曜日（日本語1文字）。 */
function youbi(string $date): string
{
    return ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', strtotime($date))];
}

/** 画面の共通ヘッダ。 */
function page_header(string $title, ?array $user = null): void
{
    ?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?>｜病院日誌・医事統計</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="app-header">
  <div class="app-title"><a href="index.php">病院日誌・医事統計</a></div>
  <nav class="app-nav">
    <a href="index.php">入力状況</a>
    <a href="nissi.php">病院日誌</a>
    <a href="qq_report.php">救急搬入</a>
    <?php if ($user && in_array($user['role'], ['ijika', 'admin'], true)): ?>
      <a href="soukatsu.php">総括</a>
      <a href="zaiin.php">平均在院日数</a>
      <a href="toukei.php">患者数統計表</a>
      <a href="byoin_houkoku.php">病院報告</a>
      <a href="audit.php">変更履歴</a>
    <?php elseif ($user && $user['dept_id'] === 'byoto'): ?>
      <a href="zaiin.php">平均在院日数</a>
    <?php endif; ?>
    <?php if ($user && $user['role'] === 'admin'): ?>
      <a href="admin_master.php">マスタ</a>
      <a href="admin_user.php">職員</a>
    <?php endif; ?>
  </nav>
  <?php if ($user): ?>
  <div class="app-user"><?= h($user['user_name']) ?>（<?= h($user['dept_name'] ?? $user['dept_id']) ?>）</div>
  <?php endif; ?>
</header>
<main>
<h1><?= h($title) ?></h1>
<?php
}

/** 画面の共通フッタ。 */
function page_footer(): void
{
    ?>
</main>
<footer class="app-footer">長崎北徳洲会病院</footer>
</body>
</html>
<?php
}

/** 画面上部に出す通知。 */
function flash(string $msg, string $kind = 'info'): void
{
    echo '<p class="flash flash-' . h($kind) . '">' . h($msg) . '</p>';
}
