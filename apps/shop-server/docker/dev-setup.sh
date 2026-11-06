#!/bin/sh
# First-time setup of the shop server's development stack on this computer. Safe to rerun.
# Needs Docker (through Colima: run `colima start` first after a restart). PHP, Composer and Node
# run in containers, so none of them need installing on the computer itself.
set -eu

cd "$(dirname "$0")/../../.."   # the repository root, where compose.yaml lives
app=apps/shop-server

docker compose build app db
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps vite npm ci

if [ ! -f "$app/.env" ]; then
    cp "$app/.env.example" "$app/.env"
fi
if ! grep -q '^APP_KEY=base64:' "$app/.env"; then
    docker compose run --rm --no-deps app php artisan key:generate
fi

docker compose up -d db --wait

# A database volume made before the three roles existed has no retail_app role. It holds only
# development data, so it is safe to remove: docker compose down -v
if ! docker compose exec -T db psql -U postgres -d retail -tAc "SELECT 1 FROM pg_roles WHERE rolname = 'retail_app'" 2>/dev/null | grep -q 1 \
    || ! docker compose exec -T db psql -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = 'retail_concurrency'" 2>/dev/null | grep -q 1; then
    echo "This database volume predates the three database roles or the test databases. Remove it with" >&2
    echo "  docker compose down -v" >&2
    echo "(development data only), then run this script again." >&2
    exit 1
fi

docker compose run --rm migrate
docker compose up -d

echo
echo "The shop server is running: http://localhost:8080"
