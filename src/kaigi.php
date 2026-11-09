<?php
/**
 * 会議・勉強・委員会等（当直入力）を「時刻＋会議・委員会名」の組で扱う。
 *
 * 保存は今までどおり4つの項目（kaigi_jikoku1・kaigi_name1＝左の欄、kaigi_jikoku2・kaigi_name2＝右の欄）に、
 * 1行に1件ずつ改行でつないで入れる。入力画面と病院日誌は、この行を組にして並べる
 * （時刻と名前を別々の欄に書いて改行の位置をそろえる手間をなくす）。
 */

/** 入力画面の1つの欄（左・右）の行数（入力済みがこれより多ければ、その分だけ出す）。 */
const KAIGI_ROWS = 4;

/** 左右の欄 => [時刻の項目, 名前の項目]。 */
function kaigi_sides(): array
{
    return [1 => ['kaigi_jikoku1', 'kaigi_name1'], 2 => ['kaigi_jikoku2', 'kaigi_name2']];
}

/** 保存されている2つの文（時刻・名前）を、行ごとの組 [[時刻, 名前], ...] にする。 */
function kaigi_pairs(string $times, string $names): array
{
    $t = $times === '' ? [] : preg_split('/\r\n|\r|\n/', $times);
    $n = $names === '' ? [] : preg_split('/\r\n|\r|\n/', $names);
    $pairs = [];
    for ($i = 0, $max = max(count($t), count($n)); $i < $max; $i++) {
        $pairs[] = [kaigi_trim($t[$i] ?? ''), kaigi_trim($n[$i] ?? '')];
    }
    // 末尾の空の組は捨てる
    while ($pairs && $pairs[count($pairs) - 1] === ['', '']) {
        array_pop($pairs);
    }
    return $pairs;
}

/** 前後の空白（全角も）を取る。空の欄は保存のとき全角空白で位置を取っているため。 */
function kaigi_trim(string $s): string
{
    return preg_replace('/^[\s　]+|[\s　]+$/u', '', $s);
}

/** 時刻の書き方をそろえる：「14:00」「14：00」→「14:00〜」（様式と同じく末尾に〜）。それ以外はそのまま。 */
function kaigi_time(string $s): string
{
    $s = kaigi_trim(mb_convert_kana($s, 'a'));
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
        return (int)$m[1] . ':' . $m[2] . '〜';
    }
    return $s;
}

/**
 * 画面から来た組（[['time' => , 'name' => ], ...]）を、保存する2つの文 [時刻, 名前] にする。
 * 両方空の行は詰める。片方だけの行は、行の位置をそろえるため空のまま残す。
 */
function kaigi_join(array $rows): array
{
    $t = $n = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $time = kaigi_time(str_replace(["\r", "\n"], ' ', (string)($r['time'] ?? '')));
        $name = kaigi_trim(str_replace(["\r", "\n"], ' ', (string)($r['name'] ?? '')));
        if ($time === '' && $name === '') {
            continue;
        }
        // 片方だけ空の欄は全角空白にする（保存のときに前後の改行が取られて、行の位置がずれないように）
        $t[] = $time === '' ? '　' : $time;
        $n[] = $name === '' ? '　' : $name;
    }
    return [implode("\n", $t), implode("\n", $n)];
}
