<?php
/**
 * 病院日誌（日次）。現行 nissi.php に相当する。
 *
 * 配置は今までの紙の病院日誌（Excel様式・A4縦）に合わせる（表題と押印欄、外来・入院・手術・外来再掲分、
 * 特殊検査・他、会議・当直者・呼び出し、行事・人事、特記事項）。画面・印刷・PDF（nissi_pdf.php）で同じ見た目。
 *
 * 現行との違い:
 *  - 表示するだけで INSERT/UPDATE しない（現行は画面を開くだけで空行が作られていた）
 *  - CT・訪問看護・在院患者数計などは入力欄ではなく、入力層からの計算結果
 *  - 末尾に部署ごとの入力者・入力時刻を出す
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/calc.php';
require_once __DIR__ . '/../src/repository.php';

$user  = require_login();
$date  = valid_date($_GET['hizuke'] ?? null) ?? date('Y-m-d', strtotime('-1 day'));
$print = isset($_GET['print']);

$monthStart = substr($date, 0, 7) . '-01';
$v      = daily_values($date);                    // その日の値
$m      = period_values($monthStart, $date);      // 月初から当日までの累計
$t      = daily_texts($date);
$g      = fn(string $c) => $v[$c] ?? null;
$gm     = fn(string $c) => $m[$c] ?? null;
$tx     = fn(string $c) => $t[$c] ?? '';
$mdays  = period_days($monthStart, $date);

page_header('病　院　日　誌', $user, false);
// 印刷はいつでも A4縦・左とじ（print.css）。「印刷用」で開いたときは画面でも同じ見た目にする
echo '<link rel="stylesheet" href="' . h(asset_url('assets/print.css')) . '" media="' . ($print ? 'all' : 'print') . '">';

// 縦書きの見出し：writing-mode は古いChromeで崩れるので、1文字ずつ改行して並べる
$tate = fn(string $s) => implode('<br>', array_map('h', mb_str_split($s)));
// 数＋単位（値が無ければ単位だけ）
$nu = fn(?float $x, string $unit, int $dec = 0) => '<span class="v">' . num($x, $dec) . '</span><span class="u">' . h($unit) . '</span>';
$teisho = [];
foreach (['3', '4', '5'] as $w) {
    $teisho[$w] = config_value("teisho_byoto{$w}", $date, '');
}

// 特記事項・定数超過報告はログインした全員に出す（2026-10 栗原様の判断。誰が見たかは閲覧履歴に残る）
$tokki = db_all('SELECT * FROM d_tokki WHERE hizuke = ? AND deleted_at IS NULL ORDER BY sort_no, tokki_id', [$date]);
?>
<form method="get" class="datebar noprint">
  <label>日付 <input type="date" name="hizuke" value="<?= h($date) ?>"></label>
  <span class="youbi">（<?= h(youbi($date)) ?>）</span>
  <button type="submit">表示</button>
  <a href="nissi.php?hizuke=<?= h($date) ?>&amp;print=1" target="_blank">印刷用</a>
  <a href="nissi_pdf.php?hizuke=<?= h($date) ?>">PDFでダウンロード</a>
</form>

<?php /* 今までの紙の病院日誌（Excel様式）に合わせた配置。表組みだけで組む（古いChromeで動くよう flex・gap・JavaScript は使わない） */ ?>
<div class="nf">

<table class="nf-head">
  <tr>
    <td class="nf-titlecell">
      <div class="nf-title">病　院　日　誌</div>
      <div class="nf-date"><?= h(wareki($date)) ?>（<?= h(youbi($date)) ?>）　天候　<?= h($tx('tenkou')) ?></div>
    </td>
    <td class="nf-stampcell">
      <table class="nf-stamp">
        <tr><th>院　長</th><th>事務長</th><th>総務課</th><th>医事課</th><th>当直者</th></tr>
        <tr><td></td><td></td><td></td><td></td><td></td></tr>
      </table>
    </td>
  </tr>
</table>

