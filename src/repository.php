<?php
/**
 * 日次データの読み書きと、提出状態・変更履歴の管理。
 *
 * 保存は必ずこの層を通す。ここで created_by / updated_by / 時刻の記録と、
 * 変更履歴（d_audit）への記録を必ず行うため。
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/auth.php';

/** ある日・ある部署の入力値を item_code => 行 で返す。 */
function dept_entries(string $date, string $deptId): array
{
    $rows = db_all(
        'SELECT v.* FROM d_daily_value v
           JOIN m_item i ON i.item_code = v.item_code
          WHERE v.hizuke = ? AND i.dept_id = ?',
        [$date, $deptId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['item_code']] = $r;
    }
    return $out;
}

/**
 * ある日・ある部署の入力値を保存する。
 *
 * $values は item_code => 画面から来た文字列。
 * 値が変わった項目だけ d_audit に旧値→新値を記録する。
 * 変更が無い項目に updated_at を打たない（誰がいつ直したかが埋もれるため）。
 *
 * @return array 保存件数と、範囲チェックに引っかかった項目
 */
function save_dept_entries(string $date, string $deptId, array $values, string $userId): array
{
    $items   = input_items_of_dept($deptId, $date);
    $now     = date('Y-m-d H:i:s');
    $ip      = client_ip();
    $saved   = 0;
    $warn    = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($items as $code => $it) {
            if (!array_key_exists($code, $values)) {
                continue;
            }
            $raw = trim((string)$values[$code]);
            $isText = in_array($it['value_type'], ['text', 'multiline'], true);

            if ($isText) {
                $num  = null;
                $text = $raw === '' ? null : $raw;
                $new  = $text;
            } else {
                if ($raw !== '' && !is_numeric($raw)) {
                    $warn[] = "{$it['item_name']}：数値で入力してください（「{$raw}」）";
                    continue;
                }
                $num  = $raw === '' ? null : (float)$raw;
                $text = null;
                $new  = $num === null ? null : (string)$num;
                // 桁の打ち間違いを拾う。止めはせず警告に留める
                if ($num !== null && $it['min_value'] !== null && $num < (float)$it['min_value']) {
                    $warn[] = "{$it['item_name']}：{$it['min_value']} 未満の値です（{$raw}）。確認してください";
                }
                if ($num !== null && $it['max_value'] !== null && $num > (float)$it['max_value']) {
                    $warn[] = "{$it['item_name']}：{$it['max_value']} を超えています（{$raw}）。確認してください";
                }
            }

            // 監査ログに旧値を残すため、書く前に必ず今の値を読む
            $cur = db_row('SELECT * FROM d_daily_value WHERE hizuke = ? AND item_code = ?', [$date, $code]);
            $old = $cur === null ? null
                 : ($isText ? $cur['value_text'] : ($cur['value_num'] === null ? null : (string)(float)$cur['value_num']));

            if ($cur === null && $new === null) {
                continue; // 元々無く、今回も空。何もしない
            }
            if ($cur !== null && (string)$old === (string)$new) {
                continue; // 値が変わっていない。更新者・時刻も触らない
            }

            if ($cur === null) {
                db_exec(
                    'INSERT INTO d_daily_value (hizuke,item_code,value_num,value_text,created_by,created_at,updated_by,updated_at)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$date, $code, $num, $text, $userId, $now, $userId, $now]
                );
                audit($date, $code, 'insert', null, $new, $userId, $now, $ip);
            } else {
                db_exec(
                    'UPDATE d_daily_value SET value_num = ?, value_text = ?, updated_by = ?, updated_at = ?
                      WHERE hizuke = ? AND item_code = ?',
                    [$num, $text, $userId, $now, $date, $code]
                );
                audit($date, $code, 'update', $old, $new, $userId, $now, $ip);
            }
            $saved++;
        }
        touch_submission($date, $deptId, 'draft', $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('入力の保存に失敗: ' . $e->getMessage());
        throw $e;
    }
    return ['saved' => $saved, 'warnings' => $warn];
}

/** 変更履歴を1件記録する。 */
function audit(string $date, string $code, string $action, ?string $old, ?string $new,
               string $userId, string $now, string $ip): void
{
    db_exec(
        'INSERT INTO d_audit (hizuke,item_code,action,old_value,new_value,acted_by,acted_at,client_ip)
         VALUES (?,?,?,?,?,?,?,?)',
        [$date, $code, $action, $old, $new, $userId, $now, $ip]
    );
}

/** 提出状態を取得する。 */
function submission(string $date, string $deptId): ?array
{
    return db_row('SELECT * FROM d_submission WHERE hizuke = ? AND dept_id = ?', [$date, $deptId]);
}

/**
 * 提出状態を進める。
 * 一度 submitted / confirmed になったものを draft に戻さない
 * （保存のたびに提出済みが取り消されると現場が混乱するため）。
 */
