<?php
/**
 * 本部報告（徳洲会本部へ毎日出す「日報」）。並びは本部の入力画面のとおり。
 *
 * 行は [表示名, 値] の配列。値は次のどれか。
 *   'item_code'            その項目の値
 *   ['code1', 'code2', …]  足した値（どれも未入力なら空）
 *   Closure fn(array $v)   計算（$v は period_values の結果）
 *   null                   システムに無い項目。空欄で並べる
 * 3つ目の要素は飾り：'dec' => 小数の桁、'stock' => 登録数（累計の代わりに参考値「前日 ○」）、'sub' => 2段目の小見出し、
 * 'head' => 見出しだけの行
 */

require_once __DIR__ . '/calc.php';

/** 値を1つ求める。 */
function honbu_value($spec, array $v): ?float
{
    if ($spec === null) {
        return null;
    }
    if ($spec instanceof Closure) {
        return $spec($v);
    }
    if (is_array($spec)) {
        $s = null;
        foreach ($spec as $c) {
            if (($v[$c] ?? null) !== null) {
                $s = ($s ?? 0) + $v[$c];
            }
        }
        return $s;
    }
    return isset($v[$spec]) ? (float)$v[$spec] : null;
}

/** 訪問診療は、往診・居宅指導も含めて本部へ報告する（病院日誌の網かけと同じ）。 */
const HONBU_HOUSHIN = ['iryou_houshin', 'iryou_oushin', 'kaigo_kyotaku'];
const HONBU_HOUSONOTA = ['houeiyou_total', 'houyaku_total'];

/** 1列目：患者数（当日数・参考値・累計数）。 */
function honbu_patients(): array
{
    $zaitaku = array_merge(HONBU_HOUSHIN, ['houkan_total', 'houkai_total', 'hourehab_total'], HONBU_HOUSONOTA);
    return [
        ['外来（通リハ・院外健診除く）', 'total_ex_tsusho'],
        ['紹介数', 'shoukai'],
        ['外来新患', 'gairai_new_total'],
        ['初診料算定人数', 'shoshin'],
        ['外来がん化学療法', 'gairai_ganchemo'],
        ['通所リハ', 'tsusho'],
        ['入院', 'byoto_zaiin_all'],
        ['療養（再掲）', null],
        ['特定疾患（再掲）', null],
        ['救急件数', 'qq_kensu'],
        ['自病院救急※1（再掲）', 'qq_jibyoin'],
        ['新入院 合計', 'byoto_nyuin_all'],
        ['救急からの新入院', 'qq_nyuin'],
        ['外来からの新入院', null],
        ['紹介からの新入院', null],
        ['在宅登録人数', 'zaitaku_ninzu', ['stock' => true]],
        ['在宅新規登録（再掲）', 'zaitaku_shinki'],
        ['在宅サービス登録件数', 'reg_total', ['stock' => true]],
        ['在宅 合計', $zaitaku],
        ['訪問診療（再掲）', HONBU_HOUSHIN],
        ['訪問看護（再掲）', 'houkan_total'],
        ['訪問介護（再掲）', 'houkai_total'],
        ['訪問リハ（再掲）', 'hourehab_total'],
        ['訪問その他（再掲）', HONBU_HOUSONOTA],
        ['分娩件数', null],
        ['ドック', 'dock_ninzu', ['sub' => '人数']],
        ['', 'dock_kensu', ['sub' => '件数']],
        ['院内健診数', 'kenshin_ninzu', ['sub' => '人数']],
        ['', 'kenshin_kensu', ['sub' => '件数']],
        ['院外健診数', null, ['sub' => '人数']],
        ['', null, ['sub' => '件数']],
        ['透析登録数', 'touseki_touroku', ['stock' => true]],
        ['透析施行数（再掲）', 'touseki_total'],
        ['高気圧酸素数', null],
        ['（↑うち減圧症と空気塞栓）', null],
        ['がん 新登録', 'gan_touroku'],
    ];
}

