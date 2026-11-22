#!/bin/bash
# Signs a release manifest with the offline key (Ed25519), next to it as manifest.json.sig (base64).
#
#   tools/release/sign.sh release/2026.11.1/manifest.json /path/to/release-private.pem
#
# It says "Signed" only once the signature is made and checks out against the key's own public half. On any
# failure it stops, says why, and leaves no signature behind: before 22 November 2026 a manifest it could not
# read still gave an empty manifest.json.sig and "Signed" (D138).
set -euo pipefail
manifest="${1:?Give the manifest}"
key="${2:?Give the private key}"
[ -f "$manifest" ] || { echo "There is no manifest at $manifest. Give its whole path, such as release/2026.11.4/manifest.json." >&2; exit 1; }
[ -f "$key" ] || { echo "There is no private key at $key. Is the key's drive plugged in?" >&2; exit 1; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
openssl pkeyutl -sign -rawin -inkey "$key" -in "$manifest" -out "$tmp/sig.bin"
[ -s "$tmp/sig.bin" ] || { echo "OpenSSL made no signature. Nothing was signed." >&2; exit 1; }
openssl pkey -in "$key" -pubout -out "$tmp/public.pem"
openssl pkeyutl -verify -pubin -inkey "$tmp/public.pem" -rawin -in "$manifest" -sigfile "$tmp/sig.bin" >/dev/null \
    || { echo "The signature does not check out against the key. Nothing was signed." >&2; exit 1; }
openssl base64 -A -in "$tmp/sig.bin" -out "$tmp/sig.b64"
mv "$tmp/sig.b64" "$manifest.sig"
echo "Signed: $manifest.sig. Publish manifest.json, manifest.json.sig and compose.yaml as the GitHub release $(basename "$(dirname "$manifest")")."
