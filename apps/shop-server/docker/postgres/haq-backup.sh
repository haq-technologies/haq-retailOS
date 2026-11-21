#!/bin/sh
# The branch's backup service (spec section 9) [S16, F24], in the db image, beside PostgreSQL.
#
#   haq-backup.sh            the loop: creates the stanza, then every 5 minutes runs what is due
#   haq-backup.sh full       a full backup now (the updater runs this before every update)
#   haq-backup.sh test-restore   the test restore now (the restore drill)
#   haq-backup.sh selftest   checks what the loop finds due and the backup disk's marker (CI runs it)
#
# Due: a full backup when there is none or the last is 7 days old; an incremental when the last backup is
# a day old; a test restore when the last verified one is 7 days old. Catch-up scheduling: what is due
# runs in the night window (from BACKUP_HOUR, default 01:00, for 5 hours) or whenever the shop is idle
# (no sale for 10 minutes), so a box that was off at night catches up at the next quiet moment. A test
# restore that did not verify is tried again a day later, or sooner for a newer backup, never at every
# pass: each try restores the whole database (D128).
#
# The test restore loads the latest backup into a temporary PostgreSQL on port 5433, with archiving off,
# and writes /backup/status/restore.json. The app's scheduler (php artisan backups:tick) then counts the
# rows and verifies every audit chain on it and writes verified-<label>.json; this loop then stops the
# temporary server and removes its files. Only a result written after the try began counts, so a second
# try of the same backup is checked again (D128).
#
# The backup disk (D129): on a server whose backups have a disk of their own (install.sh --backup-dir, so
# BACKUP_OWN_DISK=true), backups go only where the disk's marker, .haq-backup-disk, is. A disk that fails
# to mount leaves an empty folder on the main disk, and backups there would look healthy while nothing is
# kept away from the sales. A disk that already holds this shop's backups is marked the first time.
#
# Back up now: the owner asks on the health page, the scheduler writes status/backup-now.json, and this
# loop, which looks every 15 seconds, takes a backup and notes the request it answered in backup-now-done.
set -u

STANZA=shop
BACKUP="${HAQ_BACKUP_ROOT:-/backup}"
STATUS="$BACKUP/status"
RESTORE="$BACKUP/restore-test"
MARKER="$BACKUP/.haq-backup-disk"
HOUR="${BACKUP_HOUR:-1}"
WAIT_VERIFY_SECONDS="${BACKUP_VERIFY_WAIT_SECONDS:-2700}"
DAY=86400

log() { echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') haq-backup: $*"; }

# Whether the backups may go to $BACKUP (D129). Without a disk of their own they always may.
disk_ready() {
    [ "${BACKUP_OWN_DISK:-}" = true ] || return 0
    [ -f "$MARKER" ] && return 0
    if [ -f "$BACKUP/repo/archive/$STANZA/archive.info" ]; then
        echo "HAQ Retail OS backup disk. Marked by the backup service on $(date -u +%F), beside this shop's backups." > "$MARKER" || return 1
        log "marked the backup disk, which holds this shop's backups"
        return 0
    fi
    return 1
}

# What is due (D35, D128): full, incr, test-restore or nothing. $1 now; $2 the last full backup and $3 the
# last backup of any kind; $4 the last verified test restore; $5 the last test restore tried and $6 the
# backup it tried; $7 the newest backup; $8 1 when the shop is quiet (the night window, or no sale for 10
# minutes). Times are seconds since 1970, 0 for never.
due() {
    if [ "$2" -eq 0 ]; then
        echo full
    elif [ "$8" != 1 ]; then
        echo nothing
    elif [ $(($1 - $2)) -ge $((7 * DAY - 3600)) ]; then
        echo full
    elif [ $(($1 - $3)) -ge $((DAY - 3600)) ]; then
        echo incr
    elif [ $(($1 - $4)) -ge $((7 * DAY)) ] && { [ $(($1 - $5)) -ge $((DAY - 3600)) ] || [ "$6" != "$7" ]; }; then
        echo test-restore
    else
        echo nothing
    fi
}

prepare() {
    if ! disk_ready; then
        log "the backup disk is not mounted at $BACKUP (no $MARKER there): no backups until it is"
        return 1
    fi
    mkdir -p "$BACKUP/repo" "$BACKUP/log" "$STATUS"
    # The app's scheduler writes the verification results and keeps a copy of the branch key here.
    chmod 1777 "$STATUS" 2>/dev/null || true
    until pg_isready -q -h /var/run/postgresql -U postgres; do sleep 2; done
    if [ ! -f "$BACKUP/repo/archive/$STANZA/archive.info" ]; then
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

# The newest backup, the one a test restore loads.
newest() { info | jq -r '[.[0].backup[]?.label] | last // empty'; }

# A test restore tried, whatever came of it, so a failing one waits before the next try (D128).
tried() { echo "$(date +%s) $label" > "$STATUS/last-tried-restore"; }

# The app's result for this try, which names the try by its start: never one left from an earlier try (D128).
checked() {
    f="$STATUS/verified-$label.json"
    [ -f "$f" ] && [ "$(jq -r '.started_at // empty' "$f" 2>/dev/null)" = "$started" ]
}

test_restore() {
    label=$(newest)
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
        tried
        return 1
    fi
    # Never archive from the copy into the real repository.
    if ! pg_ctl -D "$RESTORE/pgdata" -w -t 600 -l "$RESTORE/postgres.log" -o "-p 5433 -c listen_addresses='*' -c archive_mode=off -c unix_socket_directories=/tmp" start; then
        echo "{\"state\": \"failed\", \"label\": \"$label\", \"started_at\": $started, \"reason\": \"start\"}" > "$STATUS/restore.json"
        stop_restore
        tried
        return 1
    fi
    until [ "$(psql -h /tmp -p 5433 -U postgres -d postgres -tAc 'SELECT pg_is_in_recovery()' 2>/dev/null)" = f ]; do sleep 2; done
    echo "{\"state\": \"ready\", \"label\": \"$label\", \"port\": 5433, \"started_at\": $started}" > "$STATUS/restore.json"
    log "the copy is up on port 5433; waiting for the app to verify it"
    waited=0
    while ! checked && [ "$waited" -lt "$WAIT_VERIFY_SECONDS" ]; do
        sleep 10
        waited=$((waited + 10))
    done
    stop_restore
    tried
    if checked; then
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
        tried=$(cut -d' ' -f1 "$STATUS/last-tried-restore" 2>/dev/null || echo 0)
        tried_label=$(cut -d' ' -f2 "$STATUS/last-tried-restore" 2>/dev/null || true)
        case "$tried" in '' | *[!0-9]*) tried=0 ;; esac
        quiet=0
        if in_window || idle; then quiet=1; fi
        case "$(due "$now" "$full" "$any" "$verified" "$tried" "$tried_label" "$(newest)" "$quiet")" in
            full) backup full || true ;;
            incr) backup incr || true ;;
            test-restore) test_restore ;;
        esac
        waited=0
        while [ "$waited" -lt 300 ] && ! asked; do
            sleep 15
            waited=$((waited + 15))
        done
    done
}

