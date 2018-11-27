CREATE TABLE IF NOT EXISTS llx_c_atgpconnector_status
(
	rowid		INTEGER PRIMARY KEY,
	code		VARCHAR(32) NOT NULL,
	label		VARCHAR(128),
	active		TINYINT DEFAULT 1 NOT NULL
)ENGINE=innodb;