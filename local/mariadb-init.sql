-- Local equivalent of the three schemas/endpoints in .upsun/config.yaml.
CREATE DATABASE IF NOT EXISTS nfw;
CREATE DATABASE IF NOT EXISTS oms;
CREATE DATABASE IF NOT EXISTS promotions;
CREATE USER IF NOT EXISTS 'nfw'@'%' IDENTIFIED BY 'nfw';
CREATE USER IF NOT EXISTS 'oms'@'%' IDENTIFIED BY 'oms';
CREATE USER IF NOT EXISTS 'promotions'@'%' IDENTIFIED BY 'promotions';
GRANT ALL ON nfw.* TO 'nfw'@'%';
GRANT ALL ON oms.* TO 'oms'@'%';
GRANT ALL ON promotions.* TO 'promotions'@'%';
