# 病院日誌・医事統計表 入力システム

長崎北徳洲会病院。各部署が電子カルテ端末から患者数・業務数を **一度だけ** 入力し、
病院日誌と医事統計表の両方をそこから自動生成する。入力者と入力時刻を自動で記録し、
未入力の部署が一目で分かるようにする。

## 何を解決するか

現行は、同じ数字を **病院日誌（PHP）** と **医事統計表（Excel月ブック）** の両方に書いている。
病院日誌の数値項目のうち約8割は医事統計表にも存在する。

このシステムでは、粒度の細かいほうを1回だけ入力し、粗いほうは計算で出す。

| 手入力する項目 | 自動で出る項目 |
|---|---|
| CT 入院 / CT 外来 | 病院日誌の「ＣＴ ○件」 |
| 外来患者数 午前・午後・夜間・時間外×2 | 医事統計表の「外来患者数 当日」 |
| 訪問看護 介護30分以上・30分以内・医療 | 病院日誌の「訪問看護 ○件」 |
| 病棟別の在院患者数 3階・4階・5階 | 日誌の「計」、統計表の「現入院」 |

日誌側にCT・訪問看護・在院患者数計の入力欄は作らない。二度書きが構造的に消える。

## 構成

```
db/
  schema.sql            テーブル定義（MySQL・正本）
  schema.sqlite.sql     ↑から自動生成（開発時のSQLite動作確認用）
  seed_master.sql       ↓のCSVから自動生成
  master/               ★ここを直す
    depts.csv           部署マスタ（19部署）
    items.csv           項目マスタ（277項目）
    config.csv          定床などの設定値
  tools/
    build_seed.php      master/*.csv → seed_master.sql と対応表を生成
    mysql_to_sqlite.php schema.sql → SQLite用DDL
    dev_setup.php       開発用SQLite DBを作り直す
    add_user.php        職員の登録・一括登録・無効化（最初の管理者はこれで作る）
    import_daily_csv.php 日次実績のCSV取り込み
    migrate_from_legacy.php 旧 nissi テーブル（sjis）→ 縦持ちへ移行
src/
  db.php          PDO接続。SQLは必ずプリペアドステートメント
  auth.php        利用者の特定。電子カルテからのID引き継ぎ／予備ログイン
  master.php      マスタ読み込み
  repository.php  日次値の保存、入力者・時刻の記録、変更履歴、提出状態
  calc.php        導出項目の算出（Excelの数式に相当）
  report.php      日別クロス表の共通描画（列仕様を配列で渡す）
  view.php        エスケープ・CSRF・共通ヘッダ
public/
  index.php          入力状況ボード（トップ）        全員
  entry.php          部署別入力（項目マスタから自動生成）自部署のみ
  nissi.php          病院日誌（?print=1 で印刷用）    全員
  qq_report.php      救急搬入受入統計（８時会・朝礼報告）全員
  soukatsu.php       各種業務量他総括（月次）        医事課・管理者
  zaiin.php          平均在院日数統計                医事課・病棟
  toukei.php         患者数統計表①〜⑤（月報）      医事課・管理者
  byoin_houkoku.php  病院報告（患者票・保健所提出）  医事課・管理者
  audit.php          変更履歴の閲覧                  医事課・管理者
  export.php         CSV出力（UTF-8 BOM付き）        医事課・管理者
  admin_master.php   目標値・部署・設定値の保守      管理者
  admin_user.php     職員マスタの保守                管理者
  login.php          予備ログイン
tools/
  extract_excel.py  現行Excelブックから値をCSVに取り出す（移行・検証用）
  check_env.php     稼働サーバが要件を満たすか確認する
docs/
  項目マスタ対応表.md  旧列名・Excel位置と項目コードの対応（自動生成）
```

## セットアップ

まず稼働サーバが要件を満たすか確認する。

```bash
php tools/check_env.php
```

