<?php
/**
 * 現在の利用者を特定する。
 *
 * 入力者と入力時刻を残すことが今回の要件の中核なので、
 * 「誰が操作しているか」の判定はこの1ファイルに閉じ込める。
 * 電子カルテ側の受け渡し方式が変わっても、直すのはここだけで済む。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view.php';

/**
 * ログイン中の職員を返す。未ログインなら null。
 *
 * mode='emr'   … 電子カルテから渡された職員IDを受け取る。
 *                受け取った直後にセッションへ入れ、以後URLには載せない。
 * mode='local' … 職員ID＋パスワードでこのシステムにログインする。
 */
function current_user(): ?array
{
    start_session();

    $auth = cfg('auth') ?? [];
    $mode = $auth['mode'] ?? 'emr';

    // 電子カルテからの引き継ぎ。初回アクセス時だけURLに載ってくる想定
    if ($mode === 'emr' && empty($_SESSION['user_id'])) {
        $param = $auth['emr_param'] ?? 'staff_id';
        $id    = $_GET[$param] ?? $_POST[$param] ?? null;
        if (is_string($id) && $id !== '') {
            if (emr_signature_ok($id, $auth)) {
                $_SESSION['user_id'] = $id;
                // IDをURLから外して開き直す。ブラウザ履歴や
                // Referer に職員IDが残るのを防ぐため
                $url = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
                $qs  = $_GET;
                unset($qs[$param], $qs['sig']);
                header('Location: ' . $url . ($qs ? '?' . http_build_query($qs) : ''));
                exit;
            }
            error_log('EMRからの職員ID引き継ぎで署名検証に失敗しました');
        }
    }

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $u = db_row(
        'SELECT u.*, d.dept_name FROM m_user u
           LEFT JOIN m_dept d ON d.dept_id = u.dept_id
          WHERE u.user_id = ? AND u.is_active = 1',
        [$_SESSION['user_id']]
    );
    if ($u === null) {
        // 職員マスタに無い／無効になったIDでは通さない
        unset($_SESSION['user_id']);
        return null;
    }
    return $u;
}

/**
 * 電子カルテから渡されたIDの検証。
 *
 * emr_secret が設定されていれば、共有シークレットによる署名を検証する。
 * 空文字なら検証しない（院内閉域網＋監査ログで担保する運用）。
 */
function emr_signature_ok(string $id, array $auth): bool
{
    $secret = $auth['emr_secret'] ?? '';
    if ($secret === '') {
        return true;
    }
    $sig = $_GET['sig'] ?? $_POST['sig'] ?? '';
    return is_string($sig) && hash_equals(hash_hmac('sha256', $id, $secret), $sig);
}

/** ログインしていなければログイン画面へ送る。 */
function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

/** 指定した役割のいずれかを持っているか。 */
function has_role(array $user, string ...$roles): bool
{
    return in_array($user['role'], $roles, true);
}

/** 医事課または管理者か（確定操作・統計表の閲覧に使う）。 */
function is_ijika(array $user): bool
{
    return has_role($user, 'ijika', 'admin');
}

/**
 * その部署の入力ができるか。
 * 自部署のみ入力可。医事課と管理者は全部署を代行入力できる。
 */
function can_edit_dept(array $user, string $deptId): bool
{
    return is_ijika($user) || $user['dept_id'] === $deptId;
}

/** ログイン中の職員IDを返す（保存時の created_by / updated_by に使う）。 */
function current_user_id(): string
{
    $u = current_user();
    return $u['user_id'] ?? '';
}

/** 操作元のIPアドレス（監査ログ用）。 */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