/** 2列目の上：時間外ウォークイン。 */
function honbu_walkin(): array
{
    $rate = function (array $v): ?float {
        $t = $v['walkin_total'] ?? null;
        return $t === null || (float)$t === 0.0 ? ($t === null ? null : 0.0) : (float)($v['walkin_nyuin_total'] ?? 0) * 100 / $t;
    };
    return [
        ['区分1　0時-8時', 'walkin1'],
        ['　　内、入院', 'walkin1_nyuin'],
        ['区分2　8時-12時', 'walkin2'],
        ['　　内、入院', 'walkin2_nyuin'],
        ['区分3　12時-18時', 'walkin3'],
        ['　　内、入院', 'walkin3_nyuin'],
        ['区分4　18時-24時', 'walkin4'],
        ['　　内、入院', 'walkin4_nyuin'],
        ['時間外ウォークイン合計', 'walkin_total'],
        ['　　内、入院', 'walkin_nyuin_total'],
        ['時間外ウォークイン入院率', $rate, ['dec' => 1]],
    ];
}

/** 2列目の下：検査など。 */
function honbu_kensa(): array
{
    return [
        ['胃カメラ', 'egd_total'],
        ['大腸ファイバー', 'cf_total'],
        ['消化管内視鏡（再掲）', null],
        ['CT', 'ct_total'],
        ['PET-CT', null],
        ['MRI', 'mri_total'],
        ['RI', null],
        ['心カテ', null],
        ['PCI（再掲）', null],
        ['アブレーション（再掲）', null],
        ['SHD（再掲）', null],
        ['心エコー', 'shin_echo_total'],
        ['胸腹部エコー', 'fuku_echo_total'],
        ['医師エコー（旧その他エコー）', 'ishi_echo_total'],
        ['検体', 'kentai_total'],
        ['服薬指導', 'fukuyaku'],
        ['調剤', 'chouzai_gairai', ['sub' => '外来']],
        ['', 'chouzai_nyuin', ['sub' => '入院']],
        ['栄養指導', 'eiyou_sum34', ['dec' => 1]],
        ['PT単位', 'pt_total', ['dec' => 1]],
        ['OT単位', 'ot_total', ['dec' => 1]],
        ['ST単位', 'st_total', ['dec' => 1]],
    ];
}

/** 3列目の上：手術（手術室のみ）。 */
function honbu_ope(): array
{
    return [
        ['外科', 'ope_geka'],
        ['整形外科', 'ope_seikei'],
        ['産婦人科', null],
        ['泌尿器科', null],
        ['脳外科', 'ope_noge'],
        ['脳血管治療（再掲）', null],
        ['心外科', null],
        ['心外開胸（再掲）', null],
        ['眼科', null],
        ['耳鼻科', null],
        ['形成外科', 'ope_keisei'],
        ['歯科', null],
        ['その他', 'ope_other'],
        ['鏡視下手術（再掲）', 'ope_kyoushika'],
        ['シャント（再掲）', null, ['sub' => '設置']],
        ['', null, ['sub' => '血栓除去']],
        ['ダビンチ・ヒノトリ（再掲）', null],
        ['全身麻酔', 'ope_zenma'],
    ];
}

/**
 * 3列目の下：営業活動報告。健康講座の参加人数は院内・院外の区別なく入力するので、
 * その日に院外があれば院外、無ければ院内の参加人数として数える（$kouza は honbu_kouza_split の結果）。
 */
function honbu_eigyo(): array
{
    return [
        ['医療講演', null, ['head' => true]],
        ['講師', null, ['sub' => '医師']],
        ['', null, ['sub' => 'その他']],
        ['院内', 'kouza_innai', ['sub' => '回数']],
        ['', 'honbu_kouza_innai_ninzu', ['sub' => '参加人数']],
        ['院外（オンライン含む）', 'kouza_ingai', ['sub' => '回数']],
        ['', 'honbu_kouza_ingai_ninzu', ['sub' => '参加人数']],
        ['依頼（再掲）', null, ['sub' => '回数']],
        ['', null, ['sub' => '参加人数']],
        ['オンライン（再掲）', null, ['sub' => '回数']],
        ['', null, ['sub' => '参加人数']],
        ['医療機関 訪問回数', 'renkei_byoin'],
        ['消防署 訪問回数', 'soumu_houmon_shoubou'],
        ['企業団体 訪問回数', ['kenshin_houmon_kigyo', 'kenshin_houmon_dantai', 'soumu_houmon_kigyo', 'soumu_houmon_dantai']],
    ];
}

