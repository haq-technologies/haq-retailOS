#!/bin/bash
# Updates a shop's server to a signed release (spec section 4) [D2, D3, S18, F16]. Updates are pulled,
# never pushed. In order:
#
#   1. Fetch the release (manifest.json, its signature and compose.yaml), from GitHub or from a folder
#      brought on a flash drive, and check the signature against this server's release public key.
#      Refuse an older release than the one installed.
#   2. Pull the three images by digest, with this shop's own read-only GHCR token if it has one.
#   3. Take a full backup. No backup, no update.
#   4. Switch to the new images and Compose file; the migration job runs first. Migrations only go
#      forward and only expand: a table is dropped only a release after the code stopped using it, and no
#      release rolls back below the floor (D106). Tables a new release adds are left to it by the previous
#      release's migrate job (D105). So the previous code still works on the new schema.
#   5. Health check: the app answers and the grants match the matrix. If not, roll back the code only;
#      the database stays, because sales may already have been made on it.
#
#   sudo /opt/haq-retail-os/bin/update.sh                          # the release this shop's channel gives
#   sudo /opt/haq-retail-os/bin/update.sh --version 2026.11.3      # this release, whatever the channel
#   sudo /opt/haq-retail-os/bin/update.sh --release /media/usb/2026.11.1
#
# Which release a plain update takes is the shop's channel (the file `channel` in the install folder, set
# with `sudo haq channel`; D81): stable takes the latest release, beta the newest one, a pre-release too,
# and pinned stays where it is until it is given a --version.
#
# Options: --dir, --project, --version (a published release), --url (a release's download folder),
# --release (a local folder), --skip-pull (staging: the images are already on this machine), --check-only
# (check the release and stop).
set -euo pipefail

dir=/opt/haq-retail-os
project=haq-retail-os
repo=haq-technologies/haq-retailOS
url=""
release=""
version_wanted=""
skip_pull=0
check_only=0
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        --version) version_wanted="$2"; shift 2 ;;
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
is_version() { printf '%s' "$1" | grep -Eq '^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$'; }
# True when release $1 comes before release $2.
older() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]; }

installed="$(json "$dir/release.json" version 2>/dev/null || echo 0)"

# Where the release comes from: a folder, a download address, a named release, or the shop's channel.
channel=stable
if [ -f "$dir/channel" ]; then channel="$(tr -d '[:space:]' < "$dir/channel")"; fi
channel="${channel:-stable}"
if [ -z "$release" ] && [ -z "$url" ]; then
    if [ -n "$version_wanted" ]; then
        is_version "$version_wanted" || { echo "Give the release as YEAR.MONTH.N, such as 2026.11.3." >&2; exit 2; }
        url="https://github.com/$repo/releases/download/$version_wanted"
    else
        case "$channel" in
            stable) url="https://github.com/$repo/releases/latest/download" ;;
            beta)
                newest="$(curl -fsSL --retry 3 "https://api.github.com/repos/$repo/releases?per_page=1" \
                    | python3 -I -c 'import json,sys; r=json.load(sys.stdin); print(r[0]["tag_name"] if r else "")' 2>/dev/null || true)"
                is_version "$newest" || { echo "Could not read the newest release from GitHub. Try again, or name one: --version 2026.11.3. Nothing was changed." >&2; exit 1; }
                url="https://github.com/$repo/releases/download/$newest"
                ;;
            pinned)
                echo "This shop is pinned to release $installed, so a plain update changes nothing."
                echo "To move it to a release: sudo haq update --version 2026.11.3. To follow releases again: sudo haq channel stable."
                exit 0
                ;;
            *) echo "This shop's channel is \"$channel\", which is not stable, beta or pinned. Set it: sudo haq channel stable." >&2; exit 2 ;;
        esac
    fi
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

echo "1. Fetching and checking the release"
for f in manifest.json manifest.json.sig compose.yaml; do
    if [ -n "$release" ]; then
        cp "$release/$f" "$work/$f"
    else
        curl -fsSL --retry 3 -o "$work/$f" "$url/$f" \
            || { echo "Could not fetch $f from $url. Check the connection, and that the release exists. Nothing was changed." >&2; exit 1; }
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
is_version "$version" || { echo "The release's version ($version) is not YEAR.MONTH.N. Nothing was changed." >&2; exit 1; }
if [ -n "$version_wanted" ] && [ "$version" != "$version_wanted" ]; then
    echo "Release $version_wanted was asked for, and the release found is $version. Nothing was changed." >&2
    exit 1
