#!/bin/bash
# Installs or updates a shop's server from the images on GHCR, then applies the shop profile and loads the
# starter catalogue (spec section 6, phase 1). Safe to run again: it keeps the secrets it made, pulls the
# images, starts the stack (the migration job runs first) and reapplies the profile, which changes nothing
# the second time. The official steps are in docs/operations/install-a-shop.md (D59).
#
#   sudo apps/shop-server/deploy/install.sh --ip 192.168.1.10 --backup-dir /mnt/backup --tag 2026.11.1
#
# It installs Docker if the server has none. With no --profile it asks for the shop's details (receipt
# text, counters, drawers, printers) and keeps them as the profile for later runs. The images are private:
# on the first run it asks for the server's read-only GHCR token (or takes GHCR_USER and GHCR_TOKEN), and
# keeps it in secrets/ for later runs and for the updater.
#
# Options: --profile (a profile made before the visit with shop:make-profile), --name (default
# haq-server.local), --dir (default /opt/haq-retail-os), --tag (a release, or main before the first one),
# --channel (stable, beta or pinned: which releases `haq update` takes later; kept as it is if not given),
# --http-port, --https-port, --project (Compose project name), --backup-dir (the second internal disk's
# folder for backups), --standin-printer (a rehearsal with no printer only: receipts go to `haq receipts`),
# --windows (the server hidden in WSL2 on a Windows PC, D86: the Windows setup gives it; the HAQ Helper then
# reports the disks and the clock and starts the off-site copy from Windows, so neither is set up here),
# --release (a release's folder, brought on a flash drive, in place of fetching it from GitHub),
# --bind (publish the ports on this address only, such as 127.0.0.1 for staging on a laptop, D124; a shop
# publishes on every address, for its counters),
# --check-only (check the release given by --tag, and stop before anything is installed).
#
# A release (--tag YEAR.MONTH.N) is checked before anything is installed, as the updater checks it: its
# signed manifest, its Compose file, and its images, which are then pulled by their digests (D99).
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dir=/opt/haq-retail-os
tag=main
profile=""
name="haq-server.local"
ip=""
standin=0
http_port=80
https_port=443
project=haq-retail-os
registry=ghcr.io/haq-technologies
backup_dir=
channel=""
windows=0
release_dir=""
check_only=0
bind=""

while [ $# -gt 0 ]; do
    case "$1" in
        --profile) profile="$2"; shift 2 ;;
        --name) name="$2"; shift 2 ;;
        --ip) ip="$2"; shift 2 ;;
        --dir) dir="$2"; shift 2 ;;
        --tag) tag="$2"; shift 2 ;;
        --channel) channel="$2"; shift 2 ;;
        --http-port) http_port="$2"; shift 2 ;;
        --https-port) https_port="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        --registry) registry="$2"; shift 2 ;;
        --backup-dir) backup_dir="$2"; shift 2 ;;
        --standin-printer) standin=1; shift ;;
        --windows) windows=1; shift ;;
        --release) release_dir="$2"; shift 2 ;;
        --check-only) check_only=1; shift ;;
        --bind) bind="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

case "$channel" in
    ""|stable|beta|pinned) ;;
    *) echo "The channel is stable, beta or pinned." >&2; exit 2 ;;
esac
if [ -z "$ip" ]; then
    echo "Give the server's fixed IP address: --ip 192.168.1.10 (docs/operations/install-a-shop.md, step 2)." >&2
    exit 2
fi
if [ -n "$profile" ] && [ ! -f "$profile" ]; then
    echo "There is no profile at $profile." >&2
    exit 2
fi
if [ "$(id -u)" != 0 ] && [ ! -w "$(dirname "$dir")" ]; then
    echo "Run it with sudo: sudo $0 $*" >&2
    exit 1
fi
# The counters reach the server at this address, so it must be the server's own, and fixed. On a Windows PC
# it is the PC's own address, which Windows forwards to the server inside WSL.
if [ "$windows" = 0 ] && command -v ip >/dev/null && ! ip -o -4 addr show | grep -q " ${ip}/"; then
    echo "WARNING: this server does not have the address $ip. Give it that fixed address first, or the counters cannot reach it." >&2
