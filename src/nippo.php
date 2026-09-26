<?php
/**
 * 電子カルテの「外来患者数（科別）日報」「入院患者数日報」の転記。
 *
 * 電子カルテからは紙で出てくる。医事課・外来がその紙を見ながら
 * public/nippo.php に打ち込み、そこから各種集計を始める。
 *
 * このファイルに置くもの:
 *   - 紙の並び（行＝科、列＝時間帯）と item_code の対応表
 *   - 紙の合計欄との照合
 *   - 複数部署（外来・病棟・当直）の値をまとめて下書きとして保存する処理
 *
 *   - 日報のExcelから値を読む処理（外来 nippo_gairai_from_xlsx・入院 nippo_nyuin_from_xlsx）
 *
 * Excelから読んだ値も、画面で打った値と同じ照合を通ってから保存される。
 */

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/xlsx.php';

/** 時間帯。紙の左から順。 */
function nippo_slots(): array
{
    return ['s0008' => '00〜08', 'am' => '午前', 'pm' => '午後', 'night' => '夜間', 's2024' => '20〜24'];
}

/** 外来の診療科。紙の上から順。 */
function nippo_ka(): array
{
    return [
        'naika' => '内科（訪問含む）', 'geka' => '外科', 'seikei' => '整形外科', 'noge' => '脳神経外科',
        'shinryo' => '心療内科', 'keisei' => '形成外科', 'jinzo' => '腎臓内科', 'kenshin' => '健診科',
        'shoka' => '消化器内科', 'kokyu' => '呼吸器内科', 'shoni' => '小児科', 'hinyo' => '泌尿器科',
        'hifu' => '皮膚科',
    ];
}

/** 医科合計の下の再掲行。 */
function nippo_saikei(): array
{
    return ['rehab' => 'リハビリ', 'dock' => 'ドック', 'kenshin' => '健診', 'houshin' => '訪問診療科'];
}

/** 訪問診療科内訳（再掲）。紙の並び順。値は当直（在宅②）の医療保険の項目に入る。 */
function nippo_houshin_uchi(): array
{
    return [
        'iryou_houkan'       => '訪問看護（医）',
        'iryou_houshin'      => '訪問診療',
        'iryou_oushin'       => '往診',
        'iryou_houeiyou'     => '訪問栄養（医）',
        'iryou_hourehab_jin' => '訪問リハ（医）',
        'iryou_houyaku'      => '訪問薬剤（医）',
    ];
}

/** 介護。紙の並び順。訪問看護・訪問介護は日報に30分の内訳が無いので日報専用の項目に入れる。 */
function nippo_kaigo(): array
{
    return [
        'nippo_kaigo_houkan' => '訪問看護',
        'nippo_kaigo_houkai' => '訪問介護',
        'kaigo_hourehab'     => '訪問リハ',
        'kaigo_houeiyou'     => '訪問栄養',
        'tsusho'             => '通所リハ',
        'kaigo_houyaku'      => '訪問薬剤',
        'kaigo_kyotaku'      => '居宅指導',
    ];
}

/** 病棟。 */
function nippo_wards(): array
{
    return ['3' => '3F', '4' => '4F', '5' => '5F'];
}

/** 入院の増減。紙の並び順（前日患者数は照合用なので含めない）。 */
function nippo_idou(): array
{
    return ['nyuin' => '入院', 'tennyu' => '転入', 'taiin' => '退院', 'tenshutsu' => '転出', 'zaiin' => '本日患者数'];
}

/** 入院の科別内訳の科。紙の左から順。 */
function nippo_bka(): array
{
    return [
        'naika' => '内科', 'shinnai' => '心内', 'geka' => '外科', 'seikei' => '整形', 'noge' => '脳外',
        'keisei' => '形成', 'shoka' => '消化器内科', 'kokyu' => '呼吸器内科', 'jinzo' => '腎臓内科',
        'hifu' => '皮膚科', 'shoni' => '小児科',
    ];
}

/**
 * 紙から転記する欄の item_code を、紙の並び順ですべて返す。
 * 画面の入力欄とこの一覧は必ず一致する（保存・照合はこの一覧だけを見る）。
 */
function nippo_codes(): array
{
    $c = [];
    foreach (['gk', 'gkn'] as $pre) {
        foreach (nippo_ka() as $ka => $_) {
            foreach (nippo_slots() as $s => $_s) {
                $c[] = "{$pre}_{$ka}_{$s}";
            }
        }
    }
    $c[] = 'gkn_uchi_dock';
    $c[] = 'gkn_uchi_kenshin';
    foreach (nippo_saikei() as $k => $_) {
        foreach (nippo_slots() as $s => $_s) {
            $c[] = "gr_{$k}_{$s}";
        }
    }
    foreach (array_keys(nippo_houshin_uchi()) as $code) {
        $c[] = $code;
    }
    foreach (array_keys(nippo_kaigo()) as $code) {
        $c[] = $code;
    }
    foreach (nippo_wards() as $w => $_) {
        foreach (nippo_idou() as $k => $_k) {
            $c[] = "byoto{$w}_{$k}";
        }
        foreach (nippo_bka() as $ka => $_k) {
            $c[] = "bk{$w}_{$ka}";
        }
    }
    return $c;
}

/**
 * 照合用の欄（紙に印字されている合計など）。保存はしない。
 * 値 true は必須、false は任意（空なら照合しない）。
 */
