#!/bin/bash
# Restores a shop's server from its backup disk (spec section 9) [F1, F2, S14], on the same box or on the
# pre-imaged spare after deploy/install.sh has set it up with the same profile and --backup-dir pointing
# at the backup disk (moved over, or copied with rsync). The restore drill in
# docs/operations/restore-drill.md walks through it.
#
#   sudo deploy/restore.sh [--dir /opt/haq-retail-os] [--project haq-retail-os] [--from-drive /dev/sdb1]
#
# With --from-drive, the backups come first from an off-site flash drive (the server and its backup disk
# were lost): it asks for two of the three key shares and unpacks the newest copy onto this machine's
# backup disk, with the branch key and the epochs (D53). Then the restore runs as from the backup disk.
#
# 1. Stops everything, then pgBackRest restores the latest backup and replays every archived WAL segment.
# 2. Starts PostgreSQL and waits for recovery to finish.
# 3. Gives the database roles this machine's own secrets (a spare box made its own at install).
# 4. Puts back the shop's app key from the backup disk, so the counters' device cookies still open (D69).
#    Starts the scheduler alone, which brings back the branch key and the shop's certificate authority
#    and starts a new signed epoch on every counter, numbering from 1,001, before any counter can sell.
#    Then the server's certificate is issued from that authority, so the counters trust this server.
# 5. Starts the rest, and checks the audit chains and the grants.
set -euo pipefail

dir=/opt/haq-retail-os
project=haq-retail-os
from_drive=
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        --from-drive) from_drive="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
[ -f "$dir/compose.yaml" ] || { echo "Run deploy/install.sh first: there is no stack in $dir." >&2; exit 2; }

compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }
started=$(date +%s)

echo "1. Stopping the stack and restoring the latest backup"
compose stop
if [ -n "$from_drive" ]; then
    echo "   First, the backups from the off-site drive $from_drive (two key shares needed)"
    HAQ_DIR="$dir" HAQ_PROJECT="$project" "$(dirname "$0")/offsite.sh" unpack "$from_drive"
fi
compose --profile restore run --rm --no-deps restore

echo "2. Starting PostgreSQL and waiting for recovery"
compose up -d db
until compose exec -T db pg_isready -q -h /var/run/postgresql -U postgres; do sleep 2; done
until [ "$(compose exec -T db psql -h /var/run/postgresql -U postgres -d postgres -tAc 'SELECT pg_is_in_recovery()')" = f ]; do sleep 2; done

echo "3. Giving the database roles this machine's secrets"
compose exec -T db sh -c 'echo "ALTER ROLE postgres PASSWORD :'"'"'pw'"'"';" | psql -q -v ON_ERROR_STOP=1 -h /var/run/postgresql -U postgres -d postgres -v pw="$(cat /run/secrets/postgres_password)"'
compose run --rm roles

echo "4. The shop's keys and the new epochs, before any counter can sell"
# The app key opens the counters' device cookies: with the shop's own, every counter stays enrolled (D69).
backup_dir="$(grep '^BACKUP_DIR=' "$dir/.env" | cut -d= -f2- || true)"
kept="$backup_dir/status/keys/app.key"
if [ -n "$backup_dir" ] && [ -s "$kept" ]; then
    key="$(cat "$kept")"
    if ! printf '%s' "$key" | grep -qE '^base64:[A-Za-z0-9+/]{43}=$'; then
        echo "   The app key on the backup disk is not one this server can use; each counter must enrol again." >&2
    elif [ "$key" != "$(grep '^APP_KEY=' "$dir/.env" | cut -d= -f2-)" ]; then
        { grep -v '^APP_KEY=' "$dir/.env"; echo "APP_KEY=$key"; } > "$dir/.env.new"
        mv "$dir/.env.new" "$dir/.env"
        chmod 600 "$dir/.env"
        echo "   The app key came back from the backup disk: every counter stays enrolled."
    fi
else
    echo "   There is no app key on the backup disk: each counter must enrol again (sudo haq enrol T01)." >&2
fi
compose up -d --wait scheduler
compose exec -T scheduler php artisan shop:after-restore --reason=restore
# The certificate from the shop's authority, if the restore brought it back in place of this machine's own.
compose run --rm --no-deps certificate

echo "5. Starting the rest and checking"
compose up -d --wait
compose exec -T app php artisan audit:verify
compose exec -T app php artisan db:mutability --check

echo "Restored in $(( ($(date +%s) - started) / 60 )) minutes. Check each counter reconnects before trading."
