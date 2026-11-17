#!/bin/bash
# The Windows spike (D86), inside the haq-server WSL distribution. haq-spike.ps1 runs this through its
# scheduled tasks; nobody runs it by hand. It lives in the spike's folder on the PC (C:\ProgramData\HAQSpike,
# /mnt/c/ProgramData/HAQSpike here), which holds the shop profile, the token for one install, the logs and
# the status file the PC reads.
#
#   inside.sh prepare                 start systemd with the distribution, as the shop server needs
#   inside.sh install <ip> <tag>      install the shop server from the release's own install.sh
#   inside.sh keepalive               hold the distribution open, and write its status every 30 seconds
#   inside.sh run <command ...>       any command, as root (haq check, haq artisan shop:rehearse ...)
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
status="$here/status"
mkdir -p "$status"

case "${1:-}" in
    prepare)
        # systemd starts Docker, and the stack's restart policies bring the shop back after a power cut.
        cat > /etc/wsl.conf <<'CONF'
[boot]
systemd=true

[user]
default=root

[interop]
appendWindowsPath=false
CONF
        echo "systemd is set; the distribution starts with it from its next start."
        ;;

    install)
        ip="${2:?Give the fixed address of the PC}"
        tag="${3:?Give the release, such as 2026.11.3}"
        if [ "$(ps -o comm= 1)" != systemd ]; then
            echo "systemd is not running: run prepare, then restart the distribution." >&2
            exit 1
        fi
        export DEBIAN_FRONTEND=noninteractive
        echo "Updating Ubuntu…"
        apt-get update -qq
        apt-get -y -qq upgrade >/dev/null
        apt-get install -y -qq git ca-certificates curl >/dev/null
        rm -rf /opt/haq-src
        git clone --quiet --branch "$tag" --depth 1 https://github.com/haq-technologies/haq-retailOS.git /opt/haq-src
        # The token was left by haq-spike.ps1 for this one install. install.sh keeps its own copy in the
        # server's secrets folder, root only, for the updater; the PC's copy goes now.
        GHCR_USER="$(cat "$here/secrets/ghcr_user")"
        GHCR_TOKEN="$(cat "$here/secrets/ghcr_token")"
        rm -f "$here/secrets/ghcr_user" "$here/secrets/ghcr_token"
        export GHCR_USER GHCR_TOKEN
        /opt/haq-src/apps/shop-server/deploy/install.sh --ip "$ip" --tag "$tag" --profile "$here/profile.json"
        unset GHCR_TOKEN
        # Rehearsal people only, with the PINs the spike's README gives; the spike is wiped afterwards.
        haq artisan shop:add-user "Spike Owner" --role=owner --pin=135790
        haq artisan shop:add-user "Spike Cashier" --role=cashier --pin=2468
        echo "Installed release $tag."
        ;;

    keepalive)
        # WSL stops a distribution soon after its last Windows process ends; this one never ends. Its task
        # starts it again if it is stopped.
        date -u +%FT%TZ > "$status/started.txt"
        rm -f "$status/ready.txt"
        while :; do
            code="$(curl -sk -o /dev/null -m 5 -w '%{http_code}' https://127.0.0.1/up || true)"
            if [ "$code" = 200 ] && [ ! -f "$status/ready.txt" ]; then
                date -u +%FT%TZ > "$status/ready.txt"
            fi
            {
                echo "written:            $(date -u +%FT%TZ)"
                echo "distribution since: $(uptime -s) (its own clock, UTC)"
                echo "systemd:            $(systemctl is-system-running 2>/dev/null || true)"
                echo "server /up:         $code"
                free -m | head -2
                df -h / | tail -1
                docker ps --format '{{.Names}}  {{.Status}}' 2>/dev/null || echo "docker: not answering"
            } > "$status/status.new" && mv "$status/status.new" "$status/status.txt"
            sleep 30
        done
        ;;

    run)
        shift
        bash -lc "$*"
        ;;

    *)
        echo "Usage: inside.sh prepare | install <ip> <tag> | keepalive | run <command>" >&2
        exit 2
        ;;
esac
