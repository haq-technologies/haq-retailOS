#!/bin/bash
# Writes a release's manifest (spec section 4) [D2, F16]: the three images pinned by digest, as GHCR holds
# them, and the hash of the Compose file that runs them. The manifest is then signed offline (sign.sh)
# and published with the Compose file as a GitHub release.
#
#   tools/release/make-manifest.sh --version 2026.11.1 [--tag main] [--registry ghcr.io/haq-technologies] [--out release/2026.11.1]
#
# --local uses images built on this computer (retail-os/retail-os-app:local and the others) by name, for
# the updater's dry run on staging; a shop's manifest always uses digests.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
version=""
tag=main
registry=ghcr.io/haq-technologies
out=""
local=0
while [ $# -gt 0 ]; do
    case "$1" in
        --version) version="$2"; shift 2 ;;
        --tag) tag="$2"; shift 2 ;;
        --registry) registry="$2"; shift 2 ;;
        --out) out="$2"; shift 2 ;;
        --local) local=1; shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
[[ "$version" =~ ^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$ ]] || { echo "Give the version as YEAR.MONTH.N, such as 2026.11.1." >&2; exit 2; }
out="${out:-$root/release/$version}"
mkdir -p "$out"

image() {
    if [ "$local" = 1 ]; then
        echo "retail-os/retail-os-$1:local"
        return
    fi
    local digest
    digest="$(docker buildx imagetools inspect "$registry/retail-os-$1:$tag" --format '{{json .Manifest.Digest}}' | tr -d '"')"
    [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || { echo "No digest for $registry/retail-os-$1:$tag." >&2; exit 1; }
    echo "$registry/retail-os-$1@$digest"
}

cp "$root/apps/shop-server/deploy/compose.yaml" "$out/compose.yaml"
compose_sha="$(openssl dgst -sha256 -r "$out/compose.yaml" | cut -d' ' -f1)"

cat > "$out/manifest.json" <<JSON
{
    "schema": 1,
    "version": "$version",
    "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "images": {
        "app": "$(image app)",
        "web": "$(image web)",
        "db": "$(image db)"
    },
    "compose_sha256": "$compose_sha"
}
JSON
echo "Wrote $out/manifest.json and $out/compose.yaml. Sign it offline: tools/release/sign.sh $out/manifest.json /path/to/release-private.pem"