fi
# A rehearsal with no printer prints to a stand-in that writes each receipt to its log; a shop never runs it.
if [ "$standin" = 1 ]; then export COMPOSE_PROFILES=standin; fi

json() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1]))
for k in sys.argv[2].split("."): d=d[k]
print(d)' "$1" "$2"; }

# A release's tag is its version, YEAR.MONTH.N; anything else (main, local) is not a release.
is_release=0
if printf '%s' "$tag" | grep -Eq '^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$'; then is_release=1; fi
app_image="$registry/retail-os-app:$tag"
web_image="$registry/retail-os-web:$tag"
db_image="$registry/retail-os-db:$tag"
compose_file="$here/compose.yaml"
release_commit=""
# A release is checked before anything is installed (D99): the updater's own checks of its signed manifest,
# its Compose file and its images pinned by digest, as a shop with no release yet runs them. The shop then
# runs exactly what the release signs from its first day, never whatever its version tag points at. Images
# loaded on this machine (SKIP_PULL=1) are used by their tags, since a loaded image keeps no digest.
if [ "$is_release" = 1 ] && [ "${SKIP_PULL:-0}" != "1" ]; then
    [ -f "$here/release-public.pem" ] || { echo "There is no release public key in deploy/, so release $tag cannot be checked. Nothing was installed." >&2; exit 1; }
    checked="$(mktemp -d)"
    trap 'rm -rf "$checked"' EXIT
    mkdir -p "$checked/release" "$checked/shop"
    for f in manifest.json manifest.json.sig compose.yaml; do
        if [ -n "$release_dir" ]; then
            cp "$release_dir/$f" "$checked/release/$f" || { echo "The release folder $release_dir has no $f. Nothing was installed." >&2; exit 1; }
        else
            curl -fsSL --retry 3 -o "$checked/release/$f" "https://github.com/haq-technologies/haq-retailOS/releases/download/$tag/$f" \
                || { echo "Could not fetch release $tag's $f from GitHub. Check the connection, and that the release exists. Nothing was installed." >&2; exit 1; }
        fi
    done
    cp "$checked/release/compose.yaml" "$checked/shop/compose.yaml"
    cp "$here/release-public.pem" "$checked/shop/release-public.pem"
    if ! "$here/update.sh" --dir "$checked/shop" --release "$checked/release" --version "$tag" --check-only >"$checked/said" 2>&1; then
        cat "$checked/said" >&2
        echo "Release $tag did not check out, so nothing was installed." >&2
        exit 1
    fi
    app_image="$(json "$checked/release/manifest.json" images.app)"
    web_image="$(json "$checked/release/manifest.json" images.web)"
    db_image="$(json "$checked/release/manifest.json" images.db)"
    release_commit="$(json "$checked/release/manifest.json" commit 2>/dev/null || true)"
    compose_file="$checked/release/compose.yaml"
    echo "Release $tag is signed: its images are installed by their digests."
    if [ "$check_only" = 1 ]; then
        echo "The release checks out. Nothing was installed (--check-only)."
        exit 0
    fi
elif [ "$check_only" = 1 ]; then
    echo "--check-only checks a release: give one with --tag, such as 2026.11.3, and leave SKIP_PULL unset." >&2
    exit 2
elif [ "$is_release" = 1 ]; then
    echo "WARNING: the images were loaded on this machine, so they are used by their tags, unchecked against release $tag's signed manifest." >&2
fi

# Docker from Ubuntu's own packages (as at G2), if the server has none yet.
if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
    if command -v apt-get >/dev/null; then
        echo "Installing Docker from Ubuntu's packages…"
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io docker-compose-v2 >/dev/null
    else
        echo "Docker is not installed, and this is not Ubuntu: install Docker with Compose first." >&2
        exit 1
    fi
fi
# Reboot recovery (spec section 4) [D46]: Docker starts with the server, and every long-running service
# has a restart policy, so a power cut needs no one to start anything.
if command -v systemctl >/dev/null && systemctl list-unit-files docker.service >/dev/null 2>&1; then
    systemctl enable docker.service containerd.service >/dev/null 2>&1 || echo "WARNING: Docker could not be set to start with the server." >&2
fi

