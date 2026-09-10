#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
docs/導入手順書.pdf と docs/システムレポート.pdf を生成する。

文章の正本はこのスクリプト。同じ内容のMarkdownを別に置くと、
このシステム自体が解こうとしている「同じ数字を二箇所に書く」を再現してしまうため作らない。

院内サーバには Python も reportlab も無いので、生成したPDFはリポジトリに入れる。

使い方:  python3 tools/build_pdf.py
必要:    pip install reportlab  ＋ 日本語TrueTypeフォント（IPAゴシック）
"""

import os
import re
import sys

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (BaseDocTemplate, Frame, KeepTogether, PageBreak,
                                Paragraph, Spacer, Table, TableStyle)

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIR = os.path.join(ROOT, 'docs')

HOSPITAL = '長崎北徳洲会病院'
SYSTEM = '病院日誌・医事統計表 入力システム'

# ---------------------------------------------------------------- フォント
# 環境によって置き場所が違うので候補を順に探す。
# 見つからないまま作ると日本語が全部「豆腐」になるので、その場合は止める。
FONT_CANDIDATES = {
    'JP': [  # 本文用（プロポーショナル）
        '/usr/share/fonts/opentype/ipafont-gothic/ipagp.ttf',
        '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf',
        'C:/Windows/Fonts/meiryo.ttc',
        '/System/Library/Fonts/ヒラギノ角ゴシック W3.ttc',
    ],
    'JPMono': [  # コマンド表示用（ASCIIが等幅のもの）
        '/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf',
        '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf',
        'C:/Windows/Fonts/msgothic.ttc',
    ],
}


def register_fonts():
    for name, paths in FONT_CANDIDATES.items():
        for path in paths:
            if os.path.exists(path):
                try:
                    pdfmetrics.registerFont(TTFont(name, path))
                except Exception as e:      # ttc など読めない形式があり得る
                    sys.stderr.write('  読めませんでした: %s (%s)\n' % (path, e))
                    continue
                print('  %-7s %s' % (name, path))
                break
        else:
            sys.stderr.write(
                '日本語フォントが見つかりません（%s）。\n'
                'Debian/Ubuntu なら  apt-get install fonts-ipafont-gothic\n' % name)
            sys.exit(1)


# ---------------------------------------------------------------- スタイル
BLUE = colors.HexColor('#1f4e79')
GRAY = colors.HexColor('#555555')
LIGHT = colors.HexColor('#eef2f7')
LINE = colors.HexColor('#b8c4d4')
WARN = colors.HexColor('#c0392b')


def styles(base_size, leading_ratio=1.45):
    s = base_size
    lead = s * leading_ratio
    return {
        'body': ParagraphStyle('body', fontName='JP', fontSize=s, leading=lead,
                               spaceAfter=s * 0.35),
        'small': ParagraphStyle('small', fontName='JP', fontSize=s - 0.8,
                                leading=(s - 0.8) * 1.4, textColor=GRAY),
        'h1': ParagraphStyle('h1', fontName='JP', fontSize=s + 4.5, leading=(s + 4.5) * 1.3,
                             textColor=BLUE, spaceBefore=s * 1.4, spaceAfter=s * 0.5,
                             keepWithNext=1),
        'h2': ParagraphStyle('h2', fontName='JP', fontSize=s + 1.2, leading=(s + 1.2) * 1.3,
                             textColor=BLUE, spaceBefore=s * 0.9, spaceAfter=s * 0.3,
                             keepWithNext=1),
        'cell': ParagraphStyle('cell', fontName='JP', fontSize=s - 0.8,
                               leading=(s - 0.8) * 1.35),
        'cellh': ParagraphStyle('cellh', fontName='JP', fontSize=s - 0.8,
                                leading=(s - 0.8) * 1.35, textColor=colors.white),
        'code': ParagraphStyle('code', fontName='JPMono', fontSize=s - 0.5,
                               leading=(s - 0.5) * 1.35),
        # 打ち込むコマンドの行はCourierで出す。日本語フォントは半角の \ を ¥ で、
        # " を ” で描くため、そのまま真似して打つと動かない行に見えてしまう。
        'codeascii': ParagraphStyle('codeascii', fontName='Courier', fontSize=s - 0.3,
                                    leading=(s - 0.5) * 1.35),
        'title': ParagraphStyle('title', fontName='JP', fontSize=s + 8, leading=(s + 8) * 1.25,
                                textColor=BLUE, alignment=TA_CENTER),
        'subtitle': ParagraphStyle('subtitle', fontName='JP', fontSize=s + 0.5,
                                   leading=(s + 0.5) * 1.4, textColor=GRAY, alignment=TA_CENTER),
    }


_EMPH = re.compile(r'<b>(.*?)</b>', re.S)


def emph(text):
    """強調を色で表す。

    IPAゴシックには太字がなく、reportlab は太字を合成しないので、
    <b> と書いても本文と同じ字面になってしまう。色を重ねて見分けられるようにする。
    """
    return _EMPH.sub('<b><font color="#1f4e79">\\1</font></b>', text)


def P(text, style, plain=False):
    """段落。plain=True のときだけ強調の色付けをしない（ヘッダ行など地色が濃い場合）。"""
    return Paragraph(text if plain else emph(text), style)


def table(data, widths, st, header=True, align=None, size_delta=0.0):
    """1行目をヘッダとした表。セルはParagraphにして折り返させる。"""
    cell, cellh = st['cell'], st['cellh']
    rows = []
    for r_i, row in enumerate(data):
        out = []
        for c_i, v in enumerate(row):
            is_head = header and r_i == 0
            out.append(P(str(v), cellh if is_head else cell, plain=is_head))
        rows.append(out)

    t = Table(rows, colWidths=widths, repeatRows=1 if header else 0)
    cmds = [
        ('GRID', (0, 0), (-1, -1), 0.4, LINE),
        ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
        ('LEFTPADDING', (0, 0), (-1, -1), 3.5),
        ('RIGHTPADDING', (0, 0), (-1, -1), 3.5),
        ('TOPPADDING', (0, 0), (-1, -1), 2.5),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 2.5),
    ]
    if header:
        cmds += [('BACKGROUND', (0, 0), (-1, 0), BLUE),
                 ('ROWBACKGROUNDS', (0, 1), (-1, -1), [colors.white, LIGHT])]
    else:
        cmds += [('ROWBACKGROUNDS', (0, 0), (-1, -1), [colors.white, LIGHT])]
    if align:
        for col, a in align.items():
            cmds.append(('ALIGN', (col, 0), (col, -1), a))
    t.setStyle(TableStyle(cmds))
    return t


def _mixed_code(line):
    """コマンド行を、半角の連なり＝Courier / それ以外＝日本語フォント に振り分ける。

    1行に「C:\\php\\php db\\tools\\add_user.php  ← 登録内容の確認」のように
    半角と日本語が混ざるため、行単位ではなく文字の並び単位で切り替える。
    """
    def esc(s):
        return (s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
                 .replace(' ', '&nbsp;'))

    out, buf, buf_ascii = [], '', True
    for ch in line:
        is_ascii = ch.isascii()
        if buf and is_ascii != buf_ascii:
            out.append(esc(buf) if buf_ascii else '<font face="JPMono">%s</font>' % esc(buf))
            buf = ''
        buf_ascii, buf = is_ascii, buf + ch
    if buf:
        out.append(esc(buf) if buf_ascii else '<font face="JPMono">%s</font>' % esc(buf))
    return ''.join(out)


def codebox(lines, st, indent=0):
    """コマンドを枠付きで出す。1行1セルにして等幅で描く。

    行の中身はそのまま打ち込むものなので、< > & はタグと解釈させずに文字として出す。
    半角の部分はCourierで描く。日本語フォントは半角の \\ を ¥、" を ” の形で持っており、
    そのまま真似して打つと動かない行に見えてしまうため。
    """
    rows = []
    for ln in lines:
        rows.append([Paragraph(_mixed_code(ln), st['codeascii'])])
    t = Table(rows, colWidths=[None])
    t.setStyle(TableStyle([
        ('BOX', (0, 0), (-1, -1), 0.5, LINE),
        ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#f6f8fa')),
        ('LEFTPADDING', (0, 0), (-1, -1), 6),
        ('RIGHTPADDING', (0, 0), (-1, -1), 4),
        ('TOPPADDING', (0, 0), (-1, -1), 1),
        ('BOTTOMPADDING', (0, 0), (-1, -1), 1),
    ]))
    if indent:
        t = Table([[t]], colWidths=[None])
        t.setStyle(TableStyle([('LEFTPADDING', (0, 0), (-1, -1), indent),
                               ('RIGHTPADDING', (0, 0), (-1, -1), 0),
                               ('TOPPADDING', (0, 0), (-1, -1), 0),
                               ('BOTTOMPADDING', (0, 0), (-1, -1), 0)]))
    return t


class Doc(BaseDocTemplate):
    """通しノンブルと、右下に文書名を入れるだけのテンプレート。"""

    def __init__(self, path, footer='', margins=(18, 16, 16, 14), show_page=True, **kw):
        top, bottom, left, right = [m * mm for m in margins]
        BaseDocTemplate.__init__(self, path, pagesize=A4,
                                 topMargin=top, bottomMargin=bottom,
                                 leftMargin=left, rightMargin=right, **kw)
        self.footer_text = footer
        self.show_page = show_page
        frame = Frame(self.leftMargin, self.bottomMargin,
                      self.width, self.height, id='main',
                      leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
        from reportlab.platypus import PageTemplate
        self.addPageTemplates([PageTemplate(id='p', frames=[frame], onPage=self._decorate)])

    def _decorate(self, canvas, doc):
        canvas.saveState()
        canvas.setFont('JP', 7.5)
        canvas.setFillColor(GRAY)
        y = self.bottomMargin - 7 * mm
        canvas.drawString(self.leftMargin, y, self.footer_text)
        if self.show_page:
            canvas.drawRightString(A4[0] - self.rightMargin, y, '- %d -' % doc.page)
        canvas.setStrokeColor(LINE)
        canvas.setLineWidth(0.4)
        canvas.line(self.leftMargin, y + 4 * mm, A4[0] - self.rightMargin, y + 4 * mm)
        canvas.restoreState()


# ================================================================ 導入手順書
def build_tejun():
    """A4縦1ページ。サーバの前で見ながら打ち込むための作業紙。"""
    st = styles(8.2, 1.38)
    path = os.path.join(OUT_DIR, '導入手順書.pdf')
    doc = Doc(path, footer='%s  %s 導入手順書' % (HOSPITAL, SYSTEM),
              margins=(12, 9, 13, 12), show_page=False)
    W = doc.width
    s = []

    # 見出し
    head = Table([[Paragraph('%s　<b>導入手順書</b>' % SYSTEM,
                             ParagraphStyle('t', fontName='JP', fontSize=13.5,
                                            leading=17, textColor=colors.white)),
                   Paragraph(HOSPITAL,
                             ParagraphStyle('t2', fontName='JP', fontSize=8.5, leading=12,
                                            textColor=colors.white, alignment=2))]],
                 colWidths=[W * 0.72, W * 0.28])
    head.setStyle(TableStyle([('BACKGROUND', (0, 0), (-1, -1), BLUE),
                              ('VALIGN', (0, 0), (-1, -1), 'MIDDLE'),
                              ('LEFTPADDING', (0, 0), (-1, -1), 8),
                              ('RIGHTPADDING', (0, 0), (-1, -1), 8),
                              ('TOPPADDING', (0, 0), (-1, -1), 5),
                              ('BOTTOMPADDING', (0, 0), (-1, -1), 5)]))
    s.append(head)
    s.append(Spacer(1, 5))

    # 前提
    s.append(P('<b>この手順の前提</b>（サーバ 10.20.103.125 で確認済み）', st['h2']))
    s.append(table([
        ['OS / Web', 'Windows 10 ／ Apache24（Apache Lounge。XAMPPではない）'],
        ['PHP', '8.3.9　CLIは <font face="Courier">C:\\php\\php.exe</font>（PATH済み。<font face="Courier">php -v</font> で確認できる）'],
        ['MySQL', '<b>すでに稼働中</b>。レセプト統計アプリ・空きベット情報が使用している。'
                  'そこへ <font face="Courier">nissi</font> データベースを1つ足すだけで、既存DBには触らない'],
        ['置き場所', '<font face="Courier">C:\\Apache24\\htdocs\\nissi</font>（移動しない。手順1で外から見えないように塞ぐ）'],
    ], [W * 0.13, W * 0.87], st, header=False))
    s.append(Spacer(1, 6))

    def step(no, title, body):
        blk = [P('<b><font color="#1f4e79">%s</font></b>　<b>%s</b>' % (no, title), st['body'])]
        blk += body
        blk.append(Spacer(1, 2.2))
        return KeepTogether(blk)

    s.append(step('0.', 'コマンドプロンプトを「管理者として実行」し、作業フォルダへ移動する', [
        codebox(['cd /d C:\\Apache24\\htdocs\\nissi',
                 'dir            ← README.md  db  public  src  が見えれば正しい場所'], st),
    ]))

    s.append(step('1.', 'Apache を設定して db・config をブラウザから隠す（最初にやる）', [
        P('<font face="Courier">C:\\Apache24\\conf\\httpd.conf</font> の末尾に追記して Apache を再起動する。'
                  '<b>URLは <font face="Courier">http://10.20.103.125/nissi/</font> のまま変わらない。</b>', st['small']),
        codebox(['Alias /nissi "C:/Apache24/htdocs/nissi/public"',
                 '<Directory "C:/Apache24/htdocs/nissi">',
                 '    Require all denied',
                 '</Directory>',
                 '<Directory "C:/Apache24/htdocs/nissi/public">',
                 '    Options -Indexes +FollowSymLinks',
                 '    Require all granted',
                 '    DirectoryIndex index.php',
                 '</Directory>'], st),
    ]))

    s.append(step('2.', 'データベースを作る（既存のMySQLに追加するだけ）', [
        codebox(['mysql -u root -p -e "CREATE DATABASE nissi DEFAULT CHARACTER SET utf8mb4;"',
                 'mysql -u root -p -e "CREATE USER \'nissi\'@\'localhost\' IDENTIFIED BY \'<パスワード>\';"',
                 'mysql -u root -p -e "GRANT SELECT,INSERT,UPDATE,DELETE ON nissi.*'
                 ' TO \'nissi\'@\'localhost\';"'], st),
    ]))

    s.append(step('3.', 'テーブルとマスタを流し込む', [
        codebox(['php db\\tools\\run_sql.php db\\schema.sql --user=root --pass=<rootのパスワード>',
                 'php db\\tools\\build_seed.php                  ← 19部署 / 278項目 / 設定10件 を生成',
                 'php db\\tools\\run_sql.php db\\seed_master.sql   ← こちらは nissi ユーザのままでよい'], st),
    ]))

    s.append(step('4.', '接続設定を作る（このファイルはリポジトリに入れない）', [
        codebox(['copy config\\config.sample.php config\\config.php',
                 'notepad config\\config.php        ← dbname・user・pass と auth.mode を書く'], st),
    ]))

    s.append(step('5.', '最初の管理者を登録する（職員マスタが空だと誰もログインできない）', [
        codebox(['php db\\tools\\add_user.php --id=<職員ID> --name=栗原 --dept=jimu'
                 ' --role=admin --password=<8文字以上>',
                 'php db\\tools\\add_user.php --list             ← 登録内容と部署IDの確認',
                 'php db\\tools\\add_user.php --csv=staff.csv    ← 2人目以降はCSVで一括登録'], st),
    ]))

    s.append(step('6.', '環境を確認する（全項目 OK になること）', [
        codebox(['php tools\\check_env.php'], st),
    ]))

    s.append(step('7.', 'ブラウザで開いて確認する', [
        table([
            ['開くURL', '正しい結果'],
            ['http://10.20.103.125/nissi/', 'ログイン画面が出る'],
            ['http://10.20.103.125/nissi/db/schema.sql', '403 か 404（中身が見えないこと）'],
            ['http://10.20.103.125/nissi/config/config.php', '403 か 404（<b>パスワードが見えたら手順1をやり直す</b>）'],
        ], [W * 0.48, W * 0.52], st),
    ]))

    s.append(step('8.', 'バックアップを登録する（これをやらないと復旧できません）', [
        codebox(['notepad config\\config.php   ← backup.dir を書く／php db\\tools\\backup.php で1回試す',
                 'schtasks /create /tn "nissi-backup" /tr "%CD%\\tools\\backup.bat"'
                 ' /sc daily /st 22:00 /ru SYSTEM'], st),
    ]))

    s.append(Spacer(1, 2))
    # この見出しだけ keepWithNext を外す。1枚に収める文書なので、
    # 「表が丸ごと入らないなら見出しごと次ページへ」という動きをさせない。
    s.append(P('<b>困ったとき</b>',
               ParagraphStyle('h2-split', parent=st['h2'], keepWithNext=0)))
    s.append(table([
        # 注意: Courier を指定した span の中に日本語を入れないこと。
        #       Courier には日本語の字が無く、黒い四角（豆腐）になる。
        ['<b>DBに接続できません</b>',
         '<font face="Courier">php tools\\check_env.php</font> の <font face="Courier">[NG]</font> の行で切り分ける'
         '（<font face="Courier">Access denied</font>=ユーザ、'
         '<font face="Courier">Unknown database</font>=DB、'
         '<font face="Courier">[2002]</font>=<font face="Courier">host=127.0.0.1</font> に）'],
        ['php が見つからない', '<font face="Courier">C:\\php\\php</font> とフルパスで打つ'],
        ['mysql が見つからない', '<b>手順3の <font face="Courier">run_sql.php</font> を使う</b>（PHPから流し込むので mysql は不要）'],
        ['日本語が化ける', 'コマンドプロンプトで先に <font face="Courier">chcp 65001</font> を実行する'],
        ['PHPのソースがそのまま表示される', 'httpd.conf の <font face="Courier">LoadModule php_module</font> が入っていない。'
                                            '<b><font color="#c0392b">その状態で置くとDBのパスワードが漏れる</font></b>'],
        ['ログインできない<br/>やり直したい',
         '手順5をやり直す（<font face="Courier">--list</font> で登録を確認）。'
         '最初からやるなら <font face="Courier">DROP DATABASE nissi;</font> — 他アプリに影響はない'],
    ], [W * 0.26, W * 0.74], st, header=False))

    doc.build(s)
    return path


# ============================================================ システムレポート
def build_report():
    st = styles(9.6, 1.55)
    path = os.path.join(OUT_DIR, 'システムレポート.pdf')
    doc = Doc(path, footer='%s  %s' % (HOSPITAL, SYSTEM))
    W = doc.width
    s = []
    B, H1, H2, SM = st['body'], st['h1'], st['h2'], st['small']

    def p(t):
        s.append(P(t, B))

    def note(t):
        s.append(P(t, SM))

    def h1(t):
        s.append(P(t, H1))

    def h2(t):
        s.append(P(t, H2))

    def tbl(data, widths, header=True, align=None):
        # 見出しの直後に Spacer を挟むと keepWithNext が Spacer で満たされてしまい、
        # 見出しだけがページ末に取り残される。表を直接続ける。
        s.append(table(data, [W * w for w in widths], st, header=header, align=align))
        s.append(Spacer(1, 5))

    # ---- 表紙 ----
    s.append(Spacer(1, 48 * mm))
    s.append(Paragraph(SYSTEM, st['title']))
    s.append(Spacer(1, 5))
    s.append(Paragraph('システムレポート', st['title']))
    s.append(Spacer(1, 14))
    s.append(Paragraph(HOSPITAL, st['subtitle']))
    s.append(Paragraph('2026年9月', st['subtitle']))
    s.append(Spacer(1, 26 * mm))
    s.append(table([
        ['このレポートの要点', ''],
        ['作ったもの',
         '各部署が電子カルテ端末から <b>一度だけ</b> 入力し、病院日誌と医事統計表の'
         '<b>両方</b>を自動生成するPHPシステム。入力者と入力時刻を自動で記録し、'
         '未入力の部署が一目で分かる'],
        ['規模', '19部署 / 278項目 / 画面13本。外部ライブラリなし・ビルド工程なし'],
        ['検証',
         '2026年8月のExcelと突き合わせて <b>52項目中52項目一致</b>。'
         '平均在院日数・稼働率・回転率・紹介率・受入率も小数以下まで一致'],
        ['副産物',
         '照合の過程で、<b>現行Excelの誤りを3種類</b> 見つけた（6章）。'
         '新システムでは構造的に起こらない'],
        ['次にやること', '別紙「導入手順書」の手順0〜7。所要およそ30分'],
    ], [W * 0.16, W * 0.84], st))
    s.append(PageBreak())

    # ---- 1 ----
    h1('1. このシステムは何か')
    p('現在は、各部署が一日の終わりに集計した患者数・業務数を医事課が受け取り、'
      '<b>病院日誌</b>（PHP・MySQL）と <b>医事統計表</b>（Excel月ブック・21シート）の'
      '両方に書き写している。')
    p('項目マスタで両者を突き合わせたところ、<b>45項目が両方に存在する</b>。'
      'つまり毎日45か所、同じ数字を二度書きしている。'
      'どの部署がまだ提出していないかも、医事課が個別に確認しないと分からない。')
    tbl([
        ['', '現在', 'このシステム'],
        ['入力の回数', '日誌とExcelで2回', '<b>1回だけ</b>'],
        ['入力する場所', '紙・Excel・日誌画面がばらばら', '電子カルテ端末のブラウザ1か所'],
        ['入力者の記録', '「担当印」欄に手書き', '職員IDと時刻を自動で記録'],
        ['入力漏れ', '医事課が部署に個別に確認', '一覧表が赤で表示。督促先がすぐ分かる'],
        ['帳票', 'Excelに転記して作る', '入力した瞬間に出来ている'],
        ['数字の食い違い', '転記のたびに起こりうる', '出どころが1つなので起こらない'],
    ], [0.16, 0.42, 0.42])
    note('「担当印」欄に氏名を書く運用はすでに現場で行われている（Excel ① の担当印、'
         '在宅② の当直者印）。それを手書きからシステムの記録に置き換えるだけで、'
         '現場の手順は増えない。')

    # ---- 2 ----
    h1('2. 仕組み — 項目マスタが1枚あるだけ')
    p('このシステムの中身は、<b>項目マスタ（278行のCSV）</b> がほとんどすべてを決めている。'
      '入力画面も帳票も、このマスタから自動で組み立てられる。'
      '項目を増やすときに触るのはCSVの1行だけで、PHPは書き換えない。')
    tbl([
        ['区分', '件数', '意味'],
        ['入力', '211', '現場が手で入れる項目。ここだけがデータベースに保存される'],
        ['合算', '47', '「入院＋外来」のように、他の項目を足すだけの項目。入力欄は作らない'],
        ['計算', '20', '稼働率・平均在院日数・紹介率など、式で出す項目。入力欄は作らない'],
        ['<b>合計</b>', '<b>278</b>', '19部署ぶん'],
    ], [0.14, 0.12, 0.74], align={1: 'CENTER'})

    h2('2-1. 導出した数字は保存しない')
    p('累計・合計・平均・稼働率・回転率・紹介率・受入率は、<b>一切データベースに保存せず、'
      '表示するたびに計算する</b>。')
    p('現行Excelは、計算した数字を別のシートへ転記して持っていた。'
      'そのため片方だけが古くなり、同じ項目が集計シートと帳票シートで違う数字になっていた'
      '（6章）。数字の出どころを1か所に保てば、この壊れ方は構造的に起こらない。')

    h2('2-2. 粒度が違う項目は「細かいほう」だけを入力する')
    p('日誌と統計表で粒度が違う項目は、細かいほうをマスタに持ち、粗いほうは足して出す。'
      'これで同じ数字を入力する画面がシステム全体で1か所になる。')
    tbl([
        ['手で入力するもの', '自動で出るもの'],
        ['CT 入院 ／ CT 外来', '病院日誌の「ＣＴ ○件」'],
        ['外来患者数 午前・午後・夜間・時間外×2', '医事統計表の「外来患者数 当日」'],
        ['訪問看護 介護30分以上・30分以内・医療', '病院日誌の「訪問看護 ○件」'],
        ['在院患者数 3階・4階・5階', '日誌の「計」、統計表の「現入院」'],
    ], [0.5, 0.5])
    note('日誌側にCT・訪問看護・在院患者数計の入力欄は作らない。'
         '入力欄が無いことが、二度書きが消えたことの証拠になる。')

    h2('2-3. 病床数のような「その時々で変わる値」')
    p('定床は2025年6月の病床再編で変わった。設定値には適用開始日を持たせてあるので、'
      '<b>2025年5月以前の帳票は当時の定床（30/27/51）、6月以降は新しい定床（30/39/39）</b>で'
      '再現される。過去の帳票を後から作り直しても、当時と同じ数字になる。')

    # ---- 3 ----
    h1('3. 画面')
    tbl([
        ['画面', '用途', '見られる人'],
        ['入力状況ボード', '縦=部署 × 横=直近14日。未入力=赤 / 一部=黄 / 提出済=緑 / 確定=濃緑 / 対象外=灰。'
                           '上部に「本日の未提出部署」', '全員'],
        ['部署別入力', '項目マスタから自動生成された入力欄。日付を指定して過去日も入れられる', '自部署のみ'],
        ['病院日誌', '現行の様式のまま。末尾に部署別の入力者・入力時刻の一覧が付く', '全員'],
        ['救急搬入受入統計', '８時会・朝礼の報告用。当日・累計・受入率', '全員'],
        ['各種業務量他総括', '月計・1日平均・目標差・稼働率・回転率', '医事課・管理者'],
        ['平均在院日数統計', '病棟別の増減内訳と在院日数', '医事課・病棟'],
        ['患者数統計表①〜⑤', '月報の様式', '医事課・管理者'],
        ['病院報告（患者票）', '保健所への提出様式', '医事課・管理者'],
        ['変更履歴', '誰がいつ何をどう直したか', '医事課・管理者'],
        ['CSV出力', 'Excelでそのまま開ける（UTF-8 BOM付き）', '医事課・管理者'],
        ['マスタ保守 / 職員', '目標値・部署・設定値・職員の登録', '管理者'],
    ], [0.2, 0.62, 0.18])
    note('印刷はブラウザの印刷機能を使う（A3横）。'
         'PDF生成ライブラリを同梱しないので、フォントの管理や文字化けの心配がない。')

    # ---- 4 ----
    h1('4. 入力者・入力時刻と、入力漏れの見つけ方')
    h2('4-1. 誰がいつ入れたか')
    p('職員IDは電子カルテから引き継ぐ。受け取った直後にセッションへ格納し、以後URLには載せない。'
      '引き継ぎが使えない端末のために、職員ID＋パスワードの予備ログインも用意してある。')
    p('保存すると、値ごとに<b>入力者と時刻</b>が残る。'
      'あとから数字を直した場合は <b>旧の値 → 新しい値</b> を変更履歴に記録するので、'
      '「いつ誰がこの数字を直したのか」を後から追える。')
    h2('4-2. 入力漏れ')
    tbl([
        ['表示', '意味', '医事課の動き'],
        ['赤', 'その部署がまだ何も入れていない', '督促する'],
        ['黄', '提出されたが、必須項目に空きがある（71項目が必須）', '確認する'],
        ['緑', '部署が提出済み', '内容を確認して確定する'],
        ['濃緑', '医事課が確定済み。以降その部署は編集できない', '—'],
        ['灰', 'その部署はその曜日は入力対象外', '—'],
    ], [0.1, 0.6, 0.3])
    note('部署ごとに入力対象の曜日と入力期限を設定してある'
         '（例：健診センターは平日のみ、当直は毎日9時まで）。'
         '土日に健診センターが赤くなって督促が空振りする、ということが起きない。')

    # ここでは改ページしない（見出しの keepWithNext で流れを整える）

    # ---- 5 ----
    h1('5. 現行Excelとの突き合わせ結果')
    p('2026年8月のExcelブックの入力層の値をそのまま新システムに取り込み、'
      '「各種業務量他総括」シートと全項目を1つずつ比べた。')
    s.append(Table([[P(
        '<b>52項目中52項目が一致した。</b>　'
        '延患者数などの単純な集計だけでなく、平均在院日数・病床回転率・紹介率・受入率といった'
        '計算値も小数以下まで一致している。',
        ParagraphStyle('ok', fontName='JP', fontSize=10, leading=15))]],
        colWidths=[W])
    )
    s[-1].setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, -1), colors.HexColor('#eaf4ea')),
        ('BOX', (0, 0), (-1, -1), 0.6, colors.HexColor('#7fae7f')),
        ('LEFTPADDING', (0, 0), (-1, -1), 8), ('RIGHTPADDING', (0, 0), (-1, -1), 8),
        ('TOPPADDING', (0, 0), (-1, -1), 6), ('BOTTOMPADDING', (0, 0), (-1, -1), 6)]))
    s.append(Spacer(1, 8))
    p('計算式は推測していない。Excelのセルに入っている数式を直接読み出して移植した。'
      '主なものは次のとおり。')
    tbl([
        ['項目', '式', 'Excelの出どころ'],
        ['延患者数合計', '延患者数 ＋ 当日退院在院日数 ＋ 転出在院日数', 'H!J = G+H+I'],
        ['1日平均患者数', '延患者数 ÷ 実日数', '総括!N6 = L6/A4'],
        ['平均在院日数', '算定対象延患者数 ÷ ((新入院＋転入＋退院＋転出)÷2)', '総括!O6 = M6/(SUM(F6:J6)/2)'],
        ['病床稼働率', '(延患者数＋退院)×100 ÷ (実日数×定床)', '総括!P6 = ((L6+I6)*100)/(A4*B6)'],
        ['病床回転率', '実日数 ÷ 平均在院日数 × 100', 'H!L40 = ($F$5/K40)*100'],
        ['救急 受入率', '患者数累計 ÷ 搬入依頼数累計 × 100', '救急搬入受入統計表!M8 = (E8/L8)*100'],
        ['搬入依頼数', '患者数 ＋ 断り件数', '救急搬入受入統計表!K8 = D8+I8'],
        ['紹介率', '(紹介患者数＋救急搬入患者数) ÷ (初診 − 外休深6歳未満) × 100', '⑦!X7 = (U7+W7)/(Q7-S7)'],
    ], [0.16, 0.52, 0.32])
    note('紹介率の分母から「外休深6歳未満」を引くこと、搬入依頼数が入力値ではなく'
         '「患者数＋断り件数」の計算値であることは、数式を読んで初めて分かった。'
         '見た目からは判断できない部分なので、推測で作らずに数式を確認する価値があった。')

    # ---- 6 ----
    h1('6. 突き合わせの過程で見つかった、現行Excelの誤り')
    p('照合の副産物として、現行ブックの数字が合っていない箇所が3種類見つかった。'
      '<b>いずれも医事課に確認いただきたい。</b>')

    h2('6-1. 病床稼働率が病棟別で誤っている（金額・実績に直結）')
    p('2025年6月の病床再編で4階と5階の定床が変わったが、総括シートの定床のセルが'
      '旧のままになっている。そのため2026年8月の病棟別の稼働率がずれている。')
    tbl([
        ['病棟', '定床<br/>〜2025年5月', '定床<br/>2025年6月〜', 'Excelの表示', '正しい値'],
        ['3階', '30', '30', '102.26%', '102.26%'],
        ['4階', '27', '39', '<b><font color="#c0392b">156.15%</font></b>', '<b>108.11%</b>'],
        ['5階', '51', '39', '<b><font color="#c0392b">82.04%</font></b>', '<b>107.28%</b>'],
        ['合計', '108', '108', '106.18%', '106.18%'],
    ], [0.14, 0.19, 0.19, 0.24, 0.24],
        align={0: 'CENTER', 1: 'CENTER', 2: 'CENTER', 3: 'CENTER', 4: 'CENTER'})
    note('合計は一致するので、総括シートを合計だけ見ていると気付けない。'
         '平均在院日数と回転率は定床を使わない式なので影響しない。'
         '影響するのは病床稼働率と、目標値ブロックの病床利用率。')

    h2('6-2. 患者数統計表②③が、違う列を参照している')
    p('各列を31日分の数値の並びとして突き合わせたところ、'
      '帳票シートの検査系の参照先が一律に約8列ぶん左へずれていた。'
      '⑤シートに後から列が挿入され、参照が追随しなかったときの典型的な壊れ方。')
    tbl([
        ['項目', '入力シート⑤の実値', '各種業務量他総括', '患者数統計表'],
        ['検体検査', '2,418', '2,418　正しい', '③ = <b><font color="#c0392b">286</font></b>'],
        ['胸・腹部エコー', '286', '286　正しい', '② = <b><font color="#c0392b">26,561</font></b>'],
        ['心エコー', '79', '79　正しい', '② = <b><font color="#c0392b">1,381</font></b>'],
        ['医師エコー', '4', '—', '② = <b><font color="#c0392b">79</font></b>'],
    ], [0.22, 0.22, 0.28, 0.28])
    note('<b>各種業務量他総括は正しい。</b>壊れているのは患者数統計表②③の検査系の一区画に限られ、'
         'リハビリ・薬剤・在宅・透析の各列は正しく対応している。')

    h2('6-3. 月のラベルが更新されていないシートがある')
    p('ブック本体は2026年8月だが、日付のセルがブックの日付に連動していないシートがある。'
      '月次でブックをコピーする運用のため、コピー元の月が残ったままになっている。')
    tbl([
        ['シート', '表示されている月'],
        ['H（平均在院日数統計）', '2026年5月31日'],
        ['救急搬入受入統計表', '２０２６年　５月度'],
        ['在宅①', '令和8年7月分'],
    ], [0.4, 0.6])

    h2('6-4. Excelの中でも二度書きしている箇所')
    p('同じ数字を複数のシートに手で入れている箇所がある。'
      '① のドック・健診・通所リハ・訪問看護・訪問診療・訪問リハ・居宅指導は ③ と 在宅② からの転記で、'
      '③ の人間ドック・健康診断と 在宅② のAE〜AL列は同じ値になっている。')
    p('<b>これら4つは、新システムでは構造的に起こらない。</b>'
      '数字の出どころが項目マスタの1行に固定され、帳票はすべてそこから導出されるため、'
      '転記も参照ズレも発生しようがない。定床は適用日付きで持つので、'
      '病床が変わったときの直し忘れも起きない。')

    # ここでは改ページしない（見出しの keepWithNext で流れを整える）

    # ---- 7 ----
    h1('7. その他の確認')
    h2('7-1. 安全性')
    tbl([
        ['試したこと', '結果'],
        ["日付の欄に <font face=\"JPMono\">' OR '1'='1</font> や <font face=\"JPMono\">DROP TABLE</font> を入れる",
         'エラーにならず、データも壊れない。SQLは必ずプリペアドステートメントを通す'],
        ['特記事項の氏名欄に <font face="Courier">&lt;script&gt;</font> を入れる', 'そのまま文字として表示され、実行されない'],
        ['他部署の入力画面を開く', '拒否される（403）'],
        ['画面を経由せずデータを送りつける', '拒否される（400）。ワンタイムトークンで検証している'],
        ['患者ID・氏名の扱い', '集計値とは別のテーブルに隔離し、病棟・医事課・管理者だけが見られる'],
    ], [0.36, 0.64])
    p('<b>設置場所について1点、対応が必要。</b>'
      'アプリ一式がApacheの公開フォルダの中に置かれているため、このままでは'
      '接続設定ファイル（DBのパスワードが書かれている）やテーブル定義のSQLが、'
      'ブラウザから直接ダウンロードできてしまう。')
    p('導入手順書の<b>手順1</b>（httpd.conf にAliasを1行追記する）で塞がる。'
      'URLも変わらないので、直すのはこの1か所だけでよい。'
      'フォルダを公開フォルダの外へ移す必要はない。')

    h2('7-2. 旧データの移行')
    p('旧 nissi テーブルからの取り込みツールを用意し、2024年11月18日のデータで検証した。'
      '値は一致し、定床も当時の30/27/51で表示された。')
    p('ただし <b>26列は移行できない</b>。新システムは同じ項目を入院・外来に分けて持つのに対し、'
      '旧日誌は「ＣＴ 11件」のように合計しか持っておらず、内訳に分解できないため'
      '（検査系のk1〜k23、new/tai、s6〜s10、s14、z3）。'
      '移行後これらは空欄になり、新しく入力した日からは自動で算出される。'
      '実行前に「下見」モードで、移行できない列とその理由が一覧で出る。')

    h2('7-3. 保守')
    tbl([
        ['依存関係', 'なし。外部ライブラリもビルド工程も使わない。PHPファイルを置けば動く'],
        ['必要なPHP', '<b>7.4以上</b>（実機は8.3.9）。7.1〜7.3でも12か所を書き換えれば動く'],
        ['項目を増やす', 'CSVに1行足して生成コマンドを1回。画面は自動生成なのでPHPは触らない'],
        ['病床が変わる', 'CSVに適用開始日付きで1行足す。古い行は消さない'],
        ['バックアップ', '毎日 mysqldump ＋ 世代管理。<b>年に一度は実際に別DBへ戻して、手順が通ることを確認する</b>'],
        ['止まったとき', '紙の様式を1か月ぶん医事課に置き、復旧後に日付を指定してまとめて入力する'],
    ], [0.2, 0.8], header=False)

    # ---- 8 ----
    h1('8. 医事課に確認したいこと')
    p('回答が出るまでは下の「現在の仮置き」で動く。'
      '回答が出た時点で、項目マスタの1〜2行を直せば全帳票に反映される。')
    tbl([
        ['#', '確認したいこと', '現在の仮置き'],
        ['1', '患者数統計表②③の参照ズレ（6-2）をどう扱うか', '各種業務量他総括の側を正とする'],
        ['2', '病院日誌の「訪問リハ」は介護のみか、介護＋医療か', '介護＋医療（385）としている'],
        ['3', '外来の「時間外」が現行画面に2行ある。正しい区分名は', '「時間外１」「時間外２」のまま'],
        ['4', '病院日誌「外来再掲」のリハビリの定義（Excelに対応する列がない）', 'リハビリ科が入力する独立項目'],
        ['5', '時間外ウォークインの区分1〜4の意味', '「区分1」〜「区分4」のまま'],
        ['6', '外来患者数の定義（Excel①は「ドック・健診・訪問診察を除した数」）', '日誌側も同じ定義とみなす'],
    ], [0.05, 0.55, 0.4])
    p('あわせて、導入前に各部署へ「同じ数字を書いている台帳」をヒアリングしたい。'
      '<b>旧Excelの運用を止める合意を先に取らないと、現場の入力が3回に増える。</b>'
      'これが、この種のシステムでいちばん多い失敗の形。')

    # ---- 9 ----
    h1('9. これからの進め方')
    tbl([
        ['段階', '内容'],
        ['1. 導入', '別紙「導入手順書」の手順0〜7。既存のMySQLにデータベースを1つ足すだけで、'
                    'レセプト統計アプリ・空きベット情報には影響しない'],
        ['2. マスタの確認', '医事課に項目マスタ（278項目）と上記6件を見ていただく'],
        ['3. 試用', '医事課＋2部署（外来・放射線科）で1か月ぶん動かし、'
                    '現行Excelの数字と全項目を突き合わせる'],
        ['4. 展開', '全部署へ広げ、旧Excelの運用を止める'],
    ], [0.16, 0.84])
    note('画面は項目マスタから自動生成されるため、部署や項目が増えても作業量は増えない。'
         '逆に、マスタが不正確だと全帳票が狂う。'
         'いちばん時間をかける価値があるのは段階2の確認。')

    doc.build(s)
    return path


def main():
    print('フォント:')
    register_fonts()
    if not os.path.isdir(OUT_DIR):
        os.makedirs(OUT_DIR)
    print('生成:')
    for path in (build_tejun(), build_report()):
        print('  %s  (%.0f KB)' % (path, os.path.getsize(path) / 1024.0))


if __name__ == '__main__':
    main()
