#!/bin/bash
# One file for HAQ support (finding LG7): the shop server's logs, its checks, its errors and its versions, in one
# ZIP to put on a USB drive or send on WhatsApp, since there is no internet to look at the server from. Run it as
# `sudo haq support-file [--out file.zip]`; on a Windows server, `haq-helper support-file` runs it and adds the
# PC's own part.
#
# It never holds the .env (the app key, the database password), secrets/, the database, the backups,
# shop.profile.json or the off-site key, and a second pass takes out a PIN hash, a key, a token or a password
# should a log ever hold one. It does hold the logs, with staff names and the shop's own details, so it is shop
# data: give it only to HAQ support. tools/ci/support-file-test.sh checks all of this.
#
#   support-file.sh --dir <install folder> --project <name> [--out <file.zip>] [--since <hours>]
set -euo pipefail

dir=""; project=""; out=""; since=168
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        --out) out="$2"; shift 2 ;;
        --since) since="$2"; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
if [ -z "$dir" ] || [ -z "$project" ]; then echo "Give --dir and --project (sudo haq support-file does)." >&2; exit 2; fi
if ! [[ "$since" =~ ^[0-9]+$ ]]; then echo "--since takes hours, such as 168 for a week." >&2; exit 2; fi
docker="${HAQ_DOCKER:-docker}"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
if [ -z "$out" ]; then out="$PWD/haq-support-$stamp.zip"; fi
if [ -e "$out" ]; then echo "$out is there already: give another --out." >&2; exit 1; fi

compose() { "$docker" compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/logs"

echo "Gathering the last $since hours of logs..."
for service in app queue scheduler web db backup migrate roles certificate printer; do
    if compose logs --no-color --timestamps --since "${since}h" "$service" > "$work/logs/$service.log" 2>/dev/null && [ -s "$work/logs/$service.log" ]; then
        continue
    fi
    rm -f "$work/logs/$service.log"
done

# What each scheduled task printed on its last run (finding LG2); a failed one's output is in the scheduler's log.
# shellcheck disable=SC2016 # $f expands inside the container, not here.
compose exec -T scheduler sh -c 'for f in storage/logs/schedule-*.log; do [ -s "$f" ] && { echo "== $f"; tail -n 200 "$f"; }; done; true' \
    > "$work/scheduled-tasks.txt" 2>/dev/null || echo "The scheduler did not answer." > "$work/scheduled-tasks.txt"

echo "Reading the errors and the server's checks..."
compose exec -T app php artisan health:errors > "$work/errors.txt" 2>&1 || echo "The app did not answer." >> "$work/errors.txt"
compose ps --all > "$work/containers.txt" 2>&1 || true
if [ -x "$dir/bin/check-install.sh" ]; then
    "$dir/bin/check-install.sh" --dir "$dir" --project "$project" > "$work/check.txt" 2>&1 || true
fi

# Versions: only the image and release lines of the .env, never the rest of it.
{
    echo "Made: $stamp"
    grep -E '^(APP_IMAGE|WEB_IMAGE|DB_IMAGE|RELEASE_VERSION)=' "$dir/.env" 2>/dev/null || echo "(no .env)"
    for f in channel release.json previous-release.json; do
        if [ -f "$dir/$f" ]; then echo "== $f"; cat "$dir/$f"; echo; fi
    done
    if [ -f "$dir/haq.conf" ]; then echo "== haq.conf"; cat "$dir/haq.conf"; fi
} > "$work/versions.txt"

{
    echo "== date"; date -u
    echo "== uptime"; uptime 2>&1 || true
    echo "== disks"; df -h 2>&1 || true
    echo "== memory"; free -m 2>&1 || true
    echo "== docker"; "$docker" version --format '{{.Server.Version}}' 2>&1 || true
} > "$work/host.txt"
# The host's own report, as the health page reads it: disks, SSDs and the time source.
status_dir="$(sed -n 's/^STATUS_DIR=//p' "$dir/haq.conf" 2>/dev/null || true)"
if [ -n "$status_dir" ] && [ -f "$status_dir/host.json" ]; then cp "$status_dir/host.json" "$work/host.json"; fi

# The redaction pass, then one ZIP readable by its owner only.
python3 -I - "$work" "$out" <<'PY'
import os, re, sys, zipfile
work, out = sys.argv[1], sys.argv[2]
redactions = [
    (re.compile(r'\$argon2(?:id|i|d)\$[^\s"\'\\,)]+'), '[a PIN hash, left out]'),
    (re.compile(r'base64:[A-Za-z0-9+/]{20,}={0,2}'), '[a key, left out]'),
    (re.compile(r'\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{20,}'), '[a token, left out]'),
    (re.compile(r'(?i)\b(password|passwd|secret|token)(["\']?\s*[=:]\s*["\']?)[^\s"\',;&]+'), r'\1\2[left out]'),
]
fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'wb') as f, zipfile.ZipFile(f, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(work):
        for name in sorted(files):
            path = os.path.join(root, name)
            with open(path, encoding='utf-8', errors='replace') as src:
                text = src.read()
            for pattern, replacement in redactions:
                text = pattern.sub(replacement, text)
            z.writestr(os.path.relpath(path, work), text)
PY
# Owned by whoever ran sudo, so they can copy it to a USB drive.
if [ -n "${SUDO_UID:-}" ] && [ -n "${SUDO_GID:-}" ]; then chown "$SUDO_UID:$SUDO_GID" "$out" 2>/dev/null || true; fi

echo "Wrote $out ($(du -h "$out" | cut -f1))."
echo "Give it only to HAQ support: it holds the shop's logs and staff names."
