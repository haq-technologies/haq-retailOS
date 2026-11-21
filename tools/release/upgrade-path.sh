#!/bin/bash
# The upgrade-path test (spec sections 4 and 14, check E5) [F16]: a shop several versions behind upgrades
# through each migration in order, and the older code still works against the newer schema, so a code-only
# rollback after an update never loses a sale (expand-then-contract).
#
#   DB_DATABASE=retail_upgrade_test tools/release/upgrade-path.sh <older apps/shop-server> <newer apps/shop-server>
#
# Both folders must have their Composer packages and an .env with an app key; the database settings come
# from the environment (DB_HOST, DB_USERNAME and the rest, DB_OWNER_USERNAME and DB_OWNER_PASSWORD). It only
# runs on a database whose name ends in _test, which it rebuilds. CI runs it on every pull request, from the
# end of Phase 3 (b34d27e) to the code under review.
#
# 1. The older version: the schema, a shop from the example profile, the starter catalogue, a cashier and
#    scripted sales on two counters; its own rehearsal check passes.
# 2. The newer version migrates the same database (only what is pending, in order) and applies the
#    mutability matrix; the grant check finds no drift.
# 3. A code-only rollback, as deploy/update.sh does one. From an older version at or above the rollback
#    floor (D106: it has D95's migration), the older code's migrate job runs first, as it does on a shop,
#    then its grant check and the owner's data export, which reads every table it knows (D105). Then the
#    older code sells again against the newer schema. Below the floor, only the selling is checked.
# 4. The update is tried again: the newer code migrates and applies its matrix, sells, and its rehearsal
#    check passes over every sale: gapless receipt numbers per counter and epoch, whole sales, a receipt
#    job for each, every audit chain and root, and the grants.
set -euo pipefail

old="${1:?Give the older apps/shop-server folder}"
new="${2:?Give the newer apps/shop-server folder}"
case "${DB_DATABASE:-}" in
    *_test) ;;
    *) echo "Set DB_DATABASE to a database whose name ends in _test; this rebuilds it." >&2; exit 2 ;;
esac

step() { echo; echo "== $*"; }
art() { local dir="$1"; shift; (cd "$dir" && php artisan "$@"); }

step "1. The older version ($(cd "$old" && git rev-parse --short HEAD 2>/dev/null || echo "$old")): schema, shop, catalogue, a cashier, sales"
art "$old" migrate:fresh --database=pgsql_owner --force
art "$old" db:mutability --database=pgsql_owner
art "$old" shop:apply-profile docker/profile.example.json
art "$old" catalogue:seed
art "$old" tinker --execute="app(App\\Identity\\Users::class)->add('Amina', App\\Identity\\Role::Cashier, '1357');"
art "$old" shop:rehearse --count=20 --pace=0 --confirm
art "$old" shop:rehearsal-check

step "2. The newer version migrates the same database, in order"
art "$new" migrate --database=pgsql_owner --force
art "$new" db:mutability --database=pgsql_owner
art "$new" db:mutability --check

step "3. A code-only rollback: the older code starts and sells against the newer schema"
# The rollback floor (D106): the first code that has D95's migration. Older code is not rolled back to.
if [ -f "$old/database/migrations/2026_11_19_000100_remove_measured_pilot.php" ]; then
    art "$old" migrate --database=pgsql_owner --force
    art "$old" db:mutability --database=pgsql_owner
    art "$old" db:mutability --check
    art "$old" shop:export - > /dev/null
    echo "The older code's migrate job, grant check and data export passed."
else
    echo "Below the rollback floor (D106): only the selling is checked."
fi
art "$old" shop:rehearse --count=6 --pace=0 --confirm

step "4. The update again: the newer code migrates, sells, and checks every sale"
art "$new" migrate --database=pgsql_owner --force
art "$new" db:mutability --database=pgsql_owner
art "$new" db:mutability --check
art "$new" catalogue:seed
art "$new" shop:rehearse --count=6 --pace=0 --confirm
art "$new" shop:rehearsal-check
art "$new" audit:verify

echo
echo "The upgrade path passed."
