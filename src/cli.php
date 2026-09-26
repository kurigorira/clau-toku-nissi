<?php
/**
 * コマンドラインツール（db/tools/*.php）共通の引数解析。
 *
 * 院内サーバのコマンドプロンプトで日本語を打つと、次の2つが起きる。
 *
 * 1. 文字コード … 既定が Shift_JIS（CP932）なので、--name=栗原 はSJISのバイト列で届く。
 * 2. 全角スペース … IMEがオンのまま空白キーを押すと全角スペース（U+3000）が入る。
 *    cmd は半角スペースでしか区切らないので、
 *      --name=栗原　--dept=jimu
 *    が1つの引数になり、--dept が消える。実機で「部署ID '' が存在しません」になった原因。
 *
 * また PHP の getopt() は、最初の非オプション引数で解析をやめて残りを黙って捨てる。
 * そのため getopt() は使わず、ここで解く。
 */

/**
 * 文字列をUTF-8に揃える。すでにUTF-8として妥当なら何もしない（chcp 65001 済みの場合など）。
 */
function cli_to_utf8(string $s): string
{
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    return mb_convert_encoding($s, 'UTF-8', 'SJIS-win');
}

/**
 * 引数を解く。
 *
 * 戻り値:
 *   'opt'   => ['id' => '108699', 'list' => true, ...]   --key=value と --flag
 *   'extra' => [[直前のオプション名|null, '剛'], ...]      -- で始まらない引数
 *
 * 全角スペースのあとに -- が続く所は、引数の区切りとみなして分ける。
 * 氏名の中の全角スペース（栗原　剛）は区切りではないのでそのまま残る。
 */
function cli_args(array $argv): array
{
    $pieces = [];
    foreach (array_slice($argv, 1) as $raw) {
        // Shift_JISのままでは全角スペース（\x81\x40）を見つけられないので、先にUTF-8にする
        $a = cli_to_utf8((string)$raw);
        foreach (preg_split('/\x{3000}+(?=--)/u', $a) as $p) {
            $p = preg_replace('/^\x{3000}+|\x{3000}+$/u', '', $p);
            if ($p !== '') {
                $pieces[] = $p;
            }
        }
    }

    $opt   = [];
    $extra = [];
    $last  = null;
    foreach ($pieces as $p) {
        if (strpos($p, '--') === 0) {
            $kv = explode('=', substr($p, 2), 2);
            $opt[$kv[0]] = isset($kv[1]) ? preg_replace('/^\x{3000}+|\x{3000}+$/u', '', $kv[1]) : true;
            $last = $kv[0];
        } else {
            $extra[] = [$last, $p];
        }
    }
    return ['opt' => $opt, 'extra' => $extra];
}