function nippo_control_names(): array
{
    $n = [];
    foreach (['raiin', 'shinrai'] as $t) {
        foreach (nippo_slots() as $s => $_) {
            $n["{$t}_{$s}"] = true;
        }
        $n["{$t}_all"] = true;
        foreach (nippo_ka() as $ka => $_) {
            $n["{$t}_row_{$ka}"] = false;   // 各科の行の「合計」列。打てば照合する
        }
    }
    $n['kaigo_total']       = true;
    $n['iryou_kaigo_total'] = true;
    foreach (nippo_wards() as $w => $_) {
        $n["zenjitsu_{$w}"] = true;
    }
    return $n;
}

/**
 * 画面から来た値を整える。
 *
 * 転記欄: 空欄は0（紙は0が大半で、全部打たせると手間と打ち漏れが増える）。
 *         0以上の整数でなければエラー。
 * 照合欄: 必須が空ならエラー。任意は空なら null（照合しない）。
 *
 * @return array [$vals (item_code => int), $ctl (name => ?int), $raw (欄 => 打った文字列), $errors (欄 => 理由)]
 */
function nippo_parse(array $postV, array $postC): array
{
    $vals = $ctl = $raw = $errors = [];
    $toInt = function (string $s): ?int {
        // 全角数字で打たれても受ける（IMEがオンのままのことがある）
        $s = trim(mb_convert_kana($s, 'n', 'UTF-8'));
        return preg_match('/^\d{1,6}$/', $s) ? (int)$s : null;
    };

    foreach (nippo_codes() as $code) {
        $s = is_string($postV[$code] ?? null) ? trim($postV[$code]) : '';
        $raw["v:{$code}"] = $s;
        if ($s === '') {
            $vals[$code] = 0;
            continue;
        }
        $n = $toInt($s);
        if ($n === null) {
            $errors["v:{$code}"] = '0以上の整数で入力してください';
            $vals[$code] = 0;
        } else {
            $vals[$code] = $n;
        }
    }
    foreach (nippo_control_names() as $name => $required) {
        $s = is_string($postC[$name] ?? null) ? trim($postC[$name]) : '';
        $raw["c:{$name}"] = $s;
        if ($s === '') {
            $ctl[$name] = null;
            if ($required) {
                $errors["c:{$name}"] = '紙に印字されている値を入力してください（照合に使います）';
            }
            continue;
        }
        $n = $toInt($s);
        if ($n === null) {
            $errors["c:{$name}"] = '0以上の整数で入力してください';
            $ctl[$name] = null;
        } else {
            $ctl[$name] = $n;
        }
    }
    return [$vals, $ctl, $raw, $errors];
}

/**
 * 紙の合計欄と、打ち込んだ値を照合する。
 *
 * errors   … 合わなければ保存しない。キーは画面の欄（色を付ける場所）
 * warnings … 保存はするが、確かめてほしいこと
 */
