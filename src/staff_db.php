<?php
/**
 * 職員マスタを電子カルテシステム（NEWTONS2.0 の SQL Server）から直接読む。
 *
 * 接続先と SELECT 文は config.php の staff_db に書く（テーブル名・列名は病院ごとに違うため）。
 * 読んだ結果は電子カルテの職員一覧（syokuinID.csv）と同じ形にして、職員画面の同じ確認画面へ回す
 * （部署名の対応表を確かめてから登録する。public/admin_user.php の csv_emr_start()）。
 *
 * このファイルは他のファイルを読まない（tools/check_env.php からも使うため。cfg() は呼ぶ側が用意する）。
 */

/** 設定（dsn が空なら null＝使わない）。 */
function staff_db_conf(): ?array
{
    $c = cfg('staff_db');
    if (!is_array($c) || trim((string)($c['dsn'] ?? '')) === '') {
        return null;
    }
    return $c;
}

/** 接続する。SQL Server は待ち時間を短くする（つながらないとき画面が長く止まらないように）。 */
function staff_db_connect(array $c): PDO
{
    $dsn = trim((string)$c['dsn']);
    if (stripos($dsn, 'sqlsrv:') === 0 && stripos($dsn, 'LoginTimeout') === false) {
        $dsn .= ';LoginTimeout=5';
    }
    return new PDO($dsn, $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * 設定の SELECT 文で職員を読む。列は emr_id・name・dept（大文字小文字は問わない）。
 *
 * @return array ['rows' => [[ID, 氏名, 部署名（そのまま）], ...], 'error' => 文|null]
 */
function staff_db_fetch(?array $c = null): array
{
    $c = $c ?? staff_db_conf();
    if ($c === null) {
        return ['rows' => [], 'error' => 'config.php の staff_db（電子カルテシステムの接続先）が設定されていません。'];
    }
    $sql = trim((string)($c['sql'] ?? ''));
    if ($sql === '') {
        return ['rows' => [], 'error' => 'config.php の staff_db.sql（職員を読む SELECT 文）が空です。'
            . 'php tools\\check_env.php で職員テーブルの候補を見て書いてください。'];
    }
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
        return ['rows' => [], 'error' => 'staff_db.sql は SELECT 文だけ書けます。'];
    }
    try {
        $rows = [];
        foreach (staff_db_connect($c)->query($sql) as $r) {
            $r = array_change_key_case($r, CASE_LOWER);
            if (!array_key_exists('emr_id', $r) || !array_key_exists('name', $r) || !array_key_exists('dept', $r)) {
                return ['rows' => [], 'error' => 'staff_db.sql の結果に emr_id・name・dept の列がありません'
                    . '（SELECT 職員ID AS emr_id, 氏名 AS name, 部署名 AS dept … のように別名を付けてください）。'];
            }
            $rows[] = [$r['emr_id'], $r['name'], $r['dept']];
        }
        return ['rows' => $rows, 'error' => null];
    } catch (Throwable $e) {
        error_log('電子カルテシステムの職員の読み込みに失敗: ' . $e->getMessage());
        return ['rows' => [], 'error' => '電子カルテシステムから読めませんでした：' . $e->getMessage()];
    }
}

/**
 * 職員画面の確認画面へ回す形（users_read_emr_csv() と同じ）にする。src/users.php の users_emr_row() を使う。
 *
 * @return array ['issued' => '電子カルテシステム …', 'rows' => [[番号, ID, 氏名, 部署名], ...], 'error' => 文|null]
 */
function staff_db_read(): array
{
    $f = staff_db_fetch();
    $issued = '電子カルテシステムから読み込み ' . date('Y-m-d H:i');
    if ($f['error'] !== null) {
        return ['issued' => $issued, 'rows' => [], 'error' => $f['error']];
    }
    $rows = [];
    $n = 0;
    foreach ($f['rows'] as [$id, $name, $dept]) {
        $n++;
        $row = users_emr_row($id, $name, $dept);
        if ($row !== null) {
            $rows[] = array_merge([$n], $row);
        }
    }
    if (!$rows) {
        return ['issued' => $issued, 'rows' => [], 'error' => '電子カルテシステムから職員の行が1件も読めませんでした（emr_id が数字の行がありません）。'];
    }
    return ['issued' => $issued, 'rows' => $rows, 'error' => null];
}

/**
 * 職員テーブルの候補（名前に syoku・shoku・staff など）と列の一覧。中身（氏名など）は読まない。
 *
 * @return array [['table' => 名前, 'columns' => [列名...], 'count' => 件数|null], ...]
 */
function staff_db_candidates(PDO $pdo): array
{
    $words  = ['syoku', 'shoku', 'staff', 'user', 'tanto', 'tantou', 'emp', 'isi', 'ishi', 'kango', 'riyo'];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tables = [];
    if ($driver === 'sqlite') {
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name") as $r) {
            $tables[$r['name']] = [];
        }
        foreach (array_keys($tables) as $t) {
            foreach ($pdo->query('PRAGMA table_info(' . $pdo->quote($t) . ')') as $c) {
                $tables[$t][] = $c['name'];
            }
        }
    } else {
        foreach ($pdo->query('SELECT TABLE_SCHEMA AS s, TABLE_NAME AS t, COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS
                               ORDER BY TABLE_SCHEMA, TABLE_NAME, ORDINAL_POSITION') as $r) {
            $tables[$r['s'] . '.' . $r['t']][] = $r['c'];
        }
    }
    $out = [];
    foreach ($tables as $t => $cols) {
        $name = strtolower(substr((string)strrchr('.' . $t, '.'), 1));
        foreach ($words as $w) {
            if (strpos($name, $w) !== false) {
                $out[] = ['table' => $t, 'columns' => $cols, 'count' => null];
                break;
            }
        }
        if (count($out) >= 30) {
            break;
        }
    }
    foreach ($out as $i => $o) {
        $q = $driver === 'sqlite' ? '"' . str_replace('"', '""', $o['table']) . '"'
                                  : implode('.', array_map(fn($p) => '[' . str_replace(']', ']]', $p) . ']', explode('.', $o['table'], 2)));
        try {
            $out[$i]['count'] = (int)$pdo->query("SELECT COUNT(*) FROM {$q}")->fetchColumn();
        } catch (Throwable $e) {
            // 権限が無いテーブルなどは件数なし
        }
    }
    return $out;
}
