-- 閲覧履歴のテーブルを足す（MySQL / MariaDB）。
-- すでに schema.sql を流して動いているサーバ向け。新しく入れる場合は schema.sql に含まれている。
--
--   C:\php\php db\tools\run_sql.php --user=root --pass=＜rootのパスワード＞ db\migrations\002_access_log.sql
--
-- 何度流しても壊れない（すでにあれば何もしない）。
CREATE TABLE IF NOT EXISTS d_access_log (
  access_id  BIGINT       NOT NULL AUTO_INCREMENT,
  acted_at   DATETIME     NOT NULL,
  user_id    VARCHAR(32)  NOT NULL DEFAULT '',
  page       VARCHAR(64)  NOT NULL DEFAULT '',
  query      VARCHAR(255) NOT NULL DEFAULT '',
  method     VARCHAR(8)   NOT NULL DEFAULT '',
  client_ip  VARCHAR(45)  NOT NULL DEFAULT '',
  PRIMARY KEY (access_id),
  KEY idx_access_at (acted_at),
  KEY idx_access_user (user_id, acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