# Ubuntu's own security updates run at night, never while the shop trades: one that updates Docker restarts
# every container (D100). Ubuntu's default is 6:00 to 7:00 by the server's clock, often UTC, so 7:00 to 8:00
# in Lagos, as the shop opens. Now they run from 23:15 to 23:45 in Lagos, after the active hours (D98) and
# before the night's backups at 01:00; a server that was off at night takes them when it next starts.
if command -v systemctl >/dev/null && [ -d /etc/systemd/system ] && [ "$(id -u)" = 0 ]; then
    mkdir -p /etc/systemd/system/apt-daily-upgrade.timer.d
    cat > /etc/systemd/system/apt-daily-upgrade.timer.d/haq-at-night.conf <<'UNIT'
# HAQ Retail OS (D100): Ubuntu's security updates at night, never while the shop trades.
[Timer]
OnCalendar=
OnCalendar=*-*-* 23:15 Africa/Lagos
RandomizedDelaySec=30m
Persistent=true
UNIT
    systemctl daemon-reload
    systemctl restart apt-daily-upgrade.timer >/dev/null 2>&1 || echo "WARNING: Ubuntu's update timer did not restart; it takes the night hours at the next start." >&2
fi

secret() { head -c 32 /dev/urandom | base64 | tr -d '/+=\n' | head -c 40; }

mkdir -p "$dir/secrets"
chmod 700 "$dir" "$dir/secrets"
cp "$compose_file" "$dir/compose.yaml"

for s in postgres_password owner_password app_password reporting_password; do
    if [ ! -f "$dir/secrets/$s" ]; then
        secret > "$dir/secrets/$s"
    fi
    # Readable by the database and migration containers; the folder itself is root only.
    chmod 644 "$dir/secrets/$s"
done

if [ ! -f "$dir/.env" ]; then
    {
        echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"
        echo "RETAIL_APP_PASSWORD=$(cat "$dir/secrets/app_password")"
    } > "$dir/.env"
fi
# The images, name and ports may change on a rerun; the secrets never do.
# A release is installed from its own commit, so the installer, the Compose file and the updater are the
# release's too (D81). A checkout of another commit still installs, with a warning.
if [ "$is_release" = 1 ] && command -v git >/dev/null; then
    at="$(git -C "$here" rev-parse HEAD 2>/dev/null || true)"
    want="$(git -C "$here" rev-parse --verify --quiet "refs/tags/$tag^{commit}" 2>/dev/null || true)"
    if [ -n "$at" ] && [ -n "$want" ] && [ "$at" != "$want" ]; then
        echo "WARNING: this checkout is not release $tag's commit, so its installer and Compose file may not be that release's." >&2
        echo "         Install from the release itself: git clone --branch $tag --depth 1 https://github.com/haq-technologies/haq-retailOS.git" >&2
    fi
fi
grep -v -E '^(APP_IMAGE|WEB_IMAGE|DB_IMAGE|RELEASE_VERSION|SERVER_NAME|SERVER_IP|HTTP_PORT|HTTPS_PORT|BACKUP_DIR|BIND_ADDRESS)=' "$dir/.env" > "$dir/.env.new" || true
{
    echo "APP_IMAGE=$app_image"
    echo "WEB_IMAGE=$web_image"
    echo "DB_IMAGE=$db_image"
    # What the back office's server health page shows as the version.
    if [ "$is_release" = 1 ]; then echo "RELEASE_VERSION=$tag"; fi
    echo "SERVER_NAME=$name"
    echo "SERVER_IP=$ip"
    echo "HTTP_PORT=$http_port"
    echo "HTTPS_PORT=$https_port"
    if [ -n "$backup_dir" ]; then echo "BACKUP_DIR=$backup_dir"; fi
    # Empty at a shop, which answers on every address; written anyway, so Compose never warns it is unset.
    echo "BIND_ADDRESS=$bind"
} >> "$dir/.env.new"
mv "$dir/.env.new" "$dir/.env"
chmod 600 "$dir/.env"

