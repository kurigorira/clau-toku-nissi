<?php
/**
 * 操作マニュアル（全職員が見られる）。
 *
 * 左に目次（全職員共通・当直者・部署別・医事課・管理者）、右に本文。開いたときは、その人に関係するもの
 * （部署の人は自部署、当直者は当直者、医事課・管理者はそれぞれ）を出す。目次の ★ はその人に関係するもの。
 * ?print=1 で印刷用、?pdf=1 で PDF をダウンロード（サーバの Chrome / Edge。src/pdf.php）。文章の正本は src/manual.php。
 */
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/manual.php';
require_once __DIR__ . '/../src/pdf.php';

$user = require_login();
$list = manual_list();
$mine = manual_for_user($user);
$key  = (string)($_GET['m'] ?? '');
$lastDept = '';      // 目次で、部署別の最後の後に「医事課・管理者」の区切りを入れる
foreach ($list as $k => $t) {
    if (strpos($k, 'dept:') === 0) {
        $lastDept = $k;
    }
}
if (!isset($list[$key])) {
    $key = manual_default($user);
}

if (!empty($_GET['print']) || !empty($_GET['pdf'])) {
    $html = (string)manual_print_html($key);
    if (!empty($_GET['print'])) {
        echo $html;
        exit;
    }
    session_write_close();      // PDFを作っている間も、同じ人が他の画面を開けるように
    $r = html_to_pdf($html);
    if ($r['pdf'] !== null) {
        $name = 'マニュアル_' . manual_file_name($key);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="manual.pdf"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . strlen($r['pdf']));
        echo $r['pdf'];
        exit;
    }
    page_header('マニュアルのPDF', $user);
    flash('PDFを作れませんでした：' . $r['error'], 'error');
    ?>
    <p>印刷用の画面から、PDFとして保存できます。</p>
    <ol>
      <li><a href="manual.php?m=<?= h(urlencode($key)) ?>&amp;print=1" target="_blank">印刷用を開く</a></li>
      <li>Ctrl+P を押し、送信先を「PDFに保存」にして「保存」</li>
    </ol>
    <p><a href="manual.php?m=<?= h(urlencode($key)) ?>">マニュアルに戻る</a></p>
    <?php
    page_footer();
    exit;
}

page_header('マニュアル', $user);
?>
<style><?= manual_css() ?></style>
<div class="manual">
  <nav class="manual-toc">
    <p class="manual-toc-head">目次</p>
    <ul>
      <?php foreach ($list as $k => $title): ?>
        <li<?= $k === $key ? ' class="current"' : '' ?>>
          <?php if (in_array($k, $mine, true)): ?><span class="mine" title="あなたに関係するマニュアル">★</span><?php endif; ?>
          <a href="manual.php?m=<?= h(urlencode($k)) ?>"><?= h(strpos($k, 'dept:') === 0 ? $title : preg_replace('/（.*$/u', '', $title)) ?></a>
        </li>
        <?php if ($k === 'toutyoku'): ?><li class="toc-sep">部署別</li><?php endif; ?>
        <?php if ($k === $lastDept): ?><li class="toc-sep">医事課・管理者</li><?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <p class="note">★はあなたに関係するマニュアルです。</p>
  </nav>
  <article class="manual-main">
    <h2 class="manual-title"><?= h($list[$key]) ?></h2>
    <p class="manual-actions">
      <a href="manual.php?m=<?= h(urlencode($key)) ?>&amp;print=1" target="_blank">印刷用</a>
      <a href="manual.php?m=<?= h(urlencode($key)) ?>&amp;pdf=1">PDFでダウンロード</a>
    </p>
    <div class="manual-body"><?= manual_body($key) ?></div>
  </article>
</div>
<?php page_footer();
