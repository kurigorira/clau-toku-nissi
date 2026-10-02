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

/**
 * 職員マスタに「電子カルテの部署名」の列（emr_dept）があるか。
 * 後から足した列なので、db/migrations/003_user_emr_dept.sql を流す前のサーバでも動くようにする。
 */
function users_has_emr_dept(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            db_row('SELECT emr_dept FROM m_user WHERE 1 = 0');
            $has = true;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** 画面に出す部署名。電子カルテの部署名があればそれ、無ければこのシステムの部署名。 */
function user_dept_label(array $u): string
{
    return trim((string)($u['emr_dept'] ?? '')) !== '' ? (string)$u['emr_dept'] : (string)($u['dept_name'] ?? $u['dept_id'] ?? '');
}

/** 役割。 */
function user_roles(): array
{
    return ['entry' => '入力者', 'toutyoku' => '当直者', 'ijika' => '医事課', 'admin' => '管理者'];
}

/** 見出しや部署名を比べるために揃える（空白を除き、全角英数字を半角に、半角カナを全角に）。 */
function user_norm(string $s): string
{
    return preg_replace('/\s+/u', '', mb_convert_kana($s, 'asKV', 'UTF-8'));
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
    $row = ['user_id' => $id, 'user_name' => $name, 'dept_id' => $deptId, 'role' => $roleCode];
    if (array_key_exists('emr_dept', $u)) {
        $row['emr_dept'] = mb_substr(trim((string)$u['emr_dept']), 0, 64);   // 電子カルテの部署名（表示用）
    }
    return [$row, null];
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
    if (isset($row['emr_dept']) && users_has_emr_dept() && (string)($cur['emr_dept'] ?? '') !== $row['emr_dept']) {
        $ch[] = '電子カルテの部署: ' . (($cur['emr_dept'] ?? '') === '' ? '（なし）' : $cur['emr_dept']) . ' → ' . $row['emr_dept'];
    }
    if ($cur['dept_id'] !== $row['dept_id']) {
        $ch[] = '入力する画面: ' . ($depts[$cur['dept_id']]['dept_name'] ?? $cur['dept_id']) . ' → ' . $depts[$row['dept_id']]['dept_name'];
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
    // 電子カルテの部署名は、列があって、行に入っているときだけ書く
    $emr = isset($row['emr_dept']) && users_has_emr_dept() ? $row['emr_dept'] : null;
    if ($cur === null) {
        db_exec('INSERT INTO m_user (user_id,user_name,dept_id,role,password_hash,is_active,created_at,updated_at)
                 VALUES (?,?,?,?,?,1,?,?)',
                [$row['user_id'], $row['user_name'], $row['dept_id'], $row['role'] ?? 'entry', $pwHash, $now, $now]);
        if ($emr !== null) {
            db_exec('UPDATE m_user SET emr_dept = ? WHERE user_id = ?', [$emr, $row['user_id']]);
        }
        return 'new';
    }
    db_exec('UPDATE m_user SET user_name = ?, dept_id = ?, role = ?, is_active = 1, updated_at = ?'
            . ($pwHash !== null ? ', password_hash = ?' : '') . ' WHERE user_id = ?',
            array_merge([$row['user_name'], $row['dept_id'], $row['role'] ?? $cur['role'], $now],
                        $pwHash !== null ? [$pwHash] : [], [$row['user_id']]));
    if ($emr !== null) {
        db_exec('UPDATE m_user SET emr_dept = ? WHERE user_id = ?', [$emr, $row['user_id']]);
    }
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

/* ======================================================================
 * 電子カルテの職員一覧（syokuinID.csv）
 *
 * 電子カルテから出した職員一覧は見出しの無いCSVで、1行目が「発行日 令和 8年 9月29日」。
 *   A列 … 電子カルテID（電子カルテからこのアプリを開くときに渡される番号）
 *   E列 … 漢字の氏名
 *   F列 … 電子カルテの部署名（新4階病棟・薬剤部・総務課 …）
 * ほかの列（番号・カナ・区分・役職・職種・資格・診療科）は使わない。
 *
 * 電子カルテの部署名はこのアプリの部署と一致しないので、対応表（m_config の emr_dept:部署名）で
 * このアプリの部署に置き換える。対応表は職員画面で確かめて保存する。
 * ====================================================================== */

/** 閲覧だけの職員の部署（depts.csv の is_active=0 の部署）。 */
const DEPT_VIEW_ONLY = 'sonota';

/**
 * 電子カルテの職員一覧なら読む。そうでなければ null（見出し付きCSVとして読む）。
 *
 * @return array|null ['issued' => '発行日 …', 'rows' => [[行番号, ID, 氏名, 電子カルテの部署名], ...], 'error' => 文|null]
 */
function users_read_emr_csv(string $path): ?array
{
    $text = (string)file_get_contents($path);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $text = cli_to_utf8($text);
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, $text);
    rewind($fp);

    $first = fgetcsv($fp);
    if (!$first || mb_strpos(user_norm((string)($first[0] ?? '')), '発行日') !== 0) {
        fclose($fp);
        return null;
    }
    $issued = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($first, fn($x) => trim((string)$x) !== ''))));
    $rows = [];
    $line = 1;
    while (($r = fgetcsv($fp)) !== false) {
        $line++;
        $id = trim(mb_convert_kana((string)($r[0] ?? ''), 'as', 'UTF-8'));
        if (!preg_match('/^\d+$/', $id)) {
            continue;   // 空行や、番号でない行（途中の見出しなど）は読まない
        }
        $name = trim(preg_replace('/[\s\x{3000}]+/u', ' ', (string)($r[4] ?? '')));
        $dept = trim(preg_replace('/[\s\x{3000}]+/u', '', (string)($r[5] ?? '')));
        $rows[] = [$line, $id, $name, $dept];
    }
    fclose($fp);
    if (!$rows) {
        return ['issued' => $issued, 'rows' => [], 'error' => '電子カルテの職員一覧に職員の行がありません。'];
    }
    return ['issued' => $issued, 'rows' => $rows, 'error' => null];
}

