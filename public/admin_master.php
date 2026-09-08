<?php
/**
 * マスタ保守（管理者のみ）。
 *
 * 項目マスタの正本は db/master/items.csv で、通常はそちらを直して
 * build_seed.php で反映する（医事課がExcelでレビューできるようにするため）。
 * この画面は、年度の目標値の登録と、CSVを触らずに済ませたい軽微な変更
 * （表示順・必須・上下限・有効期限）のためのもの。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!has_role($user, 'admin')) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('マスタ保守は管理者のみ利用できます。', 'error');
    page_footer();
    exit;
}

$tab      = $_GET['tab'] ?? 'target';
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_target') {
        $fy = (int)($_POST['fiscal_year'] ?? 0);
        if ($fy < 2000 || $fy > 2100) {
            $messages[] = ['年度の指定が不正です。', 'error'];
        } else {
            $n = 0;
            foreach ($_POST['t'] ?? [] as $code => $val) {
                $val = trim((string)$val);
                if ($val !== '' && !is_numeric($val)) {
                    $messages[] = ["{$code}：数値で入力してください。", 'error'];
                    continue;
                }
                // 空欄なら目標なしとして削除する
                db_exec('DELETE FROM m_target WHERE item_code = ? AND fiscal_year = ?', [$code, $fy]);
                if ($val !== '') {
                    db_exec('INSERT INTO m_target (item_code, fiscal_year, target_avg) VALUES (?,?,?)',
                            [$code, $fy, (float)$val]);
                    $n++;
                }
            }
            $messages[] = ["{$fy}年度の目標値を{$n}件登録しました。", 'ok'];
        }
    }

    if ($action === 'save_dept') {
        foreach ($_POST['d'] ?? [] as $id => $d) {
            $days = preg_replace('/[^1-7]/', '', (string)($d['entry_days'] ?? ''));
            $time = preg_match('/^\d{2}:\d{2}$/', (string)($d['deadline_time'] ?? '')) ? $d['deadline_time'] : '18:00';
            db_exec('UPDATE m_dept SET deadline_time = ?, entry_days = ?, is_active = ? WHERE dept_id = ?',
                    [$time, $days === '' ? '1234567' : $days, empty($d['is_active']) ? 0 : 1, $id]);
        }
        $messages[] = ['部署マスタを更新しました。', 'ok'];
    }

    if ($action === 'save_config') {
        $key  = trim((string)($_POST['config_key'] ?? ''));
        $from = valid_date($_POST['valid_from'] ?? null);
        $val  = trim((string)($_POST['config_value'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($key === '' || $from === null) {
            $messages[] = ['設定キーと適用開始日は必須です。', 'error'];
        } else {
            db_exec('DELETE FROM m_config WHERE config_key = ? AND valid_from = ?', [$key, $from]);
            db_exec('INSERT INTO m_config (config_key, valid_from, config_value, note) VALUES (?,?,?,?)',
                    [$key, $from, $val, $note]);
            $messages[] = ["設定「{$key}」（{$from}〜）を登録しました。", 'ok'];
        }
    }
}

page_header('マスタ保守', $user);
foreach ($messages as [$m, $k]) { flash($m, $k); }
?>
<nav class="tabs noprint">
  <a href="?tab=target" class="<?= $tab === 'target' ? 'active' : '' ?>">年度目標値</a>
  <a href="?tab=dept"   class="<?= $tab === 'dept'   ? 'active' : '' ?>">部署</a>
  <a href="?tab=config" class="<?= $tab === 'config' ? 'active' : '' ?>">設定値（定床など）</a>
  <a href="?tab=item"   class="<?= $tab === 'item'   ? 'active' : '' ?>">項目一覧</a>
</nav>

<?php if ($tab === 'target'):
    $fy = (int)($_GET['fy'] ?? fiscal_year(date('Y-m-d')));
    $cur = [];
    foreach (db_all('SELECT * FROM m_target WHERE fiscal_year = ?', [$fy]) as $r) {
        $cur[$r['item_code']] = $r['target_avg'];
    }
    // 総括表に出る数値項目だけを対象にする
    $targets = array_filter(all_items(), fn($i) => $i['value_type'] === 'int' && $i['dept_id'] !== 'jimu');
?>
  <form method="get" class="datebar">
    <input type="hidden" name="tab" value="target">
    <label>年度 <input type="number" name="fy" value="<?= (int)$fy ?>" min="2000" max="2100" size="6"></label>
    <button type="submit">表示</button>
  </form>
  <p class="note">
    総括表の「目標」列に使う1日平均の目標値です。空欄にすると目標なし（目標差も表示されません）。
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_target">
    <input type="hidden" name="fiscal_year" value="<?= (int)$fy ?>">
    <table class="report">
      <tr><th>部署</th><th>項目</th><th>単位</th><th>1日平均の目標</th></tr>
      <?php $depts = all_depts(false); foreach ($targets as $code => $it): ?>
        <tr>
          <td><?= h($depts[$it['dept_id']]['dept_name'] ?? $it['dept_id']) ?></td>
          <th><?= h($it['item_name']) ?></th>
          <td><?= h($it['unit']) ?></td>
          <td><input type="text" name="t[<?= h($code) ?>]" size="8" class="num"
                     value="<?= h($cur[$code] ?? '') ?>"></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="actions"><button type="submit" class="primary">保存</button></p>
  </form>

<?php elseif ($tab === 'dept'): ?>
  <p class="note">
    入力対象曜日は 1=月 … 7=日 を並べて指定します（例 12345 なら平日のみ）。
    対象外の曜日は入力状況ボードで灰色になり、未提出として数えません。
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_dept">
    <table class="report">
      <tr><th>部署ID</th><th>部署名</th><th>入力期限</th><th>入力対象曜日</th><th>有効</th><th>項目数</th></tr>
      <?php
      $counts = [];
      foreach (db_all("SELECT dept_id, COUNT(*) c FROM m_item WHERE calc_type = 'input' GROUP BY dept_id") as $r) {
          $counts[$r['dept_id']] = $r['c'];
      }
      foreach (all_depts(false) as $id => $d): ?>
        <tr>
          <td><code><?= h($id) ?></code></td>
          <th><?= h($d['dept_name']) ?></th>
          <td><input type="text" name="d[<?= h($id) ?>][deadline_time]" size="5" value="<?= h($d['deadline_time']) ?>"></td>
          <td><input type="text" name="d[<?= h($id) ?>][entry_days]" size="8" value="<?= h($d['entry_days']) ?>"></td>
          <td><input type="checkbox" name="d[<?= h($id) ?>][is_active]" value="1" <?= $d['is_active'] ? 'checked' : '' ?>></td>
          <td class="n"><?= (int)($counts[$id] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="actions"><button type="submit" class="primary">保存</button></p>
  </form>

<?php elseif ($tab === 'config'): ?>
  <p class="note">
    定床のように途中で変わる値は、古い行を消さずに新しい適用開始日で追加します。
    過去の帳票は当時の値で再現されます（2025年6月の病床再編がこの形で入っています）。
  </p>
  <table class="report">
    <tr><th>設定キー</th><th>適用開始日</th><th>値</th><th>備考</th></tr>
    <?php foreach (db_all('SELECT * FROM m_config ORDER BY config_key, valid_from') as $r): ?>
      <tr><td><code><?= h($r['config_key']) ?></code></td><td><?= h($r['valid_from']) ?></td>
          <td class="n"><?= h($r['config_value']) ?></td><td><?= h($r['note']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <h2>追加・上書き</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_config">
    <table class="report">
      <tr><th>設定キー</th><td><input type="text" name="config_key" size="24" required></td></tr>
      <tr><th>適用開始日</th><td><input type="date" name="valid_from" value="<?= h(date('Y-m-d')) ?>" required></td></tr>
      <tr><th>値</th><td><input type="text" name="config_value" size="24"></td></tr>
      <tr><th>備考</th><td><input type="text" name="note" size="48"></td></tr>
    </table>
    <p class="actions"><button type="submit" class="primary">登録</button></p>
  </form>

<?php else:
    $depts = all_depts(false);
    $label = ['input' => '入力', 'sum' => '合算', 'func' => '計算'];
?>
  <p class="note">
    項目マスタの正本は <code>db/master/items.csv</code> です。項目の追加・変更はCSVを直し、
    <code>php db/tools/build_seed.php</code> で生成したSQLを流してください。この画面は確認用です。
  </p>
  <div class="board-scroll">
  <table class="report">
    <tr><th>項目コード</th><th>項目名</th><th>部署</th><th>区分</th><th>単位</th><th>必須</th>
        <th>期間集計</th><th>旧列名</th><th>Excel</th><th>算出元</th></tr>
    <?php foreach (all_items() as $code => $i): ?>
      <tr>
        <td><code><?= h($code) ?></code></td>
        <th><?= h($i['item_name']) ?></th>
        <td><?= h($depts[$i['dept_id']]['dept_name'] ?? $i['dept_id']) ?></td>
        <td><?= h($label[$i['calc_type']] ?? $i['calc_type']) ?></td>
        <td><?= h($i['unit']) ?></td>
        <td><?= (int)$i['required'] === 1 ? '○' : '' ?></td>
        <td><?= $i['agg_type'] === 'last' ? '最終日' : '合算' ?></td>
        <td><?= h($i['legacy_column']) ?></td>
        <td><?= h($i['excel_ref']) ?></td>
        <td class="note"><?= h($i['calc_source']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
<?php endif;
page_footer();
