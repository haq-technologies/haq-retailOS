#!/bin/sh
# Signs a release manifest with the offline key (Ed25519), next to it as manifest.json.sig (base64).
#
#   tools/release/sign.sh release/2026.11.1/manifest.json /path/to/release-private.pem
set -eu
manifest="${1:?Give the manifest}"
key="${2:?Give the private key}"
openssl pkeyutl -sign -rawin -inkey "$key" -in "$manifest" | openssl base64 -A > "$manifest.sig"
echo "Signed: $manifest.sig. Publish manifest.json, manifest.json.sig and compose.yaml as the GitHub release $(basename "$(dirname "$manifest")")."
