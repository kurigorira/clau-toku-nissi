<?php
/**
 * 職員マスタ保守（管理者のみ）。
 *
 * user_id は電子カルテの職員IDをそのまま使う。入力者の記録はこのIDで残るため、
 * 退職・異動時は削除ではなく「無効」にする（過去の記録の追跡を残すため）。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/repository.php';
require_once __DIR__ . '/../src/users.php';

$user = require_login();
if (!has_role($user, 'admin')) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('職員マスタは管理者のみ利用できます。', 'error');
    page_footer();
    exit;
}

$roles    = user_roles();
$messages = [];
$preview  = null;   // CSVの確認画面に出す内容

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $now    = date('Y-m-d H:i:s');

    // ---- CSVで一括登録（まず確認画面、次に登録） ----------------------------
    // 見出し付きCSV（電子カルテID・氏名・部署・役割）と、電子カルテの職員一覧（1行目が「発行日」）の両方を読む
    if ($action === 'csv_preview') {
        $f = $_FILES['csv'] ?? null;
        unset($_SESSION['csv_emr'], $_SESSION['csv_import']);
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || is_array($f['error'])) {
            $messages[] = ['CSVファイルを選んでください。', 'error'];
        } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 2 * 1024 * 1024) {
            $messages[] = ['CSVを受け取れませんでした（2MBまで）。', 'error'];
        } elseif (($emr = users_read_emr_csv($f['tmp_name'])) !== null) {
            if ($emr['error'] !== null) {
                $messages[] = [$emr['error'], 'error'];
            } else {
                // 電子カルテの職員一覧。部署名の対応表は、保存済みのもの＋初めての部署は候補
                $depts = all_depts(false);
                $saved = emr_dept_map_load();
                $map   = [];
                foreach ($emr['rows'] as [, , , $ed]) {
                    if (!isset($map[$ed])) {
                        $map[$ed] = isset($saved[$ed], $depts[$saved[$ed]]) ? $saved[$ed] : emr_dept_guess($ed, $depts);
                    }
                }
                $_SESSION['csv_emr'] = ['issued' => $emr['issued'], 'rows' => $emr['rows'], 'map' => $map,
                                        'saved' => array_keys($saved), 'deactivate' => !empty($_POST['deactivate_missing'])];
                $preview = csv_build_preview(emr_rows_to_users($emr['rows'], $map), !empty($_POST['deactivate_missing']), $user['user_id']);
            }
        } else {
            $csv = users_read_csv($f['tmp_name']);
            if ($csv['error'] !== null) {
                $messages[] = [$csv['error'], 'error'];
            } else {
                $preview = csv_build_preview($csv['rows'], !empty($_POST['deactivate_missing']), $user['user_id']);
            }
        }
    }

    // 電子カルテの部署名の対応を変えて、確認画面を作り直す（ファイルは選び直さない）
    if ($action === 'csv_remap') {
        $emr = $_SESSION['csv_emr'] ?? null;
        if ($emr === null) {
            $messages[] = ['確認画面の内容が見つかりません。もう一度CSVを選んでください。', 'error'];
        } else {
            $depts = all_depts(false);
            foreach ((array)($_POST['map'] ?? []) as $i => $deptId) {
                $names = array_keys($emr['map']);
                if (isset($names[(int)$i]) && is_string($deptId) && isset($depts[$deptId])) {
                    $emr['map'][$names[(int)$i]] = $deptId;
                }
            }
            $_SESSION['csv_emr'] = $emr;
            $preview = csv_build_preview(emr_rows_to_users($emr['rows'], $emr['map']), $emr['deactivate'], $user['user_id']);
        }
    }

    if ($action === 'csv_commit') {
        $imp = $_SESSION['csv_import'] ?? null;
        if (!$imp || !hash_equals($imp['token'], (string)($_POST['token'] ?? ''))) {
            $messages[] = ['確認画面の内容が見つかりません。もう一度CSVを選んでください。', 'error'];
        } else {
            unset($_SESSION['csv_import'], $_SESSION['csv_emr']);
            $depts = all_depts(false);
            $cnt = ['new' => 0, 'update' => 0, 'same' => 0, 'off' => 0];
            $pdo = db();
            $pdo->beginTransaction();
            try {
                if (!empty($imp['emr_map'])) {
                    emr_dept_map_save($imp['emr_map']);    // 確かめた対応表を先に保存する（次回からこれが出る）
                }
                foreach ($imp['rows'] as $row) {
                    [$row2, $err] = user_validate($row, $depts);   // 確認画面から時間がたっても部署が消えていないか
                    if ($err !== null) {
                        continue;
                    }
                    $kind = user_plan($row2, $depts)['kind'];
                    if ($kind === 'same') {
                        $cnt['same']++;
                        continue;
                    }
                    user_apply($row2);
                    $cnt[$kind]++;
                }
                foreach ($imp['deactivate'] as $id) {
                    if ($id !== $user['user_id']) {
                        $cnt['off'] += db_exec('UPDATE m_user SET is_active = 0, updated_at = ? WHERE user_id = ? AND is_active = 1',
                                               [$now, $id]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            error_log("職員CSV一括登録: 新規{$cnt['new']} 更新{$cnt['update']} 無効化{$cnt['off']} by {$user['user_id']}");
            $messages[] = ["新規 {$cnt['new']}人・更新 {$cnt['update']}人・変更なし {$cnt['same']}人"
                . ($cnt['off'] ? "・無効にした {$cnt['off']}人" : '') . ' を登録しました。', 'ok'];
        }
    }

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

/**
 * CSVの行（[行番号, ['user_id','user_name','dept_id','role']]）から確認画面の内容を作り、
 * 登録に使う行をセッションに置く。
 */
