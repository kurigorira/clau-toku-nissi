-- 職員マスタに「電子カルテの部署名」の列を足す（MySQL / MariaDB）。
-- すでに schema.sql を流して動いているサーバ向け。新しく入れる場合は schema.sql に含まれている。
--
--   C:\php\php db\tools\run_sql.php --user=root --pass=＜rootのパスワード＞ db\migrations\003_user_emr_dept.sql
--
-- 2回流すと「Duplicate column name 'emr_dept'」と出て止まるが、列はすでにあるので問題ない。
ALTER TABLE m_user ADD COLUMN emr_dept VARCHAR(64) NOT NULL DEFAULT '' AFTER dept_id;
