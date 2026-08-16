#!/bin/bash
set -euo pipefail

# Runs once, on an empty data directory, via the MariaDB entrypoint.
# Creates both schemas, loads them and registers the local game world.
# main.sql now already uses the camelCase table names the PHP code queries, so
# the gameservers -> gameServers clone this script used to do is gone.

WORLD_ID="${TRAVIAN_WORLD_ID:-dev}"
WORLD_UNIQUE_ID="${TRAVIAN_WORLD_UNIQUE_ID:-9}"
WORLD_URL="${TRAVIAN_WORLD_URL:-http://localhost:8080/}"
MAP_SIZE="${TRAVIAN_MAP_SIZE:-50}"
SPEED="${TRAVIAN_SPEED:-3}"
CONNECTION_FILE="/travian/main_script/copyable/include/connection.php"

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS main  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS world CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'travian'@'%' IDENTIFIED BY 'travian';
GRANT ALL PRIVILEGES ON main.*  TO 'travian'@'%';
GRANT ALL PRIVILEGES ON world.* TO 'travian'@'%';
FLUSH PRIVILEGES;
SQL

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" main  < /travian/main.sql
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" world < /travian/main_script/include/schema/T4.4.sql

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" main <<SQL
INSERT INTO gameServers
    (id, worldId, speed, name, version, gameWorldUrl, startTime, roundLength,
     finished, registerClosed, activation, preregistration_key_only, hidden,
     promoted, configFileLocation)
VALUES
    (${WORLD_UNIQUE_ID}, '${WORLD_ID}', ${SPEED}, 'Local Dev', 4, '${WORLD_URL}',
     UNIX_TIMESTAMP(), 7, 0, 0, 0, 0, 0, 0, '${CONNECTION_FILE}')
ON DUPLICATE KEY UPDATE
    worldId=VALUES(worldId),
    speed=VALUES(speed),
    name=VALUES(name),
    gameWorldUrl=VALUES(gameWorldUrl),
    startTime=VALUES(startTime),
    finished=VALUES(finished),
    registerClosed=VALUES(registerClosed),
    activation=VALUES(activation),
    hidden=VALUES(hidden),
    configFileLocation=VALUES(configFileLocation);
SQL

# The world config row is what bootstrap.php reads on every request; installed=0
# tells install.php there is still work to do.
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" world <<SQL
INSERT IGNORE INTO config
    (id, startTime, map_size, worldUniqueId, installed, loginInfoTitle, loginInfoHTML, message)
VALUES
    (1, UNIX_TIMESTAMP(), ${MAP_SIZE}, ${WORLD_UNIQUE_ID}, 0, '', '', '');
SQL

echo "[init] schemas loaded, world '${WORLD_ID}' registered (map_size=${MAP_SIZE})"
