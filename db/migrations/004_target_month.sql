-- 月別の目標値のテーブルを足す（MySQL / MariaDB）。事業計画の Excel を取り込むために使う。
-- すでに schema.sql を流して動いているサーバ向け。新しく入れる場合は schema.sql に含まれている。
--
--   C:\php\php db\tools\run_sql.php --user=root --pass=＜rootのパスワード＞ db\migrations\004_target_month.sql
--
-- 何度流しても壊れない（すでにあれば何もしない）。
CREATE TABLE IF NOT EXISTS m_target_month (
  item_code    VARCHAR(40)   NOT NULL,
  ym           CHAR(7)       NOT NULL,
  target_total DECIMAL(12,2) NOT NULL,
  updated_by   VARCHAR(32)   NOT NULL DEFAULT '',
  updated_at   DATETIME          NULL,
  PRIMARY KEY (item_code, ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