fi
if [ "$installed" = "$version" ]; then
    echo "Release $version is already installed."
    exit 0
fi
if [ "$installed" != 0 ] && older "$version" "$installed"; then
    echo "Release $version is older than the installed $installed. Nothing was changed." >&2
    exit 1
fi
# A release may name the oldest release that can go straight to it, when it finishes a change an earlier
# one began (expand, then contract). A shop further behind takes that release first (D81).
upgrade_from="$(json "$work/manifest.json" upgrade_from 2>/dev/null || true)"
if [ -n "$upgrade_from" ] && [ "$installed" != 0 ] && older "$installed" "$upgrade_from"; then
    echo "Release $version needs release $upgrade_from or a newer one first, and this shop runs $installed. Nothing was changed." >&2
    echo "Take that one first: sudo haq update --version $upgrade_from" >&2
    exit 1
fi
commit="$(json "$work/manifest.json" commit 2>/dev/null || true)"
app="$(json "$work/manifest.json" images.app)"
web="$(json "$work/manifest.json" images.web)"
db="$(json "$work/manifest.json" images.db)"
# Every image is pinned by its digest, so what is pulled is exactly what was signed (D2, D65).
for image in "$app" "$web" "$db"; do
    if ! printf '%s' "$image" | grep -Eq '^[a-z0-9.:/_-]+@sha256:[0-9a-f]{64}$'; then
        echo "The release names an image that is not pinned by its digest ($image). Nothing was changed." >&2
        exit 1
    fi
done
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
grep -v -E '^(APP_IMAGE|WEB_IMAGE|DB_IMAGE|RELEASE_VERSION)=' "$dir/.env.previous" > "$dir/.env"
# RELEASE_VERSION is what the back office's server health page shows as the version.
printf 'APP_IMAGE=%s\nWEB_IMAGE=%s\nDB_IMAGE=%s\nRELEASE_VERSION=%s\n' "$app" "$web" "$db" "$version" >> "$dir/.env"
chmod 600 "$dir/.env"
cp "$work/compose.yaml" "$dir/compose.yaml"

rollback() {
    echo "   $1 Rolling back the code only; the database stays as it is." >&2
    cp "$dir/.env.previous" "$dir/.env"
    cp "$dir/compose.yaml.previous" "$dir/compose.yaml"
    # The previous release's own migrate job runs first. It leaves any table the new release made to that
    # release (D105), so the previous release starts on the newer schema; say so only if it really did.
    if compose up -d --wait --remove-orphans; then
        echo "Release $version was NOT installed. The previous release runs again. Tell HAQ support." >&2
    else
        echo "Release $version was NOT installed, and the previous release did not start either. The shop cannot" >&2
        echo "sell from this server. Tell HAQ support now, with the output of sudo haq check" >&2
        echo "(on a Windows server: haq-helper server check, in Terminal (Admin))." >&2
    fi
    exit 1
}

if ! compose up -d --wait --remove-orphans; then
    migrate="$(compose ps -a --format '{{.Service}} {{.ExitCode}}' | awk '$1 == "migrate" { print $2 }')"
    if [ "$migrate" != 0 ]; then
        # Each migration runs in its own transaction: the one that failed left nothing behind, and the ones
        # before it stay, which the previous release works with (D105). Restoring the backup would not undo
        # them, because restore.sh replays every archived WAL segment.
        rollback "The migration failed."
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

# What is installed, and the release before it. The images of the release before that are removed, so
# months of weekly releases never fill the disk; this one and the one before stay (D81).
unused="$(python3 -I - "$dir/release.json" "$version" "$app" "$web" "$db" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$commit" <<'PY'
import json, sys

path, version, app, web, db, at, commit = sys.argv[1:8]
try:
    with open(path) as f:
        old = json.load(f)
except (OSError, ValueError):
    old = {}
new = {"version": version, "images": {"app": app, "web": web, "db": db}, "installed_at": at}
if commit:
    new["commit"] = commit
if old.get("version"):
    new["previous"] = {"version": old["version"], "images": old.get("images") or {}}
with open(path, "w") as f:
    json.dump(new, f, indent=4)
    f.write("\n")
keep = {app, web, db} | set((old.get("images") or {}).values())
for image in ((old.get("previous") or {}).get("images") or {}).values():
    if image not in keep:
        print(image)
PY
)"
for image in $unused; do docker image rm "$image" >/dev/null 2>&1 || true; done
rm -f "$dir/.env.previous" "$dir/compose.yaml.previous"
echo "Release $version is installed."
