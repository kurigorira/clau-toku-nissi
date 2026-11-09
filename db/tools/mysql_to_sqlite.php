<?php
/**
 * db/schema.sql（MySQL用・正本）を SQLite 用DDLに機械変換する。
 *
 * 本番は MySQL / MariaDB だが、開発機に MySQL を立てられない場合に
 * SQLite で同じアプリを動かして動作確認できるようにするための開発用ツール。
 * スキーマの正本は db/schema.sql ひとつだけに保ち、二重管理しない。
 *
 * 使い方:  php db/tools/mysql_to_sqlite.php > db/schema.sqlite.sql
 */

$src = dirname(__DIR__) . '/schema.sql';
$sql = file_get_contents($src);
if ($sql === false) {
    fwrite(STDERR, "schema.sql を読めません: $src\n");
    exit(1);
}

// SQLite が解釈できない MySQL 固有の記述を落とす
$sql = preg_replace('/^\s*SET NAMES.*$/mi', '', $sql);
$sql = preg_replace('/\)\s*ENGINE=\w+\s+DEFAULT CHARSET=\w+\s*;/i', ');', $sql);

// KEY 定義はテーブル内では書けないので、CREATE INDEX に外出しする
$indexes = [];
$sql = preg_replace_callback(
    '/CREATE TABLE\s+(\w+)\s*\((.*?)\n\)\s*;/s',
    function ($m) use (&$indexes) {
        $table = $m[1];
        $lines = explode("\n", $m[2]);
        $kept  = [];
        foreach ($lines as $line) {
            // 「  KEY idx_xxx (a, b),  -- コメント」の行をインデックス定義として抜き出す
            if (preg_match('/^\s*KEY\s+(\w+)\s*\(([^)]*)\)\s*,?\s*(--.*)?$/i', $line, $k)) {
                $indexes[] = "CREATE INDEX {$k[1]} ON {$table} ({$k[2]});";
                continue;
            }
            $kept[] = $line;
        }
        $body = rtrim(implode("\n", $kept));
        $body = rtrim($body, ",\n \t");  // 末尾に残った余分なカンマを除去

        // BIGINT NOT NULL AUTO_INCREMENT + PRIMARY KEY → SQLite の rowid 別名にする
        if (preg_match('/(\w+)\s+BIGINT\s+NOT NULL\s+AUTO_INCREMENT/i', $body, $a)) {
            $pk   = $a[1];
            $body = preg_replace('/\s*' . $pk . '\s+BIGINT\s+NOT NULL\s+AUTO_INCREMENT\s*,/i',
                                 "\n  {$pk} INTEGER PRIMARY KEY AUTOINCREMENT,", $body);
            $body = preg_replace('/,?\s*PRIMARY KEY\s*\(\s*' . $pk . '\s*\)/i', '', $body);
            $body = rtrim($body, ",\n \t");
        }
        // 最終行に残った余分なカンマを外す。行末にコメントが付いていても対応する。
        $body = preg_replace('/,([ \t]*(--[^\n]*)?)$/', '$1', $body);
        return "CREATE TABLE {$table} (\n{$body}\n);";
    },
    $sql
);

echo "-- 自動生成ファイル。編集しないこと。\n";
echo "-- db/schema.sql から db/tools/mysql_to_sqlite.php が生成する（開発時のSQLite動作確認用）。\n";
echo "PRAGMA foreign_keys = ON;\n";
echo $sql;
echo "\n" . implode("\n", $indexes) . "\n";
