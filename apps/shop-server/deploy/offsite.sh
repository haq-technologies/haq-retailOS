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
#   sudo /opt/haq-retail-os/bin/offsite.sh copy-windows E: A  on a Windows PC's server, from the HAQ Helper (D86)
#   sudo /opt/haq-retail-os/bin/offsite.sh unpack-windows E: A
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
dir="${HAQ_DIR:-$(dirname "$here")}"
project="${HAQ_PROJECT:-haq-retail-os}"
[ -f "$dir/compose.yaml" ] || { echo "There is no stack in $dir." >&2; exit 2; }
compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }

# One drive at a time (D101): plugging a drive in to restore from it starts the HAQ Helper's copy onto it too,
# and the copy must never unmount the drive under the unpack. An unpack waits for a copy to end; a copy
# while an unpack runs is skipped.
lock_drives() {
    exec 9>/run/haq-offsite.lock
    flock "$@" 9
}

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

# On a Windows PC's server (D86) the server runs in WSL2, where the drive is not a device but a Windows
# drive letter. The HAQ Helper reads its label in Windows and gives the letter with A or B; WSL mounts the
# letter with drvfs, owned by PostgreSQL's user as on Linux.
mount_windows_drive() {
    letter="$(printf '%s' "$1" | tr -d ':' | tr '[:lower:]' '[:upper:]')"
    case "$letter" in
        [A-Z]) ;;
        *) echo "Give the drive's letter, such as E:." >&2; exit 2 ;;
    esac
    case "$2" in
        A | B) drive="$2" ;;
        *) echo "Give which off-site drive it is: A (HAQ-OFFSITE-A) or B (HAQ-OFFSITE-B)." >&2; exit 2 ;;
    esac
    mnt="/run/haq-offsite/$drive"
    mkdir -p "$mnt"
    mountpoint -q "$mnt" && umount "$mnt"
    mount -t drvfs "${letter}:" "$mnt" -o uid=999,gid=999,umask=077
}

# The copy onto a mounted drive, the same on Linux and on Windows.
copy_to_drive() {
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
}

unpack_drive() {
    trap unmount_drive EXIT
    # Asks for two of the three shares: on this terminal, or on standard input when the HAQ Helper sends them
    # from Windows, whose runner has no terminal (D101).
    tty=()
    [ -t 0 ] || tty=(-T)
    compose run --rm --no-deps "${tty[@]}" --user root -v "$mnt:/offsite" --entrypoint haq-offsite.sh backup unpack /offsite
}

case "${1:-}" in
    key)
        compose run --rm --no-deps -T --user root --entrypoint haq-offsite.sh backup key "${2:-}"
        ;;
    copy)
        lock_drives -n || { echo "A restore is reading an off-site drive, so this copy is skipped." >&2; exit 75; }
        mount_drive "${2:?Give the drive, such as /dev/sdb1}"
        copy_to_drive
        ;;
    copy-windows)
        lock_drives -n || { echo "A restore is reading an off-site drive, so this copy is skipped." >&2; exit 75; }
        mount_windows_drive "${2:?Give the drive letter, such as E:}" "${3:?Give A or B}"
        copy_to_drive
        ;;
    unpack)
        lock_drives
        mount_drive "${2:?Give the drive, such as /dev/sdb1}"
        unpack_drive
        ;;
    unpack-windows)
        lock_drives
        mount_windows_drive "${2:?Give the drive letter, such as E:}" "${3:?Give A or B}"
        unpack_drive
        ;;
    *)
        echo "Usage: offsite.sh key | copy <device> | unpack <device> | copy-windows <letter> <A|B> | unpack-windows <letter> <A|B>" >&2
        exit 2
        ;;
esac
