#!/usr/bin/env bash
# Crea (o recrea) la base de datos de pruebas a partir del script oficial y sus parches.
#   bash tests/setup-test-db.sh            # usa DB_* de .env y la base test_lothub
#   TEST_DB=test_otra bash tests/setup-test-db.sh
#
# En MariaDB/MySQL las bases test_% suelen tener permisos para todos los usuarios; si no,
# concede al usuario de la aplicación privilegios sobre la base de pruebas.
set -euo pipefail
cd "$(dirname "$0")/.."

env_value() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST=${DB_HOST:-$(env_value DB_HOST)}
DB_PORT=${DB_PORT:-$(env_value DB_PORT)}
DB_USERNAME=${DB_USERNAME:-$(env_value DB_USERNAME)}
DB_PASSWORD=${DB_PASSWORD:-$(env_value DB_PASSWORD)}
TEST_DB=${TEST_DB:-test_lothub}
MYSQL=${MYSQL:-mysql}

run() { MYSQL_PWD="$DB_PASSWORD" "$MYSQL" -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$@"; }

run -e "DROP DATABASE IF EXISTS \`$TEST_DB\`; CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

for file in database/schema/cartera_inmobiliaria.sql database/schema/patches/*.sql; do
    sed -e "s/cartera_inmobiliaria/$TEST_DB/g" "$file" | run "$TEST_DB"
done

# El evento de reservas vencidas no debe correr durante las pruebas.
run "$TEST_DB" -e "DROP EVENT IF EXISTS ev_expire_reservations;"

# Datos base (permisos, geografía y administrador) con la contraseña conocida de las pruebas.
DB_DATABASE="$TEST_DB" ADMIN_PASSWORD="Admin12345" php artisan db:seed --force --no-interaction >/dev/null

echo "Base de pruebas $TEST_DB lista."
