#!/bin/bash
# The restore drill (spec section 9; check E1; PLAN.md, CI step 2) [F2, D35]: a shop with sales is backed up
# by pgBackRest, the backup is restored into a temporary PostgreSQL by the backup service, and the app
# verifies the copy (its row counts and every audit chain), as the weekly test restore does in a shop.
#
# It runs as its own Compose project with its own volumes, so the development database is never touched.
# CI runs it on every pull request and every week. From the repository root, after
# apps/shop-server/docker/dev-setup.sh has built the images and installed the packages:
#   tools/backup/restore-drill.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

project=retail-os-drill
dc() { docker compose --project-name "$project" "$@"; }
art() { dc exec -T scheduler php artisan "$@"; }
step() { echo; echo "== $*"; }
status() { dc exec -T backup sh -c "cat /backup/status/restore.json 2>/dev/null" || true; }
cleanup() { dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

step "1. A fresh shop with sales"
dc up -d db --wait
dc run --rm migrate
dc up -d backup scheduler
art shop:apply-profile docker/profile.example.json
art catalogue:seed
art tinker --execute="app(App\\Identity\\Users::class)->add('Amina', App\\Identity\\Role::Cashier, '1357');"
art shop:rehearse --count=12 --pace=0 --confirm
art shop:rehearsal-check
sales="$(dc exec -T db psql -U postgres -d retail -tAc 'SELECT count(*) FROM sales')"
echo "   $sales sales to back up"

step "2. A full backup with pgBackRest"
dc exec -T backup haq-backup.sh full

step "3. The test restore into a temporary PostgreSQL, verified by the app"
dc exec -T -d backup haq-backup.sh test-restore
for _ in $(seq 1 90); do
    state="$(status | python3 -I -c 'import json,sys; print(json.load(sys.stdin).get("state", ""))' 2>/dev/null || true)"
    [ "$state" = ready ] && break
    [ "$state" = failed ] && { status; echo "The restore failed." >&2; exit 1; }
    sleep 2
done
[ "$state" = ready ] || { status; echo "The restored copy never came up." >&2; exit 1; }
art backups:tick
for _ in $(seq 1 60); do
    state="$(status | python3 -I -c 'import json,sys; print(json.load(sys.stdin).get("state", ""))' 2>/dev/null || true)"
    [ "$state" = done ] && break
    sleep 2
done
status
verified="$(status | python3 -I -c 'import json,sys; print(json.load(sys.stdin).get("verified"))' 2>/dev/null || true)"
[ "$verified" = True ] || { echo "The restored copy was not verified." >&2; exit 1; }

step "4. What the health page shows"
art backups:status

echo
echo "The restore drill passed: $sales sales backed up, restored and verified."
