<?php
/**
 * 電子カルテ日報の転記画面。
 *
 * 電子カルテから出る紙（外来患者数（科別）日報・入院患者数日報）を、
 * 紙と同じ並びで打ち込む。紙に印字されている合計（医科合計・介護合計など）も
 * 打ち込み、科別の合計と照合してから保存する。合わなければ保存しない。
 *
 * 保存すると、外来・病棟・当直の各部署に「下書き」として値が入る。
 * 各部署は普段の入力画面で確かめて提出する。外来の提出はこの画面から行う。
 *
 * JavaScriptは使わない（院内端末のChromeが古いため）。照合はサーバ側で行う。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/nippo.php';
require_once __DIR__ . '/../src/calc.php';

$user = require_login();

if (!can_edit_dept($user, 'gairai')) {
    http_response_code(403);
    page_header('権限がありません', $user);
    flash('日報の転記は外来・医事課・管理者のみ行えます。', 'error');
    page_footer();
    exit;
}

$date      = valid_date($_REQUEST['hizuke'] ?? null) ?? date('Y-m-d', strtotime('-1 day'));
$depts     = all_depts();
$sub       = submission($date, 'gairai');
$confirmed = ($sub['status'] ?? '') === 'confirmed';
$locked    = $confirmed && !is_ijika($user);

$messages = [];
$errors   = [];
$warnings = [];
$skipped  = [];

// 日報の欄以外の外来の入力項目（外来癌化学療法など）。旧日誌からの移行専用の欄は出さない
$nippoSet = array_flip(nippo_codes());
$others   = [];
foreach (input_items_of_dept('gairai', $date) as $code => $it) {
    if (!isset($nippoSet[$code]) && strpos($code, '_ikou') === false) {
        $others[$code] = $it;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $arr = fn($x) => is_array($x) ? $x : [];
    [$vals, $ctl, $raw, $errors] = nippo_parse($arr($_POST['v'] ?? null), $arr($_POST['c'] ?? null));
    $otherVals = array_filter(array_intersect_key($arr($_POST['o'] ?? null), $others), 'is_string');

    if ($locked) {
        $messages[] = ['この日付は医事課が確定済みです。訂正が必要な場合は医事課へ連絡してください。', 'error'];
    } elseif (($_POST['action'] ?? '') === 'load') {
        // Excelから読み込む。保存はせず、読んだ欄と照合欄を埋めて表示し直すだけ。
        // 読まなかった欄（すでに打ってある値）はそのまま残す（$raw は送られてきた値のまま）
        $errors = [];
        $load   = nippo_load_uploads([$_FILES['xlsx_gairai'] ?? null, $_FILES['xlsx_nyuin'] ?? null], $date);
        if ($load['errors']) {
            $errors = $load['errors'];
            $messages[] = ['Excelを読み込めませんでした。欄は何も変えていません。', 'error'];
        } else {
            foreach ($load['v'] as $code => $n) {
                $raw["v:{$code}"] = (string)$n;
            }
            foreach ($load['c'] as $name => $n) {
                $raw["c:{$name}"] = (string)$n;
            }
            foreach ($load['notes'] as $m) {
                $messages[] = [$m . '（まだ保存していません）', 'ok'];
            }
            $warnings = $load['warnings'];
            $rest = [];
            if (!isset($load['kinds']['gairai'])) {
                $rest[] = '外来';
            }
            if (!isset($load['kinds']['nyuin'])) {
                $rest[] = '入院';
            }
            $messages[] = [($rest ? implode('・', $rest) . 'の欄は紙を見て入力し、' : '')
                . '数字を確かめてから「照合して保存」を押してください。', 'info'];
        }
    } else {
        if (!$errors) {
            $chk      = nippo_check($vals, $ctl, $date);
            $errors   = $chk['errors'];
            $warnings = $chk['warnings'];
        }
        if ($errors) {
            $messages[] = ['紙の合計と合わない欄があります。赤い欄を確かめてください（まだ保存していません）。', 'error'];
        } else {
            $res = nippo_save($date, $vals, $user['user_id']);
            if ($otherVals) {
                $o = save_dept_entries($date, 'gairai', $otherVals, $user['user_id']);
                $res['saved'] += $o['saved'];
                $res['warnings'] = array_merge($res['warnings'], $o['warnings']);
            }
            $warnings = array_merge($warnings, $res['warnings']);
            $skipped  = $res['skipped'];
            $names    = array_map(fn($d) => $depts[$d]['dept_name'] ?? $d, $res['depts']);
            if (($_POST['action'] ?? '') === 'submit') {
                touch_submission($date, 'gairai', 'submitted', $user['user_id']);
                $messages[] = ["{$res['saved']}件を保存し、外来の日報を提出しました。", 'ok'];
            } else {
                $messages[] = ["{$res['saved']}件を保存しました（" . implode('・', $names) . 'に下書きとして入りました）。', 'ok'];
            }
            $sub = submission($date, 'gairai');
        }
    }
} else {
    $saved = nippo_load($date);
    $vals  = [];
    foreach (nippo_codes() as $code) {
        $vals[$code] = $saved[$code] ?? null;
    }
    $raw = [];
    foreach ($vals as $code => $x) {
        $raw["v:{$code}"] = $x === null ? '' : (string)$x;
    }
    // 保存済みの日なら、照合欄は保存済みの値から埋める（行ごとの合計は任意なので空のまま）
    if ($saved) {
        foreach (nippo_controls_from($saved) as $name => $x) {
            $raw["c:{$name}"] = (string)$x;
        }
    }
    $otherVals = [];
}

/**
 * アップロードされた日報のExcel（外来・入院、どちらか一方でも両方でもよい）を読む。
 * どちらの日報かは中身で判定する（欄を取り違えて選ばれても読める）。
 * 一時ファイルのまま読み、どこにも保存しない。1つでも読めなければ何も返さない。
 */