PHPのバージョン・拡張モジュール・utf8mb4の可否・テーブルの作成状況をまとめて判定する。
このスクリプト自体は古いPHPでも動くので、「PHPが古すぎる」場合もその旨が表示される。

```bash
# 1. データベースを作る
mysql -u root -p -e "CREATE DATABASE nissi DEFAULT CHARACTER SET utf8mb4;"
mysql -u root -p -e "CREATE USER 'nissi'@'localhost' IDENTIFIED BY '＜パスワード＞';
                     GRANT SELECT,INSERT,UPDATE,DELETE ON nissi.* TO 'nissi'@'localhost';"

# 2. スキーマとマスタを流し込む
mysql -u nissi -p nissi < db/schema.sql
php db/tools/build_seed.php
mysql -u nissi -p nissi < db/seed_master.sql

# 3. 設定ファイルを作る（config.php はリポジトリに入れない）
cp config/config.sample.php config/config.php
vi config/config.php

# 4. 最初の管理者を登録する（この時点では職員マスタが空で、誰もログインできない）
php db/tools/add_user.php --id=＜職員ID＞ --name=＜氏名＞ --dept=jimu --role=admin --password=＜8文字以上＞

# 5. 残りの職員を登録する（CSVで一括登録できる）
php db/tools/add_user.php --csv=staff.csv     # user_id,user_name,dept_id,role,password
php db/tools/add_user.php --list              # 部署IDと登録済み職員の確認

# 6. 設定を確認する
php tools/check_env.php
```

2人目以降は画面（管理者でログイン → 職員）からも登録できる。
`role` は `entry`（入力者）/ `toutyoku`（当直者）/ `ijika`（医事課）/ `admin`（管理者）。
`password` を空にすると予備ログインは無効になり、電子カルテからのID引き継ぎでのみ入れる。

### Windows のコマンドプロンプトで実行する場合

**手順2〜8はすべて、プロジェクトのルートフォルダ**（`README.md` と `db` `src` `public`
が並んでいるフォルダ）で実行する。まずそこへ移動する。

```
cd /d C:\nissi
```

`/d` はドライブをまたいで移動するために必要（`D:` に置いた場合など）。
`dir` と打って `README.md` `db` `public` `src` が見えていればその場所で合っている。

上の手順は Linux の書き方なので、Windows では次のように読み替える。

| 手順 | Linuxの書き方 | Windowsのコマンドプロンプト |
|---|---|---|
| ファイルのコピー | `cp a b` | `copy a b` |
| 設定ファイルの編集 | `vi config/config.php` | `notepad config\config.php` |
| 区切り文字 | `/` | `\`（PHPは両方受け付けるのでどちらでもよい） |

**`php` と `mysql` にパスが通っているか**を先に確かめる。

```
php -v
mysql --version
```

「内部コマンドまたは外部コマンド〜として認識されていません」と出る場合は、
フルパスで打つか、環境変数 PATH に追加する。XAMPP なら通常は次の場所にある。

```
C:\xampp\php\php.exe
C:\xampp\mysql\bin\mysql.exe
```

フルパスで打つ例：

```
C:\xampp\mysql\bin\mysql -u nissi -p nissi < db\schema.sql
C:\xampp\php\php db\tools\build_seed.php
```

**日本語を入力するとき。** コマンドプロンプトの既定の文字コードは Shift_JIS のため、
`--name=栗原` のように日本語を渡すと文字化けの原因になる。
`db/tools/add_user.php` は Shift_JIS で届いた場合も自動でUTF-8に直すので、
そのまま打って問題ない。気になる場合は先に `chcp 65001` を実行しておく。

職員をCSVで一括登録するときは、Excelで「CSV UTF-8（コンマ区切り）」で保存するのが確実。
通常の「CSV（コンマ区切り）」で保存した Shift_JIS のファイルも読めるようにしてある。

Apache/nginx のドキュメントルートは **`public/` を指す**。`src/` `config/` `db/` を
Web から直接開けないようにするため。

### 開発機で動かす（MySQLが無くても確認できる）

```bash
php db/tools/mysql_to_sqlite.php > db/schema.sqlite.sql
php db/tools/build_seed.php
php db/tools/dev_setup.php          # db/dev.sqlite を作る
# config/config.php の dsn を sqlite に、auth.mode を local にする
php -S 127.0.0.1:8080 -t public     # kurihara / test1234
```

## よくある変更

**集計項目を増やす／減らす**
`db/master/items.csv` に1行足して `php db/tools/build_seed.php`、生成されたSQLを流す。
入力画面は項目マスタから自動生成されるので、PHPは触らない。

- `calc_type=input` … 現場が手入力する
- `calc_type=sum` … `calc_source` に並べた項目コードを合算する
- `calc_type=func` … `src/calc.php` の名前付き関数で算出する（稼働率・回転率など）
- `agg_type=sum` … 月計は期間内を合算（新入院・検査件数などフロー）
- `agg_type=last` … 月計は最終日の値（登録人数などストック）

**病床数が変わった**
`db/master/config.csv` に `valid_from` 付きで新しい行を足す。古い行は消さない。
過去の帳票は当時の定床で再現される。

**部署を増やす**
`db/master/depts.csv` に1行足す。`entry_days` で入力対象曜日、`deadline_time` で入力期限を指定する。

## 旧データの移行

```bash
# まず下見（書き込まない）。どの列が移行できるか・できないかを表示する
php db/tools/migrate_from_legacy.php \
    --dsn="mysql:host=localhost;dbname=nissi_old" --user=... --pass=... --dry-run