# Backups go to the second internal disk (spec section 9). PostgreSQL's user (999) owns the folder. The
# disk carries a marker, so a disk that later fails to mount, leaving an empty folder on the main disk,
# stops the backups with a warning instead of taking them beside the sales (D129).
if [ -n "$backup_dir" ]; then
    mkdir -p "$backup_dir"
    if command -v mountpoint >/dev/null && ! mountpoint -q "$backup_dir"; then
        echo "WARNING: $backup_dir is not a mounted disk, so the backups would share the main disk. Mount the second disk there (docs/operations/install-a-shop.md, step 3) and run this again." >&2
    fi
    [ -f "$backup_dir/.haq-backup-disk" ] || echo "HAQ Retail OS backup disk. Prepared by install.sh on $(date -u +%F)." > "$backup_dir/.haq-backup-disk"
    chown 999:999 "$backup_dir" "$backup_dir/.haq-backup-disk" 2>/dev/null || echo "Could not give $backup_dir to PostgreSQL's user (999); do it before trading." >&2
else
    echo "WARNING: no --backup-dir. Backups share the database's disk until the second disk is mounted and this is run again with --backup-dir." >&2
fi

# The updater, the restore and the install check live with the stack, so updates need no checkout; and the
# release public key, so the updater can check a release's signature (spec section 4).
mkdir -p "$dir/bin"
cp "$here/update.sh" "$here/restore.sh" "$here/check-install.sh" "$here/host-health.sh" "$here/offsite.sh" "$here/support-file.sh" "$dir/bin/"
if [ -f "$here/release-public.pem" ]; then
    cp "$here/release-public.pem" "$dir/release-public.pem"
else
    echo "WARNING: there is no release public key in deploy/, so this server cannot take updates yet." >&2
fi

compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }

# The shop's own read-only GHCR token lives in secrets/, readable by root only, where the updater finds it
# too. Given once as GHCR_USER and GHCR_TOKEN, it is kept there; Docker is signed out after each pull, so
# the token is never left in Docker's own settings.
# Asked for once, hidden as it is typed, so the token is never on the command line or in the shell's history.
if [ -z "${GHCR_TOKEN:-}" ] && [ ! -f "$dir/secrets/ghcr_token" ] && [ "${SKIP_PULL:-0}" != "1" ] && [ -t 0 ]; then
    echo "The images are private. Give the server's read-only GHCR token (a classic token with only read:packages)."
    read -r -p "GitHub user name for the token: " GHCR_USER
    read -r -s -p "The token (it is not shown as you type): " GHCR_TOKEN
    echo
fi
if [ -n "${GHCR_TOKEN:-}" ]; then
    printf '%s' "${GHCR_USER:?Set GHCR_USER too}" > "$dir/secrets/ghcr_user"
    printf '%s' "$GHCR_TOKEN" > "$dir/secrets/ghcr_token"
    chmod 600 "$dir/secrets/ghcr_user" "$dir/secrets/ghcr_token"
fi
if [ "${SKIP_PULL:-0}" != "1" ]; then
    if [ -f "$dir/secrets/ghcr_token" ]; then
        docker login ghcr.io -u "$(cat "$dir/secrets/ghcr_user")" --password-stdin < "$dir/secrets/ghcr_token" >/dev/null
    fi
    if ! compose pull; then
        if [ -f "$dir/secrets/ghcr_token" ]; then docker logout ghcr.io >/dev/null; fi
        echo "The images did not download. Check the internet connection and the GHCR token (to give a new one, run this again with GHCR_USER and GHCR_TOKEN set)." >&2
        exit 1
    fi
    if [ -f "$dir/secrets/ghcr_token" ]; then docker logout ghcr.io >/dev/null; fi
fi
compose up -d --wait

# The shop's details. A profile made before the visit (--profile) is used as it is; otherwise the questions
# below make one with shop:make-profile, kept root-only in the install folder so a rerun asks nothing.
ask() { # ask <variable> <question> [default]
    local answer
    read -r -p "$2${3:+ [$3]}: " answer
    printf -v "$1" '%s' "${answer:-${3:-}}"
}
if [ -z "$profile" ] && [ -f "$dir/shop.profile.json" ]; then
    profile="$dir/shop.profile.json"
