-- 自動生成ファイル。編集しないこと。
-- db/schema.sql から db/tools/mysql_to_sqlite.php が生成する（開発時のSQLite動作確認用）。
PRAGMA foreign_keys = ON;
-- ============================================================
--  病院日誌・医事統計表 入力システム  スキーマ定義
--  長崎北徳洲会病院
--
--  対象DB : MySQL 5.6 以降 / MariaDB 10 以降
--  文字コード: utf8mb4（旧 nissi テーブルは sjis だが新系は utf8mb4 に統一する）
--
--  【設計の要点】
--  1. 集計項目は列ではなく「行」で持つ（縦持ち）。
--     項目を増やすときに ALTER TABLE も画面修正も不要にするため。
--  2. 累計・合計・平均・稼働率などの「導出項目」は保存しない。
--     現行Excelは導出値を各シートに転記していたため参照ズレが起き、
--     同じ項目が集計シートと帳票シートで違う数字になっていた。
--     数字の出どころを1か所にすることで、この壊れ方を構造的に防ぐ。
--  3. 削除は論理削除。訂正は上書き＋ d_audit への履歴記録とする。
-- ============================================================


DROP TABLE IF EXISTS d_audit;
DROP TABLE IF EXISTS d_tokki;
DROP TABLE IF EXISTS d_submission;
DROP TABLE IF EXISTS d_daily_value;
DROP TABLE IF EXISTS m_target;
DROP TABLE IF EXISTS m_config;
DROP TABLE IF EXISTS m_item;
DROP TABLE IF EXISTS m_user;
DROP TABLE IF EXISTS m_dept;


-- ------------------------------------------------------------
--  m_dept : 部署マスタ
--  入力画面の単位でもある。現行Excelの入力シート単位と対応する。
-- ------------------------------------------------------------
CREATE TABLE m_dept (

  dept_id       VARCHAR(16)  NOT NULL,               -- 'gairai' 'housha' など
  dept_name     VARCHAR(64)  NOT NULL,
  sort_no       INT          NOT NULL DEFAULT 0,
  deadline_time VARCHAR(5)   NOT NULL DEFAULT '18:00', -- 入力期限 HH:MM。未提出判定の基準
  entry_days    VARCHAR(7)   NOT NULL DEFAULT '1234567', -- 入力対象曜日（1=月〜7=日）
  is_active     INT          NOT NULL DEFAULT 1,
  PRIMARY KEY (dept_id)
);


-- ------------------------------------------------------------
--  m_user : 職員マスタ
--  user_id は電子カルテの職員IDをそのまま使う（引き継ぎのため）。
-- ------------------------------------------------------------
CREATE TABLE m_user (

  user_id       VARCHAR(32)  NOT NULL,
  user_name     VARCHAR(64)  NOT NULL,
  dept_id       VARCHAR(16)  NOT NULL,
  -- entry=入力者 / toutyoku=当直者 / ijika=医事課 / admin=管理者
  role          VARCHAR(16)  NOT NULL DEFAULT 'entry',
  -- 電子カルテからのID引き継ぎが使えない端末・保守用の予備ログイン。
  -- 通常はNULL（＝ローカルログイン不可）。
  password_hash VARCHAR(255)     NULL,
  is_active     INT          NOT NULL DEFAULT 1,
  created_at    DATETIME         NULL,
  updated_at    DATETIME         NULL,
  PRIMARY KEY (user_id)
);


-- ------------------------------------------------------------
--  m_item : 項目マスタ  ← このシステムの心臓部
--
--  calc_type で「入力項目」と「導出項目」を分ける。
--    input : 現場が手入力する。d_daily_value に保存される
--    sum   : calc_source に並べた item_code を単純合算する
--    func  : calc.php の名前付き関数で算出する（稼働率・回転率など）
--
--  重複項目の扱い：
--    病院日誌と医事統計表で粒度が違う項目は、常に「細かいほう」を
--    input として持ち、粗いほうを sum で導出する。
--    例) CT は「入院」「外来」を input とし、日誌の「CT 件数」は
--        その2つの sum。日誌側に入力欄は作らない。
-- ------------------------------------------------------------
CREATE TABLE m_item (

  item_code     VARCHAR(40)  NOT NULL,
  item_name     VARCHAR(64)  NOT NULL,
  dept_id       VARCHAR(16)  NOT NULL,               -- 入力責任部署
  group_code    VARCHAR(32)  NOT NULL DEFAULT '',    -- 入力画面内のまとまり
  group_name    VARCHAR(64)  NOT NULL DEFAULT '',
  calc_type     VARCHAR(8)   NOT NULL DEFAULT 'input', -- input / sum / func
  calc_source   VARCHAR(512)     NULL,               -- sum:item_code,... / func:関数名
  value_type    VARCHAR(12)  NOT NULL DEFAULT 'int', -- int/decimal/text/multiline
  -- 期間集計の方法: sum=期間内を合算（フロー） / last=最終日の値を採る（ストック）
  agg_type      VARCHAR(8)   NOT NULL DEFAULT 'sum',
  unit          VARCHAR(8)   NOT NULL DEFAULT '',
  sort_no       INT          NOT NULL DEFAULT 0,
  required      INT          NOT NULL DEFAULT 0,     -- 1=必須。欠測判定に使う
  min_value     INT              NULL,               -- 桁間違いを弾く範囲チェック
  max_value     INT              NULL,
  valid_from    DATE         NOT NULL DEFAULT '2000-01-01',
  valid_to      DATE             NULL,               -- 廃止しても過去帳票では表示される
  legacy_column VARCHAR(16)      NULL,               -- 旧 nissi テーブルの列名
  excel_ref     VARCHAR(40)      NULL,               -- 元Excelの シート!列（対応表・検証用）
  note          VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (item_code)
);