function csv_build_preview(array $rows, bool $deactivate, string $selfId): array
{
    $depts   = all_depts(false);
    $preview = ['new' => [], 'update' => [], 'same' => 0, 'errors' => [], 'deactivate' => []];
    $valid   = [];
    foreach ($rows as [$line, $u]) {
        [$row, $err] = user_validate($u, $depts);
        if ($err === null && isset($valid[$row['user_id']])) {
            $err = "職員ID「{$row['user_id']}」がCSVの中で重なっています（{$valid[$row['user_id']]['line']}行目）";
        }
        if ($err !== null) {
            $preview['errors'][] = [$line, trim((string)($u['user_id'] ?? '')), trim((string)($u['user_name'] ?? '')), $err];
            continue;
        }
        $plan = user_plan($row, $depts);
        $valid[$row['user_id']] = $row + ['line' => $line];
        if ($plan['kind'] === 'same') {
            $preview['same']++;
        } else {
            $preview[$plan['kind']][] = [$row, $plan['changes']];
        }
    }
    if ($deactivate) {
        foreach (db_all('SELECT user_id, user_name FROM m_user WHERE is_active = 1 ORDER BY user_id') as $x) {
            // 自分自身は無効にしない（作業中に入れなくなるため）
            if (!isset($valid[$x['user_id']]) && $x['user_id'] !== $selfId) {
                $preview['deactivate'][] = $x;
            }
        }
    }
    $_SESSION['csv_import'] = [
        'token'      => bin2hex(random_bytes(16)),
        'rows'       => array_values(array_map(fn($r) => array_diff_key($r, ['line' => 1]), $valid)),
        'deactivate' => array_column($preview['deactivate'], 'user_id'),
        'emr_map'    => $_SESSION['csv_emr']['map'] ?? null,
    ];
    return $preview;
}

