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
 * 電子カルテからCSV等で出せるようになったときは、同じ対応表と照合を使う
 * 取り込みツールを db/tools に足せばよい（画面と同じルールで検査される）。
 */

require_once __DIR__ . '/repository.php';

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