function touch_submission(string $date, string $deptId, string $status, string $userId): void
{
    $rank = ['none' => 0, 'draft' => 1, 'submitted' => 2, 'confirmed' => 3];
    $cur  = submission($date, $deptId);
    $now  = date('Y-m-d H:i:s');

    if ($cur === null) {
        db_exec('INSERT INTO d_submission (hizuke,dept_id,status) VALUES (?,?,?)', [$date, $deptId, 'none']);
        $cur = ['status' => 'none'];
    }
    $next = ($rank[$status] ?? 0) > ($rank[$cur['status']] ?? 0) ? $status : $cur['status'];

    if ($status === 'submitted') {
        db_exec('UPDATE d_submission SET status = ?, submitted_by = ?, submitted_at = ? WHERE hizuke = ? AND dept_id = ?',
            [$next, $userId, $now, $date, $deptId]);
    } elseif ($status === 'confirmed') {
        db_exec('UPDATE d_submission SET status = ?, confirmed_by = ?, confirmed_at = ? WHERE hizuke = ? AND dept_id = ?',
            [$next, $userId, $now, $date, $deptId]);
    } else {
        db_exec('UPDATE d_submission SET status = ? WHERE hizuke = ? AND dept_id = ?', [$next, $date, $deptId]);
    }
}

/** その部署がその日に入力すべきか（曜日で判定）。 */
function is_entry_day(array $dept, string $date): bool
{
    $w = (int)date('N', strtotime($date)); // 1=月 … 7=日
    return strpos($dept['entry_days'], (string)$w) !== false;
}

/**
 * 入力状況を返す。入力漏れ判定の中核。
 *
 * 戻り値の status:
 *   off        その部署の入力対象日ではない
 *   none       未入力
 *   partial    入力途中、または提出済みだが必須項目に欠測がある
 *   submitted  提出済
 *   confirmed  医事課が確定済
 */
function entry_status(string $from, string $to): array
{
    $depts = all_depts();
    $items = all_items($to);

    // 部署ごとの必須項目
    $required = [];
    foreach ($items as $code => $it) {
        if ($it['calc_type'] === 'input' && (int)$it['required'] === 1) {
            $required[$it['dept_id']][] = $code;
        }
    }

    // 期間内に値が入っている件数を 日付×部署 で数える
    $filled = [];
    foreach (db_all(
        'SELECT v.hizuke, i.dept_id, v.item_code FROM d_daily_value v
           JOIN m_item i ON i.item_code = v.item_code
          WHERE v.hizuke BETWEEN ? AND ? AND (v.value_num IS NOT NULL OR v.value_text IS NOT NULL)',
        [$from, $to]
    ) as $r) {
        $filled[$r['hizuke']][$r['dept_id']][$r['item_code']] = true;
    }

    $subs = [];
    foreach (db_all('SELECT * FROM d_submission WHERE hizuke BETWEEN ? AND ?', [$from, $to]) as $r) {
        $subs[$r['hizuke']][$r['dept_id']] = $r;
    }

    $out = [];
    $d   = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($d <= $end) {
        $date = $d->format('Y-m-d');
        foreach ($depts as $id => $dept) {
            $sub  = $subs[$date][$id] ?? null;
            $have = $filled[$date][$id] ?? [];

            if (!is_entry_day($dept, $date)) {
                $out[$date][$id] = ['status' => 'off', 'missing' => [], 'sub' => $sub];
                continue;
            }

            $missing = [];
            foreach ($required[$id] ?? [] as $code) {
                if (!isset($have[$code])) {
                    $missing[] = $items[$code]['item_name'];
                }
            }

            $st = $sub['status'] ?? 'none';
            if ($st === 'confirmed') {
                $status = 'confirmed';
            } elseif ($st === 'submitted') {
                $status = $missing ? 'partial' : 'submitted';
            } elseif ($have) {
                $status = 'partial';
            } else {
                $status = 'none';
            }
            $out[$date][$id] = ['status' => $status, 'missing' => $missing, 'sub' => $sub];
        }
        $d = $d->modify('+1 day');
    }
    return $out;
}

/** ある日の、部署ごとの最終更新者と時刻。病院日誌の末尾に出す。 */
function daily_updaters(string $date): array
{
    return db_all(
        'SELECT i.dept_id, d.dept_name,
                MAX(v.updated_at) AS last_at,
                COUNT(*)          AS cnt
           FROM d_daily_value v
           JOIN m_item i ON i.item_code = v.item_code
           JOIN m_dept d ON d.dept_id   = i.dept_id
          WHERE v.hizuke = ?
          GROUP BY i.dept_id, d.dept_name
          ORDER BY d.sort_no',
        [$date]
    );
}

/** ある日・ある部署の最終更新者の氏名と時刻。入力画面の下部に出す。 */
function last_update_of_dept(string $date, string $deptId): ?array
{
    return db_row(
        'SELECT v.updated_at, v.updated_by, u.user_name
           FROM d_daily_value v
           JOIN m_item i ON i.item_code = v.item_code
           LEFT JOIN m_user u ON u.user_id = v.updated_by
          WHERE v.hizuke = ? AND i.dept_id = ?
          ORDER BY v.updated_at DESC
          LIMIT 1',
        [$date, $deptId]
    );
}