# What the loop finds due, from a table of cases, and the backup disk's marker, in a temporary folder. It
# needs no PostgreSQL, so CI runs it in the db image beside the off-site self-test.
selftest() {
    fail() { echo "haq-backup selftest: $*" >&2; exit 1; }
    expect() { # what should be due, then due's arguments
        want=$1
        shift
        got=$(due "$@")
        [ "$got" = "$want" ] || fail "due $* gave $got, not $want"
    }
    d=$DAY
    n=$((100 * d))
    # No full backup yet: one now, quiet or not.
    expect full "$n" 0 0 0 0 "" "" 0
    # Not quiet: nothing, however much is due.
    expect nothing "$n" $((n - 8 * d)) $((n - 2 * d)) 0 0 "" L 0
    # A full after 7 days, less an hour's slack; an incremental after a day, less an hour.
    expect full "$n" $((n - 7 * d + 3600)) $((n - 2 * d)) "$n" "$n" L L 1
    expect nothing "$n" $((n - 7 * d + 3601)) $((n - 3600)) "$n" "$n" L L 1
    expect incr "$n" $((n - 6 * d)) $((n - d + 3600)) "$n" "$n" L L 1
    expect nothing "$n" $((n - 6 * d)) $((n - d + 3601)) "$n" "$n" L L 1
    # The weekly test restore, the first one too.
    expect test-restore "$n" $((n - 6 * d)) $((n - 3600)) 0 0 "" L 1
    expect test-restore "$n" $((n - 6 * d)) $((n - 3600)) $((n - 7 * d)) $((n - 7 * d)) K L 1
    expect nothing "$n" $((n - 6 * d)) $((n - 3600)) $((n - 7 * d + 1)) $((n - 7 * d + 1)) L L 1
    # After a try that did not verify (D128): not again for the same backup within the day...
    expect nothing "$n" $((n - 6 * d)) $((n - 3600)) $((n - 14 * d)) $((n - 300)) L L 1
    expect nothing "$n" $((n - 6 * d)) $((n - 3600)) $((n - 14 * d)) $((n - d + 3601)) L L 1
    # ...but a day later, or at once for a newer backup.
    expect test-restore "$n" $((n - 6 * d)) $((n - 3600)) $((n - 14 * d)) $((n - d + 3600)) L L 1
    expect test-restore "$n" $((n - 6 * d)) $((n - 3600)) $((n - 14 * d)) $((n - 300)) L M 1

    # The backup disk's marker (D129).
    root=$(mktemp -d)
    trap 'rm -rf "$root"' EXIT
    BACKUP="$root"
    MARKER="$root/.haq-backup-disk"
    BACKUP_OWN_DISK=
    disk_ready || fail "without a disk of their own, the backups were refused"
    BACKUP_OWN_DISK=true
    if disk_ready; then fail "an empty folder with no marker was taken for the backup disk"; fi
    [ ! -f "$MARKER" ] || fail "an empty folder was marked as the backup disk"
    mkdir -p "$root/repo/archive/$STANZA" && echo archive > "$root/repo/archive/$STANZA/archive.info"
    disk_ready >/dev/null || fail "a disk holding this shop's backups was refused"
    [ -f "$MARKER" ] || fail "a disk holding this shop's backups was not marked"
    rm -rf "$root/repo"
    disk_ready || fail "a marked disk was refused"
    rm -rf "$root"
    trap - EXIT
    echo "haq-backup selftest passed: full, incremental and weekly test restore when due and quiet; a failed test restore waits a day or for a newer backup; backups only on a marked disk."
}

case "${1:-loop}" in
    loop) loop ;;
    full) prepare && backup full ;;
    incr) prepare && backup incr ;;
    test-restore) prepare && test_restore ;;
    status) write_status && cat "$STATUS/backup.json" ;;
    selftest) selftest ;;
    *) echo "Usage: haq-backup.sh [loop|full|incr|test-restore|status|selftest]" >&2; exit 2 ;;
esac