function nippo_load_uploads(array $files, string $date): array
{
    $fail = fn(string $m) => ['v' => [], 'c' => [], 'errors' => [$m], 'notes' => [], 'warnings' => [], 'kinds' => []];
    $out  = ['v' => [], 'c' => [], 'errors' => [], 'notes' => [], 'warnings' => [], 'kinds' => []];
    $any  = false;
    foreach ($files as $f) {
        if (!is_array($f) || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $any  = true;
        $name = (string)($f['name'] ?? '');
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $fail("{$name}：ファイルが大きすぎます。日報のExcelか確かめてください。");
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return $fail("{$name}：ファイルを受け取れませんでした（コード " . (int)$f['error'] . '）。もう一度選んでください。');
        }
        try {
            $sheets = xlsx_read($f['tmp_name']);
        } catch (RuntimeException $e) {
            return $fail("{$name}：" . $e->getMessage());
        }
        $kind = nippo_xlsx_kind($sheets);
        if ($kind === null) {
            return $fail("{$name}：外来日報・入院患者数日報のどちらのExcelでもありません。");
        }
        if (isset($out['kinds'][$kind])) {
            return $fail(($kind === 'gairai' ? '外来日報' : '入院患者数日報') . 'のExcelが2つ選ばれています。');
        }
        $r = $kind === 'gairai' ? nippo_gairai_from_xlsx($sheets, $date) : nippo_nyuin_from_xlsx($sheets, $date);
        if ($r['errors']) {
            return ['v' => [], 'c' => [], 'errors' => array_map(fn($e) => "{$name}：{$e}", $r['errors']),
                    'notes' => [], 'warnings' => [], 'kinds' => []];
        }
        $out['kinds'][$kind] = true;
        $out['v'] += $r['v'];
        $out['c'] += $r['c'];
        $out['notes']    = array_merge($out['notes'], $r['notes']);
        $out['warnings'] = array_merge($out['warnings'], $r['warnings'] ?? []);
    }
    if (!$any) {
        return $fail('読み込むExcelファイルを選んでください。');
    }
    return $out;
}

