# -*- coding: utf-8 -*-
"""
現行の医事統計表Excel（月ブック）から、入力層の値を CSV に取り出す。

項目マスタ（db/master/items.csv）の excel_ref 列を頼りに、
「どのシートのどの列が、どの item_code に対応するか」を機械的に解決する。
対応表が間違っていればここでズレが出るので、検証にもなる。

開発・移行時にだけ使うツール。本番サーバでは動かさない（xlrdが要るため）。

使い方:  python3 tools/extract_excel.py <book.xls> <年> <月> > out.csv
出力  :  hizuke,item_code,value
"""
import csv, sys, datetime, xlrd

SHEET_ALIAS = {'⑧': '⑧コロナ対策', '⑨': '⑨時間外ウォークイン'}

def col_index(letters):
    n = 0
    for ch in letters:
        n = n * 26 + (ord(ch) - 64)
    return n - 1

def main():
    book_path, year, month = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
    book = xlrd.open_workbook(book_path)
    names = set(book.sheet_names())

    # excel_ref が「シート!列」の形をしている入力項目だけを対象にする。
    # 「総括!N6」のような集計層への参照や、「②!O / H!AM」のような複数参照は
    # 導出項目なので取り込まない。
    targets = []
    with open('db/master/items.csv', encoding='utf-8') as f:
        for row in csv.DictReader(f):
            if row['calc_type'] != 'input':
                continue
            ref = (row['excel_ref'] or '').strip()
            if not ref or '!' not in ref or '/' in ref:
                continue
            sheet, col = ref.split('!', 1)
            sheet = SHEET_ALIAS.get(sheet, sheet)
            if sheet not in names or not col.isalpha():
                continue
            targets.append((row['item_code'], sheet, col_index(col), ref))

    # 各シートで「A列が1〜31の行」を日付行とみなす
    dayrow = {}
    for _, sheet, _, _ in targets:
        if sheet in dayrow:
            continue
        s = book.sheet_by_name(sheet)
        m = {}
        for r in range(s.nrows):
            v = s.cell_value(r, 0)
            if isinstance(v, float) and v == int(v) and 1 <= v <= 31:
                m.setdefault(int(v), r)     # 同じ日が複数あれば最初の行を採る
        dayrow[sheet] = m

    w = csv.writer(sys.stdout)
    w.writerow(['hizuke', 'item_code', 'value'])
    stats = {}
    for code, sheet, ci, ref in targets:
        s = book.sheet_by_name(sheet)
        n = 0
        for day, r in sorted(dayrow[sheet].items()):
            try:
                datetime.date(year, month, day)
            except ValueError:
                continue                     # 月末を超える行は飛ばす
            if ci >= s.ncols:
                continue
            v = s.cell_value(r, ci)
            if v == '' or v is None:
                continue
            if not isinstance(v, float):
                continue                     # 数値以外（担当印など）は取り込まない
            w.writerow([f'{year:04d}-{month:02d}-{day:02d}', code, int(v) if v == int(v) else v])
            n += 1
        stats[code] = n

    empty = [c for c, n in stats.items() if n == 0]
    sys.stderr.write(f'対象 {len(targets)} 項目 / 値を取得できた項目 {len(stats)-len(empty)}\n')
    if empty:
        sys.stderr.write('値が1件も取れなかった項目（excel_ref の確認が必要）: ' + ', '.join(empty) + '\n')

main()
