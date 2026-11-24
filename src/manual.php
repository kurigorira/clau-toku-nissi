<?php
/**
 * 操作マニュアル（全職員共通・当直者・部署別・医事課・管理者）。
 *
 * 文章の正本はこのファイルだけ。画面（public/manual.php）・印刷用・PDF（同じ画面の ?pdf=1、
 * まとめて作るときは tools/build_manual_pdf.php）は、ここで作ったHTMLをそのまま使う。
 * 部署ごとの「入力する項目」の表は項目マスタから作るので、項目を足したり移したりすると、マニュアルも自動で変わる。
 *
 * 書き方：利用者向け。画面のボタン名・見出しは、画面の文字とそろえる（変えたらここも直す）。
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/nippo.php';

/** 当直者が入力する4画面以外で、部署別マニュアルを作る部署（ボードに出る入力部署）。 */
function manual_dept_ids(): array
{
    $out = [];
    foreach (all_depts() as $id => $d) {
        if ($id !== DEPT_VIEW_ONLY && !in_array($id, TOUTYOKU_DEPTS, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/** マニュアルの一覧（キー => 表題）。並びは目次の並び。 */
function manual_list(): array
{
    $list = [
        'zenin'    => '全職員共通（開き方・画面の見方・入力のしかた）',
        'toutyoku' => '当直者（日報転記・病棟・救急・当直）',
    ];
    $depts = all_depts();
    foreach (manual_dept_ids() as $id) {
        $list['dept:' . $id] = $depts[$id]['dept_name'];
    }
    $list['ijika'] = '医事課（督促・確定・訂正・帳票）';
    $list['admin'] = '管理者（職員・マスタ・閲覧履歴）';
    return $list;
}

/** ログインした人に関係するマニュアル（最初に開くもの・目次で印を付けるもの）。 */
function manual_for_user(array $user): array
{
    $keys = ['zenin'];
    $own  = $user['dept_id'] ?? '';
    if (in_array($own, manual_dept_ids(), true)) {
        $keys[] = 'dept:' . $own;
    }
    // 医事課・管理者の権限を除いた、その人ふだんの入力権限で判定する
    $real  = $user['role_real'] ?? $user['role'];
    $plain = ['role' => $real === 'toutyoku' ? 'toutyoku' : 'entry'] + $user;
    if (can_edit_dept($plain, 'toutyoku')) {
        $keys[] = 'toutyoku';
    }
    if (can_edit_dept($plain, 'naishikyo') && $own !== 'naishikyo') {
        $keys[] = 'dept:naishikyo';      // 外来の職員
    }
    if (in_array($real, ['ijika', 'admin'], true)) {
        $keys[] = 'ijika';
    }
    if ($real === 'admin') {
        $keys[] = 'admin';
    }
    return array_values(array_unique($keys));
}

/** 最初に開くマニュアル。部署の人は自部署、当直者は当直者、医事課・管理者はそれぞれ。 */
function manual_default(array $user): string
{
    $keys = manual_for_user($user);
    foreach (['admin', 'ijika'] as $k) {
        if (in_array($k, $keys, true)) {
            return $k;
        }
    }
    foreach ($keys as $k) {
        if (strpos($k, 'dept:') === 0) {
            return $k;
        }
    }
    return in_array('toutyoku', $keys, true) ? 'toutyoku' : 'zenin';
}

/** 締切の文。当直は翌朝。 */
function manual_deadline(string $deptId): string
{
    $d = all_depts()[$deptId] ?? null;
    $t = substr((string)($d['deadline_time'] ?? '18:00'), 0, 5);
    return $t < '12:00' ? "翌朝 {$t} まで" : "その日の {$t} まで";
}

/** 部署の入力項目を、画面の枠（グループ）ごとに表にする。$skip に入れた項目コードは出さない。 */
function manual_items_table(string $deptId, array $skip = []): string
{
    $nippo = array_flip(nippo_codes());
    $rows  = [];
    foreach (input_items_of_dept($deptId, date('Y-m-d')) as $code => $it) {
        if (isset($skip[$code]) || strpos($code, '_ikou') !== false) {
            continue;
        }
        $rows[$it['group_name'] ?: $it['group_code']][] = [$code, $it];
    }
    if (!$rows) {
        return '<p>入力する項目はありません。</p>';
    }
    $hasNippo = false;
    $html = '<table class="manual-items"><thead><tr><th>画面の枠</th><th>項目</th><th>単位</th><th>必須</th><th>メモ</th></tr></thead><tbody>';
    foreach ($rows as $group => $list) {
        foreach ($list as $i => [$code, $it]) {
            $fromNippo = isset($nippo[$code]) && $deptId !== 'gairai';
            $hasNippo  = $hasNippo || $fromNippo;
            $kind = ['text' => '文字', 'multiline' => '文字（複数行）'][$it['value_type']] ?? '';
            $html .= '<tr>'
                . ($i === 0 ? '<th rowspan="' . count($list) . '">' . h($group) . '</th>' : '')
                . '<td>' . h($it['item_name']) . ($fromNippo ? ' <span class="from-nippo">日報</span>' : '') . '</td>'
                . '<td>' . h($kind !== '' ? $kind : (string)$it['unit']) . '</td>'
                . '<td class="c">' . ((int)$it['required'] === 1 ? '○' : '') . '</td>'
                . '<td>' . h((string)$it['note']) . '</td></tr>';
        }
    }
    $html .= '</tbody></table>';
    $html .= '<p class="note">必須（○）の項目が空のまま提出すると、入力状況ボードで黄色（欠測あり）になります。0件の日は 0 を入れてください。';
    if ($hasNippo) {
        $html .= '<br><span class="from-nippo">日報</span> の項目は、当直者が電子カルテ日報を転記すると「下書き」として値が入ります。数字を確かめて提出してください（提出したあとは、日報の転記で上書きされません）。';
    }
    return $html . '</p>';
}

/** 入力の手順（部署別・当直者の画面で共通）。 */
function manual_entry_steps(string $deptId): string
{
    $name = all_depts()[$deptId]['dept_name'] ?? $deptId;
    return '<ol>'
        . '<li>入力状況ボード（トップ画面）で「' . h($name) . '」の部署名を押す。過去の日はボードのその日のマスを押す。</li>'
        . '<li>画面上の「日付」が入力する日か確かめる（開いたときは前日）。違う日は日付を変えて「表示」。</li>'
        . '<li>数字を入れる。0件の項目は 0 を入れる（空欄は「未入力」の扱い）。</li>'
        . '<li>途中でやめるときは「一時保存」。入れ終わったら「提出する」。</li>'
        . '<li>画面の上に「確認してください」が出たら、桁の打ち間違いがないか見直す（保存はされています。正しければそのままでよい）。</li>'
        . '</ol>'
        . '<p>提出したあとも、医事課が確定するまでは同じ画面で直せます（直したら、もう一度「提出する」）。'
        . '医事課が確定した日は直せません。直したいときは医事課へ連絡してください。</p>';
}

/** マニュアル本文（HTML）。存在しないキーは null。 */
function manual_body(string $key): ?string
{
    $depts = all_depts();
    if (strpos($key, 'dept:') === 0) {
        $id = substr($key, 5);
        return in_array($id, manual_dept_ids(), true) ? manual_dept($id, $depts[$id]) : null;
    }
    switch ($key) {
        case 'zenin':
            return manual_zenin();
        case 'toutyoku':
            return manual_toutyoku();
        case 'ijika':
            return manual_ijika();
        case 'admin':
            return manual_admin();
    }
    return null;
}

/* ------------------------------------------------------------------ 全職員共通 */

function manual_zenin(): string
{
    $rows = '';
    foreach (all_depts() as $id => $d) {
        if ($id === DEPT_VIEW_ONLY) {
            continue;
        }
        if (in_array($id, TOUTYOKU_DEPTS, true)) {
            $who = '当直者（総務課・医事課・事務部の職員、当直の職員、役割が当直者の人）';
        } elseif ($id === 'naishikyo') {
            $who = '内視鏡室の職員・外来の職員';
        } else {
            $who = $d['dept_name'] . 'の職員';
        }
        $rows .= '<tr><td>' . h($id === 'gairai' ? '外来（日報転記）' : $d['dept_name']) . '</td><td>' . h($who) . '</td><td>' . h(manual_deadline($id)) . '</td></tr>';
    }

    return <<<HTML
<h2>1. このシステムでできること</h2>
<p>病院日誌・医事統計表の数字を、各部署が毎日この画面に入力します。医事課は入力状況を見て督促し、確認して確定します。
病院日誌・救急搬入・総括・患者数統計表・病院報告・本部報告・平均在院日数は、入力された数字から自動で作られます。
Excelに転記したり、電話で入力を確認したりする作業はなくなります。</p>

<h2>2. 開き方・終わり方</h2>
<ul>
  <li><b>電子カルテの画面から開きます。</b>電子カルテに入っている人の職員IDがそのまま使われるので、ログインは要りません。</li>
  <li>電子カルテの端末は共用なので、<b>電子カルテから開き直すと、開いた人に入れ替わります。</b>30分操作しないと自動で終了します。</li>
  <li>終わるときは右上の「終了」を押します。右上には今使っている人の名前と部署が出ています。違う人の名前なら、電子カルテから開き直してください。</li>
  <li>電子カルテが使えない端末では、職員ID とパスワードで入ります（予備ログイン。パスワードは管理者が設定します）。</li>
</ul>

<h2>3. 画面の上のメニュー</h2>
<table class="manual-table">
  <tr><th>メニュー</th><th>中身</th></tr>
  <tr><td>入力状況</td><td>トップ画面。部署ごと・日ごとの入力の状況（色）。部署名を押すと、その部署の入力画面が開く</td></tr>
  <tr><td>病院日誌</td><td>1日分の病院日誌（今までの様式のまま）。「印刷用」「PDFでダウンロード」もここから</td></tr>
  <tr><td>救急搬入</td><td>救急搬入受入統計（８時会・朝礼の報告用）</td></tr>
  <tr><td>総括</td><td>各種業務量他総括（月計・1日平均・目標との差など）</td></tr>
  <tr><td>月別グラフ</td><td>各種業務量の月別の推移</td></tr>
  <tr><td>患者数統計表</td><td>患者数統計表①〜⑤（月報）</td></tr>
  <tr><td>病院報告</td><td>病院報告（患者票）</td></tr>
  <tr><td>本部報告</td><td>徳洲会本部の日報と同じ並びの当日数・累計数</td></tr>
  <tr><td>平均在院日数</td><td>病棟別の増減の内訳と平均在院日数</td></tr>
  <tr><td>マニュアル</td><td>この画面</td></tr>
</table>
<p class="note">医事課・管理者には、ほかに「まとめて入力」「変更履歴」（管理者は「マスタ」「職員」「閲覧履歴」も）が出ます。</p>

<h2>4. 入力状況ボードの見方</h2>
<table class="manual-table">
  <tr><th>色</th><th>意味</th></tr>
  <tr><td><span class="badge st-none">未入力</span></td><td>まだ何も入っていない</td></tr>
  <tr><td><span class="badge st-partial">途中・欠測あり</span></td><td>一時保存のまま、または提出したが必須の項目に空きがある</td></tr>
  <tr><td><span class="badge st-submitted">提出済</span></td><td>部署が提出した</td></tr>
  <tr><td><span class="badge st-confirmed">医事課確定</span></td><td>医事課が確定した。部署はもう直せない</td></tr>
</table>
<p>上の「○月○日の未提出」には、前日の分でまだ提出されていない部署と、空いている項目の名前が出ます。
部署名の横の「当直者が記載」は当直者が入力する画面、「外来も記載」は外来の職員も入力できる画面です。</p>

<h2>5. 入力のしかた（入力する部署の人）</h2>
<ol>
  <li>入力状況ボードで自分の部署名を押す（過去の日は、ボードのその日のマスを押す）。</li>
  <li>画面上の「日付」を確かめる（開いたときは前日）。違う日は日付を変えて「表示」。</li>
  <li>数字を入れる。<b>0件の項目は 0 を入れる</b>（空欄は「未入力」の扱い）。休みの日も 0 を入れて提出します。</li>
  <li>途中でやめるときは「一時保存」、入れ終わったら「<b>提出する</b>」。</li>
  <li>「確認してください」と出たら、桁の打ち間違いがないか見直す（保存はされています。正しければそのままでよい）。
      数字の欄に文字を入れたときは、その欄だけ保存されません。</li>
</ol>
<p>提出したあとも、医事課が確定するまでは同じ画面で直せます（直したら、もう一度「提出する」）。
画面の下に、最後に直した人と時刻、提出した時刻が出ます。数字を直すと、前の値と新しい値・直した人・時刻が変更履歴に残ります。</p>

<h2>6. どの画面に誰が入力するか</h2>
<table class="manual-table">
  <tr><th>入力画面</th><th>入力する人</th><th>締切</th></tr>
  {$rows}
</table>
<p class="note">医事課・管理者は、パスワードを確かめたあと、すべての画面に入力・訂正できます。
病棟・救急・医師事務・その他の職員は、入力する画面がありません（見ることはできます）。
自分の部署の画面で「入力権限がありません」と出るときは、職員の登録（入力する画面）が違っています。管理者へ連絡してください。</p>

<h2>7. 印刷・PDF</h2>
<ul>
  <li>病院日誌：「印刷用」を開いてブラウザで印刷（A4縦）。「PDFでダウンロード」でPDFも作れます。</li>
  <li>総括・統計表などは、ブラウザの印刷（Ctrl+P）で印刷します。</li>
  <li>このマニュアルも、上の「印刷用」「PDFでダウンロード」で印刷・保存できます。</li>
</ul>

<h2>8. 個人情報について</h2>
<ul>
  <li>病院日誌の「特記事項等」には患者の氏名が入ります。画面を開いたまま席を離れないでください。</li>
  <li>誰がいつどの画面を見たかは記録されています（閲覧履歴）。</li>
</ul>

<h2>9. 困ったとき</h2>
<table class="manual-table">
  <tr><th>こんなとき</th><th>どうする</th></tr>
  <tr><td>「入力権限がありません」と出る</td><td>右上の名前と部署を確かめる。違う人なら電子カルテから開き直す。自分なのに出るときは管理者へ</td></tr>
  <tr><td>「医事課が確定済みです。編集できません」と出る</td><td>医事課へ連絡（医事課が訂正するか、確定を外す）</td></tr>
  <tr><td>前の日の数字を間違えていた</td><td>確定前なら、その日のマスを押して直し、もう一度「提出する」</td></tr>
  <tr><td>画面がおかしい・つながらない</td><td>情報システム担当（管理者）へ連絡</td></tr>
</table>
HTML;
}

/* ------------------------------------------------------------------ 当直者 */

function manual_toutyoku(): string
{
    $nippoSet = array_flip(nippo_codes());
    $gairaiOther = manual_items_table('gairai', $nippoSet);
    $byoto   = manual_items_table('byoto');
    $kyukyu  = manual_items_table('kyukyu');
    $tou     = manual_items_table('toutyoku');
    $dl      = h(manual_deadline('toutyoku'));

    return <<<HTML
<h2>1. 当直者とは</h2>
<p>総務課・医事課・事務部の職員（医師事務作業補助・秘書を除く）、当直の職員、役割が「当直者」の職員です。
当直者は、<b>外来（日報転記）・病棟・救急・当直</b> の4つの画面に入力します。入力状況ボードでは、部署名の横に「当直者が記載」と出ています。
締切は <b>{$dl}</b> です。</p>

<h2>2. 毎日の流れ</h2>
<ol>
  <li><b>外来（日報転記）</b>：電子カルテの「外来患者数（科別）日報」「入院患者数日報」を転記する（3章）。</li>
  <li><b>病棟</b>：転記で入った入退院の下書きを確かめ、在院患者数などを入れて提出（4章）。</li>
  <li><b>救急</b>：救急搬入の件数などを入れて提出（5章）。</li>
  <li><b>当直</b>：天候・当直者・初診・コロナ対策・特記事項などを入れて提出（6章）。</li>
</ol>
<p>入力状況ボードの「外来」「病棟」「救急」「当直」の部署名を押すと、それぞれの画面が開きます。
開いたときの日付は前日です。夜中に前の日の分を入れるときは、日付を確かめてください。</p>

<h2>3. 外来（日報転記）</h2>
<p>電子カルテから毎日出る紙「外来患者数（科別）日報」「入院患者数日報」を、<b>紙と同じ並び</b>で打ち込む画面です。</p>
<ol>
  <li>日付を確かめる（開いたときは前日）。</li>
  <li>日報のExcelがあるときは、画面の上で「外来日報（.xlsm / .xlsx）」「入院日報（.xlsm / .xlsx）」を選び「<b>Excelから読み込む</b>」。
      片方だけでも読めます。欄に数字が入るだけで、まだ保存はしません。</li>
  <li>Excelが無い欄は、紙を見て打つ（科別×時間帯の来院数・新来数、再掲、訪問診療科内訳、介護、入院の入退院と科別内訳）。<b>空欄は 0 として保存</b>します。</li>
  <li>紙に印字されている<b>医科合計（来院・新来）・介護合計・医療介護合計・各病棟の前日患者数</b>も打つ。</li>
  <li>「<b>照合して保存</b>」。紙の合計と合わない欄は赤くなり、<b>保存されません</b>。打ち間違いを直してもう一度。</li>
  <li>合っていれば保存され、病棟（入退院）や通所リハ・訪問看護・地域支援などの画面に「下書き」として値が入ります。</li>
  <li>最後に「<b>照合して保存し、外来の日報を提出する</b>」で外来を提出します。</li>
</ol>
<p class="note">「提出済みの部署の値は上書きしていません」と出たときは、その部署がもう提出していて、日報の数字と違う項目があります。一覧を見て、必要ならその部署か医事課に直してもらってください。
画面の下の「この日報から計算される値」には、紙の余白に手書きしていた数字（外来患者数・通所を除いた数・累計など）が出ます。</p>
<h3>日報に載っていない外来の項目</h3>
{$gairaiOther}

<h2>4. 病棟</h2>
<p>日報転記をすると、入院・退院・転入・転出などが下書きで入ります。数字を確かめ、在院患者数などを入れて「提出する」。</p>
<p><b>定数超過報告（近隣受入不可のため）</b>：在院患者数が定床を超えた日は「オーバーベッドの患者を入力してください」と出ます。
病棟・ID・氏名・入院経路・病名を入れます（最大6名）。行を消すときは、その行の欄を空にして保存します。病院日誌の最下段「特記事項等」に出ます。</p>
{$byoto}

<h2>5. 救急</h2>
{$kyukyu}

<h2>6. 当直</h2>
<p><b>特記事項</b>は自由に書ける欄で、病院日誌の最下段「特記事項等」に出ます。患者の氏名を書くことがあるので、取り扱いに注意してください。</p>
{$tou}
HTML;
}

/* ------------------------------------------------------------------ 部署別 */

function manual_dept(string $id, array $d): string
{
    $name  = h($d['dept_name']);
    $who   = $id === 'naishikyo' ? '内視鏡室の職員と<b>外来の職員</b>' : "{$name}の職員";
    $extra = '';
    switch ($id) {
        case 'renkei':
            $extra = '<h2>会議・勉強・委員会等</h2><p>「時刻＋会議・委員会名」を<b>1行に1件</b>入れます。時刻は「14:00」と入れると「14:00〜」になります。'
                . '左の欄が埋まったら右の欄へ。消すときは時刻と名前を空にして保存します。病院日誌の左の欄・右の欄に出ます（多い日は行が増えます）。</p>';
            break;
        case 'shinjo':
            $extra = '<p>癌新登録数は、2026年11月から診療情報管理室が入力します（それまでは検査科の画面でした）。</p>';
            break;
        case 'naishikyo':
            $extra = '<p>内視鏡室が休みの日など、外来の職員も入力できます。どちらが入れても、入力した人と時刻が残ります。</p>';
            break;
    }
    $dl   = h(manual_deadline($id));
    $step = manual_entry_steps($id);
    $tbl  = manual_items_table($id);

    return <<<HTML
<h2>1. 入力する人・締切</h2>
<p>入力するのは {$who} です（医事課・管理者も入力・訂正できます）。締切は <b>{$dl}</b> です。
職員の登録で「入力する画面」が{$name}になっている人が入力できます。入力できないときは管理者へ連絡してください。</p>
{$extra}
<h2>2. 入力のしかた</h2>
{$step}
<h2>3. 入力する項目</h2>
{$tbl}
<p class="note">開き方・画面の見方・困ったときは「全職員共通」のマニュアルを見てください。</p>
HTML;
}

/* ------------------------------------------------------------------ 医事課 */

function manual_ijika(): string
{
    return <<<HTML
<h2>1. 医事課として作業する</h2>
<p>電子カルテから開いたときは、まず <b>入力者として</b> 動きます（自部署の入力はそのままできます）。
全部署の訂正・確定・まとめて入力・変更履歴・CSV出力を使うときは、右上の「<b>医事課として作業</b>」を押してパスワードを入れ「確定」します。
予備ログイン（職員ID＋パスワード）で入ったときは、最初から医事課として作業できます。</p>

<h2>2. 毎朝の確認と督促</h2>
<ol>
  <li>入力状況ボードの上の「○月○日の未提出」で、前日の分を出していない部署と、空いている項目を見る。</li>
  <li>赤（未入力）・黄（途中・欠測あり）の部署に連絡する。部署名を押すと、その部署の画面が開いて中身を確かめられます。</li>
  <li>当直者が入れる4画面（外来・病棟・救急・当直）の締切は翌朝9時、ほかの部署はその日の18時です。</li>
</ol>

<h2>3. 確定・確定を外す</h2>
<ul>
  <li>入力状況ボードのいちばん下に「<b>医事課の確定</b>」の行が出ます（医事課・管理者だけ）。</li>
  <li>日付の「<b>確定</b>」で、その日の <b>提出済み（緑）の部署だけ</b> をまとめて確定します（濃い緑）。赤・黄の部署は確定されず、どの部署を残したかが画面に出ます。</li>
  <li>確定した日は、その部署の画面では直せなくなります。</li>
  <li>「<b>外す</b>」で確定を外すと、提出済みに戻り、部署がまた直せるようになります。</li>
</ul>

<h2>4. 訂正</h2>
<ul>
  <li>ボードのマスを押すと、その日・その部署の画面が開きます。医事課は確定済みの日でも直せます（画面に「医事課として訂正できますが、変更履歴に残ります」と出ます）。</li>
  <li>部署の画面の日付の横のリストで、ほかの部署に切り替えられます。</li>
  <li>直した値は、前の値・新しい値・直した人・時刻が「変更履歴」に残ります。「訂正のみ」で、新規の入力を除いて見られます。</li>
</ul>

<h2>5. まとめて入力</h2>
<p>メニューの「まとめて入力」で、1日分の全部署を1画面で入れられます（紙の控えから過去の日を埋めるときなど）。
<b>値を入れた部署だけ</b>保存・提出されます（何も入れていない部署は「未入力」のまま）。確定済みの部署も訂正でき、変更履歴に残ります。</p>

<h2>6. 帳票・CSV</h2>
<table class="manual-table">
  <tr><th>帳票</th><th>使い方</th></tr>
  <tr><td>病院日誌</td><td>日付を選んで表示。「印刷用」（A4縦・左とじ）・「PDFでダウンロード」。末尾に部署ごとの入力者・入力時刻が出る</td></tr>
  <tr><td>救急搬入</td><td>８時会・朝礼の報告用。当日・累計・受入率</td></tr>
  <tr><td>総括</td><td>前日までの当月分（期間も指定できる）。CSV出力あり</td></tr>
  <tr><td>患者数統計表</td><td>①〜⑤を月ごとに。CSV出力あり</td></tr>
  <tr><td>病院報告</td><td>保健所への提出様式（患者票）。CSV出力あり</td></tr>
  <tr><td>本部報告</td><td>徳洲会本部の日報と同じ並びの当日数・累計数</td></tr>
  <tr><td>平均在院日数</td><td>病棟別の増減の内訳と平均在院日数</td></tr>
  <tr><td>変更履歴</td><td>誰がいつ何をどう直したか。CSV出力あり</td></tr>
</table>
<p class="note">CSV は Excel でそのまま開けます。外来の日報転記も、医事課は当直者と同じように行えます（「当直者」のマニュアル）。</p>
HTML;
}

/* ------------------------------------------------------------------ 管理者 */

function manual_admin(): string
{
    $roles = '';
    foreach (user_roles() as $code => $label) {
        $desc = [
            'entry'    => '「入力する画面」の部署に入力する（普通の職員）',
            'toutyoku' => '当直者として、外来（日報転記）・病棟・救急・当直に入力する（総務課・医事課・事務部の職員は、役割が入力者でも当直者として入力できる）',
            'ijika'    => 'パスワード確認のあと、全部署の訂正・確定・まとめて入力・変更履歴・CSV',
            'admin'    => '医事課のすべてに加えて、マスタ・職員・閲覧履歴',
        ][$code] ?? '';
        $roles .= '<tr><td>' . h($label) . '</td><td>' . h($desc) . '</td></tr>';
    }

    return <<<HTML
<h2>1. 管理者として作業する</h2>
<p>電子カルテから開いたときは、右上の「<b>管理者として作業</b>」でパスワードを入れてから、マスタ・職員・閲覧履歴を使います。</p>

<h2>2. 職員（メニューの「職員」）</h2>
<ul>
  <li><b>医事システムから読み込む</b>：医事システム（NEWTON）の職員から、電子カルテID・氏名・部署名を読みます。確認の画面が出てから登録します。
      「医事システムに無い職員を無効にする」で退職者を整理できます。</li>
  <li><b>CSVで一括登録</b>：CSVからも同じように登録できます。</li>
  <li><b>登録済みの職員</b>：職員ID・氏名・部署（電子カルテ）・<b>入力する画面</b>・<b>役割</b>・有効・予備ログイン。変えたら「一覧の変更を保存」。</li>
  <li>新しい部署の職員（例：診療情報管理室）を登録したら、「入力する画面」をその部署にします。</li>
  <li>予備ログイン（電子カルテが使えない端末用）のパスワードは、職員ごとに設定します（8文字以上。空にすると無効）。</li>
</ul>
<table class="manual-table">
  <tr><th>役割</th><th>できること</th></tr>
  {$roles}
</table>
<p class="note">医師事務作業補助・秘書の職員は当直者に含めません（閲覧のみ）。病棟・救急の職員も閲覧のみです。</p>

<h2>3. マスタ（メニューの「マスタ」）</h2>
<ul>
  <li><b>事業計画（月別の目標）の取り込み</b>：事業計画指標値のExcelを選ぶと、確認の画面のあと月別の目標が登録されます。</li>
  <li><b>1日平均の目標</b>・<b>追加・上書き</b>：目標値や、表示順・必須・上下限などの軽い変更。</li>
  <li>項目や部署そのものを増やす・移すときは、項目マスタ（db/master の CSV）を直してサーバに反映します（情報システム担当の作業。README「よくある変更」）。</li>
</ul>

<h2>4. 閲覧履歴（メニューの「閲覧履歴」）</h2>
<p>誰がいつどの画面を見たか（特記事項・CSV出力を含む）を確かめられます。</p>

<h2>5. サーバの更新・バックアップ</h2>
<p>新しい版に置き直す手順、毎日のバックアップと復旧、止まったときの対応は README と「導入手順書」「システムレポート」（docs フォルダ）にあります。</p>
HTML;
}

/* ------------------------------------------------------------------ 印刷用・PDF */

/** マニュアルの見た目（画面と印刷用で同じ）。院内端末のChromeが古いので、gap・grid は使わない。 */
function manual_css(): string
{
    return <<<CSS
.manual-body h2 { margin: 22px 0 8px; padding: 3px 8px; font-size: 17px; color: #1f4e79; background: #eef2f7; border-left: 5px solid #1f4e79; }
.manual-body h3 { margin: 16px 0 6px; font-size: 15px; color: #1f4e79; }
.manual-body p, .manual-body li { line-height: 1.7; }
.manual-body ol, .manual-body ul { padding-left: 1.6em; }
.manual-body table { border-collapse: collapse; margin: 6px 0 10px; background: #fff; }
.manual-body th, .manual-body td { border: 1px solid #b8c4d4; padding: 3px 8px; font-size: 13px; vertical-align: top; text-align: left; }
.manual-body thead th, .manual-table tr:first-child th { background: #eef2f7; }
.manual-items thead th { white-space: nowrap; }
.manual-items tbody th { background: #f6f8fb; font-weight: normal; white-space: nowrap; }
.manual-body td.c { text-align: center; }
.manual-body .note { color: #555; font-size: 13px; }
.from-nippo { display: inline-block; padding: 0 4px; font-size: 11px; color: #2c4a6b; background: #dde7f2; border-radius: 3px; }
.badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 12px; }
.st-none { background: #f2a6a6; } .st-partial { background: #f6dd8e; } .st-submitted { background: #a8d8a8; } .st-confirmed { background: #5aa45a; }
CSS;
}

/** 印刷用（PDFの元）のHTML。ヘッダ・メニューは出さない。 */
function manual_print_html(string $key): ?string
{
    $body = manual_body($key);
    if ($body === null) {
        return null;
    }
    $title = manual_list()[$key];
    $css   = manual_css();
    $ver   = defined('APP_VERSION') ? APP_VERSION : '';
    return '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>' . h('マニュアル　' . $title) . '</title><style>'
        . '@page { size: A4 portrait; margin: 14mm 13mm 16mm; }'
        . 'body { margin: 0; font-family: "Yu Gothic", "Meiryo", "IPAPGothic", "IPAGothic", sans-serif; font-size: 14px; color: #111; background: #fff; }'
        . '.manual-head { border-bottom: 2px solid #1f4e79; padding-bottom: 6px; margin-bottom: 6px; }'
        . '.manual-head .sys { font-size: 12px; color: #555; } .manual-head h1 { margin: 2px 0 0; font-size: 21px; color: #1f4e79; }'
        . '.manual-body h2 { page-break-after: avoid; } .manual-body tr { page-break-inside: avoid; }'
        . '.manual-foot { margin-top: 18px; font-size: 11px; color: #777; }'
        . $css . '</style></head><body>'
        . '<div class="manual-head"><div class="sys">長崎北徳洲会病院　病院日誌・医事統計 操作マニュアル</div><h1>' . h($title) . '</h1></div>'
        . '<div class="manual-body">' . $body . '</div>'
        . '<p class="manual-foot">版 ' . h($ver) . '（' . h(date('Y年n月j日')) . ' 作成）。最新の内容は、システムの「マニュアル」タブで見られます。</p>'
        . '</body></html>';
}

/** PDFのファイル名に使う短い名前（例：02_当直者.pdf）。 */
function manual_file_name(string $key): string
{
    $keys  = array_keys(manual_list());
    $no    = array_search($key, $keys, true);
    $title = preg_replace('/（.*$/u', '', manual_list()[$key]);
    return sprintf('%02d_%s.pdf', ($no === false ? 0 : $no) + 1, $title);
}