function nippo_check(array $v, array $c, string $date): array
{
    $errors = $warnings = [];
    $slots  = nippo_slots();

    // 1. 外来：時間帯ごと・科ごとの合計 ＝ 紙の医科合計
    foreach (['raiin' => ['gk', '来院'], 'shinrai' => ['gkn', '新来']] as $t => [$pre, $label]) {
        $all = 0;
        foreach ($slots as $s => $sname) {
            $sum = 0;
            foreach (nippo_ka() as $ka => $_) {
                $sum += $v["{$pre}_{$ka}_{$s}"];
            }
            $all += $sum;
            if ($c["{$t}_{$s}"] !== null && $c["{$t}_{$s}"] !== $sum) {
                $errors["c:{$t}_{$s}"] = "{$label} {$sname}：科別の合計 {$sum} ≠ 紙の医科合計 {$c["{$t}_{$s}"]}";
            }
        }
        if ($c["{$t}_all"] !== null && $c["{$t}_all"] !== $all) {
            $errors["c:{$t}_all"] = "{$label} 合計：科別の合計 {$all} ≠ 紙の医科合計 {$c["{$t}_all"]}";
        }
        foreach (nippo_ka() as $ka => $kname) {
            if ($c["{$t}_row_{$ka}"] === null) {
                continue;
            }
            $row = 0;
            foreach ($slots as $s => $_) {
                $row += $v["{$pre}_{$ka}_{$s}"];
            }
            if ($row !== $c["{$t}_row_{$ka}"]) {
                $errors["c:{$t}_row_{$ka}"] = "{$label} {$kname}：時間帯の合計 {$row} ≠ 紙の合計 {$c["{$t}_row_{$ka}"]}";
            }
        }
    }

    // 2. 介護の合計、医療介護合計
    $kaigo = 0;
    foreach (array_keys(nippo_kaigo()) as $code) {
        $kaigo += $v[$code];
    }
    if ($c['kaigo_total'] !== null && $c['kaigo_total'] !== $kaigo) {
        $errors['c:kaigo_total'] = "介護：7項目の合計 {$kaigo} ≠ 紙の介護合計 {$c['kaigo_total']}";
    }
    if ($c['iryou_kaigo_total'] !== null && $c['raiin_all'] !== null && $c['kaigo_total'] !== null
        && $c['raiin_all'] + $c['kaigo_total'] !== $c['iryou_kaigo_total']) {
        $errors['c:iryou_kaigo_total'] = "医療介護合計：医科合計 {$c['raiin_all']} ＋ 介護合計 {$c['kaigo_total']}"
            . " ≠ 紙の医療介護合計 {$c['iryou_kaigo_total']}";
    }

    // 3. 訪問診療科内訳の合計 ＝ 訪問診療科の行の合計（紙の「再掲分合計」）
    $uchi = 0;
    foreach (array_keys(nippo_houshin_uchi()) as $code) {
        $uchi += $v[$code];
    }
    $houshin = 0;
    foreach ($slots as $s => $_) {
        $houshin += $v["gr_houshin_{$s}"];
    }
    if ($uchi !== $houshin) {
        $errors['v:iryou_houkan'] = "訪問診療科内訳の合計 {$uchi} ≠ 訪問診療科の行の合計 {$houshin}（紙の「再掲分合計」と一致するはず）";
    }

    // 4. 入院：前日＋入院＋転入−退院−転出 ＝ 本日、科別内訳の合計 ＝ 本日
    $prevDay = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    foreach (nippo_wards() as $w => $wname) {
        $honjitsu = $v["byoto{$w}_zaiin"];
        if ($c["zenjitsu_{$w}"] !== null) {
            $calc = $c["zenjitsu_{$w}"] + $v["byoto{$w}_nyuin"] + $v["byoto{$w}_tennyu"]
                  - $v["byoto{$w}_taiin"] - $v["byoto{$w}_tenshutsu"];
            if ($calc !== $honjitsu) {
                $errors["v:byoto{$w}_zaiin"] = "{$wname}：前日 {$c["zenjitsu_{$w}"]} ＋入院＋転入−退院−転出 ＝ {$calc} ≠ 本日患者数 {$honjitsu}";
            }
            $stored = db_row('SELECT value_num FROM d_daily_value WHERE hizuke = ? AND item_code = ?',
                             [$prevDay, "byoto{$w}_zaiin"]);
            if ($stored && $stored['value_num'] !== null && (int)$stored['value_num'] !== $c["zenjitsu_{$w}"]) {
                $warnings[] = "{$wname}：紙の前日患者数 {$c["zenjitsu_{$w}"]} が、前日に保存された本日患者数 "
                    . (int)$stored['value_num'] . ' と違います';
            }
        }
        $ka = 0;
        foreach (nippo_bka() as $k => $_) {
            $ka += $v["bk{$w}_{$k}"];
        }
        if ($ka !== $honjitsu) {
            $errors["v:bk{$w}_naika"] = "{$wname}：科別内訳の合計 {$ka} ≠ 本日患者数 {$honjitsu}";
        }
    }

    // 5. 警告だけにするもの
    $kenshinRow = $dockKenshin = 0;
    foreach ($slots as $s => $_) {
        $kenshinRow  += $v["gk_kenshin_{$s}"];
        $dockKenshin += $v["gr_dock_{$s}"] + $v["gr_kenshin_{$s}"];
    }
    if ($kenshinRow !== $dockKenshin) {
        $warnings[] = "健診科の行の合計 {$kenshinRow} が、再掲のドック＋健診 {$dockKenshin} と違います";
    }
    $kenshinNew = 0;
    foreach ($slots as $s => $_) {
        $kenshinNew += $v["gkn_kenshin_{$s}"];
    }
    if ($kenshinNew !== $v['gkn_uchi_dock'] + $v['gkn_uchi_kenshin']) {
        $warnings[] = "健診科の新来 {$kenshinNew} が、健診科新患内訳（ドック＋健診）"
            . ($v['gkn_uchi_dock'] + $v['gkn_uchi_kenshin']) . ' と違います';
    }
    // 当直が在宅②に入れた30分以上／以内の内訳と、日報の合計
    foreach (['houkan' => '訪問看護（介護）', 'houkai' => '訪問介護'] as $k => $label) {
        $r = db_row(
            'SELECT COUNT(*) AS n, SUM(value_num) AS s FROM d_daily_value
              WHERE hizuke = ? AND item_code IN (?, ?) AND value_num IS NOT NULL',
            [$date, "kaigo_{$k}_over30", "kaigo_{$k}_under30"]
        );
        if ($r && (int)$r['n'] > 0 && (int)$r['s'] !== $v["nippo_kaigo_{$k}"]) {
            $warnings[] = "{$label}：当直が入力した30分以上＋30分以内 " . (int)$r['s']
                . " が、日報の {$v["nippo_kaigo_{$k}"]} と違います";
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/** 保存済みの値を item_code => int で返す（転記欄のぶんだけ）。 */
function nippo_load(string $date): array
{
    $codes = nippo_codes();
    $ph    = implode(',', array_fill(0, count($codes), '?'));
    $out   = [];
    foreach (db_all(
        "SELECT item_code, value_num FROM d_daily_value WHERE hizuke = ? AND item_code IN ($ph) AND value_num IS NOT NULL",
        array_merge([$date], $codes)
    ) as $r) {
        $out[$r['item_code']] = (int)$r['value_num'];
    }
    return $out;
}

/**
 * 保存済みの値から照合欄を埋める。保存済みの日報を開き直したとき、
 * 紙の合計をもう一度打たなくても保存し直せるようにするため
 * （保存できた時点で、紙の合計と一致していたことは確かめてある）。
 */
function nippo_controls_from(array $v): array
{
    $c = [];
    foreach (['raiin' => 'gk', 'shinrai' => 'gkn'] as $t => $pre) {
        $all = 0;
        foreach (nippo_slots() as $s => $_) {
            $sum = 0;
            foreach (nippo_ka() as $ka => $_k) {
                $sum += $v["{$pre}_{$ka}_{$s}"] ?? 0;
            }
            $c["{$t}_{$s}"] = $sum;
            $all += $sum;
        }
        $c["{$t}_all"] = $all;
    }
    $kaigo = 0;
    foreach (array_keys(nippo_kaigo()) as $code) {
        $kaigo += $v[$code] ?? 0;
    }
    $c['kaigo_total']       = $kaigo;
    $c['iryou_kaigo_total'] = $c['raiin_all'] + $kaigo;
    foreach (nippo_wards() as $w => $_) {
        $c["zenjitsu_{$w}"] = ($v["byoto{$w}_zaiin"] ?? 0) - ($v["byoto{$w}_nyuin"] ?? 0) - ($v["byoto{$w}_tennyu"] ?? 0)
                            + ($v["byoto{$w}_taiin"] ?? 0) + ($v["byoto{$w}_tenshutsu"] ?? 0);
    }
    return $c;
}

/**
 * 日報の値を保存する。照合（nippo_check）を通ったあとに呼ぶ。
 *
 * - 外来の項目と、病棟・当直の項目を、1つのトランザクションで書く
 * - 書いた部署は提出状態を「下書き」にする（提出はそれぞれの部署が確かめてから行う）
 * - すでに提出・確定している部署の値は上書きしない。違う値があれば返す
 *
 * @return array ['saved' => 件数, 'depts' => [書いた部署], 'skipped' => [dept_id => [[item_code, 保存済み, 日報], ...]], 'warnings' => [...]]
 */
function nippo_save(string $date, array $v, string $userId): array
{
    $items  = all_items($date);
    $byDept = [];
    foreach (nippo_codes() as $code) {
        if (!isset($items[$code])) {
            continue;   // マスタに無い（有効期間外）。書かない
        }
        $byDept[$items[$code]['dept_id']][$code] = (string)$v[$code];
    }

    $saved   = 0;
    $written = [];
    $skipped = [];
    $warn    = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($byDept as $deptId => $vals) {
            $st = submission($date, $deptId)['status'] ?? 'none';
            if ($deptId !== 'gairai' && in_array($st, ['submitted', 'confirmed'], true)) {
                $cur = [];
                foreach (dept_entries($date, $deptId) as $code => $r) {
                    $cur[$code] = $r['value_num'] === null ? null : (int)$r['value_num'];
                }
                foreach ($vals as $code => $new) {
                    if (($cur[$code] ?? null) !== (int)$new) {
                        $skipped[$deptId][] = [$code, $cur[$code] ?? null, (int)$new];
                    }
                }
                continue;
            }
            $res = write_entries($date, input_items_of_dept($deptId, $date), $vals, $userId);
            touch_submission($date, $deptId, 'draft', $userId);
            $saved  += $res['saved'];
            $warn    = array_merge($warn, $res['warnings']);
            $written[] = $deptId;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('日報の保存に失敗: ' . $e->getMessage());
        throw $e;
    }
    return ['saved' => $saved, 'depts' => $written, 'skipped' => $skipped, 'warnings' => $warn];
}

/* ======================================================================
 * 外来日報のExcel（.xlsx）から読む
 *
 * 外来日報のExcelは、電子カルテの生データのシート（Sheet2）と、それを数式で
 * 並べ替えた紙の様式のシート（Sheet1）でできている。読むのは紙の様式のほう。
 * リハビリ・ドック・健診・介護・訪問診療科内訳などは医事課がそのシートに
 * 手で入れているので、紙と同じ数字がすべてそこにそろっている。
 *
 * セル番地は決め打ちせず、見出しの文字（「科名」「午前」「医科合計」など）で
 * 位置を探す。様式に行が足されても読めるようにするため。
 * 入院患者数日報は別のブック（nippo_nyuin_from_xlsx）。
 * ====================================================================== */

/** 見出しを比べるために揃える。空白を除き、全角英数を半角に、波ダッシュを1種類にする。 */
function nippo_norm(string $s): string
{
    $s = mb_convert_kana($s, 'as', 'UTF-8');
    $s = str_replace(['〜', '～', '∼'], '~', $s);
    return preg_replace('/\s+/u', '', $s);
}

/**
 * 日報のExcelを読み、転記画面の欄に入れる値を返す。
 *
 * @param array  $sheets xlsx_read() の戻り値
 * @param string $date   画面で選んでいる日付。ファイルの「9月 3日」と月日が違えば読まない
 * @return array ['v' => [item_code => int], 'c' => [照合欄 => int], 'errors' => [...], 'notes' => [...]]
 */
function nippo_gairai_from_xlsx(array $sheets, string $date): array
{
    $v = $c = $errors = $notes = [];
    $fail = fn(string $m) => ['v' => [], 'c' => [], 'errors' => [$m], 'notes' => []];

    // ---- 紙の様式のシートを探す（「科名」と「医科合計」があるシート） ----
    $grid = null;
    $sheetName = '';
    foreach ($sheets as $name => $cells) {
        $g = xlsx_grid($cells);
        $has = ['科名' => false, '医科合計' => false];
        foreach ($g as $row) {
            foreach ($row as $x) {
                $n = nippo_norm($x);
                if (isset($has[$n])) {
                    $has[$n] = true;
                }
            }
        }
        if ($has['科名'] && $has['医科合計']) {
            $grid = $g;
            $sheetName = (string)$name;
            break;
        }
    }
    if ($grid === null) {
        return $fail('日報の様式のシートが見つかりません（「科名」と「医科合計」のあるシートがありません）。日報のExcelか確かめてください。');
    }

    /** セルの文字（揃えたもの）。 */
    $txt = fn(int $r, int $col) => isset($grid[$r][$col]) ? nippo_norm($grid[$r][$col]) : '';
    /** 見出しの位置をすべて探す。$fromRow より下、$col を指定すればその列だけ。 */
    $find = function (string $label, int $fromRow = 0, ?int $col = null) use ($grid): array {
        $want = nippo_norm($label);
        $hits = [];
        foreach ($grid as $r => $row) {
            if ($r <= $fromRow) {
                continue;
            }
            foreach ($row as $cc => $x) {
                if (($col === null || $cc === $col) && nippo_norm($x) === $want) {
                    $hits[] = [$r, $cc];
                }
            }
        }
        return $hits;
    };
    /** 数値のセルを読む。空は0。0以上の整数でなければエラーに積んで null。 */
    $num = function (int $r, int $col, string $what) use ($grid, &$errors): ?int {
        $x = $grid[$r][$col] ?? '';
        if (trim($x) === '') {
            return 0;
        }
        if (!is_numeric($x) || (float)$x < 0 || floor((float)$x) != (float)$x) {
            $errors[] = "{$what}：数値として読めません（" . xlsx_colname($col) . "{$r}「{$x}」）";
            return null;
        }
        return (int)$x;
    };

    // ---- 日付（「＜ 9月 3日時間帯・…」） ----
    $md = null;
    foreach ($grid as $row) {
        foreach ($row as $x) {
            if (preg_match('/(\d{1,2})月(\d{1,2})日/u', nippo_norm($x), $m)) {
                $md = [(int)$m[1], (int)$m[2]];
                break 2;
            }
        }
    }
    if ($md === null) {
        return $fail('ファイルの中に日報の日付（「9月 3日」のような表記）が見つかりません。');
    }
    [$wantM, $wantD] = [(int)substr($date, 5, 2), (int)substr($date, 8, 2)];
    if ($md !== [$wantM, $wantD]) {
        return $fail("このファイルは {$md[0]}月{$md[1]}日 の日報です。画面の日付（{$wantM}月{$wantD}日）と違うので読み込みませんでした。"
            . '日付を合わせてからもう一度読み込んでください。');
    }

    // ---- 「科名」の行と、時間帯の列 ----
    $head = $find('科名');
    [$hr, $labelCol] = $head[0];
    $slotOf = ['00~08' => 's0008', '午前' => 'am', '午後' => 'pm', '夜間' => 'night', '20~24' => 's2024', '合計' => 'total'];
    $groups = [];
    $cur    = [];
    foreach ($grid[$hr] as $col => $x) {
        $k = $slotOf[nippo_norm($x)] ?? null;
        if ($k === null) {
            continue;
        }
        if (isset($cur[$k])) {          // 同じ見出しがもう一度出たら、新来の表が始まった
            $groups[] = $cur;
            $cur = [];
        }
        $cur[$k] = $col;
    }
    if ($cur) {
        $groups[] = $cur;
    }
    foreach (['来院数' => 0, '新来数' => 1] as $label => $i) {
        foreach (nippo_slots() as $s => $sname) {
            if (!isset($groups[$i][$s])) {
                return $fail("{$label}の表に「{$sname}」の列が見つかりません。日報の様式が変わっていないか確かめてください。");
            }
        }
    }
    [$rc, $sc] = $groups;   // 来院・新来の列

    // ---- 医科合計の行 ----
    $sumHits = $find('医科合計', $hr, $labelCol);
    if (!$sumHits) {
        return $fail('「医科合計」の行が見つかりません。');
    }
    $sumRow = $sumHits[0][0];

    // ---- 科ごとの行 ----
    $kaByLabel = [];
    foreach (nippo_ka() as $ka => $kname) {
        $kaByLabel[nippo_norm($kname)] = $ka;
    }
    $kaByLabel[nippo_norm('内科')] = 'naika';
    $seen = [];
    for ($r = $hr + 1; $r < $sumRow; $r++) {
        $label = $txt($r, $labelCol);
        $ka    = $kaByLabel[$label] ?? null;
        if ($ka === null) {
            // 表に無い科（空行も含む）。値が入っていたら黙って捨てずに止める
            foreach ([$rc, $sc] as $cols) {
                foreach (nippo_slots() as $s => $_) {
                    $x = $grid[$r][$cols[$s]] ?? '';
                    if (is_numeric($x) && (float)$x != 0.0) {
                        $errors[] = '「' . ($grid[$r][$labelCol] ?? '（科名なし）') . "」の行（{$r}行目）に人数が入っていますが、"
                            . 'この科は取り込み先がありません。医事課・管理者に相談してください。';
                        continue 3;
                    }
                }
            }
            continue;
        }
        $seen[$ka] = true;
        $kname = nippo_ka()[$ka];
        foreach ([['gk', $rc, 'raiin', '来院'], ['gkn', $sc, 'shinrai', '新来']] as [$pre, $cols, $t, $tl]) {
            foreach (nippo_slots() as $s => $sname) {
                $n = $num($r, $cols[$s], "{$tl} {$kname} {$sname}");
                if ($n !== null) {
                    $v["{$pre}_{$ka}_{$s}"] = $n;
                }
            }
            if (isset($cols['total'])) {
                $n = $num($r, $cols['total'], "{$tl} {$kname} 合計");
                if ($n !== null) {
                    $c["{$t}_row_{$ka}"] = $n;
                }
            }
        }
    }
    foreach (nippo_ka() as $ka => $kname) {
        if (!isset($seen[$ka])) {
            $errors[] = "「{$kname}」の行が見つかりません。";
        }
    }

    // 医科合計 → 照合欄
    foreach ([['raiin', $rc, '来院'], ['shinrai', $sc, '新来']] as [$t, $cols, $tl]) {
        foreach (nippo_slots() as $s => $sname) {
            $n = $num($sumRow, $cols[$s], "{$tl} 医科合計 {$sname}");
            if ($n !== null) {
                $c["{$t}_{$s}"] = $n;
            }
        }
        if (isset($cols['total'])) {
            $n = $num($sumRow, $cols['total'], "{$tl} 医科合計 合計");
            if ($n !== null) {
                $c["{$t}_all"] = $n;
            }
        }
    }

    // ---- 再掲（医科合計より下の、科名の列にある見出し） ----
    foreach (nippo_saikei() as $k => $kname) {
        $hit = $find($kname, $sumRow, $labelCol);
        if (!$hit) {
            $errors[] = "再掲の「{$kname}」の行が見つかりません。";
            continue;
        }
        foreach (nippo_slots() as $s => $sname) {
            $n = $num($hit[0][0], $rc[$s], "再掲 {$kname} {$sname}");
            if ($n !== null) {
                $v["gr_{$k}_{$s}"] = $n;
            }
        }
    }

    // ---- 健診科新患内訳（「※健診科新患内訳」の列の「ドック」「健診」の1つ下） ----
    $uchiCol = null;
    foreach ($grid as $r => $row) {
        foreach ($row as $col => $x) {
            if (mb_strpos(nippo_norm($x), '健診科新患内訳') !== false) {
                [$uchiRow, $uchiCol] = [$r, $col];
                break 2;
            }
        }
    }
    if ($uchiCol === null) {
        $errors[] = '「健診科新患内訳」が見つかりません。';
    } else {
        foreach (['ドック' => 'gkn_uchi_dock', '健診' => 'gkn_uchi_kenshin'] as $label => $code) {
            $hit = $find($label, $uchiRow, $uchiCol);
            if (!$hit) {
                $errors[] = "健診科新患内訳の「{$label}」が見つかりません。";
                continue;
            }
            $n = $num($hit[0][0] + 1, $uchiCol, "健診科新患内訳 {$label}");
            if ($n !== null) {
                $v[$code] = $n;
            }
        }
    }

    // ---- 介護（見出しの1つ下）、介護合計 ----
    foreach (nippo_kaigo() + ['c:kaigo_total' => '介護合計'] as $code => $label) {
        $hit = $find($label, $sumRow);
        if (!$hit) {
            $errors[] = "介護の「{$label}」が見つかりません。";
            continue;
        }
        $n = $num($hit[0][0] + 1, $hit[0][1], $label);
        if ($n === null) {
            continue;
        }
        if (strpos($code, 'c:') === 0) {
            $c[substr($code, 2)] = $n;
        } else {
            $v[$code] = $n;
        }
    }

    // ---- 医療介護合計（同じ行の右にある数値） ----
    $hit = $find('医療介護合計', $sumRow);
    if (!$hit) {
        $errors[] = '「医療介護合計」が見つかりません。';
    } else {
        [$r, $col] = $hit[0];
        foreach ($grid[$r] as $cc => $x) {
            if ($cc > $col && is_numeric($x)) {
                $n = $num($r, $cc, '医療介護合計');
                if ($n !== null) {
                    $c['iryou_kaigo_total'] = $n;
                }
                break;
            }
        }
        if (!isset($c['iryou_kaigo_total'])) {
            $errors[] = '「医療介護合計」の数値が見つかりません。';
        }
    }

    // ---- 訪問診療科内訳（見出しの右隣） ----
    foreach (nippo_houshin_uchi() as $code => $label) {
        $hit = $find($label, $sumRow);
        if (!$hit) {
            $errors[] = "訪問診療科内訳の「{$label}」が見つかりません。";
            continue;
        }
        $n = $num($hit[0][0], $hit[0][1] + 1, "訪問診療科内訳 {$label}");
        if ($n !== null) {
            $v[$code] = $n;
        }
    }

    if ($errors) {
        return ['v' => [], 'c' => [], 'errors' => $errors, 'notes' => []];
    }
    $notes[] = "外来日報（「{$sheetName}」シート）から " . count($v) . ' 欄を読み込みました。';
    return ['v' => $v, 'c' => $c, 'errors' => [], 'notes' => $notes];
}

/* ======================================================================
 * 入院患者数日報のExcel（.xlsm）から読む
 *
 * 入院日報は月1冊のブックで、シート「1」〜「31」が日ごとの日報
 * （ほかに MENU・目標・作業用）。日のシートは紙の下半分と同じ並び。
 * マクロ付き（.xlsm）だが、中身は .xlsx と同じ zip＋XML なので同じ方法で読める。
 * マクロ（vbaProject.bin）は開かないし動かさない。
 *
 * 【古い月のデータが残ったシートに注意】
 * まだ使っていない日のシートには前の月の数字が残っている（9月なのに「31」にまで数字がある）。
 * シートの日付は MENU の処理年月から自動で入るので、日付だけでは見分けられない。
 * そこで「前日のシートの本日患者数」と「この日の前日患者数」が合うかを確かめる。
 * ====================================================================== */

/** 入院の科別内訳の見出し（揃えた文字）→ 科コード。日のシートの見出しに合わせる。 */
function nippo_bka_labels(): array
{
    $out = [];
    foreach (nippo_bka() as $ka => $label) {
        $out[nippo_norm($label)] = $ka;
    }
    return $out;
}

/**
 * どちらの日報のブックかを中身で判定する。画面の欄を取り違えて選ばれても読めるように。
 * @return string|null 'gairai' / 'nyuin' / 分からなければ null
 */
function nippo_xlsx_kind(array $sheets): ?string
{
    $gairai = false;
    foreach ($sheets as $cells) {
        $has = [];
        foreach ($cells as $x) {
            $n = nippo_norm($x);
            if (mb_strpos($n, '入院患者数日報') !== false) {
                return 'nyuin';
            }
            if ($n === '科名' || $n === '医科合計') {
                $has[$n] = true;
            }
        }
        if (count($has) === 2) {
            $gairai = true;
        }
    }
    return $gairai ? 'gairai' : null;
}

/**
 * 入院日報のブックから、選んだ日のシートを読む。
 *
 * @return array ['v' => [item_code => int], 'c' => ['zenjitsu_3' => int, ...], 'errors' => [...], 'notes' => [...], 'warnings' => [...]]
 */
function nippo_nyuin_from_xlsx(array $sheets, string $date): array
{
    $fail = fn(string $m) => ['v' => [], 'c' => [], 'errors' => [$m], 'notes' => [], 'warnings' => []];
    $y = (int)substr($date, 0, 4);
    $m = (int)substr($date, 5, 2);
    $d = (int)substr($date, 8, 2);

    if (!isset($sheets[(string)$d])) {
        return $fail("入院日報のブックに「{$d}」日のシートがありません。");
    }
    $one = nippo_nyuin_sheet(xlsx_grid($sheets[(string)$d]), "{$d}日");
    if ($one['errors']) {
        return $fail(implode(' / ', $one['errors']));
    }
    if ($one['ym'] === null || $one['day'] === null) {
        return $fail("入院日報の「{$d}」日のシートに日付（「2026年9月」と日）が見つかりません。");
    }
    if ($one['ym'] !== [$y, $m] || $one['day'] !== $d) {
        return $fail("入院日報の「{$d}」日のシートは {$one['ym'][0]}年{$one['ym'][1]}月{$one['day']}日 になっています。"
            . "画面の日付（{$y}年{$m}月{$d}日）と違うので読み込みませんでした。MENU の処理年月を確かめてください。");
    }
    $v = $one['v'];
    $c = [];
    $honjitsu = 0;
    foreach (nippo_wards() as $w => $_) {
        $c["zenjitsu_{$w}"] = $one['zenjitsu'][$w];
        $honjitsu += $v["byoto{$w}_zaiin"];
    }
    if ($honjitsu === 0) {
        return $fail("入院日報の「{$d}」日のシートには、まだ本日患者数が入っていません。");
    }

    // 前日のシートとのつながり。古い月のデータが残ったシートを見分ける
    $warnings = [];
    if ($d > 1 && isset($sheets[(string)($d - 1)])) {
        $prev = nippo_nyuin_sheet(xlsx_grid($sheets[(string)($d - 1)]), ($d - 1) . '日');
        if (!$prev['errors']) {
            $bad = [];
            foreach (nippo_wards() as $w => $wname) {
                if ($prev['v']["byoto{$w}_zaiin"] !== $c["zenjitsu_{$w}"]) {
                    $bad[] = "{$wname} 前日 {$c["zenjitsu_{$w}"]} ／ {$prev['day']}日の本日 " . $prev['v']["byoto{$w}_zaiin"];
                }
            }
            if ($bad) {
                $warnings[] = "入院日報：この日の前日患者数が、前日（" . ($d - 1) . "日）のシートの本日患者数と合いません（"
                    . implode('、', $bad) . '）。前の月のデータが残ったシートかもしれません。数字を紙と見比べてください。';
            }
        }
    }

    return ['v' => $v, 'c' => $c, 'errors' => [],
            'notes' => ["入院日報（「{$d}」シート）から " . count($v) . ' 欄と前日患者数を読み込みました。'],
            'warnings' => $warnings];
}

/**
 * 入院日報の日のシートを1枚読む。
 * @return array ['v' => [...], 'zenjitsu' => [w => int], 'ym' => [年, 月]|null, 'day' => int|null, 'errors' => [...]]
 */
function nippo_nyuin_sheet(array $grid, string $what): array
{
    $errors = [];
    $out    = ['v' => [], 'zenjitsu' => [], 'ym' => null, 'day' => null, 'errors' => &$errors];
    $txt    = fn(int $r, int $col) => isset($grid[$r][$col]) ? nippo_norm($grid[$r][$col]) : '';
    $num    = function (int $r, int $col, string $label) use ($grid, &$errors, $what): int {
        $x = trim($grid[$r][$col] ?? '');
        if ($x === '') {
            return 0;
        }
        if (!is_numeric($x) || (float)$x < 0 || floor((float)$x) != (float)$x) {
            $errors[] = "{$what}のシート {$label}：数値として読めません（" . xlsx_colname($col) . "{$r}「{$x}」）";
            return 0;
        }
        return (int)$x;
    };

    // 日付：「2026年9月」のセルと、その右の最初の数値（日）
    foreach ($grid as $r => $row) {
        foreach ($row as $col => $x) {
            if (preg_match('/^(\d{4})年(\d{1,2})月$/u', nippo_norm($x), $mm)) {
                $out['ym'] = [(int)$mm[1], (int)$mm[2]];
                foreach ($row as $cc => $y) {
                    if ($cc > $col && is_numeric($y)) {
                        $out['day'] = (int)$y;
                        break;
                    }
                }
                break 2;
            }
        }
    }

    // 見出し行：「病棟」が2つある行。1つ目＝入退院情報、2つ目＝科別内訳
    $hr = $idouCol = $kaCol = null;
    foreach ($grid as $r => $row) {
        $cols = [];
        foreach ($row as $col => $x) {
            if (nippo_norm($x) === '病棟') {
                $cols[] = $col;
            }
        }
        if (count($cols) >= 2) {
            [$hr, $idouCol, $kaCol] = [$r, $cols[0], $cols[1]];
            break;
        }
    }
    if ($hr === null) {
        $errors[] = "{$what}のシートに「病棟」の見出しが見つかりません。入院患者数日報のシートか確かめてください。";
        return $out;
    }

    // 入退院の列（見出し行とその下の行）
    $want = ['前日患者数' => 'zenjitsu', '入院' => 'nyuin', '転入' => 'tennyu', '退院' => 'taiin',
             '転出' => 'tenshutsu', '本日患者数' => 'zaiin'];
    $idou = [];
    foreach ([$hr, $hr + 1] as $r) {
        foreach ($grid[$r] ?? [] as $col => $x) {
            $k = $want[nippo_norm($x)] ?? null;
            if ($k !== null && $col > $idouCol && $col < $kaCol && !isset($idou[$k])) {
                $idou[$k] = $col;
            }
        }
    }
    foreach ($want as $label => $k) {
        if (!isset($idou[$k])) {
            $errors[] = "{$what}のシートに「{$label}」の列が見つかりません。";
        }
    }

    // 科別の列（見出し行の、2つ目の「病棟」より右）
    $bka  = nippo_bka_labels();
    $kaAt = [];
    $unknown = [];
    foreach ($grid[$hr] as $col => $x) {
        if ($col <= $kaCol) {
            continue;
        }
        $n = nippo_norm($x);
        if (isset($bka[$n])) {
            $kaAt[$bka[$n]] = $col;
        } elseif ($n !== '合計' && $n !== '') {
            $unknown[$col] = trim($x);
        }
    }
    foreach (nippo_bka() as $ka => $label) {
        if (!isset($kaAt[$ka])) {
            $errors[] = "{$what}のシートの科別内訳に「{$label}」の列が見つかりません。";
        }
    }
    if ($errors) {
        return $out;
    }

    // 病棟の行（3F・4F・5F）
    foreach (nippo_wards() as $w => $wname) {
        $row = null;
        foreach ($grid as $r => $_) {
            if ($r > $hr && $txt($r, $idouCol) === $wname) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            $errors[] = "{$what}のシートに「{$wname}」の行が見つかりません。";
            continue;
        }
        foreach ($idou as $k => $col) {
            $n = $num($row, $col, "{$wname} " . array_search($k, $want, true));
            if ($k === 'zenjitsu') {
                $out['zenjitsu'][$w] = $n;
            } else {
                $out['v']["byoto{$w}_{$k}"] = $n;
            }
        }
        // 科別は同じ行の、科別側の「病棟」列に同じ病棟名がある前提。違えば探し直す
        $kaRow = $txt($row, $kaCol) === $wname ? $row : null;
        if ($kaRow === null) {
            foreach ($grid as $r => $_) {
                if ($r > $hr && $txt($r, $kaCol) === $wname) {
                    $kaRow = $r;
                    break;
                }
            }
        }
        if ($kaRow === null) {
            $errors[] = "{$what}のシートの科別内訳に「{$wname}」の行が見つかりません。";
            continue;
        }
        foreach ($kaAt as $ka => $col) {
            $out['v']["bk{$w}_{$ka}"] = $num($kaRow, $col, "{$wname} " . nippo_bka()[$ka]);
        }
        foreach ($unknown as $col => $label) {
            $x = $grid[$kaRow][$col] ?? '';
            if (is_numeric($x) && (float)$x != 0.0) {
                $errors[] = "{$what}のシートの科別内訳「{$label}」（{$wname}）に人数がありますが、取り込み先がありません。医事課・管理者に相談してください。";
            }
        }
    }
    return $out;
}