# 問題なければ実行
php db/tools/migrate_from_legacy.php --dsn="..." --user=... --pass=...
```

**移行できない列がある。** 新システムは同じ項目を入院・外来などに分けて持つのに対し、
旧日誌は「ＣＴ 11件」のように合計しか持っていないため、内訳に分解できない。
該当するのは 26 列（`k1`〜`k23` の検査系、`new`/`tai`、`s6`〜`s10`、`s14`、`z3`）で、
移行後これらは空欄になる。新しく入力した日以降は自動で算出される。

下見の出力に、移行できない列とその理由が一覧で出るので、実行前に必ず確認すること。

文字コードは既定が `sjis`。移行後、天候・術名・会議名・行事・人事が正しく表示されるか
必ず目視で確認する（下見の最後にサンプルが表示される）。

## バックアップと復旧

```bash
# 毎日 深夜に取得（cron）
mysqldump -u nissi -p --single-transaction nissi | gzip > /backup/nissi_$(date +\%Y\%m\%d).sql.gz

# 復旧
gunzip -c /backup/nissi_YYYYMMDD.sql.gz | mysql -u nissi -p nissi
```

> **バックアップを取るだけでは足りない。** 年に一度は実際に別DBへ復旧してみて、
> 手順が通ることを確認する。取れていても戻せない事例が最も多い。

## システムが止まったとき

現行の紙の日誌様式を1か月ぶん印刷して医事課に置いておく。停止中は紙で運用し、
復旧後にまとめて入力する。日付を指定して過去日を入力できるので、後追いで埋められる。

## 保守する人へ

- 依存ライブラリなし・ビルド工程なし。PHPファイルを置けば動く（**PHP 7.4 以上**）
  - 7.4 が必要なのはアロー関数を12か所で使っているため。7.1〜7.3 の場合は
    通常のクロージャに書き換えれば動く（`src/calc.php` `public/zaiin.php` `public/nissi.php` ほか）
  - サーバが要件を満たすかは `php tools/check_env.php` で確認できる
- SQLは必ず `src/db.php` のプリペアドステートメント経由で書く
- 画面に文字を出すときは必ず `h()` を通す（特記事項に患者氏名が入るため）
- 導出値は **保存しない**。現行Excelは導出値を各シートに転記していたため参照ズレが起き、
  同じ項目が集計シートと帳票シートで違う数字になっていた。数字の出どころを1つに保つこと