-- ------------------------------------------------------------
--  d_daily_value : 日次実績（縦持ち）
--  calc_type='input' の項目だけが入る。導出項目は保存しない。
-- ------------------------------------------------------------
CREATE TABLE d_daily_value (

  hizuke     DATE          NOT NULL,
  item_code  VARCHAR(40)   NOT NULL,
  value_num  DECIMAL(12,2)     NULL,                 -- 数値項目
  value_text TEXT              NULL,                 -- 文字項目（天候・術名・会議名など）
  created_by VARCHAR(32)   NOT NULL DEFAULT '',
  created_at DATETIME          NULL,
  updated_by VARCHAR(32)   NOT NULL DEFAULT '',
  updated_at DATETIME          NULL,
  PRIMARY KEY (hizuke, item_code)
);


-- ------------------------------------------------------------
--  d_submission : 部署ごとの提出状態  ← 入力漏れ判定の核
--    none      未着手
--    draft     一時保存（入力中）
--    submitted 部署が提出済
--    confirmed 医事課が確定（以降、部署は編集不可）
-- ------------------------------------------------------------
CREATE TABLE d_submission (

  hizuke       DATE        NOT NULL,
  dept_id      VARCHAR(16) NOT NULL,
  status       VARCHAR(12) NOT NULL DEFAULT 'none',
  submitted_by VARCHAR(32) NOT NULL DEFAULT '',
  submitted_at DATETIME        NULL,
  confirmed_by VARCHAR(32) NOT NULL DEFAULT '',
  confirmed_at DATETIME        NULL,
  PRIMARY KEY (hizuke, dept_id)
);


-- ------------------------------------------------------------
--  d_audit : 変更履歴
--  「誰がいつ何をどう変えたか」。訂正の追跡に使う。
-- ------------------------------------------------------------
CREATE TABLE d_audit (

  audit_id INTEGER PRIMARY KEY AUTOINCREMENT,
  hizuke     DATE         NOT NULL,
  item_code  VARCHAR(40)  NOT NULL,
  action     VARCHAR(8)   NOT NULL,                  -- insert / update / delete
  old_value  TEXT             NULL,
  new_value  TEXT             NULL,
  acted_by   VARCHAR(32)  NOT NULL DEFAULT '',
  acted_at   DATETIME         NULL,
  client_ip  VARCHAR(45)  NOT NULL DEFAULT ''
);


-- ------------------------------------------------------------
--  d_tokki : 特記事項（定員超過報告）
--  患者ID・氏名を含む＝個人情報のため、集計値とは別テーブルに隔離する。
--  閲覧は病棟・医事課・管理者のみ。現行の6行固定をやめ可変行にした。
-- ------------------------------------------------------------
CREATE TABLE d_tokki (

  tokki_id INTEGER PRIMARY KEY AUTOINCREMENT,
  hizuke       DATE         NOT NULL,
  ward         VARCHAR(16)  NOT NULL DEFAULT '',     -- 病棟
  patient_id   VARCHAR(16)  NOT NULL DEFAULT '',     -- 患者ID
  patient_name VARCHAR(64)  NOT NULL DEFAULT '',     -- 氏名
  route        VARCHAR(32)  NOT NULL DEFAULT '',     -- 入院経路
  diagnosis    VARCHAR(128) NOT NULL DEFAULT '',     -- 病名
  sort_no      INT          NOT NULL DEFAULT 0,
  created_by   VARCHAR(32)  NOT NULL DEFAULT '',
  created_at   DATETIME         NULL,
  updated_by   VARCHAR(32)  NOT NULL DEFAULT '',
  updated_at   DATETIME         NULL,
  deleted_at   DATETIME         NULL                -- 論理削除（NULL=有効）
);


-- ------------------------------------------------------------
--  m_target : 目標値マスタ
--  「各種業務量他総括」の目標列。年度初めに医事課が設定する。
-- ------------------------------------------------------------
CREATE TABLE m_target (

  item_code   VARCHAR(40)   NOT NULL,
  fiscal_year INT           NOT NULL,                -- 年度（4月始まり）
  target_avg  DECIMAL(12,2)     NULL,                -- 1日平均の目標値
  PRIMARY KEY (item_code, fiscal_year)
);


-- ------------------------------------------------------------
--  m_config : 設定値
--  現行はHTMLに直書きされており、病床数の変更時に複数ファイルを
--  直す必要があった。ここに集約する。
--  valid_from を持たせ、過去日付の帳票は当時の値で再現する。
-- ------------------------------------------------------------
CREATE TABLE m_config (

  config_key   VARCHAR(64)  NOT NULL,
  valid_from   DATE         NOT NULL DEFAULT '2000-01-01',
  config_value VARCHAR(255) NOT NULL DEFAULT '',
  note         VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (config_key, valid_from)
);

CREATE INDEX idx_user_dept ON m_user (dept_id);
CREATE INDEX idx_item_dept ON m_item (dept_id, sort_no);
CREATE INDEX idx_item_group ON m_item (group_code, sort_no);
CREATE INDEX idx_daily_item ON d_daily_value (item_code, hizuke);
CREATE INDEX idx_audit_hizuke ON d_audit (hizuke);
CREATE INDEX idx_audit_at ON d_audit (acted_at);
CREATE INDEX idx_tokki_hizuke ON d_tokki (hizuke);
