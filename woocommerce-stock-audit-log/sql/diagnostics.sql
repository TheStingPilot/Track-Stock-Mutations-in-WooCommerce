CREATE TABLE IF NOT EXISTS %i (
failure_type varchar(32) NOT NULL,
occurrences bigint unsigned NOT NULL,
last_seen datetime NOT NULL,
stock_owner_id bigint unsigned NOT NULL,
PRIMARY KEY (failure_type)
) {{collation}};
