<?php
/**
 * 部署の入力欄（表示）と、画面から来た値の保存。部署の入力画面（entry.php）と
 * まとめて入力（bulk_entry.php・1日分の全部署）で同じものを使う。
 *
 * 入力欄は項目マスタ（m_item）から自動で作る。当直の会議は「時刻＋会議・委員会名」の組（src/kaigi.php）、
 * 病棟は定数超過報告（最大6名。src/tokki.php）も出す。
 * 項目コードは全部署で重ならないので、何部署分を1つのフォームに並べても v[項目コード] で区別できる。
 */
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/tokki.php';
require_once __DIR__ . '/kaigi.php';

/** 画面から来た値（$post['v'] と、当直の会議の組）を、その部署の 項目コード => 値 にする。 */
function entry_form_values(string $deptId, array $post): array
{
    $values = is_array($post['v'] ?? null) ? $post['v'] : [];
    // 当直の会議は「時刻＋会議・委員会名」の組で入力し、保存は今までの4項目（左右の時刻・名前）に改行でつないで入れる
    if ($deptId === 'toutyoku' && isset($post['kaigi']) && is_array($post['kaigi'])) {
        foreach (kaigi_sides() as $side => [$tCode, $nCode]) {
            [$values[$tCode], $values[$nCode]] = kaigi_join(is_array($post['kaigi'][$side] ?? null) ? $post['kaigi'][$side] : []);
        }
    }
    return $values;
}

/**
 * 保存する（その部署の入力欄と、病棟は定数超過報告）。
 *
 * @return array ['saved' => 件数, 'warnings' => [...]]
 */
