#!/bin/sh
# First-time setup of the shop server's development stack on this computer. Safe to rerun.
# Needs Docker (through Colima: run `colima start` first after a restart). PHP, Composer and Node
# run in containers, so none of them need installing on the computer itself.
set -eu

cd "$(dirname "$0")/../../.."   # the repository root, where compose.yaml lives
app=apps/shop-server

docker compose build app
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps vite npm ci

if [ ! -f "$app/.env" ]; then
    cp "$app/.env.example" "$app/.env"
fi
if ! grep -q '^APP_KEY=base64:' "$app/.env"; then
    docker compose run --rm --no-deps app php artisan key:generate
fi

docker compose up -d
docker compose exec app php artisan migrate

echo
echo "The shop server is running: http://localhost:8080"
