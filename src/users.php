<?php
/**
 * 職員マスタの登録・更新。コマンド（db/tools/add_user.php）と画面（public/admin_user.php）で共有する。
 *
 * 職員ID（user_id）は電子カルテの職員IDをそのまま使う。
 * 電子カルテから開いたとき、ここに登録されたIDと一文字でも違えば入れないので、
 * 先頭の0は消さない（「0108699」と「108699」は別のID）。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/cli.php';

/** 役割。 */
function user_roles(): array
{
    return ['entry' => '入力者', 'toutyoku' => '当直者', 'ijika' => '医事課', 'admin' => '管理者'];
}

/** 見出しや部署名を比べるために揃える（空白を除き、全角英数字を半角に）。 */
function user_norm(string $s): string
{
    return preg_replace('/\s+/u', '', mb_convert_kana($s, 'as', 'UTF-8'));
}

/**
 * 1人ぶんを確かめて揃える。
 *
 * 部署は部署ID（jimu）でも部署名（事務課）でもよい。役割も英字（ijika）でも日本語（医事課）でもよい。
 * 役割が空なら null（新規なら入力者、既存なら今の役割のまま）。
 *
 * @return array [揃えた行 ['user_id','user_name','dept_id','role'|null], エラー文|null]
 */
function user_validate(array $u, array $depts): array
{
    $id   = trim(mb_convert_kana((string)($u['user_id'] ?? ''), 'as', 'UTF-8'));
    $name = trim(preg_replace('/\s+/u', ' ', (string)($u['user_name'] ?? '')));
    $dept = trim((string)($u['dept_id'] ?? ''));
    $role = trim((string)($u['role'] ?? ''));

    if ($id === '' || $name === '') {
        return [null, '職員IDと氏名は必須です'];
    }
    if (!preg_match('/^[\w.\-]{1,32}$/', $id)) {
        return [null, "職員ID「{$id}」は半角英数字・ハイフン・ピリオド・アンダースコアで32文字までです"];
    }
    $deptId = null;
    if (isset($depts[$dept])) {
        $deptId = $dept;
    } else {
        foreach ($depts as $did => $d) {
            if (user_norm($d['dept_name']) === user_norm($dept) || user_norm($did) === user_norm($dept)) {
                $deptId = $did;
                break;
            }
        }
    }
    if ($deptId === null) {
        return [null, "部署ID '{$dept}' が存在しません"];
    }
    $roleCode = null;
    if ($role !== '') {
        foreach (user_roles() as $code => $label) {
            if ($role === $code || user_norm($role) === user_norm($label)) {
                $roleCode = $code;
            }
        }
        if ($roleCode === null) {
            return [null, "役割 '{$role}' は " . implode(' / ', user_roles()) . ' のいずれかです'];
        }
    }
    return [['user_id' => $id, 'user_name' => $name, 'dept_id' => $deptId, 'role' => $roleCode], null];
}

/**
 * 登録すると何が起きるかを返す（まだ書かない）。
 * @return array ['kind' => 'new'|'update'|'same', 'changes' => ['部署: 外来 → 病棟', ...]]
 */
function user_plan(array $row, array $depts): array
{
    $cur = db_row('SELECT * FROM m_user WHERE user_id = ?', [$row['user_id']]);
    if ($cur === null) {
        return ['kind' => 'new', 'changes' => []];
    }
    $ch = [];
    if ($cur['user_name'] !== $row['user_name']) {
        $ch[] = "氏名: {$cur['user_name']} → {$row['user_name']}";
    }
    if ($cur['dept_id'] !== $row['dept_id']) {
        $ch[] = '部署: ' . ($depts[$cur['dept_id']]['dept_name'] ?? $cur['dept_id']) . ' → ' . $depts[$row['dept_id']]['dept_name'];
    }
    if ($row['role'] !== null && $cur['role'] !== $row['role']) {
        $ch[] = '役割: ' . (user_roles()[$cur['role']] ?? $cur['role']) . ' → ' . user_roles()[$row['role']];
    }
    if ((int)$cur['is_active'] !== 1) {
        $ch[] = '無効 → 有効';
    }
    return ['kind' => $ch ? 'update' : 'same', 'changes' => $ch];
}

