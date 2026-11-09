<?php
/**
 * 定数超過報告（オーバーベッドの患者。病院日誌の最下段「特記事項等」）。
 *
 * 病棟の入力画面（entry.php?dept=byoto）で入力する。紙の様式どおり最大6名。
 * 行を直したり消したりしたときは、前の行に deleted_at を付けて残し（論理削除）、新しい行を入れ直す。
 */
require_once __DIR__ . '/repository.php';

/** 1日に入れられる人数（紙の様式の行数）。 */
const TOKKI_MAX = 6;

/** 病棟の選択肢。 */
function tokki_wards(): array
{
    return ['3階病棟', '4階病棟', '5階病棟'];
}

/** 入院経路の候補（自由に書いてもよい）。 */
function tokki_routes(): array
{
    return ['救急搬入', '外来', '紹介'];
}

/** その日の有効な行（並び順どおり）。 */
function tokki_rows(string $date): array
{
    return db_all('SELECT * FROM d_tokki WHERE hizuke = ? AND deleted_at IS NULL ORDER BY sort_no, tokki_id', [$date]);
}

/**
 * 画面から来た行をそろえる。全部の欄が空の行は捨て、文字数は列の長さで切る。最大 TOKKI_MAX 行。
 *
 * @return array [['ward','patient_id','patient_name','route','diagnosis'], ...]
 */
function tokki_normalize(array $posted): array
{
    $len  = ['ward' => 16, 'patient_id' => 16, 'patient_name' => 64, 'route' => 32, 'diagnosis' => 128];
    $rows = [];
    foreach ($posted as $r) {
        if (!is_array($r)) {
            continue;
        }
        $row = [];
        foreach ($len as $k => $max) {
            $row[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($r[$k] ?? ''))), 0, $max);
        }
        if (implode('', [$row['patient_id'], $row['patient_name'], $row['route'], $row['diagnosis']]) === '') {
            continue;      // 病棟だけ選んであって他が空の行も、空の行として捨てる
        }
        $rows[] = $row;
        if (count($rows) >= TOKKI_MAX) {
            break;
        }
    }
    return $rows;
}

/**
 * 保存する。中身が変わっていなければ何もしない。
 * トランザクションは呼び出し側で張る（他の入力欄と一緒に保存するため）。
 *
 * @return array ['changed' => bool, 'warnings' => [...]]
 */
function tokki_save(string $date, array $posted, string $userId): array
{
    $rows = tokki_normalize($posted);
    $warn = [];
    foreach ($rows as $i => $r) {
        $no = $i + 1;
        if ($r['patient_id'] !== '' && !preg_match('/^[0-9A-Za-z\-]+$/', $r['patient_id'])) {
            $warn[] = "定数超過報告 {$no}行目：ID「{$r['patient_id']}」に数字・英字以外が入っています。";
        }
        if ($r['patient_name'] === '') {
            $warn[] = "定数超過報告 {$no}行目：氏名が空です。";
        }
    }
    $cols = ['ward', 'patient_id', 'patient_name', 'route', 'diagnosis'];
    $now  = array_map(fn($r) => array_intersect_key($r, array_flip($cols)), tokki_rows($date));
    if ($now === $rows) {
        return ['changed' => false, 'warnings' => $warn];
    }
    $ts = date('Y-m-d H:i:s');
    db_exec('UPDATE d_tokki SET deleted_at = ?, updated_by = ?, updated_at = ? WHERE hizuke = ? AND deleted_at IS NULL',
            [$ts, $userId, $ts, $date]);
    foreach ($rows as $i => $r) {
        db_exec('INSERT INTO d_tokki (hizuke, ward, patient_id, patient_name, route, diagnosis, sort_no,
                                      created_by, created_at, updated_by, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$date, $r['ward'], $r['patient_id'], $r['patient_name'], $r['route'], $r['diagnosis'], $i + 1,
                 $userId, $ts, $userId, $ts]);
    }
    return ['changed' => true, 'warnings' => $warn];
}

/**
 * 定床を超えている病棟・合計の文（無ければ空）。例「5階 40名／定床39名、計 109名／定床108名」
 *
 * @param array $zaiin ['3' => 在院患者数, ...]（未入力は null）
 */
function tokki_overbed(array $zaiin, string $date): string
{
    $over = [];
    $sum = $beds = 0;
    $all = true;
    foreach (['3', '4', '5'] as $w) {
        $n = $zaiin[$w] ?? null;
        $t = (int)config_value("teisho_byoto{$w}", $date, 0);
        $beds += $t;
        if ($n === null) {
            $all = false;
            continue;
        }
        $sum += (int)$n;
        if ($t > 0 && (int)$n > $t) {
            $over[] = "{$w}階 {$n}名／定床{$t}名";
        }
    }
    if ($all && $beds > 0 && $sum > $beds) {
        $over[] = "計 {$sum}名／定床{$beds}名";
    }
    return implode('、', $over);
}
