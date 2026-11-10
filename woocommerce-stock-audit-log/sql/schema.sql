CREATE TABLE {{audit_table}} (
id bigint unsigned NOT NULL AUTO_INCREMENT,
timestamp datetime NOT NULL,
product_id bigint unsigned NOT NULL,
variation_id bigint unsigned DEFAULT NULL,
sku varchar(255) NOT NULL DEFAULT '',
product_name varchar(255) NOT NULL DEFAULT '',
old_stock decimal(12,0) DEFAULT NULL,
new_stock decimal(12,0) DEFAULT NULL,
difference decimal(12,0) DEFAULT NULL,
source varchar(100) NOT NULL,
user_id bigint unsigned DEFAULT NULL,
username varchar(255) DEFAULT NULL,
order_id bigint unsigned DEFAULT NULL,
order_number varchar(191) DEFAULT NULL,
request_uri text,
ip_address varchar(64) DEFAULT NULL,
notes text,
PRIMARY KEY  (id),
KEY timestamp_id (timestamp,id),
KEY product_time (product_id,timestamp,id),
KEY variation_time (variation_id,timestamp,id),
KEY sku (sku(191)),
KEY source_time (source,timestamp,id),
KEY order_id (order_id),
KEY order_number (order_number),
KEY user_id (user_id)
) {{collation}};
CREATE TABLE {{settings_table}} (
setting_key varchar(64) NOT NULL,
setting_value bigint unsigned NOT NULL,
PRIMARY KEY  (setting_key)
) {{collation}};
