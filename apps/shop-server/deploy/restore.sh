#!/bin/bash
# Restores a shop's server from its backup disk (spec section 9) [F1, F2, S14], on the same box or on the
# pre-imaged spare after deploy/install.sh has set it up with the same profile and --backup-dir pointing
# at the backup disk (moved over, or copied with rsync). The restore drill in
# docs/operations/restore-drill.md walks through it.
#
#   sudo deploy/restore.sh [--dir /opt/haq-retail-os] [--project haq-retail-os]
#
# 1. Stops everything, then pgBackRest restores the latest backup and replays every archived WAL segment.
# 2. Starts PostgreSQL and waits for recovery to finish.
# 3. Gives the database roles this machine's own secrets (a spare box made its own at install).
# 4. Starts the scheduler alone, which brings back the branch key from the backup disk and starts a new
#    signed epoch on every counter, numbering from 1,001, before any counter can sell.
# 5. Starts the rest, and checks the audit chains and the grants.
set -euo pipefail

dir=/opt/haq-retail-os
project=haq-retail-os
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
[ -f "$dir/compose.yaml" ] || { echo "Run deploy/install.sh first: there is no stack in $dir." >&2; exit 2; }

compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }
started=$(date +%s)

echo "1. Stopping the stack and restoring the latest backup"
compose stop
compose --profile restore run --rm --no-deps restore

echo "2. Starting PostgreSQL and waiting for recovery"
compose up -d db
until compose exec -T db pg_isready -q -h /var/run/postgresql -U postgres; do sleep 2; done
until [ "$(compose exec -T db psql -h /var/run/postgresql -U postgres -d postgres -tAc 'SELECT pg_is_in_recovery()')" = f ]; do sleep 2; done

echo "3. Giving the database roles this machine's secrets"
compose exec -T db sh -c 'echo "ALTER ROLE postgres PASSWORD :'"'"'pw'"'"';" | psql -q -v ON_ERROR_STOP=1 -h /var/run/postgresql -U postgres -d postgres -v pw="$(cat /run/secrets/postgres_password)"'
compose run --rm roles

echo "4. The branch key and the new epochs, before any counter can sell"
compose up -d --wait scheduler
compose exec -T scheduler php artisan shop:after-restore --reason=restore

echo "5. Starting the rest and checking"
compose up -d --wait
compose exec -T app php artisan audit:verify
compose exec -T app php artisan db:mutability --check

echo "Restored in $(( ($(date +%s) - started) / 60 )) minutes. Check each counter reconnects before trading."