$entries = dept_entries($date, 'gairai');
$hasData = (bool)nippo_load($date);
$calc    = $hasData ? daily_values($date) : [];

/** 欄の値（打った文字列）。 */
$rv = fn(string $key) => $raw[$key] ?? '';
/** 転記欄1つ。 */
$cell = function (string $code, string $label) use ($rv, $errors, $locked) {
    $k = "v:{$code}";
    return '<input type="text" inputmode="numeric" class="num' . (isset($errors[$k]) ? ' ng' : '') . '"'
         . ' name="v[' . h($code) . ']" value="' . h($rv($k)) . '" size="3" title="' . h($label) . '"'
         . ($locked ? ' readonly' : '') . '>';
};
/** 照合欄1つ。 */
$ctlCell = function (string $name, string $label, bool $required = true) use ($rv, $errors, $locked) {
    $k = "c:{$name}";
    return '<input type="text" inputmode="numeric" class="num ctl' . (isset($errors[$k]) ? ' ng' : '') . '"'
         . ' name="c[' . h($name) . ']" value="' . h($rv($k)) . '" size="3" title="' . h($label)
         . ($required ? '（紙の値・必須）' : '（紙の値・任意）') . '"' . ($locked ? ' readonly' : '') . '>';
};

page_header('電子カルテ日報の転記', $user);
?>
<form method="get" class="datebar">
  <label>日報の日付 <input type="date" name="hizuke" value="<?= h($date) ?>"></label>
  <span class="youbi">（<?= h(youbi($date)) ?>）</span>
  <button type="submit">表示</button>
  <span class="note">電子カルテの「外来患者数（科別）」「入院患者数日報」の紙を見ながら入力します。空欄は0として保存します。</span>
</form>

<?php foreach ($messages as [$m, $k]) { flash($m, $k); } ?>
<?php if ($errors): ?>
  <div class="flash flash-error">
    <ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($warnings): ?>
  <div class="flash flash-warn">
    <strong>確認してください<?= ($errors || ($_POST['action'] ?? '') === 'load') ? '' : '（保存はしています）' ?></strong>
    <ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php if ($skipped):
    $items = all_items($date); ?>
  <div class="flash flash-warn">
    <strong>提出済みの部署の値は上書きしていません。日報と違う値があります。</strong>
    <ul>
    <?php foreach ($skipped as $d => $list): foreach ($list as [$code, $cur, $new]): ?>
      <li><?= h($depts[$d]['dept_name'] ?? $d) ?>：<?= h($items[$code]['item_name'] ?? $code) ?>
        　保存済み <?= h($cur === null ? '（空）' : (string)$cur) ?> → 日報 <?= h((string)$new) ?></li>
    <?php endforeach; endforeach; ?>
    </ul>
    必要なら、その部署か医事課が入力画面で訂正してください。
  </div>
<?php endif; ?>
<?php if ($confirmed): ?>
  <p class="flash flash-<?= $locked ? 'error' : 'warn' ?>">
    この日付の外来は医事課が確定済みです<?= $locked ? '。編集できません。' : '。医事課として訂正できますが、変更履歴に残ります。' ?>
  </p>
<?php endif; ?>

