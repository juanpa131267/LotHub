#!/usr/bin/env bash
# Despliegue de LotHub en el servidor de producción (ver docs/DESPLIEGUE.md).
#
#   bash tools/deploy/deploy.sh            # despliega la rama main
#   BRANCH=v1.0.0 bash tools/deploy/deploy.sh
#
# Los parches de base de datos NO se aplican solos: el script lista los nuevos y se detiene para
# que se apliquen con un respaldo recién tomado. lothub:check verifica el resultado al final.
set -euo pipefail
cd "$(dirname "$0")/../.."

BRANCH=${BRANCH:-main}
PHP=${PHP:-php}

echo "› Respaldo previo"
$PHP artisan lothub:backup --only-db

echo "› Código ($BRANCH)"
BEFORE=$(git rev-parse HEAD)
git fetch --tags origin
git checkout -q "$BRANCH"
git pull -q --ff-only origin "$BRANCH" 2>/dev/null || true

NEW_PATCHES=$(git diff --name-only "$BEFORE" HEAD -- database/schema/patches/ || true)
if [ -n "$NEW_PATCHES" ]; then
    echo "!! Hay parches de base de datos nuevos. Aplícalos en orden y vuelve a ejecutar el script:"
    echo "$NEW_PATCHES" | sed 's/^/   mysql -u <usuario> -p cartera_inmobiliaria < /'
    exit 2
fi

echo "› Mantenimiento"
$PHP artisan down --retry=60 || true
trap '$PHP artisan up' EXIT

echo "› Dependencias"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

echo "› Permisos nuevos y cachés"
$PHP artisan db:seed --class=PermissionSeeder --force --no-interaction
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

$PHP artisan up
trap - EXIT

echo "› Verificación"
$PHP artisan lothub:check