fi
if [ -z "$profile" ]; then
    echo
    echo "About the shop. The receipts print these; press Enter to take the value in brackets."
    receipt_name=""; address=""; phone=""; footer=""; drawer=""; right=""; printer=""
    while :; do
        shop=""; while [ -z "$shop" ]; do ask shop "Shop name"; done
        ask receipt_name "Shop name on the receipt (at most 40 letters)" "${shop:0:40}"
        ask address "Address on the receipt (optional)"
        ask phone "Phone on the receipt (optional)"
        ask footer "Receipt footer, such as the returns wording (optional)"
        counters=""
        until [[ "$counters" =~ ^[0-9]+$ ]] && [ "$counters" -ge 1 ] && [ "$counters" -le 20 ]; do ask counters "How many counters (PCs that sell)" 1; done
        drawers=""; printers=""
        for i in $(seq 1 "$counters"); do
            code="$(printf 'T%02d' "$i")"
            ask drawer "Does $code have a cash drawer? (y/n)" n
            case "$drawer" in [yY]*) drawers="${drawers:+$drawers,}$code" ;; esac
            while :; do
                ask printer "Receipt printer's IP address for $code (Enter if it has none yet)" "$( [ "$standin" = 1 ] && echo printer )"
                if [ -z "$printer" ] || [[ "$printer" =~ ^[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]]; then break; fi
                echo "Type an IP address such as 192.168.1.21, or press Enter for none."
            done
            if [ -n "$printer" ]; then printers="${printers:+$printers,}$code=$printer"; fi
        done
        devices=""
        until [[ "$devices" =~ ^[0-9]+$ ]] && [ "$devices" -le 20 ]; do ask devices "How many back-office devices (the owner's laptop or phone)" 1; done
        echo
        echo "  $shop: $counters counter(s), drawers: ${drawers:-none}, printers: ${printers:-none yet}, back-office devices: $devices"
        echo "  Receipt: $receipt_name${address:+, $address}${phone:+, $phone}"
        ask right "Is this right? (y/n)" y
        case "$right" in [yY]*) break ;; esac
    done
    compose exec -T app php artisan shop:make-profile --no-interaction --shop="$shop" --receipt-name="$receipt_name" --address="$address" \
        --phone="$phone" --footer="$footer" --terminals="$counters" --drawers="$drawers" --printers="$printers" --back-office="$devices" \
        --output=/tmp/shop.profile.json >/dev/null
    (umask 077 && compose exec -T app sh -c 'cat /tmp/shop.profile.json; rm -f /tmp/shop.profile.json' > "$dir/shop.profile.json")
    profile="$dir/shop.profile.json"
    echo "Kept as $profile; a later run uses it again."
fi

# The profile holds shop data: it goes in through stdin and is removed straight after.
compose exec -T app sh -c 'cat > /tmp/shop.profile.json' < "$profile"
compose exec -T app sh -c 'php artisan shop:apply-profile /tmp/shop.profile.json; status=$?; rm -f /tmp/shop.profile.json; exit $status'
compose exec -T app php artisan catalogue:seed
compose exec -T app php artisan db:mutability --check

# The host's own report for the health page: SSD health from smartctl and the time source, every 15
# minutes, into the backup disk's status folder (spec section 4) [F24, D45].
if [ -n "$backup_dir" ]; then
    status_dir="$backup_dir/status"
else
    status_dir="$(docker volume inspect -f '{{ .Mountpoint }}' "${project}_backup-repo" 2>/dev/null || true)/status"
fi
# On a Windows PC (D86) the HAQ Helper writes this report from Windows, through `haq host-report`, since
# WSL cannot see the PC's disks.
if [ "$windows" = 0 ] && ! command -v smartctl >/dev/null && command -v apt-get >/dev/null; then
    apt-get install -y -qq smartmontools >/dev/null 2>&1 || echo "WARNING: smartmontools did not install, so the health page cannot show SSD health. Install it when the server is online." >&2
fi
if [ "$windows" = 0 ] && command -v systemctl >/dev/null && [ -d /etc/systemd/system ] && [ "$status_dir" != "/status" ]; then
    cat > /etc/systemd/system/haq-host-health.service <<UNIT
[Unit]
Description=HAQ Retail OS: the server's SSD and clock report for the health page

[Service]
Type=oneshot
ExecStart=$dir/bin/host-health.sh $status_dir
UNIT
    cat > /etc/systemd/system/haq-host-health.timer <<UNIT
