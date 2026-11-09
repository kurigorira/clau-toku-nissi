<?php
/**
 * .xlsx の最小限の読み取り。電子カルテ日報のExcelを取り込むために使う。
 *
 * 外部ライブラリは使わない（Composer を入れない方針のため）。
 * .xlsx は zip の中に XML が入っているだけなので、ZipArchive と SimpleXML で読める。
 * 数式のセルは、Excel が保存したときの計算結果（キャッシュ値）を読む。
 *
 * 読めないもの（ここでは扱わない）:
 *   - .xls（Excel 97-2003 形式）… 中身がまったく別の形式。Excelで .xlsx として保存し直してもらう
 *   - 書式・結合セル・図形 … 値だけを読む
 */

/** 受け付けるファイルの大きさの上限。日報は30KBほど。 */
const XLSX_MAX_FILE = 5 * 1024 * 1024;
/** zip の中の1つのXMLを展開したときの上限。小さな zip が巨大に膨らむ細工（zip爆弾）を防ぐ。 */
const XLSX_MAX_PART = 20 * 1024 * 1024;

const XLSX_NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const XLSX_NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

/**
 * .xlsx を読み、シート名 => ['A1' => 値, ...] を返す。
 * 値は文字列（数値も文字列のまま。呼び出し側で数値にする）。空のセルは含めない。
 *
 * 読めないときは RuntimeException。メッセージはそのまま画面に出せる文にしてある。
 */
function xlsx_read(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('このサーバのPHPでは zip 拡張が無効なため、Excelを読めません。'
            . 'php.ini の extension=zip を有効にして Apache を再起動してください。');
    }
    $size = @filesize($path);
    if ($size === false || $size === 0) {
        throw new RuntimeException('ファイルが空です。');
    }
    if ($size > XLSX_MAX_FILE) {
        throw new RuntimeException('ファイルが大きすぎます（5MBまで）。日報のExcelか確かめてください。');
    }
    $head = (string)file_get_contents($path, false, null, 0, 8);
    if (strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0) {
        throw new RuntimeException('古い形式のExcel（.xls）です。Excelで開き、「名前を付けて保存」で'
            . '「Excel ブック（*.xlsx）」を選んで保存し直してから読み込んでください。');
    }
    if (strncmp($head, "PK", 2) !== 0) {
        throw new RuntimeException('Excelのファイル（.xlsx）ではありません。');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excelのファイルが壊れているため開けません。');
    }
    try {
        $wb = xlsx_xml($zip, 'xl/workbook.xml');
        if ($wb === null) {
            throw new RuntimeException('Excelのファイル（.xlsx）ではありません（ブックの情報がありません）。');
        }
        $rels   = xlsx_xml($zip, 'xl/_rels/workbook.xml.rels');
        $shared = xlsx_shared_strings(xlsx_xml($zip, 'xl/sharedStrings.xml'));

        // シートの r:id → zip の中のパス
        $target = [];
        if ($rels !== null) {
            // children() で名前空間を指定した要素では、名前空間の無い属性は attributes() で読む
            foreach ($rels->children(XLSX_NS_PKG)->Relationship as $r) {
                $t = (string)$r->attributes()['Target'];
                // 「/xl/worksheets/sheet1.xml」（絶対）と「worksheets/sheet1.xml」（xl/ からの相対）の両方がある
                $target[(string)$r->attributes()['Id']] = ($t !== '' && $t[0] === '/') ? ltrim($t, '/') : 'xl/' . $t;
            }
        }

        $out = [];
        foreach ($wb->children(XLSX_NS_MAIN)->sheets->sheet as $sh) {
            $name = (string)$sh->attributes()['name'];
            $rid  = (string)$sh->attributes(XLSX_NS_REL)['id'];
            if (!isset($target[$rid])) {
                continue;
            }
            $xml = xlsx_xml($zip, $target[$rid]);
            $out[$name] = $xml === null ? [] : xlsx_cells($xml, $shared);
        }
        return $out;
    } finally {
        $zip->close();
    }
}