/**
 * 登録・更新する。パスワードは $pwHash を渡したときだけ変える（null なら今のまま）。
 * 役割が null なら、新規は入力者、既存は今の役割のまま（CSVで役割を空にしても医事課が入力者に下がらないように）。
 */
function user_apply(array $row, ?string $pwHash = null): string
{
    $now = date('Y-m-d H:i:s');
    $cur = db_row('SELECT * FROM m_user WHERE user_id = ?', [$row['user_id']]);
    if ($cur === null) {
        db_exec('INSERT INTO m_user (user_id,user_name,dept_id,role,password_hash,is_active,created_at,updated_at)
                 VALUES (?,?,?,?,?,1,?,?)',
                [$row['user_id'], $row['user_name'], $row['dept_id'], $row['role'] ?? 'entry', $pwHash, $now, $now]);
        return 'new';
    }
    db_exec('UPDATE m_user SET user_name = ?, dept_id = ?, role = ?, is_active = 1, updated_at = ?'
            . ($pwHash !== null ? ', password_hash = ?' : '') . ' WHERE user_id = ?',
            array_merge([$row['user_name'], $row['dept_id'], $row['role'] ?? $cur['role'], $now],
                        $pwHash !== null ? [$pwHash] : [], [$row['user_id']]));
    return 'update';
}

/**
 * 職員のCSVを読む。ExcelのCSV（Shift_JIS）もUTF-8も読む。
 *
 * 1行目は見出し。日本語でも英字でもよい:
 *   電子カルテID（職員ID・ID・user_id） / 氏名（user_name） / 部署（部署名・部署ID・dept_id） / 役割（role・任意）
 *
 * @return array ['rows' => [[行番号, ['user_id'=>…, 'user_name'=>…, 'dept_id'=>…, 'role'=>…]], ...], 'error' => 文|null]
 */
function users_read_csv(string $path): array
{
    $text = (string)file_get_contents($path);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);     // ExcelのBOM
    $text = cli_to_utf8($text);
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, $text);
    rewind($fp);

    $alias = [
        'user_id'   => ['電子カルテid', '電子カルテ職員id', '職員id', '職員番号', 'id', 'user_id'],
        'user_name' => ['氏名', '名前', '職員名', 'user_name', 'name'],
        'dept_id'   => ['部署', '部署名', '部署id', '所属', 'dept_id', 'dept'],
        'role'      => ['役割', '権限', 'role'],
        'password'  => ['password', 'パスワード'],   // コマンドの一括登録だけが使う。画面では読まない
    ];
    $head = fgetcsv($fp);
    if (!$head) {
        return ['rows' => [], 'error' => 'CSVが空です。'];
    }
    $col = [];
    foreach ($head as $i => $h) {
        $n = mb_strtolower(user_norm((string)$h));
        foreach ($alias as $key => $names) {
            if (in_array($n, $names, true) && !isset($col[$key])) {
                $col[$key] = $i;
            }
        }
    }
    foreach (['user_id' => '電子カルテID', 'user_name' => '氏名', 'dept_id' => '部署'] as $key => $label) {
        if (!isset($col[$key])) {
            return ['rows' => [], 'error' => "1行目の見出しに「{$label}」の列がありません（見出しは 電子カルテID・氏名・部署・役割）。"];
        }
    }
    $rows = [];
    $line = 1;
    while (($r = fgetcsv($fp)) !== false) {
        $line++;
        if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) {
            continue;   // 空行
        }
        $u = [];
        foreach ($col as $key => $i) {
            $u[$key] = (string)($r[$i] ?? '');
        }
        $rows[] = [$line, $u];
    }
    fclose($fp);
    if (!$rows) {
        return ['rows' => [], 'error' => 'CSVに職員の行がありません。'];
    }
    return ['rows' => $rows, 'error' => null];
}
