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
# 3. The older code sells again against the newer schema.
# 4. The newer code sells, and its rehearsal check passes over every sale: gapless receipt numbers per
#    counter and epoch, whole sales, a receipt job for each, every audit chain and root, and the grants.
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

step "3. The older code still sells against the newer schema (a code-only rollback)"
art "$old" shop:rehearse --count=6 --pace=0 --confirm

step "4. The newer code sells, and checks every sale"
art "$new" catalogue:seed
art "$new" shop:rehearse --count=6 --pace=0 --confirm
art "$new" shop:rehearsal-check
art "$new" audit:verify

echo
echo "The upgrade path passed."
