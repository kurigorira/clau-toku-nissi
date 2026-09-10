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
 *   php db/tools/add_user.php --id=kurihara --name=栗原 --dept=jimu --role=admin --password=＜8文字以上＞
 *   php db/tools/add_user.php --list
 *   php db/tools/add_user.php --csv=staff.csv        （user_id,user_name,dept_id,role,password の順）
 *
 * role は entry（入力者）/ toutyoku（当直者）/ ijika（医事課）/ admin（管理者）。
 * password を省略すると予備ログインは無効になり、電子カルテからの
 * ID引き継ぎでのみログインできる。
 */

require_once dirname(__DIR__, 2) . '/src/db.php';

$ROLES = ['entry' => '入力者', 'toutyoku' => '当直者', 'ijika' => '医事課', 'admin' => '管理者'];

/**
 * コマンドラインから渡された文字列をUTF-8に揃える。
 *
 * Windowsのコマンドプロンプトは既定の文字コードがShift_JIS（CP932）なので、
 * --name=栗原 のように日本語を渡すとSJISのバイト列のまま届く。
 * そのままUTF-8のデータベースに入れると文字化けするため、ここで変換する。
 * すでにUTF-8として妥当なら何もしない（chcp 65001 済みの場合など）。
 */
function to_utf8(string $s): string
{
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    return mb_convert_encoding($s, 'UTF-8', 'SJIS-win');
}

$opt = getopt('', ['id:', 'name:', 'dept:', 'role:', 'password:', 'csv:', 'list', 'disable:', 'help']);
foreach (['name', 'dept', 'role', 'id'] as $k) {
    if (isset($opt[$k]) && is_string($opt[$k])) {
        $opt[$k] = to_utf8($opt[$k]);
    }
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

/** 1人ぶん登録・更新する。問題があればメッセージを返す。 */
function upsert_user(array $u, array $depts, array $roles): string
{
    $id   = trim((string)($u['user_id'] ?? ''));
    $name = trim((string)($u['user_name'] ?? ''));
    $dept = trim((string)($u['dept_id'] ?? ''));
    $role = trim((string)($u['role'] ?? 'entry'));
    $pw   = (string)($u['password'] ?? '');

    if ($id === '' || $name === '') {
        return "  × 職員IDと氏名は必須です（{$id}）";
    }
    if (!preg_match('/^[\w.\-]{1,32}$/', $id)) {
        return "  × {$id}: 職員IDは半角英数字・ハイフン・ピリオド・アンダースコアで32文字までです";
    }
    if (!isset($depts[$dept])) {
        return "  × {$id}: 部署ID '{$dept}' が存在しません";
    }
    if (!isset($roles[$role])) {
        return "  × {$id}: 役割 '{$role}' は " . implode(' / ', array_keys($roles)) . " のいずれかです";
    }
    if ($pw !== '' && mb_strlen($pw) < 8) {
        return "  × {$id}: パスワードは8文字以上にしてください";
    }

    $now  = date('Y-m-d H:i:s');
    $hash = $pw === '' ? null : password_hash($pw, PASSWORD_DEFAULT);
    $cur  = db_row('SELECT user_id FROM m_user WHERE user_id = ?', [$id]);

    if ($cur) {
        // パスワード未指定なら既存のものを変えない
        if ($hash === null) {
            db_exec('UPDATE m_user SET user_name = ?, dept_id = ?, role = ?, is_active = 1, updated_at = ?
                      WHERE user_id = ?', [$name, $dept, $role, $now, $id]);
        } else {
            db_exec('UPDATE m_user SET user_name = ?, dept_id = ?, role = ?, password_hash = ?, is_active = 1, updated_at = ?
                      WHERE user_id = ?', [$name, $dept, $role, $hash, $now, $id]);
        }
        return "  ○ {$id}（{$name}）を更新しました";
    }

    db_exec('INSERT INTO m_user (user_id,user_name,dept_id,role,password_hash,is_active,created_at,updated_at)
             VALUES (?,?,?,?,?,1,?,?)', [$id, $name, $dept, $role, $hash, $now, $now]);
    return "  ○ {$id}（{$name}／{$depts[$dept]}／{$roles[$role]}）を登録しました";
}

// ---- CSV一括 -------------------------------------------------------------
if (isset($opt['csv'])) {
    $path = $opt['csv'];
    if (!is_file($path)) {
        fwrite(STDERR, "CSVがありません: {$path}\n");
        exit(1);
    }
    $fp   = fopen($path, 'r');
    $head = fgetcsv($fp);
    if ($head && isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);   // ExcelのBOM
    }
    $ok = $ng = 0;
    while (($r = fgetcsv($fp)) !== false) {
        if (count($r) === 1 && trim((string)$r[0]) === '') {
            continue;
        }
        $row = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
        // Excelから「CSV(コンマ区切り)」で保存するとSJISになるため、ここで揃える
        $row = array_map('to_utf8', $row);
        $msg = upsert_user($row, $depts, $ROLES);
        echo $msg . "\n";
        strpos($msg, '○') !== false ? $ok++ : $ng++;
    }
    fclose($fp);
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
], $depts, $ROLES);
echo $msg . "\n";
exit(strpos($msg, '○') !== false ? 0 : 1);
