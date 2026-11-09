<?php
/**
 * データベース接続。
 *
 * SQLは必ずプリペアドステートメントで発行する。
 * 現行システムは $_GET の値をSQL文字列に直接埋め込んでおり、
 * 電子カルテ上に載せる以上そこは通せないため、この層に閉じ込める。
 */

// 全画面がこのファイルを読むので、ここで例外・致命的エラーの受け止めを仕掛ける。
// これが無いと、テーブル未作成などのときに真っ白な500だけが返り原因が分からない。
require_once __DIR__ . '/errors.php';
install_error_handlers();

/** 設定を読む。config.php が無ければ何が足りないかを明示して止める。 */
function cfg(?string $key = null)
{
    static $conf = null;
    if ($conf === null) {
        $path = dirname(__DIR__) . '/config/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('config/config.php がありません。config/config.sample.php をコピーして作成してください。');
        }
        $conf = require $path;
    }
    return $key === null ? $conf : ($conf[$key] ?? null);
}

/** PDO接続を返す（1リクエスト内では使い回す）。 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $d = cfg('db');
    try {
        $pdo = new PDO($d['dsn'], $d['user'] ?? null, $d['pass'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // 本当のプリペアドを使う。エミュレーションだと型の扱いが曖昧になる
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // 例外を握りつぶさない。ただし接続文字列やパスワードは画面に出さない。
        // 原因は必ずサーバのエラーログに出す（Windows/Apache24 なら C:\Apache24\logs\error.log）。
        error_log('DB接続に失敗: ' . $e->getMessage());
        http_response_code(500);
        $msg = 'データベースに接続できません。管理者に連絡してください。';
        // 切り分け中だけ config.php の debug を true にすると、理由が画面に出る。
        // 出すのは例外のメッセージだけ。dsn（ホスト名・DB名）とパスワードは debug でも出さない。
        if (cfg('debug')) {
            $msg .= '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                  . "\n\n原因の切り分けは  php tools/check_env.php  でもできます。</pre>";
        }
        exit($msg);
    }
    // SQLite（開発用）でも外部キーと日付比較が期待どおり動くようにする
    if (strpos($d['dsn'], 'sqlite:') === 0) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/** SELECT して全行を返す。 */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** SELECT して1行を返す。無ければ null。 */
function db_row(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** INSERT / UPDATE / DELETE を実行し、影響行数を返す。 */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