/**
 * 見出し付きCSV（users_read_csv の結果）の部署の列が、ほとんどこのアプリに無い部署名（電子カルテの部署名）なら、
 * 電子カルテの職員一覧として扱う（Excelで見出しを付けて保存し直したファイルなど）。
 * そうでなければ null（今までどおりの見出し付きCSV）。
 *
 * @return array|null users_read_emr_csv() と同じ形。rows の5番目は行の役割（空なら部署ごとの役割）。'headed' => true
 */
function users_csv_as_emr(array $csvRows, array $depts): ?array
{
    $known = [];
    foreach ($depts as $id => $d) {
        $known[user_norm((string)$id)] = true;
        $known[user_norm((string)$d['dept_name'])] = true;
    }
    $filled = $unknown = 0;
    $rows = [];
    foreach ($csvRows as [$line, $u]) {
        $dept = trim(preg_replace('/[\s\x{3000}]+/u', '', (string)($u['dept_id'] ?? '')));
        if ($dept !== '') {
            $filled++;
            if (!isset($known[user_norm($dept)])) {
                $unknown++;
            }
        }
        $rows[] = [$line, trim(mb_convert_kana((string)($u['user_id'] ?? ''), 'as', 'UTF-8')),
                   trim(preg_replace('/[\s\x{3000}]+/u', ' ', (string)($u['user_name'] ?? ''))), $dept,
                   trim((string)($u['role'] ?? ''))];
    }
    // 半分より多くがこのアプリに無い部署名なら電子カルテの部署名とみなす
    // （このアプリの部署名で書いたCSVに打ち間違いが少しあるだけなら、今までどおりその行をエラーにする）
    return $unknown * 2 > $filled ? ['issued' => '見出し付きCSV', 'rows' => $rows, 'error' => null, 'headed' => true] : null;
}

