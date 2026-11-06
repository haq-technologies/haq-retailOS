#!/bin/bash
# Updates a shop's server to a signed release (spec section 4) [D2, D3, S18, F16]. Updates are pulled,
# never pushed. In order:
#
#   1. Fetch the release (manifest.json, its signature and compose.yaml), from GitHub or from a folder
#      brought on a flash drive, and check the signature against this server's release public key.
#      Refuse an older release than the one installed.
#   2. Pull the three images by digest, with this shop's own read-only GHCR token if it has one.
#   3. Take a full backup. No backup, no update.
#   4. Switch to the new images and Compose file; the migration job runs first, and migrations only
#      expand, so the previous code still works on the new schema.
#   5. Health check: the app answers and the grants match the matrix. If not, roll back the code only;
#      the database stays, because sales may already have been made on it.
#
#   sudo /opt/haq-retail-os/bin/update.sh                          # the latest release on GitHub
#   sudo /opt/haq-retail-os/bin/update.sh --release /media/usb/2026.11.1
#
# Options: --dir, --project, --url (a release's download folder), --release (a local folder),
# --skip-pull (staging: the images are already on this machine), --check-only (check the release and stop).
set -euo pipefail

dir=/opt/haq-retail-os
project=haq-retail-os
url=https://github.com/haq-technologies/haq-retailOS/releases/latest/download
release=""
skip_pull=0
check_only=0
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        --url) url="$2"; shift 2 ;;
        --release) release="$2"; shift 2 ;;
        --skip-pull) skip_pull=1; shift ;;
        --check-only) check_only=1; shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

[ -f "$dir/compose.yaml" ] || { echo "There is no shop server in $dir." >&2; exit 2; }
[ -f "$dir/release-public.pem" ] || { echo "This server has no release public key ($dir/release-public.pem), so it cannot check a release." >&2; exit 2; }

compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }
env_value() { grep -E "^$1=" "$dir/.env" | head -1 | cut -d= -f2-; }
json() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); 
for k in sys.argv[2].split("."): d=d[k]
print(d)' "$1" "$2"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

echo "1. Fetching and checking the release"
for f in manifest.json manifest.json.sig compose.yaml; do
    if [ -n "$release" ]; then
        cp "$release/$f" "$work/$f"
    else
        curl -fsSL --retry 3 -o "$work/$f" "$url/$f"
    fi
done
openssl base64 -d -A -in "$work/manifest.json.sig" -out "$work/signature.bin"
if ! openssl pkeyutl -verify -pubin -inkey "$dir/release-public.pem" -rawin -in "$work/manifest.json" -sigfile "$work/signature.bin" >/dev/null 2>&1; then
    echo "The release's signature does not match this server's release key. Nothing was changed." >&2
    exit 1
fi
[ "$(json "$work/manifest.json" schema)" = 1 ] || { echo "This updater does not know this manifest's format." >&2; exit 1; }
version="$(json "$work/manifest.json" version)"
[ "$(openssl dgst -sha256 -r "$work/compose.yaml" | cut -d' ' -f1)" = "$(json "$work/manifest.json" compose_sha256)" ] \
    || { echo "The release's Compose file is not the one its manifest names. Nothing was changed." >&2; exit 1; }
installed="$(json "$dir/release.json" version 2>/dev/null || echo 0)"
if [ "$installed" = "$version" ]; then
    echo "Release $version is already installed."
    exit 0
fi
if [ "$(printf '%s\n%s\n' "$installed" "$version" | sort -V | tail -1)" != "$version" ]; then
    echo "Release $version is older than the installed $installed. Nothing was changed." >&2
    exit 1
fi
app="$(json "$work/manifest.json" images.app)"
web="$(json "$work/manifest.json" images.web)"
db="$(json "$work/manifest.json" images.db)"
echo "   Release $version is signed; installed: $installed"
if [ "$check_only" = 1 ]; then
    echo "The release checks out. Nothing was changed (--check-only)."
    exit 0
fi

echo "2. Pulling the images"
if [ "$skip_pull" != 1 ]; then
    if [ -f "$dir/secrets/ghcr_token" ]; then
        docker login ghcr.io -u "$(cat "$dir/secrets/ghcr_user")" --password-stdin < "$dir/secrets/ghcr_token" >/dev/null
    fi
    for image in "$app" "$web" "$db"; do docker pull -q "$image"; done
    [ -f "$dir/secrets/ghcr_token" ] && docker logout ghcr.io >/dev/null || true
fi

echo "3. A full backup before anything changes"
if ! compose exec -T backup /usr/local/bin/haq-backup.sh full; then
    echo "The backup failed, so the update stops here. Nothing was changed." >&2
    exit 1
fi

echo "4. Switching to release $version"
cp "$dir/.env" "$dir/.env.previous"
cp "$dir/compose.yaml" "$dir/compose.yaml.previous"
grep -v -E '^(APP_IMAGE|WEB_IMAGE|DB_IMAGE)=' "$dir/.env.previous" > "$dir/.env"
printf 'APP_IMAGE=%s\nWEB_IMAGE=%s\nDB_IMAGE=%s\n' "$app" "$web" "$db" >> "$dir/.env"
chmod 600 "$dir/.env"
cp "$work/compose.yaml" "$dir/compose.yaml"

rollback() {
    echo "   $1 Rolling back the code only; the database stays as it is." >&2
    cp "$dir/.env.previous" "$dir/.env"
    cp "$dir/compose.yaml.previous" "$dir/compose.yaml"
    compose up -d --wait --remove-orphans || true
    echo "Release $version was NOT installed. The previous release runs again. Tell HAQ support." >&2
    exit 1
}

if ! compose up -d --wait --remove-orphans; then
    migrate="$(compose ps -a --format '{{.Service}} {{.ExitCode}}' | awk '$1 == "migrate" { print $2 }')"
    if [ "$migrate" != 0 ]; then
        rollback "The migration failed (each migration runs in a transaction, so the schema is as it was; if not, restore the backup just taken with restore.sh)."
    fi
    rollback "The new release did not start."
fi

echo "5. Health check"
healthy=0
for _ in $(seq 1 45); do
    if curl -kfsS "https://127.0.0.1:$(env_value HTTPS_PORT)/up" >/dev/null 2>&1; then healthy=1; break; fi
    sleep 2
done
if [ "$healthy" != 1 ] || [ "${UPDATE_FAIL_HEALTH:-0}" = 1 ]; then
    rollback "The health check failed."
fi
if ! compose exec -T app php artisan db:mutability --check >/dev/null; then
    rollback "The grants no longer match the mutability matrix."
fi

printf '{"version": "%s", "images": {"app": "%s", "web": "%s", "db": "%s"}, "installed_at": "%s"}\n' \
    "$version" "$app" "$web" "$db" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$dir/release.json"
rm -f "$dir/.env.previous" "$dir/compose.yaml.previous"
echo "Release $version is installed."
