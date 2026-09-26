<?php
/**
 * 病院日誌（日次）。現行 nissi.php に相当する。
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

page_header('病　院　日　誌', $user);
if ($print) {
    echo '<link rel="stylesheet" href="assets/print.css">';
}
?>
<form method="get" class="datebar noprint">
  <label>日付 <input type="date" name="hizuke" value="<?= h($date) ?>"></label>
  <span class="youbi">（<?= h(youbi($date)) ?>）</span>
  <button type="submit">表示</button>
  <a href="nissi.php?hizuke=<?= h($date) ?>&amp;print=1" target="_blank">印刷用</a>
</form>

<p><strong><?= h($date) ?>（<?= h(youbi($date)) ?>）</strong>　天候：<?= h($tx('tenkou')) ?></p>

<div class="nissi-grid">

<table class="nissi">
  <tr><th colspan="4" class="sec">外来患者数</th></tr>
  <tr><th>時間帯</th><th>患者数</th><th>新患</th><th>備考</th></tr>
  <?php foreach ([['午前','gairai_am','gairai_am_new'],['午後','gairai_pm','gairai_pm_new'],
                  ['夜間','gairai_night','gairai_night_new'],['時間外１','gairai_ex1','gairai_ex1_new'],
                  ['時間外２','gairai_ex2','gairai_ex2_new']] as [$lab,$c,$cn]): ?>
    <tr><th><?= h($lab) ?></th><td class="n"><?= num($g($c)) ?></td><td class="n"><?= num($g($cn)) ?></td><td></td></tr>
  <?php endforeach; ?>
  <tr><th>計</th><td class="n"><?= num($g('gairai_total')) ?></td><td class="n"><?= num($g('gairai_new_total')) ?></td><td></td></tr>
  <tr><th>延数</th><td class="n"><?= num($gm('gairai_total')) ?></td><td class="n"><?= num($gm('gairai_new_total')) ?></td><td></td></tr>
  <tr><th>平均</th>
      <td class="n"><?= num($gm('gairai_total') === null ? null : $gm('gairai_total') / $mdays, 1) ?></td>
      <td class="n"><?= num($gm('gairai_new_total') === null ? null : $gm('gairai_new_total') / $mdays, 1) ?></td><td></td></tr>
</table>

<table class="nissi">
  <tr><th colspan="3" class="sec">入院患者数</th></tr>
  <tr><th>病棟</th><th>患者数</th><th>定床</th></tr>
  <?php foreach (['3','4','5'] as $w): ?>
    <tr><th><?= h($w) ?>階</th><td class="n"><?= num($g("byoto{$w}_zaiin")) ?></td>
        <td class="n"><?= h(config_value("teisho_byoto{$w}", $date, '')) ?></td></tr>
  <?php endforeach; ?>
  <tr><th>計</th><td class="n"><?= num($g('byoto_zaiin_all')) ?></td>
      <td class="n"><?= (int)config_value('teisho_byoto3', $date, 0) + (int)config_value('teisho_byoto4', $date, 0) + (int)config_value('teisho_byoto5', $date, 0) ?></td></tr>
  <tr><th>延数</th><td class="n"><?= num($gm('byoto_zaiin_all')) ?></td><td></td></tr>
  <tr><th>平均</th><td class="n"><?= num($gm('byoto_zaiin_all') === null ? null : $gm('byoto_zaiin_all') / $mdays, 1) ?></td><td></td></tr>
  <tr><th>新入院</th><td class="n"><?= num($g('byoto_nyuin_all')) ?></td><td></td></tr>
  <tr><th>退院</th><td class="n"><?= num($g('byoto_taiin_all')) ?></td><td></td></tr>
  <tr><th>新入院延数</th><td class="n"><?= num($gm('byoto_nyuin_all')) ?></td><td></td></tr>
  <tr><th>退院延数</th><td class="n"><?= num($gm('byoto_taiin_all')) ?></td><td></td></tr>
</table>

<table class="nissi">
  <tr><th colspan="2" class="sec">手術</th></tr>
  <?php foreach ([['外科','ope_geka'],['整形','ope_seikei'],['脳外科','ope_noge'],
                  ['形成','ope_keisei'],['その他','ope_other'],['計','ope_total'],['全麻','ope_zenma']] as [$lab,$c]): ?>
    <tr><th><?= h($lab) ?></th><td class="n"><?= num($g($c)) ?> 件</td></tr>
  <?php endforeach; ?>
  <tr><th colspan="2" class="sec">術名</th></tr>
  <?php foreach (['①'=>'ope_name1','②'=>'ope_name2','③'=>'ope_name3',
                  '④'=>'ope_name4','⑤'=>'ope_name5','⑥'=>'ope_name6'] as $no => $c): ?>
    <tr><th><?= h($no) ?></th><td class="memo"><?= h($tx($c)) ?></td></tr>
  <?php endforeach; ?>
</table>

<table class="nissi">
  <tr><th colspan="2" class="sec">救急搬入</th></tr>
  <tr><th>入院</th><td class="n"><?= num($g('qq_nyuin')) ?> 名</td></tr>
  <tr><th>外来</th><td class="n"><?= num($g('qq_gairai')) ?> 名</td></tr>
  <tr><th>転送</th><td class="n"><?= num($g('qq_tensou')) ?> 名</td></tr>
  <tr><th>計</th><td class="n"><?= num($g('qq_nyuin_gairai_total')) ?> 名</td></tr>
  <tr><th>件数</th><td class="n"><?= num($g('qq_kensu')) ?> 件</td></tr>
  <tr><th colspan="2" class="sec">外来再掲分</th></tr>
  <?php foreach ([['リハビリ','rehab_gairai_kensu'],['通所リハ','tsusho'],['訪問診療','iryou_houshin'],
                  ['往　診','iryou_oushin'],['居宅指導','kaigo_kyotaku'],['訪問看護','houkan_total'],
                  ['訪問介護','houkai_total'],['訪問リハ','hourehab_total'],['訪問栄養','houeiyou_total'],
                  ['訪問薬剤','houyaku_total'],['人間ﾄﾞｯｸ','dock_kensu'],['健康診断','kenshin_kensu'],
                  ['透析登録数','touseki_touroku'],['透析実施数','touseki_total']] as [$lab,$c]): ?>
    <tr><th><?= h($lab) ?></th><td class="n"><?= num($g($c)) ?> 件</td></tr>
  <?php endforeach; ?>
</table>

<table class="nissi">
  <tr><th colspan="4" class="sec">特殊検査・他</th></tr>
  <?php
  $kensa = [
    ['Ｃ　Ｔ','ct_total'],['心エコー','shin_echo_total'],
    ['ＭＲＩ','mri_total'],['胸・腹エコー','fuku_echo_total'],
    ['マンモグラフィ','mammo_total'],['医師エコー','ishi_echo_total'],
    ['一般撮影','ippan_total'],['検体検査件数','kentai_total'],
    ['胃透視','itoushi_total'],['ＳＡＳ','sas'],
    ['その他造影','zouei_total'],['ＥＧＤ','egd_total'],
    ['血管造影','cag_total'],['ＣＳ','cf_total'],
    ['血管造影手術','cag_ope'],['癌化学療法','gairai_ganchemo'],
    ['服薬指導','fukuyaku'],['癌新登録数','gan_touroku'],
    ['調剤（入院）','chouzai_nyuin'],['理学療法','pt_total'],
    ['調剤（外来）','chouzai_gairai'],['作業療法','ot_total'],
    ['栄養指導','eiyou_sum12'],['言語療法','st_total'],
  ];
  foreach (array_chunk($kensa, 2) as $pair): ?>
    <tr>
      <?php foreach ($pair as [$lab,$c]): ?>
        <th><?= h($lab) ?></th><td class="n"><?= num($g($c)) ?> 件</td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>

<table class="nissi">
  <tr><th colspan="3" class="sec">在宅</th></tr>
  <tr><th>登録人数</th><td class="n"><?= num($g('zaitaku_ninzu')) ?> 人</td><td></td></tr>
  <tr><th>新規人数</th><td class="n"><?= num($g('zaitaku_shinki')) ?> 人</td><td></td></tr>
  <tr><th>登録件数</th><td class="n"><?= num($g('reg_total')) ?> 件</td><td></td></tr>
  <tr><th colspan="3" class="sec">医療連携</th></tr>
  <tr><th>病院訪問</th><td class="n"><?= num($g('renkei_byoin')) ?> 件</td><td></td></tr>
  <tr><th>その他訪問</th><td class="n"><?= num($g('renkei_other')) ?> 件</td><td></td></tr>
  <tr><th>健康講座</th><td class="n"><?= num($g('renkei_kouza')) ?> 件</td><td></td></tr>
  <tr><th>相談件数</th><td class="n"><?= num($g('renkei_soudan')) ?> 件</td><td></td></tr>
</table>

<table class="nissi">
  <tr><th colspan="5" class="sec">水道光熱費</th></tr>
  <tr><th></th><th>電気</th><th>ガス</th><th>水道（上＋下）</th><th></th></tr>
  <tr><th>使用量</th><td class="n"><?= num($g('den_shiyou')) ?> Kw</td><td class="n"><?= num($g('gas_shiyou')) ?> m3</td><td class="n"><?= num($g('sui_shiyou')) ?> m3</td><td></td></tr>
  <tr><th>料金</th><td class="n"><?= num($g('den_ryoukin')) ?> 円</td><td class="n"><?= num($g('gas_ryoukin')) ?> 円</td><td class="n"><?= num($g('sui_ryoukin')) ?> 円</td>
      <td class="n">1日合計 <?= num($g('netu_ryoukin_total')) ?> 円</td></tr>
  <tr><th>当月目標額</th><td class="n"><?= num($g('den_mokuhyou')) ?> 円</td><td class="n"><?= num($g('gas_mokuhyou')) ?> 円</td><td class="n"><?= num($g('sui_mokuhyou')) ?> 円</td><td></td></tr>
  <tr><th>目標値対比</th>
    <?php $diff = 0; foreach (['den','gas','sui'] as $k):
        $d = ($g("{$k}_ryoukin") ?? null) === null || ($g("{$k}_mokuhyou") ?? null) === null
             ? null : $g("{$k}_ryoukin") - $g("{$k}_mokuhyou");
        $diff += (float)($d ?? 0); ?>
      <td class="n"><?= num($d) ?> 円</td>
    <?php endforeach; ?>
    <td class="n">計 <?= num($diff) ?> 円</td></tr>
</table>

<table class="nissi">
  <tr><th colspan="2" class="sec">当直者</th></tr>
  <?php foreach ([['医師','toutyoku_ishi1'],['医師','toutyoku_ishi2'],['看護','toutyoku_kango'],
                  ['放射線','toutyoku_housha'],['事務','toutyoku_jimu']] as [$lab,$c]): ?>
    <tr><th><?= h($lab) ?></th><td><?= h($tx($c)) ?></td></tr>
  <?php endforeach; ?>
  <tr><th colspan="2" class="sec">呼び出し</th></tr>
  <?php foreach ([['医師','yobidashi_ishi'],['手術室','yobidashi_ope'],['在宅','yobidashi_zaitaku'],
                  ['検査','yobidashi_kensa'],['放射線','yobidashi_housha'],['薬局','yobidashi_yakkyoku']] as [$lab,$c]): ?>
    <tr><th><?= h($lab) ?></th><td><?= h($tx($c)) ?></td></tr>
  <?php endforeach; ?>
</table>

</div>

<table class="nissi" style="margin-top:10px">
  <tr><th class="sec" style="width:120px">会議・委員会等</th>
      <td class="memo"><?= nl2br(h($tx('kaigi_jikoku1'))) ?></td><td class="memo"><?= nl2br(h($tx('kaigi_name1'))) ?></td>
      <td class="memo"><?= nl2br(h($tx('kaigi_jikoku2'))) ?></td><td class="memo"><?= nl2br(h($tx('kaigi_name2'))) ?></td></tr>
  <tr><th class="sec">行事</th><td class="memo" colspan="4"><?= nl2br(h($tx('gyoji'))) ?></td></tr>
  <tr><th class="sec">人事</th><td class="memo" colspan="4"><?= nl2br(h($tx('jinji'))) ?></td></tr>
</table>

<?php
// 特記事項（定員超過報告）は患者ID・氏名を含むため、閲覧できる役割を絞る
$canSeeTokki = is_ijika($user) || $user['dept_id'] === 'byoto';
$tokki = $canSeeTokki
    ? db_all('SELECT * FROM d_tokki WHERE hizuke = ? AND deleted_at IS NULL ORDER BY sort_no, tokki_id', [$date])
    : [];
?>
<h2>特記事項等：定員超過報告</h2>
<?php if (!$canSeeTokki): ?>
  <p class="note">患者氏名を含むため、病棟・医事課・管理者のみ表示します。</p>
<?php elseif (!$tokki): ?>
  <p class="note">該当なし</p>
<?php else: ?>
<table class="nissi">
  <tr><th>病棟</th><th>ＩＤ</th><th>氏名</th><th>入院経路</th><th>病名</th></tr>
  <?php foreach ($tokki as $r): ?>
    <tr><td><?= h($r['ward']) ?></td><td><?= h($r['patient_id']) ?></td><td><?= h($r['patient_name']) ?></td>
        <td><?= h($r['route']) ?></td><td><?= h($r['diagnosis']) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<h2 class="updaters">入力状況</h2>
<table class="updaters">
  <tr><th>部署</th><th>入力件数</th><th>最終更新</th><th>提出</th></tr>
  <?php
  $subs = [];
  foreach (db_all('SELECT * FROM d_submission WHERE hizuke = ?', [$date]) as $r) {
      $subs[$r['dept_id']] = $r;
  }
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