/** 保存してある対応表（電子カルテの部署名 => dept_id）。 */
function emr_dept_map_load(): array
{
    $out = [];
    foreach (db_all("SELECT config_key, config_value FROM m_config WHERE config_key LIKE 'emr_dept:%'") as $r) {
        $out[substr($r['config_key'], strlen('emr_dept:'))] = $r['config_value'];
    }
    return $out;
}

/** 対応表を保存する（ある部署名は上書き）。 */
function emr_dept_map_save(array $map): void
{
    foreach ($map as $emrName => $deptId) {
        $key = 'emr_dept:' . $emrName;
        db_exec('DELETE FROM m_config WHERE config_key = ?', [$key]);
        db_exec("INSERT INTO m_config (config_key, valid_from, config_value, note) VALUES (?, '2000-01-01', ?, ?)",
                [$key, $deptId, '電子カルテの部署名との対応（職員画面で設定）']);
    }
}

/**
 * 初めて見る電子カルテの部署名に、このアプリの部署の候補を出す。
 * あくまで候補。画面で確かめてから保存する。
 */
function emr_dept_guess(string $emrName, array $depts): string
{
    $n = user_norm($emrName);
    foreach ($depts as $id => $d) {
        if ($id !== DEPT_VIEW_ONLY && user_norm($d['dept_name']) === $n) {
            return $id;     // 名前がそのまま同じ（栄養科・医事課 など）
        }
    }
    // 先に当たったものを使う（「医師事務支援課」を事務課にしないよう、医師事務は事務より先）
    $rules = [
        '医師事務' => DEPT_VIEW_ONLY, '秘書' => DEPT_VIEW_ONLY,
        '病棟' => 'byoto', '薬剤' => 'yakuzai', '栄養' => 'eiyou', 'リハビリ' => 'rehab', '放射線' => 'housha',
        '検査' => 'kensa', '臨床工学' => 'touseki', '透析' => 'touseki', '外来' => 'gairai', '手術' => 'ope',
        '医事' => 'ijika', '総務' => 'jimu', '事務' => 'jimu',
        '施設' => 'shisetsu', '地域医療連携' => 'renkei', '連携' => 'renkei',
        '健康管理' => 'dock', '健診' => 'dock', 'ドック' => 'dock', '訪問' => 'zaitaku',
        '内視鏡' => 'naishikyo', '救急' => 'kyukyu', '感染' => 'kansen',
    ];
    foreach ($rules as $word => $id) {
        if (mb_strpos($n, $word) !== false && ($id === DEPT_VIEW_ONLY || isset($depts[$id]))) {
            return $id;
        }
    }
    return DEPT_VIEW_ONLY;
}

/*
 * 電子カルテの部署名ごとの役割（m_config の emr_role:部署名）。
 * 例：総務課の職員は管理者。取り込むときに、その部署の職員の役割をこれにする。
 * 'keep' は「決めない」（新規は入力者、登録済みの人は今の役割のまま）。
 */

/** 保存してある部署ごとの役割（電子カルテの部署名 => 'keep' / 役割コード）。 */
function emr_role_map_load(): array
{
    $out = [];
    foreach (db_all("SELECT config_key, config_value FROM m_config WHERE config_key LIKE 'emr_role:%'") as $r) {
        $out[substr($r['config_key'], strlen('emr_role:'))] = $r['config_value'];
    }
    return $out;
}

/** 部署ごとの役割を保存する（'keep' も保存して「決めない」を覚える）。 */
function emr_role_map_save(array $map): void
{
    foreach ($map as $emrName => $role) {
        $key = 'emr_role:' . $emrName;
        db_exec('DELETE FROM m_config WHERE config_key = ?', [$key]);
        db_exec("INSERT INTO m_config (config_key, valid_from, config_value, note) VALUES (?, '2000-01-01', ?, ?)",
                [$key, $role, '電子カルテの部署ごとの役割（職員画面で設定）']);
    }
}

/** 初めて見る部署名の役割の候補。総務課の職員は管理者（栗原様の指定）。それ以外は決めない。 */
function emr_role_guess(string $emrName): string
{
    return mb_strpos(user_norm($emrName), '総務') !== false ? 'admin' : 'keep';
}