<?php /* ---- 1段目：外来患者数｜入院患者数｜手術・術名・救急搬入｜外来再掲分 ---- */ ?>
<table class="nf-row">
  <tr>
    <td class="nf-c1">
      <table class="nf-t nf-gairai">
        <tr><th colspan="3" class="sec">外来患者数</th></tr>
        <tr><th>時間帯</th><th>患者数</th><th>新患</th></tr>
        <?php // 午前は医科の午前＋日報の介護合計、計・延数・平均は医療介護合計（介護を含む）。新患は医科のみ
        foreach ([['午　前', '8:00〜12:00', 'nissi_gairai_am', 'gairai_am_new'],
                  ['午　後', '12:00〜17:00', 'gairai_pm', 'gairai_pm_new'],
                  ['夜　間', '17:00〜20:00', 'gairai_night', 'gairai_night_new'],
                  ['時間外', '20:00〜24:00', 'gairai_ex1', 'gairai_ex1_new'],
                  ['時間外', '0:00〜8:00', 'gairai_ex2', 'gairai_ex2_new']] as [$lab, $time, $c, $cn]): ?>
          <tr class="tall"><th><?= h($lab) ?><br><small>(<?= h($time) ?>)</small></th>
            <td class="n"><?= num($g($c)) ?></td><td class="n"><?= num($g($cn)) ?></td></tr>
        <?php endforeach; ?>
        <tr class="tall"><th>計</th><td class="n"><?= num($g('iryou_kaigo_total')) ?></td><td class="n"><?= num($g('gairai_new_total')) ?></td></tr>
        <tr class="tall"><th>延　数</th><td class="n"><?= num($gm('iryou_kaigo_total')) ?></td><td class="n"><?= num($gm('gairai_new_total')) ?></td></tr>
        <tr class="tall"><th>平　均</th>
          <td class="n"><?= num($gm('iryou_kaigo_total') === null ? null : $gm('iryou_kaigo_total') / $mdays, 1) ?></td>
          <td class="n"><?= num($gm('gairai_new_total') === null ? null : $gm('gairai_new_total') / $mdays, 1) ?></td></tr>
      </table>
    </td>
    <td class="nf-c2">
      <table class="nf-t">
        <tr><th colspan="3" class="sec">入院患者数</th></tr>
        <tr><th>病棟</th><th>患者数</th><th>定床</th></tr>
        <?php foreach (['3', '4', '5'] as $w): ?>
          <tr class="tall"><th><?= h($w) ?>　階</th><td class="n"><?= num($g("byoto{$w}_zaiin")) ?></td>
            <td class="n"><?= h($teisho[$w]) ?></td></tr>
        <?php endforeach; ?>
        <tr class="tall"><th>計</th><td class="n"><?= num($g('byoto_zaiin_all')) ?></td>
          <td class="n"><?= (int)$teisho['3'] + (int)$teisho['4'] + (int)$teisho['5'] ?></td></tr>
        <tr class="tall"><th>延　数</th><td class="n" colspan="2"><?= num($gm('byoto_zaiin_all')) ?></td></tr>
        <tr class="tall"><th>平　均</th><td class="n" colspan="2"><?= num($gm('byoto_zaiin_all') === null ? null : $gm('byoto_zaiin_all') / $mdays, 1) ?></td></tr>
        <tr><th colspan="2">新　入　院</th><td class="n"><?= num($g('byoto_nyuin_all')) ?></td></tr>
        <tr><th colspan="2">退　　院</th><td class="n"><?= num($g('byoto_taiin_all')) ?></td></tr>
        <tr><th colspan="2">新入院延数</th><td class="n"><?= num($gm('byoto_nyuin_all')) ?></td></tr>
        <tr><th colspan="2">退院延数</th><td class="n"><?= num($gm('byoto_taiin_all')) ?></td></tr>
      </table>
    </td>
    <td class="nf-c3">
      <table class="nf-t">
        <?php $ope = [['外　科', 'ope_geka'], ['整　形', 'ope_seikei'], ['脳外科', 'ope_noge'], ['形　成', 'ope_keisei'],
                      ['その他', 'ope_other'], ['計', 'ope_total'], ['うち全麻', 'ope_zenma']];
        foreach ($ope as $i => [$lab, $c]): ?>
          <tr><?php if ($i === 0): ?><th rowspan="<?= count($ope) ?>" class="tate"><?= $tate('手術') ?></th><?php endif; ?>
            <th><?= h($lab) ?></th><td class="n"><?= $nu($g($c), '件') ?></td></tr>
        <?php endforeach; ?>
        <?php foreach (['①', '②', '③', '④', '⑤', '⑥'] as $i => $no): ?>
          <tr><?php if ($i === 0): ?><th rowspan="6" class="tate"><?= $tate('術名') ?></th><?php endif; ?>
            <td colspan="2" class="opename" title="<?= h($tx('ope_name' . ($i + 1))) ?>"><?= h($no) ?> <?= h($tx('ope_name' . ($i + 1))) ?></td></tr>
        <?php endforeach; ?>
        <tr><th rowspan="5" class="tate"><?= $tate('救急搬入') ?></th>
          <th>入　院</th><td class="n"><?= $nu($g('qq_nyuin'), '名') ?></td></tr>
        <tr><th>外　来</th><td class="n"><?= $nu($g('qq_gairai'), '名') ?></td></tr>
        <tr><th>転　送</th><td class="n"><?= $nu($g('qq_tensou'), '名') ?></td></tr>
        <tr><th rowspan="2">計</th><td class="n"><?= $nu($g('qq_kensu'), '件') ?></td></tr>
        <tr><td class="n"><?= $nu($g('qq_nyuin_gairai_total'), '名') ?></td></tr>
      </table>
    </td>
    <td class="nf-c4">
      <table class="nf-t">
        <?php
        // 網かけの3つ（訪問診療・往診・居宅指導）は様式どおり「訪問診療として本部報告」
        $saikei = [['リハビリ', 'rehab_gairai_kensu', '件'], ['通所リハビリ', 'tsusho', '件'],
                   ['訪問診療', 'iryou_houshin', '件', true], ['往　診', 'iryou_oushin', '件', true],
                   ['居宅指導', 'kaigo_kyotaku', '件', true], ['訪問看護', 'houkan_total', '件'],
                   ['訪問介護', 'houkai_total', '件'], ['訪問リハビリ', 'hourehab_total', '件'],
                   ['訪問栄養', 'houeiyou_total', '件'], ['訪問薬剤', 'houyaku_total', '件'],
                   ['人間ドック', 'dock_kensu', '人'], ['健康診断', 'kenshin_kensu', '人'],
                   ['透析登録数', 'touseki_touroku', '人'], ['透析実施数', 'touseki_total', '件']];
        foreach ($saikei as $i => $r): ?>
          <tr<?= !empty($r[3]) ? ' class="shade"' : '' ?>>
            <?php if ($i === 0): ?><th rowspan="<?= count($saikei) ?>" class="tate"><?= $tate('外来再掲分') ?><br><span class="tate-sub"><?= $tate('医＋介') ?></span></th><?php endif; ?>
            <th><?= h($r[0]) ?></th><td class="n"><?= $nu($g($r[1]), $r[2]) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <p class="nf-note">網かけ（訪問診療・往診・居宅指導）は訪問診療として本部報告</p>
    </td>
  </tr>
