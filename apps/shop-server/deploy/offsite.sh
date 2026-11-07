#!/bin/bash
# The off-site copy on the shop server (spec sections 4 and 9; check E1) [S16, S17, D53]. Two flash drives,
# labelled HAQ-OFFSITE-A and HAQ-OFFSITE-B and formatted exFAT, take turns: plug one in and the server
# writes an encrypted copy of every backup onto it by itself (udev starts the haq-offsite@ service that
# install.sh adds), checks it, notes it for the health page and unmounts it. Take it away from the shop and
# bring the other back. docs/operations/offsite-copy.md has the routine.
#
#   sudo /opt/haq-retail-os/bin/offsite.sh key            once, at install: the key and its three shares
#   sudo /opt/haq-retail-os/bin/offsite.sh copy /dev/sdb1  what the plugged-in drive starts (or by hand)
#   sudo /opt/haq-retail-os/bin/offsite.sh unpack /dev/sdb1   on a spare box, before deploy/restore.sh
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dir="${HAQ_DIR:-$(dirname "$here")}"
project="${HAQ_PROJECT:-haq-retail-os}"
[ -f "$dir/compose.yaml" ] || { echo "There is no stack in $dir." >&2; exit 2; }
compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }

# The drive's label, A or B, and where it is mounted while the copy runs. The copy runs as root, so it reads
# the backup disk's branch key (the app's own) as well as the backups (PostgreSQL's), and an unpack puts
# every file back with its owner.
mount_drive() {
    device="$1"
    label="$(blkid -o value -s LABEL "$device" 2>/dev/null || true)"
    case "$label" in
        HAQ-OFFSITE-A | HAQ-OFFSITE-B) drive="${label#HAQ-OFFSITE-}" ;;
        *) echo "$device is not an off-site drive: label it HAQ-OFFSITE-A or HAQ-OFFSITE-B." >&2; exit 2 ;;
    esac
    mnt="/run/haq-offsite/$drive"
    mkdir -p "$mnt"
    mountpoint -q "$mnt" && umount "$mnt"
    case "$(blkid -o value -s TYPE "$device")" in
        exfat | vfat) mount -o uid=999,gid=999,umask=077 "$device" "$mnt" ;;
        *) mount "$device" "$mnt"; chown 999:999 "$mnt" ;;
    esac
}

unmount_drive() {
    sync
    umount "$mnt" 2>/dev/null || true
    rmdir "$mnt" 2>/dev/null || true
}

case "${1:-}" in
    key)
        compose run --rm --no-deps -T --user root --entrypoint haq-offsite.sh backup key "${2:-}"
        ;;
    copy)
        mount_drive "${2:?Give the drive, such as /dev/sdb1}"
        trap unmount_drive EXIT
        status=0
        compose run --rm --no-deps -T --user root -e "HAQ_OFFSITE_DRIVE=$drive" -v "$mnt:/offsite" --entrypoint haq-offsite.sh backup copy /offsite || status=$?
        unmount_drive
        trap - EXIT
        if [ "$status" = 0 ]; then
            echo "Drive $drive has its copy and is safe to remove. Take it away from the shop."
        else
            echo "The copy to drive $drive failed; the health page says why." >&2
        fi
        exit "$status"
        ;;
    unpack)
        mount_drive "${2:?Give the drive, such as /dev/sdb1}"
        trap unmount_drive EXIT
        # Asks for two of the three shares on this terminal.
        compose run --rm --no-deps --user root -v "$mnt:/offsite" --entrypoint haq-offsite.sh backup unpack /offsite
        ;;
    *)
        echo "Usage: offsite.sh key | copy <device> | unpack <device>" >&2
        exit 2
        ;;
esac