/** 電子カルテの職員一覧の行を、対応表で部署を置き換えて登録用の行にする。役割は取り込まない。 */
function emr_rows_to_users(array $rows, array $map): array
{
    $out = [];
    foreach ($rows as [$line, $id, $name, $emrDept]) {
        $out[] = [$line, ['user_id' => $id, 'user_name' => $name, 'dept_id' => $map[$emrDept] ?? DEPT_VIEW_ONLY]];
    }
    return $out;
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

<?php if ($preview !== null): ?>
<h2>CSVの確認（まだ登録していません）</h2>
<?php if (!empty($_SESSION['csv_emr'])): $emr = $_SESSION['csv_emr'];
    $cnt = array_count_values(array_map(fn($r) => $r[3], $emr['rows'])); ?>
<p>電子カルテの職員一覧（<?= h($emr['issued']) ?>・<?= count($emr['rows']) ?>人）を読みました。</p>
<h3>電子カルテの部署 → このシステムの部署</h3>
<p class="note">
  電子カルテの部署名を、このシステムのどの部署として扱うかを決めます。<strong>★は今回初めて出た部署名で、名前からの候補です。必ず確かめてください。</strong>
  日誌に関係しない部署は「その他（閲覧のみ）」にします（入力はできず、病院日誌などを見るだけ）。
  決めた対応は「この内容で登録する」で保存され、次回からはそれが出ます。
</p>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="csv_remap">
  <table class="report">
    <tr><th>電子カルテの部署名</th><th>人数</th><th>このシステムの部署</th></tr>
    <?php $i = 0; foreach ($emr['map'] as $ed => $did): ?>
    <tr><td><?= in_array($ed, $emr['saved'], true) ? '' : '★' ?><?= h($ed === '' ? '（空欄）' : $ed) ?></td>
      <td class="n"><?= (int)($cnt[$ed] ?? 0) ?></td>
      <td><select name="map[<?= $i++ ?>]">
        <?php foreach ($depts as $id => $d): ?>
          <option value="<?= h($id) ?>"<?= $id === $did ? ' selected' : '' ?>><?= h($d['dept_name']) ?></option>
        <?php endforeach; ?></select></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="actions"><button type="submit">対応を変えて確認し直す</button></p>
</form>
<?php endif; ?>
<p>
  新規 <strong><?= count($preview['new']) ?></strong>人・
  更新 <strong><?= count($preview['update']) ?></strong>人・
  変更なし <?= (int)$preview['same'] ?>人・
  エラー <strong><?= count($preview['errors']) ?></strong>行
  <?php if ($preview['deactivate']): ?>・無効にする <strong><?= count($preview['deactivate']) ?></strong>人<?php endif; ?>
</p>
<?php if ($preview['errors']): ?>
  <div class="flash flash-error"><strong>次の行は登録しません</strong><ul>
  <?php foreach ($preview['errors'] as [$line, $id, $name, $err]): ?>
    <li><?= (int)$line ?>行目 <?= h($id) ?> <?= h($name) ?>：<?= h($err) ?></li>
  <?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($preview['new'] || $preview['update']): ?>
<table class="report">
  <tr><th>区分</th><th>電子カルテID</th><th>氏名</th><th>部署</th><th>役割</th><th>変わる所</th></tr>
  <?php foreach (['new' => '新規', 'update' => '更新'] as $k => $lab): foreach ($preview[$k] as [$r, $ch]): ?>
    <tr><td><?= h($lab) ?></td><td><code><?= h($r['user_id']) ?></code></td><td><?= h($r['user_name']) ?></td>
      <td><?= h($depts[$r['dept_id']]['dept_name'] ?? $r['dept_id']) ?></td>
      <td><?= h($r['role'] === null ? ($k === 'new' ? '入力者' : '（今のまま）') : $roles[$r['role']]) ?></td>
      <td><?= h(implode('、', $ch)) ?></td></tr>
  <?php endforeach; endforeach; ?>
</table>
<?php endif; ?>
<?php if ($preview['deactivate']): ?>
  <p class="flash flash-warn">CSVに無いため無効にする職員：
    <?= h(implode('、', array_map(fn($x) => "{$x['user_name']}（{$x['user_id']}）", $preview['deactivate']))) ?></p>
<?php endif; ?>
<?php if ($preview['new'] || $preview['update'] || $preview['deactivate']): ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="csv_commit">
  <input type="hidden" name="token" value="<?= h($_SESSION['csv_import']['token'] ?? '') ?>">
  <p class="actions"><button type="submit" class="primary">この内容で登録する</button>
    <a href="admin_user.php">やめる</a></p>
</form>
<?php else: ?>
  <p>登録・更新する職員はありません。</p>
<?php endif; ?>
<hr>
<?php endif; ?>

<h2>CSVで一括登録</h2>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="csv_preview">
  <p>
    <input type="file" name="csv" accept=".csv">
    <label><input type="checkbox" name="deactivate_missing" value="1"> CSVに無い職員を無効にする（退職者の整理）</label>
    <button type="submit">確認する</button>
  </p>
  <p class="note">
    1行目の見出しは <strong>電子カルテID・氏名・部署・役割</strong>（役割は空でよい。空なら新規は入力者、登録済みの人は今のまま）。
    部署は部署名（事務課）でも部署ID（jimu）でもよい。ExcelでCSV（コンマ区切り）として保存したものをそのまま選べます。<br>
    <strong>電子カルテIDの先頭の0に注意</strong>：Excelで数値として扱うと 0108699 が 108699 になります。ID列は「文字列」にしてから入力してください。
    パスワードはCSVでは扱いません（下の一覧の「PW変更」から設定します）。<br>
    <strong>電子カルテから出した職員一覧（syokuinID.csv）もそのまま選べます</strong>（A列＝電子カルテID、E列＝氏名、F列＝部署）。
    <strong>Excelで開いて保存し直さずに</strong>選んでください（Excelで保存すると先頭の0が消えることがあります）。
  </p>
</form>

<h2>登録済みの職員</h2>
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
