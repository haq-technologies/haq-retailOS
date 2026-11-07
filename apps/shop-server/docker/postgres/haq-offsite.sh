#!/bin/sh
# The off-site copy (spec sections 4 and 9; check E1) [S16, S17, D53], in the db image beside pgBackRest.
# The backup disk inside the server is lost with the server in a theft or a fire, so the whole backup
# repository is also copied, encrypted, onto one of two flash drives, swapped weekly, one always away from
# the shop.
#
#   haq-offsite.sh key                once, at install: makes the key and prints its three shares
#   haq-offsite.sh copy /offsite      writes an encrypted copy onto the drive mounted there
#   haq-offsite.sh unpack /offsite    on a spare box: asks for two shares and puts the newest copy back
#   haq-offsite.sh selftest           the whole round trip in a temporary folder (CI runs it)
#
# The server encrypts to a public key (age) and never holds the private key. The private key is kept,
# wrapped with a random key (OpenSSL), on the backup disk and on each drive; the random key is split into
# three shares (Shamir, ssss), any two of which unwrap it: the shop owner's, HAQ's, and a sealed paper copy
# at the owner's bank. So nobody can read a drive alone, and restoring from one needs two shares. Restoring
# from the backup disk itself needs none (deploy/restore.sh).
set -eu

STATUS="${HAQ_OFFSITE_STATUS:-/backup/status}"
REPO="${HAQ_OFFSITE_REPO:-/backup/repo}"
KEYDIR="$STATUS/offsite"
KEEP=2

log() { echo "$(date -u '+%Y-%m-%dT%H:%M:%SZ') haq-offsite: $*" >&2; }
fail() { log "$*"; exit 1; }

scratch() { # a private folder in memory where it can be, for anything secret
    if [ -d /dev/shm ] && [ -w /dev/shm ]; then mktemp -d -p /dev/shm; else mktemp -d; fi
}

make_key() {
    [ ! -f "$KEYDIR/recipient.txt" ] || [ "${1:-}" = --replace ] || fail "The off-site key exists already. Its shares are with the owner, HAQ and the bank."
    mkdir -p "$KEYDIR"
    tmp=$(scratch)
    trap 'rm -rf "$tmp"' EXIT
    age-keygen -o "$tmp/identity.txt" 2>/dev/null
    age-keygen -y "$tmp/identity.txt" > "$KEYDIR/recipient.txt.new"
    HAQ_WRAP=$(openssl rand -hex 32)
    export HAQ_WRAP
    openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -in "$tmp/identity.txt" -out "$KEYDIR/identity.enc.new" -pass env:HAQ_WRAP
    shares=$(echo "$HAQ_WRAP" | ssss-split -t 2 -n 3 -x -q -w haq)
    unset HAQ_WRAP
    mv "$KEYDIR/identity.enc.new" "$KEYDIR/identity.enc"
    mv "$KEYDIR/recipient.txt.new" "$KEYDIR/recipient.txt"
    fingerprint=$(sha256sum "$KEYDIR/identity.enc" | cut -c1-12)
    echo "{\"made_at\": $(date +%s), \"fingerprint\": \"$fingerprint\"}" > "$KEYDIR/key.json"
    rm -rf "$tmp"
    trap - EXIT
    cat <<SHARES
The off-site key is made (fingerprint $fingerprint). Write each share on its own sheet with the shop's name
and the fingerprint, and give it away now; this is the only time they are shown. Any two open a drive.

  Share for the shop owner:   $(echo "$shares" | sed -n 1p)
  Share for HAQ:              $(echo "$shares" | sed -n 2p)
  Share for the bank (seal it in an envelope):
                              $(echo "$shares" | sed -n 3p)
SHARES
}

drive_status() { # $1 drive, $2 ok, $3 bytes, $4 file or reason
    file="$STATUS/offsite.json"
    [ -f "$file" ] || echo '{"drives": {}}' > "$file"
    if [ "$2" = true ]; then
        jq --arg d "$1" --argjson b "$3" --arg f "$4" '.drives[$d] = {copied_at: (now | floor), ok: true, bytes: $b, file: $f}' "$file" > "$file.tmp"
    else
        jq --arg d "$1" --arg r "$4" '.drives[$d] = ((.drives[$d] // {}) + {failed_at: (now | floor), ok: false, reason: $r})' "$file" > "$file.tmp"
    fi
    mv "$file.tmp" "$file"
}

