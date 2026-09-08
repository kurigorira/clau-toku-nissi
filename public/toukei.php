<?php
/**
 * 患者数統計表（月報）①〜⑤。
 *
 * 現行Excelの帳票層に相当する。ただし現行の `②③患者数統計表` は
 * 入力層の誤った列を参照しており、検体検査やエコー類が
 * 集計層（各種業務量他総括）と食い違っていた。
 * ここでは入力層から直接導出するので、その食い違いは起きない。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/report.php';

$user = require_login();
if (!is_ijika($user)) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('患者数統計表は医事課と管理者のみ閲覧できます。', 'error');
    page_footer();
    exit;
}

$month = valid_month($_GET['month'] ?? null);
$sheet = $_GET['sheet'] ?? '2';
$from  = $month . '-01';
$to    = date('Y-m-t', strtotime($from));

$sheets = [
    '1' => '① 外来数・営業活動',
    '2' => '② 特殊検査',
    '3' => '③ 検査・指導・リハビリ・救急',
    '4' => '④ 在宅（介護保険・医療保険）',
    '5' => '⑤ 透析',
];
if (!isset($sheets[$sheet])) {
    $sheet = '2';
}

page_header('患者数統計表　' . $month, $user);
?>
<form method="get" class="datebar noprint">
  <label>対象月 <input type="month" name="month" value="<?= h($month) ?>"></label>
  <input type="hidden" name="sheet" value="<?= h($sheet) ?>">
  <button type="submit">表示</button>
  <a href="export.php?type=toukei&amp;sheet=<?= h($sheet) ?>&amp;month=<?= h($month) ?>">CSV出力</a>
</form>

<nav class="tabs noprint">
  <?php foreach ($sheets as $k => $label): ?>
    <a href="?month=<?= h($month) ?>&amp;sheet=<?= h($k) ?>"
       class="<?= $k === $sheet ? 'active' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php
if ($sheet === '1') {
    // ① は日別ではなく月報。保険種別ごとの延患者数と営業活動報告
    $v    = period_values($from, $to);
    $g    = fn(string $c) => $v[$c] ?? null;
    $iryou = [
        ['一般外来',                 'gairai_total'],
        ['（うち新患）',             'gairai_new_total'],
        ['訪問看護（医療保険適応分）','iryou_houkan'],
        ['人間ドック',               'dock_ninzu'],
        ['健康診断',                 'kenshin_ninzu'],
    ];
    $kaigo = [
        ['通所リハ',   'tsusho'],
        ['訪問看護',   'houkan_kaigo_disp'],
        ['訪問介護',   'houkai_total'],
    ];
    $v['houkan_kaigo_disp'] = ($g('kaigo_houkan_over30') ?? 0) + ($g('kaigo_houkan_under30') ?? 0);
    $eigyou = [
        ['病院訪問',   'renkei_byoin'],
        ['その他訪問', 'renkei_other'],
        ['健康講座',   'renkei_kouza'],
        ['相談件数',   'renkei_soudan'],
    ];
    ?>
    <h2>１．外来数の報告</h2>
    <table class="report">
      <tr><th>保険種別</th><th>外来種別</th><th>のべ患者数</th></tr>
      <?php $first = true; foreach ($iryou as [$lab, $c]): ?>
        <tr><?php if ($first): ?><th rowspan="<?= count($iryou) ?>">医療</th><?php $first = false; endif; ?>
          <th><?= h($lab) ?></th><td class="n"><?= num($v[$c] ?? null) ?></td></tr>
      <?php endforeach; ?>
      <?php $first = true; foreach ($kaigo as [$lab, $c]): ?>
        <tr><?php if ($first): ?><th rowspan="<?= count($kaigo) + 1 ?>">介護</th><?php $first = false; endif; ?>
          <th><?= h($lab) ?></th><td class="n"><?= num($v[$c] ?? null) ?></td></tr>
      <?php endforeach; ?>
      <tr><th>介護合計</th><td class="n"><?= num($g('kaigo_shoukei')) ?></td></tr>
    </table>

    <h2>４．営業活動報告</h2>
    <table class="report narrow">
      <tr><th>項目</th><th>当月</th></tr>
      <?php foreach ($eigyou as [$lab, $c]): ?>
        <tr><th><?= h($lab) ?></th><td class="n"><?= num($v[$c] ?? null) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p class="note">
      現行Excelの「マーケティング件数／消防署訪問数／病院訪問数」は、病院日誌の医療連携欄と
      項目が一致していません。当面は日誌側の4項目を表示しています（要確認）。
    </p>
    <?php
} else {
    $specs = [
      '2' => [
        ['group' => 'EGD',            'cols' => cols_nyuin_gairai('egd')],
        ['group' => 'CF（CS）',       'cols' => cols_nyuin_gairai('cf')],
        ['group' => 'その他（BF等）', 'cols' => cols_nyuin_gairai('naishikyo_other', false)],
        ['group' => '心エコー',       'cols' => cols_nyuin_gairai('shin_echo', false)],
        ['group' => '胸・腹部エコー', 'cols' => cols_nyuin_gairai('fuku_echo', false)],
        ['group' => '医師エコー',     'cols' => cols_nyuin_gairai('ishi_echo', false)],
        ['group' => 'CT',             'cols' => cols_nyuin_gairai('ct', false)],
        ['group' => 'MRI',            'cols' => cols_nyuin_gairai('mri', false)],
        ['group' => '血管造影',       'cols' => cols_nyuin_gairai('cag', false)],
        ['group' => 'マンモ',         'cols' => cols_nyuin_gairai('mammo', false)],
        ['group' => '一般単純撮影',   'cols' => cols_nyuin_gairai('ippan', false)],
        ['group' => '血管造影手術',   'cols' => [['label' => '件数', 'code' => 'cag_ope']]],
      ],
      '3' => [
        ['group' => '検体検査',   'cols' => cols_nyuin_gairai('kentai')],
        ['group' => 'SAS',        'cols' => [['label' => '件数', 'code' => 'sas']]],
        ['group' => '栄養指導',   'cols' => [
            ['label' => '①入院', 'code' => 'eiyou_nyuin'],
            ['label' => '②外来', 'code' => 'eiyou_gairai'],
            ['label' => '③①+②', 'code' => 'eiyou_sum12'],
            ['label' => '④その他', 'code' => 'eiyou_other'],
            ['label' => '⑤本部報告', 'code' => 'eiyou_sum34'],
            ['label' => '累計', 'code' => 'eiyou_sum34', 'cum' => true],
        ]],
        ['group' => '服薬指導',   'cols' => cols_touji('fukuyaku')],
        ['group' => '調剤（入院）', 'cols' => cols_touji('chouzai_nyuin')],
        ['group' => '調剤（外来）', 'cols' => cols_touji('chouzai_gairai')],
        ['group' => '理学療法',   'cols' => cols_nyuin_gairai('pt')],
        ['group' => '作業療法',   'cols' => cols_nyuin_gairai('ot')],
        ['group' => '言語療法',   'cols' => cols_nyuin_gairai('st')],
        ['group' => '救急搬入',   'cols' => [
            ['label' => '件数', 'code' => 'qq_kensu'],
            ['label' => '累計', 'code' => 'qq_kensu', 'cum' => true],
            ['label' => '患者数', 'code' => 'qq_kanja'],
            ['label' => '累計', 'code' => 'qq_kanja', 'cum' => true],
            ['label' => '入院数', 'code' => 'qq_nyuin'],
            ['label' => '累計', 'code' => 'qq_nyuin', 'cum' => true],
            ['label' => '入院率', 'code' => 'qq_nyuinritsu'],
        ]],
      ],
      '4' => [
        ['group' => '通所リハ（Ａ）', 'cols' => cols_touji('tsusho')],
        ['group' => '介護保険（Ｂ）', 'cols' => [
            ['label' => '訪看30分以上', 'code' => 'kaigo_houkan_over30'],
            ['label' => '訪看30分以内', 'code' => 'kaigo_houkan_under30'],
            ['label' => '訪介30分以上', 'code' => 'kaigo_houkai_over30'],
            ['label' => '訪介30分以内', 'code' => 'kaigo_houkai_under30'],
            ['label' => '居宅指導',     'code' => 'kaigo_kyotaku'],
            ['label' => '訪問栄養',     'code' => 'kaigo_houeiyou'],
            ['label' => '訪問リハ',     'code' => 'kaigo_hourehab'],
            ['label' => '訪問薬剤',     'code' => 'kaigo_houyaku'],
            ['label' => '小計',         'code' => 'kaigo_shoukei'],
            ['label' => '累計',         'code' => 'kaigo_shoukei', 'cum' => true],
        ]],
        ['group' => '医療保険', 'cols' => [
            ['label' => '訪問看護',   'code' => 'iryou_houkan'],
            ['label' => '訪問診療',   'code' => 'iryou_houshin'],
            ['label' => '往診',       'code' => 'iryou_oushin'],
            ['label' => '訪問栄養',   'code' => 'iryou_houeiyou'],
            ['label' => '訪リハ実人数', 'code' => 'iryou_hourehab_jin'],
            ['label' => '訪リハ単位数', 'code' => 'iryou_hourehab_tani'],
            ['label' => '訪問薬剤',   'code' => 'iryou_houyaku'],
            ['label' => '小計',       'code' => 'iryou_shoukei'],
            ['label' => '累計',       'code' => 'iryou_shoukei', 'cum' => true],
        ]],
      ],
      '5' => [
        ['group' => '透析件数', 'cols' => cols_nyuin_gairai('touseki')],
        ['group' => '透析登録人数', 'cols' => [
            ['label' => '登録人数', 'code' => 'touseki_touroku'],
            ['label' => '新規登録', 'code' => 'touseki_shinki'],
            ['label' => '登録抹消', 'code' => 'touseki_masshou'],
        ]],
      ],
    ];
    echo '<h2>' . h($sheets[$sheet]) . '</h2>';
    render_daily_table($from, $to, $specs[$sheet]);
    if ($sheet === '5') {
        echo '<p class="note">透析登録人数は残高（ストック）のため、月計は最終日の値を表示します。</p>';
    }
}
page_footer();
