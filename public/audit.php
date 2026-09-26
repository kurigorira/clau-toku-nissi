<?php
/**
 * 変更履歴の閲覧。
 *
 * 「いつ・誰が・どの数字を・何から何に変えたか」を追える。
 * 医事課が確定したあとの訂正を追跡するために使う。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('変更履歴は医事課と管理者のみ閲覧できます。', 'error');
    page_footer();
    exit;
}

$from   = valid_date($_GET['from'] ?? null) ?? date('Y-m-01', strtotime('-1 month'));
$to     = valid_date($_GET['to'] ?? null) ?? date('Y-m-t', strtotime($from));
$deptId = (string)($_GET['dept'] ?? '');
$onlyUp = !empty($_GET['only_update']);   // 新規登録を除き、訂正だけを見る

$where  = ['a.hizuke BETWEEN ? AND ?'];
$params = [$from, $to];
if ($deptId !== '' && isset(all_depts(false)[$deptId])) {
    $where[]  = 'i.dept_id = ?';
    $params[] = $deptId;
}
if ($onlyUp) {
    $where[] = "a.action = 'update'";
}

$limit = 500;
$rows  = db_all(
    'SELECT a.*, i.item_name, i.dept_id, i.unit, u.user_name
       FROM d_audit a
       LEFT JOIN m_item i ON i.item_code = a.item_code
       LEFT JOIN m_user u ON u.user_id   = a.acted_by
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY a.acted_at DESC, a.audit_id DESC
      LIMIT ' . $limit,
    $params
);
$total = (int)db_row(
    'SELECT COUNT(*) c FROM d_audit a LEFT JOIN m_item i ON i.item_code = a.item_code
      WHERE ' . implode(' AND ', $where), $params)['c'];

$depts  = all_depts(false);
$labels = ['insert' => '新規', 'update' => '訂正', 'delete' => '削除'];

page_header('変更履歴', $user);
?>
<form method="get" class="datebar">
  <label>期間 <input type="date" name="from" value="<?= h($from) ?>"></label>
  〜 <input type="date" name="to" value="<?= h($to) ?>">
  <label>部署
    <select name="dept">
      <option value="">すべて</option>
      <?php foreach ($depts as $id => $d): ?>
        <option value="<?= h($id) ?>"<?= $id === $deptId ? ' selected' : '' ?>><?= h($d['dept_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><input type="checkbox" name="only_update" value="1" <?= $onlyUp ? 'checked' : '' ?>> 訂正のみ</label>
  <button type="submit">表示</button>
  <a href="export.php?type=audit&amp;month=<?= h(substr($from, 0, 7)) ?>">CSV出力</a>
</form>

<p class="note">
  該当 <?= number_format($total) ?> 件<?= $total > $limit ? "（新しい順に {$limit} 件まで表示）" : '' ?>。
  取り込みや一括登録は実行者が <code>IMPORT_*</code> になります。
</p>

<div class="board-scroll">
<table class="report">
  <tr><th>実行日時</th><th>実行者</th><th>対象日</th><th>部署</th><th>項目</th><th>操作</th>
      <th>変更前</th><th>変更後</th><th>IP</th></tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['acted_at']) ?></td>
      <td><?= h($r['user_name'] ?? $r['acted_by']) ?></td>
      <td><?= h($r['hizuke']) ?></td>
      <td><?= h($depts[$r['dept_id']]['dept_name'] ?? '') ?></td>
      <th><?= h($r['item_name'] ?? $r['item_code']) ?></th>
      <td><?= h($labels[$r['action']] ?? $r['action']) ?></td>
      <td class="n"><?= $r['old_value'] === null ? '—' : h($r['old_value']) ?></td>
      <td class="n"><?= $r['new_value'] === null ? '—' : h($r['new_value']) ?></td>
      <td><?= h($r['client_ip']) ?></td>
    </tr>
  <?php endforeach; ?>
</table>
</div>
<?php page_footer();