copy() {
    target="${1:?Give the folder where the drive is mounted}"
    drive="${HAQ_OFFSITE_DRIVE:-?}"
    [ -f "$KEYDIR/recipient.txt" ] || { drive_status "$drive" false 0 "no key"; fail "There is no off-site key yet: run haq-offsite.sh key once."; }
    [ -d "$REPO" ] || { drive_status "$drive" false 0 "no backups"; fail "There is no backup repository at $REPO."; }
    [ -d "$target" ] && [ -w "$target" ] || { drive_status "$drive" false 0 "drive not writable"; fail "The drive at $target cannot be written."; }

    need=$(du -sk "$REPO" | cut -f1)
    free=$(df -Pk "$target" | awk 'NR == 2 { print $4 }')
    old=$(ls -1 "$target"/haq-backup-*.tar.age 2>/dev/null | wc -l)
    # Room for the new copy beside the one before; older copies go first.
    ls -1 "$target"/haq-backup-*.tar.age 2>/dev/null | sort | head -n "$(( old > KEEP - 1 ? old - (KEEP - 1) : 0 ))" | while read -r f; do rm -f "$f"; done
    free=$(df -Pk "$target" | awk 'NR == 2 { print $4 }')
    [ "$free" -gt $(( need + need / 10 + 1024 )) ] || { drive_status "$drive" false 0 "drive full"; fail "The drive has ${free} KB free and the copy needs about ${need} KB."; }

    name="haq-backup-$(date -u +%Y%m%dT%H%M%S)$(date -u +%N | cut -c1-3)Z.tar.age"
    log "copying the backups ($need KB) to drive $drive as $name"
    tmp=$(scratch)
    trap 'rm -rf "$tmp"; rm -f "$target/$name.partial"' EXIT
    # The stream is hashed as it is written, then the file is read back and hashed again. tar's own status
    # is kept: 1 only says a WAL file changed while it was read (pgBackRest writes new ones beside), 2 fails.
    # With the repository go the branch's signing key and each counter's highest epoch, kept beside it on the
    # backup disk, so a spare box restored from a drive keeps both (D35).
    extra=""
    [ -d "$STATUS/keys" ] && extra="keys"
    [ -f "$STATUS/epochs.json" ] && extra="$extra epochs.json"
    { tar -cf - -C "$(dirname "$REPO")" "$(basename "$REPO")" ${extra:+-C "$STATUS"} $extra; echo $? > "$tmp/tar"; } \
        | age -r "$(cat "$KEYDIR/recipient.txt")" | tee "$target/$name.partial" | sha256sum | cut -d' ' -f1 > "$tmp/written"
    sync
    [ "$(cat "$tmp/tar")" -le 1 ] || { drive_status "$drive" false 0 "backups could not be read"; fail "The backups could not be read for the copy."; }
    [ "$(sha256sum "$target/$name.partial" | cut -d' ' -f1)" = "$(cat "$tmp/written")" ] || { drive_status "$drive" false 0 "copy did not read back"; fail "The copy on the drive does not match what was written."; }
    mv "$target/$name.partial" "$target/$name"
    cp "$KEYDIR/identity.enc" "$target/identity.enc"
    cp "$KEYDIR/key.json" "$target/key.json"
    cat > "$target/README.txt" <<README
HAQ Retail OS off-site backup (drive $drive).

This drive holds encrypted copies of the shop server's backups. Nobody can read them without two of the
three key shares (the shop owner's, HAQ's and the sealed copy at the bank). To restore a shop from it, see
docs/operations/restore-drill.md, "Restoring from a flash drive". Keep this drive away from the shop until
it is swapped back.
README
    sync
    bytes=$(stat -c %s "$target/$name")
    rm -rf "$tmp"
    trap - EXIT
    drive_status "$drive" true "$bytes" "$name"
    log "drive $drive: $name written and checked ($bytes bytes)"
}