</table>

<?php /* ---- 2段目：特殊検査・他（3組）｜癌新登録数・在宅・医療連携 ---- */ ?>
<table class="nf-row">
  <tr>
    <td class="nf-full">
      <table class="nf-t nf-kensa">
        <?php // 列の幅を決めておく（字幅の広いフォントでも表が右へ広がらないように。table-layout: fixed） ?>
        <colgroup><col class="k-tate"><col class="k-lab-a"><col class="k-val"><col class="k-lab-b"><col class="k-val">
          <col class="k-lab-c"><col class="k-val-c"><col class="k-tate"><col class="k-lab-d"><col class="k-val"></colgroup>
        <?php
        $colA = [['ＣＴ', 'ct_total', '件'], ['ＭＲＩ', 'mri_total', '件'], ['一般単純撮影', 'ippan_total', '件'],
                 ['血管造影', 'cag_total', '件'], ['血管造影手術', 'cag_ope', '件'], ['マンモグラフィー', 'mammo_total', '件'],
                 ['胃透視', 'itoushi_total', '件'], ['その他透視・造影', 'zouei_total', '件']];
        $colB = [['心エコー', 'shin_echo_total', '件'], ['胸・腹エコー', 'fuku_echo_total', '件'], ['医師エコー', 'ishi_echo_total', '件'],
                 ['検体検査件数', 'kentai_total', '件'], ['ＳＡＳ', 'sas', '件'], ['ＥＧＤ', 'egd_total', '件'],
                 ['ＣＳ', 'cf_total', '件'], ['癌化学療法', 'gairai_ganchemo', '件']];
        $colC = [['服薬指導件数', 'fukuyaku', '件'], ['調剤件数（入院）', 'chouzai_nyuin', '件'], ['調剤件数（外来）', 'chouzai_gairai', '件'],
                 ['栄養指導件数', 'eiyou_sum12', '件'], ['理学療法単位数', 'pt_total', '単位'], ['作業療法単位数', 'ot_total', '単位'],
                 ['言語療法単位数', 'st_total', '単位'], null];
        for ($i = 0; $i < 8; $i++): ?>
          <tr>
            <?php if ($i === 0): ?><th rowspan="8" class="tate"><?= $tate('特殊検査・他') ?></th><?php endif; ?>
            <?php foreach ([$colA[$i], $colB[$i], $colC[$i]] as $r): ?>
              <?php if ($r === null): ?><th></th><td></td>
              <?php else: ?><th><?= h($r[0]) ?></th><td class="n"><?= $nu($g($r[1]), $r[2]) ?></td><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($i === 0): ?>
              <th colspan="2" class="lft">癌新登録数</th><td class="n"><?= $nu($g('gan_touroku'), '件') ?></td>
            <?php elseif ($i <= 3):
                $z = [1 => ['在宅登録人数', 'zaitaku_ninzu', '人'], 2 => ['在宅新規登録人数', 'zaitaku_shinki', '人'],
                      3 => ['在宅登録件数', 'reg_total', '件']][$i]; ?>
              <?php if ($i === 1): ?><th rowspan="3" class="tate"><?= $tate('在宅') ?></th><?php endif; ?>
              <th><?= h($z[0]) ?></th><td class="n"><?= $nu($g($z[1]), $z[2]) ?></td>
            <?php else:
                $rn = [4 => ['病院訪問', 'renkei_byoin'], 5 => ['その他訪問', 'renkei_other'],
                       6 => ['健康講座', 'renkei_kouza'], 7 => ['相談件数', 'renkei_soudan']][$i]; ?>
              <?php if ($i === 4): ?><th rowspan="4" class="tate"><?= $tate('医療連携') ?></th><?php endif; ?>
              <th><?= h($rn[0]) ?></th><td class="n"><?= $nu($g($rn[1]), '件') ?></td>
            <?php endif; ?>
          </tr>
        <?php endfor; ?>
      </table>
    </td>
  </tr>
