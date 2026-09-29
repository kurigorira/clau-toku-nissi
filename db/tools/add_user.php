<?php
/**
 * 職員を登録・更新する（コマンドライン用）。
 *
 * 職員マスタが空の状態では誰もログインできず、管理画面も開けないため、
 * 最初の管理者はこのツールで登録する。
 * 2人目以降は画面（admin_user.php）から登録できるが、
 * 部署ごとにまとめて登録したいときはこちらのほうが早い。
 *
 * 使い方:
 *   php db/tools/add_user.php --id=kurihara --dept=jimu --role=admin --password=＜8文字以上＞ --name=栗原
 *   php db/tools/add_user.php --list
 *   php db/tools/add_user.php --csv=staff.csv        （user_id,user_name,dept_id,role,password の順）
 *
 * role は entry（入力者）/ toutyoku（当直者）/ ijika（医事課）/ admin（管理者）。
 * password を省略すると予備ログインは無効になり、電子カルテからの
 * ID引き継ぎでのみログインできる。
 */

require_once dirname(__DIR__, 2) . '/src/db.php';

require_once dirname(__DIR__, 2) . '/src/cli.php';
require_once dirname(__DIR__, 2) . '/src/users.php';

$ROLES = user_roles();

// 引数の解き方（Shift_JIS・全角スペース・getopt() の問題）は src/cli.php を参照
['opt' => $opt, 'extra' => $extra] = cli_args($argv);

if ($extra) {
    $words = array_map(fn($e) => $e[1], $extra);
    fwrite(STDERR, '  × 余分な引数があります: ' . implode(' ', $words) . "\n");
    // ほとんどは「氏名の空白で分かれた」ケース。直前のオプションとつないだ形を示す
    $key = $extra[0][0];
    if ($key !== null && isset($opt[$key]) && is_string($opt[$key])) {
        fwrite(STDERR, "    値に空白を含めるときは \"\" で囲んでください。\n");
        fwrite(STDERR, "      --{$key}=\"" . $opt[$key] . ' ' . implode(' ', $words) . "\"\n");
    }
    fwrite(STDERR, "    （登録はしていません）\n");
    exit(1);
}

if (isset($opt['help']) || !$opt) {
    fwrite(STDERR, <<<TXT
職員の登録・更新ツール

  php db/tools/add_user.php --id=<職員ID> --name=<氏名> --dept=<部署ID> --role=<役割> [--password=<パスワード>]
  php db/tools/add_user.php --csv=<CSVファイル>     一括登録（user_id,user_name,dept_id,role,password）
  php db/tools/add_user.php --list                  登録済みの一覧
  php db/tools/add_user.php --disable=<職員ID>      無効にする（削除はしない）

役割: entry=入力者 / toutyoku=当直者 / ijika=医事課 / admin=管理者
部署IDは --list で確認できる。

TXT);
    exit(1);
}

$deptRows = all_depts(false);   // 登録用（src/users.php に渡す）
$depts = [];
foreach (db_all('SELECT dept_id, dept_name FROM m_dept ORDER BY sort_no') as $d) {
    $depts[$d['dept_id']] = $d['dept_name'];
}
if (!$depts) {
    fwrite(STDERR, "部署マスタが空です。先に db/seed_master.sql を流してください。\n");
    exit(1);
}

// ---- 一覧 ----------------------------------------------------------------
if (isset($opt['list'])) {
    echo "【部署】\n";
    foreach ($depts as $id => $name) {
        printf("  %-10s %s\n", $id, $name);
    }
    echo "\n【職員】\n";
    $users = db_all('SELECT * FROM m_user ORDER BY is_active DESC, dept_id, user_id');
    if (!$users) {
        echo "  まだ登録されていません。\n";
    }
    foreach ($users as $u) {
        printf("  %-14s %-12s %-14s %-8s %s %s\n",
            $u['user_id'], $u['user_name'], $depts[$u['dept_id']] ?? $u['dept_id'],
            $ROLES[$u['role']] ?? $u['role'],
            $u['is_active'] ? '有効' : '無効',
            $u['password_hash'] ? '予備ログインあり' : '');
    }
    exit(0);
}

// ---- 無効化 --------------------------------------------------------------
if (isset($opt['disable'])) {
    $id = $opt['disable'];
    $n  = db_exec('UPDATE m_user SET is_active = 0, updated_at = ? WHERE user_id = ?',
                  [date('Y-m-d H:i:s'), $id]);
    echo $n ? "{$id} を無効にしました（過去の入力記録は残ります）。\n" : "{$id} は見つかりません。\n";
    exit($n ? 0 : 1);
}

/** 1人ぶん登録・更新する（src/users.php を使う）。結果の1行を返す。 */
function upsert_user(array $u, array $deptRows, array $roles): string
{
    $pw = (string)($u['password'] ?? '');
    [$row, $err] = user_validate($u, $deptRows);
    $id = trim((string)($u['user_id'] ?? ''));
    if ($err !== null) {
        return $id === '' ? "  × {$err}" : "  × {$id}: {$err}";
    }
    if ($pw !== '' && mb_strlen($pw) < 8) {
        return "  × {$row['user_id']}: パスワードは8文字以上にしてください";
    }
    $kind = user_apply($row, $pw === '' ? null : password_hash($pw, PASSWORD_DEFAULT));
    $cur  = db_row('SELECT role FROM m_user WHERE user_id = ?', [$row['user_id']]);
    return $kind === 'new'
        ? "  ○ {$row['user_id']}（{$row['user_name']}／{$deptRows[$row['dept_id']]['dept_name']}／{$roles[$cur['role']]}）を登録しました"
        : "  ○ {$row['user_id']}（{$row['user_name']}）を更新しました";
}

// ---- CSV一括 -------------------------------------------------------------
if (isset($opt['csv'])) {
    $path = $opt['csv'];
    if (!is_file($path)) {
        fwrite(STDERR, "CSVがありません: {$path}\n");
        exit(1);
    }
    // 見出しは 電子カルテID・氏名・部署・役割・パスワード（英字の user_id,user_name,dept_id,role,password も可）。
    // Excelから「CSV(コンマ区切り)」で保存した Shift_JIS も読める（src/users.php）
    $csv = users_read_csv($path);
    if ($csv['error'] !== null) {
        fwrite(STDERR, $csv['error'] . "\n");
        exit(1);
    }
    $ok = $ng = 0;
    foreach ($csv['rows'] as [$line, $row]) {
        $msg = upsert_user($row, $deptRows, $ROLES);
        echo $msg . "\n";
        strpos($msg, '○') !== false ? $ok++ : $ng++;
    }
    echo "\n登録・更新 {$ok}件 / エラー {$ng}件\n";
    exit($ng ? 1 : 0);
}

// ---- 1人ぶん -------------------------------------------------------------
$msg = upsert_user([
    'user_id'   => $opt['id']       ?? '',
    'user_name' => $opt['name']     ?? '',
    'dept_id'   => $opt['dept']     ?? '',
    'role'      => $opt['role']     ?? 'entry',
    'password'  => $opt['password'] ?? '',
], $deptRows, $ROLES);
echo $msg . "\n";
exit(strpos($msg, '○') !== false ? 0 : 1);