unpack() {
    source="${1:?Give the folder where the drive is mounted}"
    newest=$(ls -1 "$source"/haq-backup-*.tar.age 2>/dev/null | sort | tail -n 1)
    [ -n "$newest" ] || fail "There is no copy on the drive at $source."
    [ -f "$source/identity.enc" ] || fail "The drive has no key file (identity.enc)."
    tmp=$(scratch)
    trap 'rm -rf "$tmp"' EXIT
    if [ -n "${HAQ_OFFSITE_SHARES:-}" ]; then
        printf '%s\n' $HAQ_OFFSITE_SHARES > "$tmp/shares"
    else
        echo "Type two of the three key shares, each on its own line (they start with haq-):" >&2
        head -n 2 > "$tmp/shares"
    fi
    HAQ_WRAP=$(ssss-combine -t 2 -x -q < "$tmp/shares" 2>&1 | tail -n 1)
    export HAQ_WRAP
    openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in "$source/identity.enc" -out "$tmp/identity.txt" -pass env:HAQ_WRAP 2>/dev/null \
        || fail "Those two shares do not open this drive. Check them, or try another pair."
    unset HAQ_WRAP
    mkdir -p "$(dirname "$REPO")" "$STATUS"
    log "unpacking $(basename "$newest") into $REPO"
    into="$(dirname "$REPO")/.offsite-unpack"
    rm -rf "$into"
    mkdir -p "$into"
    age -d -i "$tmp/identity.txt" "$newest" | tar -C "$into" -xf -
    # A spare box made its own empty repository at install: it is kept aside, never deleted.
    if [ -d "$REPO" ]; then
        aside="$REPO.before-unpack-$(date -u +%Y%m%dT%H%M%SZ)"
        mv "$REPO" "$aside"
        log "the backups already here are kept aside at $aside"
    fi
    mv "$into/$(basename "$REPO")" "$REPO"
    [ -d "$into/keys" ] && { rm -rf "$STATUS/keys"; mv "$into/keys" "$STATUS/keys"; chmod 700 "$STATUS/keys"; }
    [ -f "$into/epochs.json" ] && mv "$into/epochs.json" "$STATUS/epochs.json"
    rm -rf "$into" "$tmp"
    trap - EXIT
    log "the backups from $(basename "$newest") are back at $REPO; deploy/restore.sh restores from them"
}

selftest() {
    root=$(mktemp -d)
    trap 'rm -rf "$root"' EXIT
    export HAQ_OFFSITE_STATUS="$root/status" HAQ_OFFSITE_REPO="$root/backup/repo"
    STATUS="$HAQ_OFFSITE_STATUS" REPO="$HAQ_OFFSITE_REPO" KEYDIR="$HAQ_OFFSITE_STATUS/offsite"
    mkdir -p "$STATUS" "$REPO/backup/shop" "$REPO/archive/shop" "$root/drive"
    head -c 300000 /dev/urandom > "$REPO/backup/shop/bundle.1"
    echo "archive" > "$REPO/archive/shop/archive.info"
    mkdir -p "$STATUS/keys" && echo "branch signing key" > "$STATUS/keys/branch.key"
    echo '{"epochs": {"T01": 3}}' > "$STATUS/epochs.json"
    shares=$(make_key | grep -o 'haq-[0-9]-[0-9a-f]*')
    [ "$(echo "$shares" | wc -l)" -eq 3 ] || fail "selftest: the key did not give three shares"
    HAQ_OFFSITE_DRIVE=A copy "$root/drive"
    HAQ_OFFSITE_DRIVE=A copy "$root/drive"
    HAQ_OFFSITE_DRIVE=A copy "$root/drive"
    [ "$(ls -1 "$root/drive"/haq-backup-*.tar.age | wc -l)" -eq "$KEEP" ] || fail "selftest: the drive does not keep exactly the newest $KEEP copies"
    [ "$(jq -r '.drives.A.ok' "$STATUS/offsite.json")" = true ] || fail "selftest: the status was not recorded"
    grep -q 'archive' "$root/drive"/haq-backup-*.tar.age && fail "selftest: the copy is not encrypted"
    mv "$REPO" "$root/original"
    mv "$STATUS/keys" "$root/original-keys"
    rm -f "$STATUS/epochs.json"
    # One share is not enough.
    if (HAQ_OFFSITE_SHARES="$(echo "$shares" | sed -n 1p) $(echo "$shares" | sed -n 1p)" unpack "$root/drive") 2>/dev/null; then
        fail "selftest: one share opened the drive"
    fi
    mkdir -p "$REPO/backup" && echo "a spare box's own empty repository" > "$REPO/backup/new"
    HAQ_OFFSITE_SHARES="$(echo "$shares" | sed -n 1p) $(echo "$shares" | sed -n 3p)" unpack "$root/drive"
    ls -d "$REPO".before-unpack-* >/dev/null 2>&1 || fail "selftest: the repository already there was not kept aside"
    diff -r "$root/original" "$REPO" >/dev/null || fail "selftest: the unpacked backups differ"
    diff -r "$root/original-keys" "$STATUS/keys" >/dev/null || fail "selftest: the branch key did not come back"
    [ "$(jq -r '.epochs.T01' "$STATUS/epochs.json")" = 3 ] || fail "selftest: the epochs did not come back"
    rm -rf "$root"
    trap - EXIT
    echo "haq-offsite selftest passed: key, copy with the branch key and epochs, keep $KEEP, encrypted, two shares open it, one does not."
}

case "${1:-}" in
    key) make_key "${2:-}" ;;
    copy) copy "${2:-}" ;;
    unpack) unpack "${2:-}" ;;
    selftest) selftest ;;
    *) echo "Usage: haq-offsite.sh key | copy <folder> | unpack <folder> | selftest" >&2; exit 2 ;;
esac
