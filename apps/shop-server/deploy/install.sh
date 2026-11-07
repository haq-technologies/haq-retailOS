#!/bin/bash
# Installs or updates a shop's server from the images on GHCR, then applies the shop profile and loads the
# starter catalogue (spec section 6, phase 1). Safe to run again: it keeps the secrets it made, pulls the
# images, starts the stack (the migration job runs first) and reapplies the profile, which changes nothing
# the second time.
#
#   sudo deploy/install.sh --profile shop.profile.json --name haq-server.local --ip 192.168.1.10 --backup-dir /mnt/backup
#
# Options: --dir (default /opt/haq-retail-os), --tag (image tag, default main), --http-port, --https-port,
# --project (Compose project name), --backup-dir (the second internal disk's folder for backups). If the
# images are private, set GHCR_USER and GHCR_TOKEN (a classic token with only read:packages) on the first
# run; they are kept in secrets/ for later runs and for the updater.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dir=/opt/haq-retail-os
tag=main
profile=""
name=""
ip=""
http_port=80
https_port=443
project=haq-retail-os
registry=ghcr.io/haq-technologies
backup_dir=

while [ $# -gt 0 ]; do
    case "$1" in
        --profile) profile="$2"; shift 2 ;;
        --name) name="$2"; shift 2 ;;
        --ip) ip="$2"; shift 2 ;;
        --dir) dir="$2"; shift 2 ;;
        --tag) tag="$2"; shift 2 ;;
        --http-port) http_port="$2"; shift 2 ;;
        --https-port) https_port="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        --registry) registry="$2"; shift 2 ;;
        --backup-dir) backup_dir="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

if [ -z "$profile" ] || [ -z "$name" ] || [ -z "$ip" ]; then
    echo "Give the shop profile (--profile), the server's name (--name) and its fixed IP (--ip)." >&2
    exit 2
fi
if [ ! -f "$profile" ]; then
    echo "There is no profile at $profile." >&2
    exit 2
fi
command -v docker >/dev/null || { echo "Docker is not installed." >&2; exit 1; }
# Reboot recovery (spec section 4) [D46]: Docker starts with the server, and every long-running service
# has a restart policy, so a power cut needs no one to start anything.
if command -v systemctl >/dev/null && systemctl list-unit-files docker.service >/dev/null 2>&1; then
    systemctl enable docker.service containerd.service >/dev/null 2>&1 || echo "WARNING: Docker could not be set to start with the server." >&2
fi

secret() { head -c 32 /dev/urandom | base64 | tr -d '/+=\n' | head -c 40; }

mkdir -p "$dir/secrets"
chmod 700 "$dir" "$dir/secrets"
cp "$here/compose.yaml" "$dir/compose.yaml"

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
# The image tags, name and ports may change on a rerun; the secrets never do.
grep -v -E '^(APP_IMAGE|WEB_IMAGE|DB_IMAGE|SERVER_NAME|SERVER_IP|HTTP_PORT|HTTPS_PORT|BACKUP_DIR)=' "$dir/.env" > "$dir/.env.new" || true
{
    echo "APP_IMAGE=$registry/retail-os-app:$tag"
    echo "WEB_IMAGE=$registry/retail-os-web:$tag"
    echo "DB_IMAGE=$registry/retail-os-db:$tag"
    echo "SERVER_NAME=$name"
    echo "SERVER_IP=$ip"
    echo "HTTP_PORT=$http_port"
    echo "HTTPS_PORT=$https_port"
    if [ -n "$backup_dir" ]; then echo "BACKUP_DIR=$backup_dir"; fi
} >> "$dir/.env.new"
mv "$dir/.env.new" "$dir/.env"
chmod 600 "$dir/.env"

# Backups go to the second internal disk (spec section 9). PostgreSQL's user (999) owns the folder.
if [ -n "$backup_dir" ]; then
    mkdir -p "$backup_dir"
    chown 999:999 "$backup_dir" 2>/dev/null || echo "Could not give $backup_dir to PostgreSQL's user (999); do it before trading." >&2
else
    echo "WARNING: no --backup-dir. Backups share the database's disk until the second disk is mounted and this is run again with --backup-dir." >&2
fi

# The updater, the restore and the install check live with the stack, so updates need no checkout; and the
# release public key, so the updater can check a release's signature (spec section 4).
mkdir -p "$dir/bin"
cp "$here/update.sh" "$here/restore.sh" "$here/check-install.sh" "$here/host-health.sh" "$here/offsite.sh" "$dir/bin/"
if [ -f "$here/release-public.pem" ]; then
    cp "$here/release-public.pem" "$dir/release-public.pem"
else
    echo "WARNING: there is no release public key in deploy/, so this server cannot take updates yet." >&2
fi

compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }

# The shop's own read-only GHCR token lives in secrets/, readable by root only, where the updater finds it
# too. Given once as GHCR_USER and GHCR_TOKEN, it is kept there; Docker is signed out after each pull, so
# the token is never left in Docker's own settings.
if [ -n "${GHCR_TOKEN:-}" ]; then
    printf '%s' "${GHCR_USER:?Set GHCR_USER too}" > "$dir/secrets/ghcr_user"
    printf '%s' "$GHCR_TOKEN" > "$dir/secrets/ghcr_token"
    chmod 600 "$dir/secrets/ghcr_user" "$dir/secrets/ghcr_token"
fi
if [ "${SKIP_PULL:-0}" != "1" ]; then
    if [ -f "$dir/secrets/ghcr_token" ]; then
        docker login ghcr.io -u "$(cat "$dir/secrets/ghcr_user")" --password-stdin < "$dir/secrets/ghcr_token" >/dev/null
    fi
    compose pull
    if [ -f "$dir/secrets/ghcr_token" ]; then docker logout ghcr.io >/dev/null; fi
fi
compose up -d --wait

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
if ! command -v smartctl >/dev/null && command -v apt-get >/dev/null; then
    apt-get install -y -qq smartmontools >/dev/null 2>&1 || echo "WARNING: smartmontools did not install, so the health page cannot show SSD health. Install it when the server is online." >&2
fi
if command -v systemctl >/dev/null && [ -d /etc/systemd/system ] && [ "$status_dir" != "/status" ]; then
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
# HAQ-OFFSITE-B starts the haq-offsite@ service, which writes an encrypted copy of every backup onto it.
if command -v systemctl >/dev/null && [ -d /etc/udev/rules.d ]; then
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

run="docker compose --project-name $project -f $dir/compose.yaml --env-file $dir/.env"
cat <<DONE

The shop server is installed in $dir and answers at https://$name:$https_port
Next:
  1. Copy the shop's certificate authority and trust it on each counter, once:
       sudo $run exec web cat /etc/haq/tls/ca.crt > haq-shop-ca.crt
  2. Add the people (each PIN is asked twice):
       sudo $run exec app php artisan shop:add-user "Name" --role=cashier
  3. Enrol each counter, then open https://$name/enrol on it and type the code:
       sudo $run exec app php artisan shop:enrol-terminal T01
  4. Make the off-site key once, and hand out its three shares (docs/operations/offsite-copy.md):
       sudo $dir/bin/offsite.sh key
DONE
