#!/bin/sh
# Makes the release signing key (spec section 4; CLAUDE.md, Stack). Run it once, on the offline computer
# that signs releases. The private key stays there and never enters this repository; commit only the
# public key, as apps/shop-server/deploy/release-public.pem, so every install carries it.
#
#   tools/release/new-key.sh /path/on/the/offline/computer
set -eu
out="${1:?Give the folder for the key, on the signing computer}"
mkdir -p "$out"
umask 077
if [ -e "$out/release-private.pem" ]; then
    echo "There is a key at $out/release-private.pem already; it is never replaced by this script." >&2
    exit 1
fi
openssl genpkey -algorithm ed25519 -out "$out/release-private.pem"
openssl pkey -in "$out/release-private.pem" -pubout -out "$out/release-public.pem"
echo "Private key: $out/release-private.pem (keep it offline, with a copy in a safe place)."
echo "Public key:  $out/release-public.pem (commit it as apps/shop-server/deploy/release-public.pem)."