[Unit]
Description=HAQ Retail OS: the server's SSD and clock report, every 15 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=15min

[Install]
WantedBy=timers.target
UNIT
    systemctl daemon-reload
    systemctl enable --now haq-host-health.timer >/dev/null 2>&1 || echo "WARNING: the host health timer did not start." >&2
fi

# The off-site copy (spec section 4) [S16, S17, D53]: plugging in a flash drive labelled HAQ-OFFSITE-A or
# HAQ-OFFSITE-B starts the haq-offsite@ service, which writes an encrypted copy of every backup onto it. On a
# Windows PC the HAQ Helper sees the drive in Windows and runs `offsite.sh copy-windows` instead (D86).
if [ "$windows" = 0 ] && command -v systemctl >/dev/null && [ -d /etc/udev/rules.d ]; then
    cat > /etc/systemd/system/haq-offsite@.service <<UNIT
[Unit]
Description=HAQ Retail OS: the off-site copy onto the flash drive %I

[Service]
Type=oneshot
Environment=HAQ_DIR=$dir HAQ_PROJECT=$project
ExecStart=$dir/bin/offsite.sh copy /dev/%I
TimeoutStartSec=3h
UNIT
    cat > /etc/udev/rules.d/99-haq-offsite.rules <<'RULE'
ACTION=="add", SUBSYSTEM=="block", ENV{ID_FS_LABEL}=="HAQ-OFFSITE-[AB]", TAG+="systemd", ENV{SYSTEMD_WANTS}+="haq-offsite@%k.service"
RULE
    systemctl daemon-reload
    udevadm control --reload 2>/dev/null || true
fi

# What is installed, for the updater: it refuses an older release, and it knows where a shop starts from
# (D81). An install from main or from local images is not a release, and leaves any record as it is.
if [ "$is_release" = 1 ]; then
    printf '{\n    "version": "%s",%s\n    "images": {"app": "%s", "web": "%s", "db": "%s"},\n    "installed_at": "%s"\n}\n' \
        "$tag" "$( [ -z "$release_commit" ] || printf '\n    "commit": "%s",' "$release_commit" )" \
        "$app_image" "$web_image" "$db_image" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$dir/release.json"
fi
# Which releases a plain `haq update` takes: stable unless told otherwise, and never changed by a rerun
# that does not name one.
if [ -n "$channel" ]; then
    printf '%s\n' "$channel" > "$dir/channel"
elif [ ! -f "$dir/channel" ]; then
    echo stable > "$dir/channel"
fi

# The everyday commands (D59): `sudo haq …`, which reads where this install is from haq.conf.
cat > "$dir/haq.conf" <<CONF
PROJECT=$project
NAME=$name
IP=$ip
HTTP_PORT=$http_port
HTTPS_PORT=$https_port
PROFILES=${COMPOSE_PROFILES:-}
STATUS_DIR=$status_dir
WINDOWS=$windows
CONF
cp "$here/haq" "$dir/bin/haq"
chmod 755 "$dir/bin/haq"
if [ "$(id -u)" = 0 ] && [ "$project" = haq-retail-os ] && [ -d /usr/local/bin ]; then
    ln -sf "$dir/bin/haq" /usr/local/bin/haq
    haq="sudo haq"
else
    haq="$dir/bin/haq"
fi
if [ "$http_port" = 80 ]; then setup="http://$ip/setup"; else setup="http://$ip:$http_port/setup?https_port=$https_port"; fi

cat <<DONE

The shop server is installed and answers at https://$ip$( [ "$https_port" = 443 ] || echo ":$https_port" ).
Next (docs/operations/install-a-shop.md has each step):
  1. Add the owner and the staff; each PIN is asked twice:
       $haq add-user "Halima" owner
       $haq add-user "Amina" cashier
  2. Make a code for each device; each works once, for 30 minutes:
       $haq enrol T01        (D01 for the owner's laptop or phone)
  3. On each device, open $setup in Chrome: paste its one line into
     Windows Terminal (Admin) to trust the shop's certificate, then type the code.
  4. Check the whole install:
       $haq check
  5. Make the off-site key once, and hand out its three shares (docs/operations/offsite-copy.md):
       $haq offsite-key
DONE