<form method="post" class="nippo-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="hizuke" value="<?= h($date) ?>">

  <?php if (!$locked): ?>
  <?php /* 欄でEnterを押したときに押されるのは、フォームの最初のボタン。
           読み込みボタンより前に「保存」を置き、これまでどおり Enter＝照合して保存 にする */ ?>
  <button type="submit" name="action" value="save" class="sr" tabindex="-1" aria-hidden="true">照合して保存</button>
  <p class="nippo-load">
    <label>外来日報（.xlsx） <input type="file" name="xlsx_gairai" accept=".xlsx,.xlsm"></label>
    <label>入院日報（.xlsm） <input type="file" name="xlsx_nyuin" accept=".xlsx,.xlsm"></label>
    <button type="submit" name="action" value="load">Excelから読み込む</button>
    <br><span class="note">片方だけでも読み込めます。欄に数字が入るだけで、まだ保存はしません。
      入院日報は、画面の日付と同じ日のシートを読みます。</span>
  </p>
  <?php endif; ?>

  <h2>外来患者数（科別）日報</h2>
  <div class="nippo-pair">
    <?php foreach (['raiin' => ['gk', '来院数（新来・再来）'], 'shinrai' => ['gkn', '新来数']] as $t => [$pre, $title]): ?>
    <table class="nippo">
      <caption><?= h($title) ?></caption>
      <tr><th>科名</th>
        <?php foreach (nippo_slots() as $s => $sn): ?><th><?= h($sn) ?></th><?php endforeach; ?>
        <th class="ctlh">合計<br><small>任意</small></th></tr>
      <?php foreach (nippo_ka() as $ka => $kn): ?>
      <tr><th class="rowh"><?= h($kn) ?></th>
        <?php foreach (nippo_slots() as $s => $sn): ?>
          <td><?= $cell("{$pre}_{$ka}_{$s}", "{$kn} {$sn}") ?></td>
        <?php endforeach; ?>
        <td class="ctlc"><?= $ctlCell("{$t}_row_{$ka}", "{$kn} 合計", false) ?></td></tr>
      <?php endforeach; ?>
      <tr class="sumrow"><th class="rowh">医科合計<br><small>紙の値</small></th>
        <?php foreach (nippo_slots() as $s => $sn): ?>
          <td class="ctlc"><?= $ctlCell("{$t}_{$s}", "医科合計 {$sn}") ?></td>
        <?php endforeach; ?>
        <td class="ctlc"><?= $ctlCell("{$t}_all", '医科合計 合計') ?></td></tr>
    </table>
    <?php endforeach; ?>
    <table class="nippo">
      <caption>健診科新患内訳</caption>
      <tr><th class="rowh">ドック</th><td><?= $cell('gkn_uchi_dock', '健診科新患内訳 ドック') ?></td></tr>
      <tr><th class="rowh">健診</th><td><?= $cell('gkn_uchi_kenshin', '健診科新患内訳 健診') ?></td></tr>
    </table>
  </div>

  <div class="nippo-pair">
    <table class="nippo">
      <caption>再掲</caption>
      <tr><th></th><?php foreach (nippo_slots() as $s => $sn): ?><th><?= h($sn) ?></th><?php endforeach; ?></tr>
      <?php foreach (nippo_saikei() as $k => $kn): ?>
      <tr><th class="rowh"><?= h($kn) ?></th>
        <?php foreach (nippo_slots() as $s => $sn): ?><td><?= $cell("gr_{$k}_{$s}", "{$kn} {$sn}") ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table>
    <table class="nippo">
      <caption>訪問診療科内訳（再掲）</caption>
      <?php foreach (nippo_houshin_uchi() as $code => $label): ?>
      <tr><th class="rowh"><?= h($label) ?></th><td><?= $cell($code, $label) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <table class="nippo">
      <caption>介護</caption>
      <?php foreach (nippo_kaigo() as $code => $label): ?>
      <tr><th class="rowh"><?= h($label) ?></th><td><?= $cell($code, $label) ?></td></tr>
      <?php endforeach; ?>
      <tr class="sumrow"><th class="rowh">介護合計<br><small>紙の値</small></th><td class="ctlc"><?= $ctlCell('kaigo_total', '介護合計') ?></td></tr>
      <tr class="sumrow"><th class="rowh">医療介護合計<br><small>紙の値</small></th><td class="ctlc"><?= $ctlCell('iryou_kaigo_total', '医療介護合計') ?></td></tr>
    </table>
  </div>

  <h2>入院患者数日報</h2>
  <div class="nippo-pair">
    <table class="nippo">
      <caption>入退院情報</caption>
      <tr><th>病棟</th><th class="ctlh">前日患者数<br><small>紙の値</small></th>
        <?php foreach (nippo_idou() as $k => $kn): ?><th><?= h($kn) ?></th><?php endforeach; ?></tr>
      <?php foreach (nippo_wards() as $w => $wn): ?>
      <tr><th class="rowh"><?= h($wn) ?></th>
        <td class="ctlc"><?= $ctlCell("zenjitsu_{$w}", "{$wn} 前日患者数") ?></td>
        <?php foreach (nippo_idou() as $k => $kn): ?><td><?= $cell("byoto{$w}_{$k}", "{$wn} {$kn}") ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table>
    <table class="nippo">
      <caption>科別内訳</caption>
      <tr><th>病棟</th><?php foreach (nippo_bka() as $k => $kn): ?><th><?= h($kn) ?></th><?php endforeach; ?></tr>
      <?php foreach (nippo_wards() as $w => $wn): ?>
      <tr><th class="rowh"><?= h($wn) ?></th>
        <?php foreach (nippo_bka() as $k => $kn): ?><td><?= $cell("bk{$w}_{$k}", "{$wn} {$kn}") ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if ($others): ?>
  <h2>日報に載っていない外来の項目</h2>
  <table class="entry-table narrow">
    <?php foreach ($others as $code => $it):
        $val = array_key_exists($code, $otherVals) ? (string)$otherVals[$code]
             : num_plain($entries[$code]['value_num'] ?? null); ?>
    <tr><th><?= h($it['item_name']) ?></th>
      <td><input type="text" inputmode="numeric" class="num" name="o[<?= h($code) ?>]" value="<?= h($val) ?>" size="6"<?= $locked ? ' readonly' : '' ?>>
        <span class="unit"><?= h($it['unit']) ?></span></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <?php if (!$locked): ?>
  <p class="actions">
    <button type="submit" name="action" value="save">照合して保存</button>
    <button type="submit" name="action" value="submit" class="primary">照合して保存し、外来の日報を提出する</button>
  </p>
  <?php endif; ?>