/**
 * 健康講座の参加人数を院内・院外に分ける（日ごと。院外があればその日の人数は院外）。
 * period_values の結果に honbu_kouza_innai_ninzu / honbu_kouza_ingai_ninzu を足して返す。
 */
function honbu_kouza_split(array $v, string $from, string $to): array
{
    $byDay = [];
    foreach (db_all("SELECT hizuke, item_code, value_num FROM d_daily_value
                      WHERE hizuke BETWEEN ? AND ? AND item_code IN ('kouza_ingai', 'kouza_ninzu') AND value_num IS NOT NULL",
                    [$from, $to]) as $r) {
        $byDay[$r['hizuke']][$r['item_code']] = (float)$r['value_num'];
    }
    foreach ($byDay as $d) {
        if (!isset($d['kouza_ninzu'])) {
            continue;
        }
        $k = ($d['kouza_ingai'] ?? 0) > 0 ? 'honbu_kouza_ingai_ninzu' : 'honbu_kouza_innai_ninzu';
        $v[$k] = ($v[$k] ?? 0) + $d['kouza_ninzu'];
    }
    return $v;
}

/** 2ページ目：放射線など。 */
function honbu_housha(): array
{
    return [
        ['一般単純撮影', 'ippan_total'],
        ['ESWL', null],
        ['リニアック', null],
        ['小線源', null],
        ['ノバリス', null],
        ['トモセラピー', null],
        ['血管造影', 'cag_total'],
        ['血管造影下手術（再掲）', 'cag_ope'],
    ];
}

/** COVID-19 関連報告：外来及び検査数・入院・退院。 */
function honbu_covid(): array
{
    $pcrG = ['corona_pcr_gairai_in', 'corona_pcr_gairai_out'];
    $pcrN = ['corona_pcr_nyuin_in', 'corona_pcr_nyuin_out'];
    return [
        'gairai' => [
            ['発熱外来受診者数', 'corona_hatsunetsu'],
            ['PCR検査依頼数（院内・院外）', $pcrG, ['sub' => '外来']],
            ['', $pcrN, ['sub' => '入院']],
            ['抗原検査数', 'corona_kougen_gairai', ['sub' => '外来']],
            ['', 'corona_kougen_nyuin', ['sub' => '入院']],
            ['検査計', array_merge($pcrG, $pcrN, ['corona_kougen_gairai', 'corona_kougen_nyuin'])],
            ['新規陽性者数（確定日）', 'corona_yousei_new'],
        ],
        'nyuin' => [
            ['疑似症者 入院数', 'corona_giji_nyuin'],
            ['陽性者 入院数', 'corona_yousei_nyuin'],
            ['ECMO使用者（再掲）', 'corona_ecmo'],
            ['人工呼吸器使用者（再掲）', 'corona_ventilator'],
            ['陽性者 新入院', 'corona_yousei_shin_nyuin'],
            ['疑似症者入院から陽性者入院数', null],
        ],
        'taiin' => [
            ['陽性者退院数 軽快', 'corona_taiin_keikai'],
            ['陽性者退院数 転院', 'corona_taiin_tenin'],
            ['陽性者退院数 死亡', 'corona_taiin_shibou'],
        ],
    ];
}

/** COVID-19 対応で行政に届けている病床数（システムに無いので空欄）。 */
function honbu_covid_beds(): array
{
    return ['陽性）確保病床数', '　内、入院数', '即応病床数', '　内、入院数', '疑似症）確保病床数', '　内、入院数', '休止病床数'];
}
