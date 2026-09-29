-- 閲覧履歴のテーブルを足す（SQLite で動かしている場合）。002_access_log.sql と同じ内容。
CREATE TABLE IF NOT EXISTS d_access_log (
  access_id INTEGER PRIMARY KEY AUTOINCREMENT,
  acted_at   DATETIME     NOT NULL,
  user_id    VARCHAR(32)  NOT NULL DEFAULT '',
  page       VARCHAR(64)  NOT NULL DEFAULT '',
  query      VARCHAR(255) NOT NULL DEFAULT '',
  method     VARCHAR(8)   NOT NULL DEFAULT '',
  client_ip  VARCHAR(45)  NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_access_at ON d_access_log (acted_at);
CREATE INDEX IF NOT EXISTS idx_access_user ON d_access_log (user_id, acted_at);
