<?php
/**
 * 閲覧履歴（管理者のみ）。
 *
 * 誰が・いつ・どの画面を・どの条件で見たか。変更履歴（audit.php）は「直した」記録で、
 * こちらは「見た」記録。特記事項（患者ID・氏名）やCSV出力を誰が見たかを追うのに使う。
 * 記録は src/auth.php の access_log_record()。
 */
require_once __DIR__ . '/../src/auth.php';

$user = require_login();
if (!has_role($user, 'admin')) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('閲覧履歴は管理者のみ見られます。', 'error');
    if (can_elevate($user)) {
        echo '<p><a href="elevate.php?next=access_log.php">管理者として作業する（パスワード）</a></p>';
    }
    page_footer();
    exit;
}

$to    = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
$from  = valid_date($_GET['from'] ?? null) ?? date('Y-m-d', strtotime($to . ' -6 days'));
$who   = trim((string)($_GET['who'] ?? ''));
$page  = (string)($_GET['page'] ?? '');
$limit = 500;

/** 画面ファイル → 名前。一覧で読みやすくするため。 */
$pages = [
    'index.php' => '入力状況', 'entry.php' => '部署別入力', 'nippo.php' => '日報転記', 'nissi.php' => '病院日誌',
    'qq_report.php' => '救急搬入', 'soukatsu.php' => '総括', 'zaiin.php' => '平均在院日数', 'toukei.php' => '患者数統計表',
    'byoin_houkoku.php' => '病院報告', 'export.php' => 'CSV出力', 'audit.php' => '変更履歴', 'access_log.php' => '閲覧履歴',
    'admin_master.php' => 'マスタ', 'admin_user.php' => '職員', 'elevate.php' => '権限の確認',
];

$where  = ['a.acted_at BETWEEN ? AND ?'];
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($who !== '') {
    $where[]  = '(a.user_id = ? OR u.user_name LIKE ?)';
    $params[] = $who;
    $params[] = '%' . $who . '%';     // 氏名の一部で探す（管理者だけが使うので % などはそのまま）
}
if ($page !== '' && isset($pages[$page])) {
    $where[]  = 'a.page = ?';
    $params[] = $page;
}
$sql = 'SELECT a.*, u.user_name, d.dept_name FROM d_access_log a
          LEFT JOIN m_user u ON u.user_id = a.user_id
          LEFT JOIN m_dept d ON d.dept_id = u.dept_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.acted_at DESC, a.access_id DESC';

try {
    $total = (int)db_row('SELECT COUNT(*) AS n FROM d_access_log a LEFT JOIN m_user u ON u.user_id = a.user_id WHERE '
                         . implode(' AND ', $where), $params)['n'];
    $rows  = db_all($sql . (isset($_GET['csv']) ? '' : " LIMIT {$limit}"), $params);
} catch (Throwable $e) {
    page_header('閲覧履歴', $user);
    flash('閲覧履歴のテーブルがありません。db/migrations/002_access_log.sql を流してください（README の「閲覧履歴」）。', 'error');
    page_footer();
    exit;
}

// CSV出力（Excelでそのまま開けるよう UTF-8 BOM付き）。絞り込みの条件はそのまま
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode("閲覧履歴_{$from}_{$to}.csv"));
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['日時', '職員ID', '氏名', '部署', '画面', '条件', '送信', '端末']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['acted_at'], $r['user_id'], $r['user_name'] ?? '', $r['dept_name'] ?? '',
                       $pages[$r['page']] ?? $r['page'], $r['query'], $r['method'], $r['client_ip']]);
    }
    exit;
}

page_header('閲覧履歴', $user);
?>
<form method="get" class="datebar">
  <label>期間 <input type="date" name="from" value="<?= h($from) ?>"></label> 〜
  <label><input type="date" name="to" value="<?= h($to) ?>"></label>
  <label>職員（IDか氏名の一部） <input type="text" name="who" value="<?= h($who) ?>" size="12"></label>
  <label>画面 <select name="page"><option value="">すべて</option>
    <?php foreach ($pages as $f => $lab): ?>
      <option value="<?= h($f) ?>"<?= $f === $page ? ' selected' : '' ?>><?= h($lab) ?></option>
    <?php endforeach; ?></select></label>
  <button type="submit">表示</button>
  <a href="?<?= h(http_build_query(['from' => $from, 'to' => $to, 'who' => $who, 'page' => $page, 'csv' => 1])) ?>">CSV出力</a>
</form>

<p><?= number_format($total) ?>件<?= $total > $limit ? "（新しい順に{$limit}件を表示。すべてはCSV出力で）" : '' ?></p>
<table class="report">
  <tr><th>日時</th><th>職員</th><th>部署</th><th>画面</th><th>条件</th><th>送信</th><th>端末</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= h($r['acted_at']) ?></td>
    <td><?= h($r['user_name'] ?? '（登録なし）') ?> <code><?= h($r['user_id']) ?></code></td>
    <td><?= h($r['dept_name'] ?? '') ?></td>
    <td><?= h($pages[$r['page']] ?? $r['page']) ?></td>
    <td class="note"><?= h($r['query']) ?></td>
    <td><?= $r['method'] === 'POST' ? '送信' : '' ?></td>
    <td><?= h($r['client_ip']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<p class="note">
  画面を開くたびに1行残ります（電子カルテから渡された職員IDやパスワードは残しません）。
  保存期間は設定の <code>access_log.keep_days</code>（既定3年）で、それより古い行は自動で消えます。
</p>
<?php page_footer();
