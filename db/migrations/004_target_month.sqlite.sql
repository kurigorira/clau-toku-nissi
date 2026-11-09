-- 月別の目標値のテーブルを足す（SQLite で動かしている場合）。
CREATE TABLE IF NOT EXISTS m_target_month (
  item_code    VARCHAR(40)   NOT NULL,
  ym           CHAR(7)       NOT NULL,
  target_total DECIMAL(12,2) NOT NULL,
  updated_by   VARCHAR(32)   NOT NULL DEFAULT '',
  updated_at   DATETIME          NULL,
  PRIMARY KEY (item_code, ym)
);