function entry_form_save(string $date, string $deptId, array $post, string $userId): array
{
    $res = save_dept_entries($date, $deptId, entry_form_values($deptId, $post), $userId);
    $warnings = $res['warnings'];
    // 病棟は定数超過報告（オーバーベッドの患者・最大6名）も一緒に保存する
    if ($deptId === 'byoto' && isset($post['tokki']) && is_array($post['tokki'])) {
        db()->beginTransaction();
        try {
            $tr = tokki_save($date, $post['tokki'], $userId);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        $warnings = array_merge($warnings, $tr['warnings']);
    }
    return ['saved' => $res['saved'], 'warnings' => $warnings];
}

/**
 * 画面から来た値に、その部署の入力が1つでもあるか（まとめて入力で、何も入れていない部署を保存しないため）。
 */
function entry_form_has_input(string $deptId, array $post): bool
{
    $items = input_items_of_dept($deptId);
    foreach (entry_form_values($deptId, $post) as $code => $v) {
        if (isset($items[$code]) && trim((string)$v) !== '') {
            return true;
        }
    }
    return $deptId === 'byoto' && is_array($post['tokki'] ?? null) && tokki_normalize($post['tokki']) !== [];
}

/** その部署の入力欄を出す（項目の枠ごと。病棟は定数超過報告の枠も）。 */
function entry_form_fields(string $deptId, string $date, array $entries, bool $locked): void
{
    // 表示順のまま group_code でまとめる
    $groups = [];
    foreach (input_items_of_dept($deptId, $date) as $code => $it) {
        $groups[$it['group_code']]['name'] = $it['group_name'];
        $groups[$it['group_code']]['items'][$code] = $it;
    }
    ?>
  <?php foreach ($groups as $gcode => $g): ?>
    <fieldset>
      <legend><?= h($g['name'] ?: $gcode) ?></legend>
      <table class="entry-table">
        <?php foreach ($g['items'] as $code => $it):
            // 会議の4項目は、左右の欄ごとに「時刻＋会議・委員会名」の組の表にまとめて出す
            $kaigiCodes = array_merge(...array_values(kaigi_sides()));
            if ($deptId === 'toutyoku' && in_array($code, $kaigiCodes, true)):
                if ($code !== 'kaigi_jikoku1') { continue; } ?>
        <tr>
          <th>会議・勉強・委員会等</th>
          <td>
            <table class="kaigi-entry"><tr>
              <?php foreach (kaigi_sides() as $side => [$tCode, $nCode]):
                  $pairs = kaigi_pairs((string)($entries[$tCode]['value_text'] ?? ''), (string)($entries[$nCode]['value_text'] ?? ''));
                  $ro = $locked ? ' readonly' : ''; ?>
                <td>
                  <table class="kaigi-side">
                    <tr><th colspan="3" class="kaigi-side-h"><?= $side === 1 ? '病院日誌の左の欄' : '病院日誌の右の欄' ?></th></tr>
                    <tr><th></th><th>時刻</th><th>会議・委員会名</th></tr>
                    <?php for ($i = 0, $rows = max(KAIGI_ROWS, count($pairs)); $i < $rows; $i++): ?>
                      <tr>
                        <th><?= $i + 1 ?></th>
                        <td><input type="text" name="kaigi[<?= $side ?>][<?= $i ?>][time]" value="<?= h($pairs[$i][0] ?? '') ?>"
                                   size="8" placeholder="14:00"<?= $ro ?>></td>
                        <td><input type="text" name="kaigi[<?= $side ?>][<?= $i ?>][name]" value="<?= h($pairs[$i][1] ?? '') ?>"
                                   size="26"<?= $ro ?>></td>
                      </tr>
                    <?php endfor; ?>
                  </table>
                </td>
              <?php endforeach; ?>
            </tr></table>
            <span class="note">1行に1件。時刻は「14:00」と入れると「14:00〜」になります。左の欄が埋まったら右の欄へ。消すときは時刻と名前を空にして保存。</span>
          </td>
        </tr>
        <?php continue; endif;
            $cur  = $entries[$code] ?? null;
            $isMl = $it['value_type'] === 'multiline';
            $isTx = $it['value_type'] === 'text' || $isMl;
            $val  = $cur === null ? '' : ($isTx ? (string)$cur['value_text'] : num_plain($cur['value_num']));
        ?>
        <tr>
          <th<?= (int)$it['required'] === 1 ? ' class="req"' : '' ?>><?= h($it['item_name']) ?></th>
          <td>
            <?php if ($isMl): ?>
              <textarea name="v[<?= h($code) ?>]" rows="3" cols="40"<?= $locked ? ' readonly' : '' ?>><?= h($val) ?></textarea>
            <?php elseif ($isTx): ?>
              <input type="text" name="v[<?= h($code) ?>]" value="<?= h($val) ?>" size="24"<?= $locked ? ' readonly' : '' ?>>
            <?php else: ?>
              <input type="text" inputmode="numeric" name="v[<?= h($code) ?>]" value="<?= h($val) ?>"
                     size="6" class="num"<?= $locked ? ' readonly' : '' ?>>
            <?php endif; ?>
            <span class="unit"><?= h($it['unit']) ?></span>
            <?php if ($it['note']): ?><span class="note"><?= h($it['note']) ?></span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </fieldset>
  <?php endforeach; ?>

  <?php if ($deptId === 'byoto'):
      // 定数超過報告（オーバーベッド）。紙の様式どおり最大6名。在院が定床を超えた日は入力を促す
      $tokkiNow = tokki_rows($date);
      $zaiin = [];
      foreach (['3', '4', '5'] as $w) {
          $e = $entries["byoto{$w}_zaiin"] ?? null;
          $zaiin[$w] = $e === null || $e['value_num'] === null ? null : (int)$e['value_num'];
      }
      $over = tokki_overbed($zaiin, $date); ?>
    <fieldset>
      <legend>定数超過報告（近隣受入不可のため）</legend>
      <?php if ($over !== ''): ?>
        <p class="flash flash-warn">在院患者数が定床を超えています（<?= h($over) ?>）。オーバーベッドの患者を入力してください。</p>
      <?php endif; ?>
      <table class="entry-table tokki-table">
        <tr><th></th><th>病棟</th><th>ＩＤ</th><th>氏名</th><th>入院経路</th><th>病名</th></tr>
        <?php for ($i = 0; $i < TOKKI_MAX; $i++):
            $r = $tokkiNow[$i] ?? []; $f = fn(string $k) => h($r[$k] ?? ''); $ro = $locked ? ' readonly' : ''; ?>
          <tr>
            <th><?= $i + 1 ?></th>
            <td><select name="tokki[<?= $i ?>][ward]"<?= $locked ? ' disabled' : '' ?>>
                  <option value=""></option>
                  <?php foreach (tokki_wards() as $wn): ?>
                    <option<?= ($r['ward'] ?? '') === $wn ? ' selected' : '' ?>><?= h($wn) ?></option>
                  <?php endforeach; ?>
                  <?php if (($r['ward'] ?? '') !== '' && !in_array($r['ward'], tokki_wards(), true)): ?>
                    <option selected><?= $f('ward') ?></option>
                  <?php endif; ?>
                </select></td>
            <td><input type="text" name="tokki[<?= $i ?>][patient_id]" value="<?= $f('patient_id') ?>" size="9" maxlength="16"<?= $ro ?>></td>
            <td><input type="text" name="tokki[<?= $i ?>][patient_name]" value="<?= $f('patient_name') ?>" size="12" maxlength="64"<?= $ro ?>></td>
            <td><input type="text" name="tokki[<?= $i ?>][route]" value="<?= $f('route') ?>" size="9" maxlength="32" list="tokki-routes"<?= $ro ?>></td>
            <td><input type="text" name="tokki[<?= $i ?>][diagnosis]" value="<?= $f('diagnosis') ?>" size="20" maxlength="128"<?= $ro ?>></td>
          </tr>
        <?php endfor; ?>
      </table>
      <datalist id="tokki-routes"><?php foreach (tokki_routes() as $rt): ?><option value="<?= h($rt) ?>"><?php endforeach; ?></datalist>
      <p class="note">最大6名。行を消すときは、その行の欄を空にして保存します。病院日誌の最下段「特記事項等」に出ます。</p>
    </fieldset>
  <?php endif; ?>

<?php
}
