#!/bin/sh
# The branch's backup service (spec section 9) [S16, F24], in the db image, beside PostgreSQL.
#
#   haq-backup.sh            the loop: creates the stanza, then every 5 minutes runs what is due
#   haq-backup.sh full       a full backup now (the updater runs this before every update)
#   haq-backup.sh test-restore   the test restore now (the restore drill)
#
# Due: a full backup when there is none or the last is 7 days old; an incremental when the last backup is
# a day old; a test restore when the last verified one is 7 days old. Catch-up scheduling: what is due
# runs in the night window (from BACKUP_HOUR, default 01:00, for 5 hours) or whenever the shop is idle
# (no sale for 10 minutes), so a box that was off at night catches up at the next quiet moment.
#
# The test restore loads the latest backup into a temporary PostgreSQL on port 5433, with archiving off,
# and writes /backup/status/restore.json. The app's scheduler (php artisan backups:tick) then counts the
# rows and verifies every audit chain on it and writes verified-<label>.json; this loop then stops the
# temporary server and removes its files.
#
# Back up now: the owner asks on the health page, the scheduler writes status/backup-now.json, and this
# loop, which looks every 15 seconds, takes a backup and notes the request it answered in backup-now-done.
set -u

STANZA=shop
STATUS=/backup/status
RESTORE=/backup/restore-test
HOUR="${BACKUP_HOUR:-1}"
WAIT_VERIFY_SECONDS="${BACKUP_VERIFY_WAIT_SECONDS:-2700}"

log() { echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') haq-backup: $*"; }

prepare() {
    mkdir -p /backup/repo /backup/log "$STATUS"
    # The app's scheduler writes the verification results and keeps a copy of the branch key here.
    chmod 1777 "$STATUS" 2>/dev/null || true
    until pg_isready -q -h /var/run/postgresql -U postgres; do sleep 2; done
    if [ ! -f /backup/repo/archive/$STANZA/archive.info ]; then
        log "creating the stanza"
        pgbackrest --stanza=$STANZA stanza-create || return 1
    fi
    pgbackrest --stanza=$STANZA check >/dev/null 2>&1 || log "the archive check failed; PostgreSQL retries the archive"
    return 0
}

info() { pgbackrest --stanza=$STANZA info --output=json 2>/dev/null || echo '[]'; }

last_stop() { # $1: a jq filter on the backup type, such as "full" or any
    if [ "$1" = any ]; then
        info | jq '[.[0].backup[]?.timestamp.stop] | max // 0'
    else
        info | jq --arg t "$1" '[.[0].backup[]? | select(.type == $t) | .timestamp.stop] | max // 0'
    fi
}

write_status() {
    info | jq '{written_at: (now | floor), backups: [.[0].backup[]? | {label, type, start: .timestamp.start, stop: .timestamp.stop, size: .info.repository.delta}], archive: .[0].archive, status: .[0].status}' \
        > "$STATUS/backup.json.tmp" && mv "$STATUS/backup.json.tmp" "$STATUS/backup.json"
}

backup() { # $1: full or incr. Returns non-zero if it failed, so the updater stops.
    log "starting a $1 backup"
    if pgbackrest --stanza=$STANZA --type="$1" backup; then
        log "the $1 backup finished"
        rm -f "$STATUS/backup-failed.json"
        write_status
        return 0
    fi
    log "the $1 backup FAILED"
    echo "{\"failed_at\": $(date +%s), \"type\": \"$1\"}" > "$STATUS/backup-failed.json"
    write_status
    return 1
}

in_window() {
    h=$(date +%H | sed 's/^0//')
    [ "$h" -ge "$HOUR" ] && [ "$h" -lt $((HOUR + 5)) ]
}

idle() {
    n=$(psql -h /var/run/postgresql -U postgres -d retail -tAc "SELECT count(*) FROM sales WHERE recorded_at > now() - interval '10 minutes'" 2>/dev/null || echo 1)
    [ "$n" = 0 ]
}

stop_restore() {
    if [ -f "$RESTORE/pgdata/postmaster.pid" ]; then
        pg_ctl -D "$RESTORE/pgdata" -m fast -w stop >/dev/null 2>&1 || true
    fi
    rm -rf "$RESTORE/pgdata"
}

test_restore() {
    label=$(info | jq -r '[.[0].backup[]?.label] | last // empty')
    if [ -z "$label" ]; then
        log "no backup to test yet"
        return 1
    fi
    log "test restore of $label"
    stop_restore
    mkdir -p "$RESTORE/pgdata" && chmod 700 "$RESTORE/pgdata"
    started=$(date +%s)
    echo "{\"state\": \"restoring\", \"label\": \"$label\", \"started_at\": $started}" > "$STATUS/restore.json"
    if ! pgbackrest --stanza=$STANZA --pg1-path="$RESTORE/pgdata" --set="$label" --type=immediate --target-action=promote restore; then
        echo "{\"state\": \"failed\", \"label\": \"$label\", \"started_at\": $started, \"reason\": \"restore\"}" > "$STATUS/restore.json"
        stop_restore
        return 1
    fi
    # Never archive from the copy into the real repository.
    if ! pg_ctl -D "$RESTORE/pgdata" -w -t 600 -l "$RESTORE/postgres.log" -o "-p 5433 -c listen_addresses='*' -c archive_mode=off -c unix_socket_directories=/tmp" start; then
        echo "{\"state\": \"failed\", \"label\": \"$label\", \"started_at\": $started, \"reason\": \"start\"}" > "$STATUS/restore.json"
        stop_restore
        return 1
    fi
    until [ "$(psql -h /tmp -p 5433 -U postgres -d postgres -tAc 'SELECT pg_is_in_recovery()' 2>/dev/null)" = f ]; do sleep 2; done
    echo "{\"state\": \"ready\", \"label\": \"$label\", \"port\": 5433, \"started_at\": $started}" > "$STATUS/restore.json"
    log "the copy is up on port 5433; waiting for the app to verify it"
    waited=0
    while [ ! -f "$STATUS/verified-$label.json" ] && [ "$waited" -lt "$WAIT_VERIFY_SECONDS" ]; do
        sleep 10
        waited=$((waited + 10))
    done
    stop_restore
    if [ -f "$STATUS/verified-$label.json" ]; then
        ok=$(jq -r '.ok' "$STATUS/verified-$label.json")
        echo "{\"state\": \"done\", \"label\": \"$label\", \"started_at\": $started, \"finished_at\": $(date +%s), \"verified\": $ok}" > "$STATUS/restore.json"
        log "test restore of $label verified: $ok"
        [ "$ok" = true ] && date +%s > "$STATUS/last-verified-restore"
    else
        echo "{\"state\": \"done\", \"label\": \"$label\", \"started_at\": $started, \"finished_at\": $(date +%s), \"verified\": false, \"reason\": \"not verified in time\"}" > "$STATUS/restore.json"
        log "test restore of $label was not verified in time"
    fi
    return 0
}

# A Back up now not yet answered (the file is the scheduler's, so the answer goes in a file of our own).
asked() {
    [ -f "$STATUS/backup-now.json" ] || return 1
    [ "$(jq -r '.requested_at // empty' "$STATUS/backup-now.json" 2>/dev/null)" != "$(cat "$STATUS/backup-now-done" 2>/dev/null)" ]
}

answer() {
    requested=$(jq -r '.requested_at // empty' "$STATUS/backup-now.json" 2>/dev/null)
    log "a backup asked for on the health page ($requested)"
    backup incr || true
    echo "$requested" > "$STATUS/backup-now-done"
}

loop() {
    until prepare; do log "waiting for the repository"; sleep 30; done
    write_status
    while true; do
        if asked; then answer; fi
        now=$(date +%s)
        full=$(last_stop full)
        any=$(last_stop any)
        verified=$(cat "$STATUS/last-verified-restore" 2>/dev/null || echo 0)
        if [ "$full" -eq 0 ]; then
            backup full || true
        elif [ $((now - full)) -ge $((7 * 86400 - 3600)) ] && { in_window || idle; }; then
            backup full || true
        elif [ $((now - any)) -ge $((86400 - 3600)) ] && { in_window || idle; }; then
            backup incr || true
        elif [ $((now - verified)) -ge $((7 * 86400)) ] && { in_window || idle; }; then
            test_restore
        fi
        waited=0
        while [ "$waited" -lt 300 ] && ! asked; do
            sleep 15
            waited=$((waited + 15))
        done
    done
}

case "${1:-loop}" in
    loop) loop ;;
    full) prepare && backup full ;;
    incr) prepare && backup incr ;;
    test-restore) prepare && test_restore ;;
    status) write_status && cat "$STATUS/backup.json" ;;
    *) echo "Usage: haq-backup.sh [loop|full|incr|test-restore|status]" >&2; exit 2 ;;
esac
