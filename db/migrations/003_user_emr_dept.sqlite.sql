-- 職員マスタに「電子カルテの部署名」の列を足す（SQLite で動かしている場合）。
ALTER TABLE m_user ADD COLUMN emr_dept VARCHAR(64) NOT NULL DEFAULT '';
