<?php
/**
 * 現在の利用者を特定する。
 *
 * 入力者と入力時刻を残すことが今回の要件の中核なので、
 * 「誰が操作しているか」の判定はこの1ファイルに閉じ込める。
 * 電子カルテ側の受け渡し方式が変わっても、直すのはここだけで済む。
 *
 * 【共用端末】
 * 電子カルテ端末は複数の職員が使う。前の人のセッションが残ったまま次の人が
 * 電子カルテから開くと、次の人の入力が前の人の名前で記録されてしまう。
 * そこで、URLに職員IDが付いてきたら、セッションに誰がいても必ずそのIDで入り直す。
 * あわせて、無操作が続いたセッションは切る（idle_minutes）。
 *
 * 【医事課・管理者の権限】
 * URLの職員IDは手で書き換えれば誰でも名乗れる。医事課・管理者のIDを名乗られると
 * 全部署の訂正・確定・職員マスタまで触れてしまうので、電子カルテIDだけで入った
 * 医事課・管理者は、パスワードで確かめる（elevate.php）までは入力者として扱う。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/view.php';

/** パスワードで確かめないと使えない役割。 */
const ROLES_NEED_PASSWORD = ['ijika', 'admin'];

/**
 * ログイン中の職員を返す。未ログインなら null。
 *
 * mode='emr'   … 電子カルテから渡された職員IDを受け取る。
 *                受け取った直後にセッションへ入れ、以後URLには載せない。
 *                予備ログイン（login.php）も使える。
 * mode='local' … 職員ID＋パスワードでこのシステムにログインする。
 *
 * 戻り値には次を足してある:
 *   role_real … 職員マスタ上の役割
 *   role      … 今この画面で使える役割（パスワード未確認の医事課・管理者は 'entry'）
 *   elevated  … 本来の役割で動いているか
 */
function current_user(): ?array
{
    start_session();

    $auth = cfg('auth') ?? [];
    $mode = $auth['mode'] ?? 'emr';

    // 無操作が続いたセッションは切る。電子カルテから開き直せばすぐ戻れる
    $idle = (int)($auth['idle_minutes'] ?? 30);
    if (!empty($_SESSION['user_id']) && $idle > 0 && isset($_SESSION['last_seen'])
        && time() - (int)$_SESSION['last_seen'] > $idle * 60) {
        auth_reset_session();
        $_SESSION['timed_out'] = true;
    }

    // 電子カルテからの引き継ぎ
    if ($mode === 'emr') {
        $param = $auth['emr_param'] ?? 'staff_id';
        $raw   = $_GET[$param] ?? $_POST[$param] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $id = auth_normalize_id($raw);
            if (mb_strlen($id) > 32) {
                error_log('EMRから長すぎる職員IDが渡されました ip=' . client_ip());
            } elseif (!emr_signature_ok($raw, $auth)) {
                error_log('EMRからの職員ID引き継ぎで署名検証に失敗しました');
            } else {
                if (($_SESSION['user_id'] ?? null) !== $id) {
                    // 別の人（または初めて）。前の人の状態（権限の確認・CSRFトークンなど）は捨てる
                    auth_reset_session();
                    $_SESSION['user_id'] = $id;
                    $_SESSION['via']     = 'emr';
                }
                // IDをURLから外して開き直す。ブラウザ履歴や Referer に職員IDが残るのを防ぐため
                $url = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?');
                $qs  = $_GET;
                unset($qs[$param], $qs['sig']);
                header('Location: ' . $url . ($qs ? '?' . http_build_query($qs) : ''));
                exit;
            }
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
        // 職員マスタに無い／無効になったIDでは通さない。何が起きたかを画面に出すため覚えておく
        $unknown = (string)$_SESSION['user_id'];
        error_log("職員マスタに無い（または無効な）職員IDで開かれました: {$unknown} ip=" . client_ip());
        auth_reset_session();
        $_SESSION['unknown_id'] = $unknown;
        return null;
    }
    $_SESSION['last_seen'] = time();

    $u['role_real'] = $u['role'];
    $u['elevated']  = true;
    // 予備ログイン（パスワードで入った）なら最初から本来の役割
    $viaEmr = $mode === 'emr' && ($_SESSION['via'] ?? 'emr') !== 'local';
    if ($viaEmr && in_array($u['role'], ROLES_NEED_PASSWORD, true) && empty($_SESSION['elevated'])) {
        $u['role']     = 'entry';
        $u['elevated'] = false;
    }
    return $u;
}

/** セッションの中身を捨て、セッションIDも作り直す（前の人の状態を持ち越さない）。 */
function auth_reset_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

/**
 * 電子カルテから来た職員IDを揃える。前後の空白を除き、全角英数字を半角にする。
 * 先頭の0は消さない（「0108699」と「108699」は別のIDとして扱う）。
 */
function auth_normalize_id(string $id): string
{
    return trim(mb_convert_kana($id, 'as', 'UTF-8'));
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

/**
 * ログインしていなければ止める。
 *
 * 予備ログイン（mode=local）ならログイン画面へ。
 * 電子カルテ経由（mode=emr）なら、未登録のIDか、電子カルテから開き直してほしいかを画面に出す
 * （予備ログイン画面に黙って飛ばすと、職員マスタの登録漏れに気づけないため）。
 */
function require_login(): array
{
    $u = current_user();
    if ($u !== null) {
        return $u;
    }
    $mode = (cfg('auth') ?? [])['mode'] ?? 'emr';
    if ($mode !== 'emr') {
        header('Location: login.php');
        exit;
    }
    $unknown  = $_SESSION['unknown_id'] ?? null;
    $timedOut = !empty($_SESSION['timed_out']);
    unset($_SESSION['unknown_id'], $_SESSION['timed_out']);

    http_response_code($unknown !== null ? 403 : 401);
    page_header($unknown !== null ? '職員マスタに登録されていません' : '電子カルテから開いてください');
    if ($unknown !== null) {
        flash("電子カルテID「{$unknown}」は、このシステムの職員マスタに登録されていないか、無効になっています。", 'error');
        echo '<p>医事課・管理者に、電子カルテID・氏名・部署を伝えて登録を依頼してください。</p>';
    } else {
        if ($timedOut) {
            flash('しばらく操作が無かったため終了しました。', 'info');
        }
        echo '<p>電子カルテのメニューから、もう一度このシステムを開いてください。</p>';
    }
    echo '<p class="note"><a href="login.php">予備ログイン（職員ID＋パスワード）</a></p>';
    page_footer();
    exit;
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

/** パスワードで確かめれば医事課・管理者の権限が使えるか（ヘッダの案内に使う）。 */
function can_elevate(array $user): bool
{
    return empty($user['elevated']) && in_array($user['role_real'] ?? '', ROLES_NEED_PASSWORD, true);
}

/**
 * その部署の入力ができるか。
 * 自部署のみ入力可。医事課と管理者は全部署を代行入力できる。
 * 当直者は、所属（病棟など）に加えて当直（在宅②の訪問系・通所リハ）の欄も入力する。
 */
function can_edit_dept(array $user, string $deptId): bool
{
    return is_ijika($user)
        || $user['dept_id'] === $deptId
        || ($user['role'] === 'toutyoku' && $deptId === 'toutyoku');
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
