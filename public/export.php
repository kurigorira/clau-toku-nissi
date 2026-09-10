<?php
/**
 * CSV出力。
 *
 * UTF-8 BOM付きで出す。BOMが無いとExcelがShift_JISと誤認して文字化けするため。
 * 改行はCRLF（Excelの既定に合わせる）。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/report.php';
require_once __DIR__ . '/../src/repository.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    exit('CSV出力は医事課と管理者のみ利用できます。');
}

$type  = $_GET['type'] ?? 'daily';
$month = valid_month($_GET['month'] ?? null);
$from  = $month . '-01';
$to    = date('Y-m-t', strtotime($from));

/** ファイル名を安全にする（改行やパス区切りを混ぜられないようにする）。 */
function safe_filename(string $s): string
{
    return preg_replace('/[^\w\-.]/u', '_', $s);
}

/** CSVとして出力して終了する。 */
function send_csv(string $name, array $rows): void
{
    $name = safe_filename($name) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $fp = fopen('php://output', 'w');
    fwrite($fp, "\xEF\xBB\xBF");           // Excel向けのBOM
    foreach ($rows as $r) {
        fputcsv($fp, $r, ',', '"', '', "\r\n");
    }
    fclose($fp);
    exit;
}

$rows = [];

switch ($type) {

    // 日次の全項目。month で指定した月の 日付×項目 を縦に並べる。
    // 監査や外部への受け渡しに使う、いちばん素の形。
    case 'daily':
        $items = all_items($to);
        $rows[] = ['日付', '曜日', '部署', '項目コード', '項目名', '区分', '値', '単位',
                   '入力者', '入力日時', '更新者', '更新日時'];
        $depts = all_depts(false);
        foreach (db_all(
            'SELECT v.*, i.item_name, i.dept_id, i.unit, i.value_type
               FROM d_daily_value v JOIN m_item i ON i.item_code = v.item_code
              WHERE v.hizuke BETWEEN ? AND ?
              ORDER BY v.hizuke, i.sort_no', [$from, $to]
        ) as $r) {
            $isText = in_array($r['value_type'], ['text', 'multiline'], true);
            $rows[] = [
                $r['hizuke'], youbi($r['hizuke']),
                $depts[$r['dept_id']]['dept_name'] ?? $r['dept_id'],
                $r['item_code'], $r['item_name'], '入力',
                $isText ? $r['value_text'] : rtrim(rtrim((string)$r['value_num'], '0'), '.'),
                $r['unit'], $r['created_by'], $r['created_at'], $r['updated_by'], $r['updated_at'],
            ];
        }
        send_csv("日次実績_{$month}", $rows);

    // 各種業務量他総括。画面と同じ数字をそのまま出す
    case 'soukatsu':
        $v    = period_values($from, $to);
        $days = period_days($from, $to);
        $fy   = fiscal_year($to);
        $rows[] = ['各種業務量他総括', $month, "実日数{$days}日"];
        $rows[] = [];
        $rows[] = ['病棟', '定床', '延患者数', '算定対象延患者数', '新入院', '退院', '転入', '転出',
                   '1日平均患者数', '平均在院日数', '病床稼働率', '病床回転率'];
        foreach (['3' => '3階', '4' => '4階', '5' => '5階', 'all' => '総合計'] as $w => $label) {
            $beds = 0;
            foreach ($w === 'all' ? ['3', '4', '5'] : [$w] as $x) {
                $beds += (int)config_value("teisho_byoto{$x}", $to, 0);
            }
            $k = fn(string $s) => $w === 'all' ? "byoto_{$s}_all" : "byoto{$w}_{$s}";
            $rows[] = [
                $label, $beds,
                $v[$w === 'all' ? 'byoto_zaiin_all' : "byoto{$w}_zaiin"] ?? '',
                $v[$w === 'all' ? 'byoto_zaiin_total_all' : "byoto{$w}_zaiin_total"] ?? '',
                $v[$k('nyuin')] ?? '', $v[$k('taiin')] ?? '', $v[$k('tennyu')] ?? '', $v[$k('tenshutsu')] ?? '',
                round($v[$w === 'all' ? 'byoto_zaiin_avg_all' : "byoto{$w}_zaiin_avg"] ?? 0, 2),
                round($v[$w === 'all' ? 'byoto_avg_days_all' : "byoto{$w}_avg_days"] ?? 0, 2),
                round($v[$w === 'all' ? 'byoto_kadou_all' : "byoto{$w}_kadou"] ?? 0, 2),
                round($v[$w === 'all' ? 'byoto_kaiten_all' : "byoto{$w}_kaiten"] ?? 0, 2),
            ];
        }
        $rows[] = [];
        $rows[] = ['項目', '目標平均', '目標延数', '実績平均', '実績延数', '目標差平均', '目標差延数'];
        foreach (all_items($to) as $code => $it) {
            if ($it['dept_id'] === 'jimu' || $it['value_type'] !== 'int') {
                continue;
            }
            $act = $v[$code] ?? null;
            if ($act === null) {
                continue;
            }
            $avg = $act / $days;
            $tgt = target_value($code, $fy);
            $rows[] = [
                $it['item_name'], $tgt === null ? '' : round($tgt, 2),
                $tgt === null ? '' : round($tgt * $days, 1),
                round($avg, 2), $act,
                $tgt === null ? '' : round($avg - $tgt, 2),
                $tgt === null ? '' : round($act - $tgt * $days, 1),
            ];
        }
        send_csv("各種業務量他総括_{$month}", $rows);

    // 患者数統計表。画面のタブと同じ内容を 日付×項目 で出す
    case 'toukei':
        $sheet = $_GET['sheet'] ?? '2';
        $codes = [
            '2' => ['egd_nyuin','egd_gairai','egd_total','cf_nyuin','cf_gairai','cf_total',
                    'naishikyo_other_nyuin','naishikyo_other_gairai','shin_echo_nyuin','shin_echo_gairai',
                    'fuku_echo_nyuin','fuku_echo_gairai','ishi_echo_nyuin','ishi_echo_gairai',
                    'ct_nyuin','ct_gairai','mri_nyuin','mri_gairai','cag_nyuin','cag_gairai',
                    'mammo_nyuin','mammo_gairai','ippan_nyuin','ippan_gairai','cag_ope'],
            '3' => ['kentai_nyuin','kentai_gairai','kentai_total','sas','eiyou_nyuin','eiyou_gairai',
                    'eiyou_sum12','eiyou_other','eiyou_sum34','fukuyaku','chouzai_nyuin','chouzai_gairai',
                    'pt_nyuin','pt_gairai','pt_total','ot_nyuin','ot_gairai','ot_total',
                    'st_nyuin','st_gairai','st_total','qq_kensu','qq_kanja','qq_nyuin'],
            '4' => ['tsusho','kaigo_houkan_over30','kaigo_houkan_under30','kaigo_houkai_over30',
                    'kaigo_houkai_under30','kaigo_kyotaku','kaigo_houeiyou','kaigo_hourehab',
                    'kaigo_houyaku','kaigo_shoukei','iryou_houkan','iryou_houshin','iryou_oushin',
                    'iryou_houeiyou','iryou_hourehab_jin','iryou_hourehab_tani','iryou_houyaku','iryou_shoukei'],
            '5' => ['touseki_nyuin','touseki_gairai','touseki_total','touseki_touroku',
                    'touseki_shinki','touseki_masshou'],
        ];
        if (!isset($codes[$sheet])) {
            http_response_code(400);
            exit('シートの指定が不正です。');
        }
        $items = all_items($to);
        $head  = ['日', '曜'];
        foreach ($codes[$sheet] as $c) {
            $head[] = $items[$c]['item_name'] ?? $c;
        }
        $rows[] = $head;
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $v   = daily_values($d);
            $row = [(int)substr($d, 8, 2), youbi($d)];
            foreach ($codes[$sheet] as $c) {
                $row[] = $v[$c] ?? '';
            }
            $rows[] = $row;
        }
        $tot = period_values($from, $to);
        $row = ['月計', ''];
        foreach ($codes[$sheet] as $c) {
            $row[] = $tot[$c] ?? '';
        }
        $rows[] = $row;
        send_csv("患者数統計表{$sheet}_{$month}", $rows);

    case 'byoin_houkoku':
        $v    = period_values($from, $to);
        $last = daily_values($to);
        $rows[] = ['病院報告（患者票）', $month];
        $rows[] = ['都道府県', '長崎県', '保健所符号', config_value('hokenjo_fugou', $to, '')];
        $rows[] = ['市区町村', '長崎市', '整理番号', config_value('seiri_bangou', $to, '')];
        $rows[] = ['医療機関名', config_value('hospital_name', $to, '')];
        $rows[] = [];
        $rows[] = ['区分', '在院患者延数', '月末在院患者数', '新入院患者数', '退院患者数'];
        foreach (['総数', '一般病床'] as $label) {
            $rows[] = [$label, $v['byoto_zaiin_all'] ?? '', $last['byoto_zaiin_all'] ?? '',
                       $v['byoto_nyuin_all'] ?? '', $v['byoto_taiin_all'] ?? ''];
        }
        $rows[] = [];
        $g = $v['gairai_total'] ?? null;
        $k = $v['kaigo_shoukei'] ?? null;
        $rows[] = ['外来患者延数', $g === null ? '' : $g - (float)($k ?? 0)];
        $rows[] = ['　全外来患者数', $g ?? ''];
        $rows[] = ['　在宅患者数（介護保険）', $k ?? ''];
        send_csv("病院報告患者票_{$month}", $rows);

    // 変更履歴。訂正の追跡や、監査を求められたときに出す
    case 'audit':
        $items  = all_items();
        $rows[] = ['日付', '項目コード', '項目名', '操作', '変更前', '変更後', '実行者', '実行日時', 'IPアドレス'];
        foreach (db_all(
            'SELECT * FROM d_audit WHERE hizuke BETWEEN ? AND ? ORDER BY acted_at, audit_id',
            [$from, $to]
        ) as $r) {
            $rows[] = [$r['hizuke'], $r['item_code'], $items[$r['item_code']]['item_name'] ?? '',
                       ['insert' => '新規', 'update' => '訂正', 'delete' => '削除'][$r['action']] ?? $r['action'],
                       $r['old_value'], $r['new_value'], $r['acted_by'], $r['acted_at'], $r['client_ip']];
        }
        send_csv("変更履歴_{$month}", $rows);

    default:
        http_response_code(400);
        exit('出力の種類が不正です。');
}