</table>

<?php /* ---- 3段目：会議・勉強・委員会等｜当直者・呼び出し ---- */ ?>
<table class="nf-row">
  <tr>
    <td class="nf-kaigicell">
      <table class="nf-t nf-kaigi">
        <tr><th rowspan="2" class="tate"><?= $tate('会議・勉強・委員会等') ?></th>
          <th class="kj">時　刻</th><th>会議・委員会名</th><th class="kj">時　刻</th><th>会議・委員会名</th></tr>
        <tr>
          <td class="memo kj"><?= nl2br(h($tx('kaigi_jikoku1'))) ?></td><td class="memo"><?= nl2br(h($tx('kaigi_name1'))) ?></td>
          <td class="memo kj"><?= nl2br(h($tx('kaigi_jikoku2'))) ?></td><td class="memo"><?= nl2br(h($tx('kaigi_name2'))) ?></td>
        </tr>
      </table>
    </td>
    <td class="nf-touchokucell">
      <table class="nf-t nf-touchoku">
        <tr><th colspan="2" class="sec">当　直　者</th><th colspan="2" class="sec">呼び出し</th></tr>
        <?php
        $yobi = [['医　師', 'yobidashi_ishi'], ['手術室', 'yobidashi_ope'], ['在　宅', 'yobidashi_zaitaku'],
                 ['検　査', 'yobidashi_kensa'], ['放射線', 'yobidashi_housha'], ['薬　局', 'yobidashi_yakkyoku']];
        $tou  = [['医　師', 'toutyoku_ishi1'], [null, 'toutyoku_ishi2'], ['看　護', 'toutyoku_kango'],
                 ['放射線', 'toutyoku_housha'], ['事　務', 'toutyoku_jimu'], ['', null]];
        foreach ($yobi as $i => [$ylab, $yc]):
            [$tlab, $tc] = $tou[$i]; ?>
          <tr>
            <?php if ($tlab !== null): ?><th<?= $i === 0 ? ' rowspan="2"' : '' ?>><?= h($tlab) ?></th><?php endif; ?>
            <td class="name"><?= $tc ? h($tx($tc)) : '' ?></td>
            <th><?= h($ylab) ?></th><td class="name"><?= h($tx($yc)) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </td>
  </tr>