/** zip の中のXMLを1つ読む。無ければ null。 */
function xlsx_xml(ZipArchive $zip, string $name): ?SimpleXMLElement
{
    $st = $zip->statName($name);
    if ($st === false) {
        return null;
    }
    if ($st['size'] > XLSX_MAX_PART) {
        throw new RuntimeException('Excelの中身が大きすぎます。日報のExcelか確かめてください。');
    }
    $s = $zip->getFromName($name);
    if ($s === false) {
        throw new RuntimeException('Excelのファイルが壊れているため読めません。');
    }
    // Excelが書くXMLに DOCTYPE は無い。あれば細工されたファイルとして読まない（実体参照の膨張を防ぐ）
    if (stripos($s, '<!DOCTYPE') !== false) {
        throw new RuntimeException('Excelのファイルとして正しくない内容が含まれています。');
    }
    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        throw new RuntimeException('Excelのファイルが壊れているため読めません。');
    }
    return $xml;
}

/**
 * 共有文字列の一覧。
 * 日本語のExcelはセルにフリガナ（<rPh>）を持っていることがある（「午前」に「ゴゼン」）。
 * フリガナまで読むと「午前ゴゼン」になって見出しが一致しないので、本文の <t> だけを読む。
 */
function xlsx_shared_strings(?SimpleXMLElement $xml): array
{
    if ($xml === null) {
        return [];
    }
    $out = [];
    foreach ($xml->children(XLSX_NS_MAIN)->si as $si) {
        $out[] = xlsx_text($si);
    }
    return $out;
}

/** <si> や <is> の本文。直下の <t> か、書式付きの <r><t> を連結する（<rPh> は読まない）。 */
function xlsx_text(SimpleXMLElement $node): string
{
    $n = $node->children(XLSX_NS_MAIN);
    if (isset($n->t)) {
        return (string)$n->t;
    }
    $s = '';
    foreach ($n->r as $r) {
        $s .= (string)$r->children(XLSX_NS_MAIN)->t;
    }
    return $s;
}

/** シートのセルを 'A1' => 値 で返す。 */
function xlsx_cells(SimpleXMLElement $sheet, array $shared): array
{
    $out  = [];
    $data = $sheet->children(XLSX_NS_MAIN)->sheetData;
    if (!$data) {
        return [];
    }
    foreach ($data->row as $row) {
        foreach ($row->children(XLSX_NS_MAIN)->c as $c) {
            $ref = (string)$c->attributes()['r'];
            $t   = (string)$c->attributes()['t'];
            $ch  = $c->children(XLSX_NS_MAIN);
            if ($t === 'inlineStr') {
                $v = isset($ch->is) ? xlsx_text($ch->is) : '';
            } elseif (!isset($ch->v)) {
                continue;               // 値の無いセル（書式だけ）
            } elseif ($t === 's') {
                $v = $shared[(int)$ch->v] ?? '';
            } else {
                $v = (string)$ch->v;    // 数値・数式の結果（str）・真偽（b）・エラー（e）
            }
            if ($ref !== '' && $v !== '') {
                $out[$ref] = $v;
            }
        }
    }
    return $out;
}

/** 'AB12' → [12, 28]（行, 列。列は A=1）。 */
function xlsx_ref(string $ref): array
{
    preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
    $col = 0;
    foreach (str_split($m[1] ?? 'A') as $ch) {
        $col = $col * 26 + (ord($ch) - 64);
    }
    return [(int)($m[2] ?? 0), $col];
}

/** シートを $grid[行][列] = 値 の形にする。見出しの位置から探すときに使う。 */
function xlsx_grid(array $cells): array
{
    $g = [];
    foreach ($cells as $ref => $v) {
        [$r, $c] = xlsx_ref($ref);
        $g[$r][$c] = $v;
    }
    ksort($g);
    foreach ($g as &$row) {
        ksort($row);
    }
    return $g;
}

/** 列番号 → 列名（28 → AB）。エラーの場所を示すのに使う。 */
function xlsx_colname(int $col): string
{
    $s = '';
    while ($col > 0) {
        $m = ($col - 1) % 26;
        $s = chr(65 + $m) . $s;
        $col = intdiv($col - 1, 26);
    }
    return $s;
}
