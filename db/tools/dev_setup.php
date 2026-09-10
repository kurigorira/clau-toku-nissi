<?php
/**
 * 開発用のSQLiteデータベースを作り直す。
 *
 * 本番は MySQL / MariaDB だが、開発機にMySQLを立てられない場合でも
 * 同じアプリを動かして動作確認できるようにするためのツール。
 * 本番では使わない。
 *
 * 使い方:  php db/tools/dev_setup.php
 */

$root = dirname(__DIR__, 2);
$dbPath = $root . '/db/dev.sqlite';

foreach ([$root . '/db/schema.sqlite.sql', $root . '/db/seed_master.sql'] as $f) {
    if (!is_file($f)) {
        fwrite(STDERR, "$f がありません。先に mysql_to_sqlite.php と build_seed.php を実行してください\n");
        exit(1);
    }
}

@unlink($dbPath);
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(file_get_contents($root . '/db/schema.sqlite.sql'));
$pdo->exec(file_get_contents($root . '/db/seed_master.sql'));

// 動作確認用の職員。本番の職員マスタとは別物
$now   = date('Y-m-d H:i:s');
$hash  = password_hash('test1234', PASSWORD_DEFAULT);
$users = [
    ['kurihara', '栗原',       'jimu',     'admin'],
    ['ijika01',  '医事課 太郎', 'ijika',    'ijika'],
    ['gairai01', '外来 花子',   'gairai',   'entry'],
    ['housha01', '放射線 一郎', 'housha',   'entry'],
    ['byoto01',  '病棟 二郎',   'byoto',    'entry'],
    ['tou01',    '当直 三郎',   'toutyoku', 'toutyoku'],
];
$st = $pdo->prepare(
    'INSERT INTO m_user (user_id,user_name,dept_id,role,password_hash,is_active,created_at,updated_at)
     VALUES (?,?,?,?,?,1,?,?)'
);
foreach ($users as [$id, $name, $dept, $role]) {
    $st->execute([$id, $name, $dept, $role, $hash, $now, $now]);
}

$n = fn(string $t) => $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
echo "開発用DBを作成しました: $dbPath\n";
echo "  m_dept={$n('m_dept')}  m_item={$n('m_item')}  m_config={$n('m_config')}  m_user={$n('m_user')}\n";
echo "  ログイン: kurihara / test1234 （ほか ijika01, gairai01, housha01, byoto01, tou01）\n";