</form>

<?php if ($hasData):
    $show = [
        ['外来患者数（①）', 'gairai_ippan', '医科合計 − ドック − 健診 − 訪問診療科'],
        ['新患数（①）',     'gairai_new_total', '新来合計'],
        ['医科合計（①総合計）', 'gairai_total', ''],
        ['介護合計',         'kaigo_nippo_total', ''],
        ['医療介護合計',     'iryou_kaigo_total', ''],
        ['通所を除いた数',   'total_ex_tsusho', '医療介護合計 − 通所リハ'],
        ['入院 本日患者数',  'byoto_zaiin_all', '3F＋4F＋5F'],
    ];
    $month = period_values(substr($date, 0, 8) . '01', $date); ?>
  <h2>この日報から計算される値</h2>
  <table class="report">
    <tr><th>項目</th><th>当日</th><th>今月累計</th><th>計算</th></tr>
    <?php foreach ($show as [$lab, $code, $how]): ?>
    <tr><th><?= h($lab) ?></th><td class="n"><?= num($calc[$code] ?? null) ?></td>
      <td class="n"><?= $code === 'byoto_zaiin_all' ? '' : num($month[$code] ?? null) ?></td>
      <td class="note"><?= h($how) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="note">これまで紙に手書きしていた数字です。医事統計表①の各列にはこの値が入ります。</p>
<?php endif; ?>

<p class="lastupdate">
  <?php $last = last_update_of_dept($date, 'gairai'); ?>
  <?php if ($last && $last['updated_at']): ?>
    最終更新：<?= h($last['updated_at']) ?>　<?= h($last['user_name'] ?? $last['updated_by']) ?>
  <?php else: ?>
    最終更新：まだ入力がありません
  <?php endif; ?>
  <?php if ($sub && $sub['submitted_at']): ?>
    ／ 外来の提出：<?= h($sub['submitted_at']) ?>
  <?php endif; ?>
</p>
<?php page_footer();
