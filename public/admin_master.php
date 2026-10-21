<?php
/**
 * マスタ保守（管理者のみ）。
 *
 * 項目マスタの正本は db/master/items.csv で、通常はそちらを直して
 * build_seed.php で反映する（医事課がExcelでレビューできるようにするため）。
 * この画面は、年度の目標値の登録（事業計画の Excel からの月別の目標の取り込みを含む）と、CSVを触らずに済ませたい軽微な変更
 * （表示順・必須・上下限・有効期限）のためのもの。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';
require_once __DIR__ . '/../src/target_import.php';

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
$preview  = null;     // 事業計画の Excel を読んだ結果（確認画面）

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // 事業計画（月別の目標）の Excel を読む → 確認画面。登録は次の「この内容で登録」で
    if ($action === 'plan_preview') {
        $f  = $_FILES['plan_xlsx'] ?? null;
        $fy = (int)($_POST['plan_fy'] ?? 0);
        unset($_SESSION['plan_import']);
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $messages[] = ['事業計画の Excel を選んでください。', 'error'];
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $messages[] = ['ファイルを受け取れませんでした（コード ' . (int)$f['error'] . '）。もう一度選んでください。', 'error'];
        } else {
            try {
                $preview = target_parse(xlsx_read($f['tmp_name']), $fy >= 2000 && $fy <= 2100 ? $fy : null);
                $preview['file'] = (string)$f['name'];
                $_SESSION['plan_import'] = ['fy' => $preview['fy'],
                    'items' => array_map(fn($x) => $x['months'], $preview['items'])];
            } catch (RuntimeException $e) {
                $messages[] = [(string)$f['name'] . '：' . $e->getMessage(), 'error'];
            }
        }
    }
    if ($action === 'plan_commit') {
        $imp = $_SESSION['plan_import'] ?? null;
        unset($_SESSION['plan_import']);
        if (!is_array($imp)) {
            $messages[] = ['取り込む内容がありません。もう一度 Excel を選んでください。', 'error'];
        } else {
            try {
                $n = target_save((int)$imp['fy'], $imp['items'], $user['user_id']);
                $messages[] = ["{$imp['fy']}年度の月別の目標を {$n} 項目登録しました。総括の目標・達成率に使われます。", 'ok'];
            } catch (Throwable $e) {
                error_log('事業計画の取り込み：' . $e->getMessage());
                $messages[] = ['登録できませんでした。月別の目標のテーブルが無い場合は db/migrations/004_target_month.sql を流してください。', 'error'];
            }
        }
    }

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
    $targets = array_filter(all_items(), fn($i) => $i['value_type'] === 'int');
?>
  <form method="get" class="datebar">
    <input type="hidden" name="tab" value="target">
    <label>年度 <input type="number" name="fy" value="<?= (int)$fy ?>" min="2000" max="2100" size="6"></label>
    <button type="submit">表示</button>
  </form>
  <?php if ($preview !== null): $items = all_items(); ?>
    <h2>事業計画の取り込み：確認（<?= (int)$preview['fy'] ?>年度・シート「<?= h($preview['sheet']) ?>」）</h2>
    <p class="note"><?= h($preview['file']) ?> の「延数」の行を、月ごとの目標延数として登録します。
      登録すると、その年度の月別の目標を項目ごとに入れ替え、下の「1日平均の目標」も年間合計÷年度の日数に更新します。</p>
    <div class="table-scroll">
    <table class="report">
      <thead><tr><th>項目</th><th>Excel の行</th>
        <?php foreach ([4,5,6,7,8,9,10,11,12,1,2,3] as $mo): ?><th><?= $mo ?>月</th><?php endforeach; ?><th>年間</th></tr></thead>
      <tbody>
      <?php foreach ($preview['items'] as $code => $x): ?>
        <tr>
          <th><?= h($items[$code]['item_name'] ?? $code) ?><?= isset($items[$code]) ? '' : '（項目なし・登録しない）' ?></th>
          <td><?= h(implode('＋', $x['from'])) ?></td>
          <?php foreach ([4,5,6,7,8,9,10,11,12,1,2,3] as $mo):
              $ym = sprintf('%04d-%02d', $mo >= 4 ? $preview['fy'] : $preview['fy'] + 1, $mo); ?>
            <td class="n"><?= isset($x['months'][$ym]) ? h(number_format($x['months'][$ym], 1)) : '' ?></td>
          <?php endforeach; ?>
          <td class="n"><?= h(number_format(array_sum($x['months']), 1)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if ($preview['notfound']): ?>
      <p class="flash flash-warn">対応表（db/master/target_map.csv）にあるのに、Excel に見つからない見出し：<?= h(implode('、', $preview['notfound'])) ?></p>
    <?php endif; ?>
    <?php if ($preview['skipped']): ?>
      <p class="note">取り込まない行（率・人数・合計など、システムの項目に当たらないもの）：
        <?= h(implode('、', array_map(fn($k) => "{$k['label']}（{$k['row']}行目）", $preview['skipped']))) ?></p>
    <?php endif; ?>
    <form method="post" class="actions">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="plan_commit">
      <button type="submit" class="primary">この内容で登録</button>
      <a href="?tab=target&amp;fy=<?= (int)$preview['fy'] ?>">やめる</a>
    </form>
    <hr>
  <?php endif; ?>

  <h2>事業計画（月別の目標）の取り込み</h2>
  <form method="post" enctype="multipart/form-data" class="datebar">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="plan_preview">
    <label>Excel（.xlsx / .xlsm） <input type="file" name="plan_xlsx" accept=".xlsx,.xlsm"></label>
    <label>年度 <select name="plan_fy"><option value="">Excel から読む</option>
      <?php for ($y = $fy - 1; $y <= $fy + 1; $y++): ?><option value="<?= $y ?>"><?= $y ?>年度</option><?php endfor; ?></select></label>
    <button type="submit">読み込む（確認画面へ）</button>
  </form>
  <p class="note">「4月」〜「3月」の見出しが並ぶ事業計画指標値のシートを読みます。どの行をどの項目にするかは
    <code>db/master/target_map.csv</code> で決めています。総括の目標は、その月の目標÷月の日数×期間の日数で計算します。</p>

  <?php
  // 登録済みの月別の目標（確認用）
  $monthly = [];
  try {
      foreach (db_all('SELECT item_code, ym, target_total FROM m_target_month WHERE ym BETWEEN ? AND ? ORDER BY item_code',
                      [sprintf('%04d-04', $fy), sprintf('%04d-03', $fy + 1)]) as $r) {
          $monthly[$r['item_code']][$r['ym']] = (float)$r['target_total'];
      }
  } catch (Throwable $e) {
      echo '<p class="flash flash-warn">月別の目標のテーブルがありません。db/migrations/004_target_month.sql を流してください。</p>';
  }
  if ($monthly): $items = all_items(); ?>
    <h2><?= (int)$fy ?>年度 月別の目標（登録済み・延数）</h2>
    <div class="table-scroll">
    <table class="report">
      <thead><tr><th>項目</th><?php foreach ([4,5,6,7,8,9,10,11,12,1,2,3] as $mo): ?><th><?= $mo ?>月</th><?php endforeach; ?><th>年間</th></tr></thead>
      <tbody>
      <?php foreach ($items as $code => $it): if (!isset($monthly[$code])) { continue; } ?>
        <tr><th><?= h($it['item_name']) ?></th>
          <?php foreach ([4,5,6,7,8,9,10,11,12,1,2,3] as $mo):
              $ym = sprintf('%04d-%02d', $mo >= 4 ? $fy : $fy + 1, $mo); ?>
            <td class="n"><?= isset($monthly[$code][$ym]) ? h(number_format($monthly[$code][$ym], 1)) : '' ?></td>
          <?php endforeach; ?>
          <td class="n"><?= h(number_format(array_sum($monthly[$code]), 1)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <h2>1日平均の目標</h2>
  <p class="note">
    総括表の「目標」列に使う1日平均の目標値です（月別の目標がある月はそちらを使い、無い月はこの値を使います）。
    空欄にすると目標なし（目標差も表示されません）。
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