</table>

<?php /* ---- 4段目：行事｜人事　5段目：特記事項等 ---- */ ?>
<table class="nf-row">
  <tr>
    <td class="nf-full">
      <table class="nf-t nf-gyoji">
        <tr><th class="tate"><?= $tate('行事') ?></th><td class="memo"><?= nl2br(h($tx('gyoji'))) ?></td>
          <th class="tate"><?= $tate('人事') ?></th><td class="memo"><?= nl2br(h($tx('jinji'))) ?></td></tr>
      </table>
    </td>
  </tr>
  <tr>
    <td class="nf-full nf-tokki">
      <?php // 紙の様式どおり、左に特記事項（自由記述・病棟の入力画面）、右にオーバーベッドの日の定数超過報告（最大6名） ?>
      <table class="nf-tokki-in">
        <tr>
          <td class="nf-tokki-l">
            <div class="nf-tokki-h">特記事項等：</div>
            <?php if ($tx('tokki_memo') !== ''): ?>
              <div class="nf-tokki-memo"><?= nl2br(h($tx('tokki_memo'))) ?></div>
            <?php elseif (!$tokki): ?>
              <p class="nf-none">特記なし</p>
            <?php endif; ?>
          </td>
          <td class="nf-tokki-r">
            <?php if ($tokki): ?>
              <div class="nf-tokki-h">定数超過報告（近隣受入不可のため）</div>
              <table class="nf-t">
                <tr><th>病棟</th><th>ＩＤ</th><th>氏名</th><th>入院経路</th><th>病名</th></tr>
                <?php foreach ($tokki as $r): ?>
                  <tr><td><?= h($r['ward']) ?></td><td><?= h($r['patient_id']) ?></td><td><?= h($r['patient_name']) ?></td>
                      <td><?= h($r['route']) ?></td><td><?= h($r['diagnosis']) ?></td></tr>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

</div>

<?php
$subs = [];
foreach (db_all('SELECT * FROM d_submission WHERE hizuke = ?', [$date]) as $r) {
    $subs[$r['dept_id']] = $r;
}
// 印刷では20部署の表の代わりに1行の要約にする（A4に収めるため）
$done = $notDone = [];
foreach (all_depts() as $id => $d) {
    if (in_array($subs[$id]['status'] ?? 'none', ['submitted', 'confirmed'], true)) {
        $done[] = $d['dept_name'];
    } else {
        $notDone[] = $d['dept_name'];
    }
}
?>
<p class="printonly nissi-status">入力状況：提出済 <?= count($done) ?>／<?= count($done) + count($notDone) ?>部署
  <?= $notDone ? '　未提出：' . h(implode('、', $notDone)) : '' ?></p>

<h2 class="updaters noprint">入力状況</h2>
<table class="updaters noprint">
  <tr><th>部署</th><th>入力件数</th><th>最終更新</th><th>提出</th></tr>
  <?php
  foreach (all_depts() as $id => $d):
      $u   = null;
      foreach (daily_updaters($date) as $row) {
          if ($row['dept_id'] === $id) { $u = $row; break; }
      }
      $s = $subs[$id] ?? null;
  ?>
  <tr>
    <td><?= h($d['dept_name']) ?></td>
    <td class="n"><?= $u ? (int)$u['cnt'] : 0 ?></td>
    <td><?= h($u['last_at'] ?? '') ?></td>
    <td><?= h(['none'=>'未入力','draft'=>'入力中','submitted'=>'提出済','confirmed'=>'確定'][$s['status'] ?? 'none']) ?>
        <?= $s && $s['submitted_by'] ? '（' . h($s['submitted_by']) . '）' : '' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php page_footer();
