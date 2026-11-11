#!/bin/bash
# Gives a published release's three images its version as their tag on GHCR (D70), so a shop installed with
# `install.sh --tag 2026.11.1` runs exactly the images the signed manifest pins. First the release is checked
# with the updater's own checks against the release public key in this repository (its signature, its Compose
# file, every image pinned by digest); then each image's version tag is pointed at the manifest's digest,
# with no rebuild. A version tag that already names another image is never moved: a published version always
# means the same images.
#
#   tools/release/tag-images.sh --release release/2026.11.1 [--check-only] [--public-key file.pem]
#
# The Release workflow runs it when a release is published, and on request for one published earlier, with
# push rights to GHCR. With --check-only it checks the release and lists the tags it would make, without
# touching the registry; the signing dry run uses it with a throwaway key (--public-key).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
release=""
check_only=0
public_key="$root/apps/shop-server/deploy/release-public.pem"
while [ $# -gt 0 ]; do
    case "$1" in
        --release) release="$2"; shift 2 ;;
        --check-only) check_only=1; shift ;;
        --public-key) public_key="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
[ -n "$release" ] && [ -d "$release" ] || { echo "Give the release's folder: --release release/2026.11.1" >&2; exit 2; }

json() { python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1]))
for k in sys.argv[2].split("."): d=d[k]
print(d)' "$1" "$2"; }

# The updater's own checks, as a shop with this public key and no release installed would run them.
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
cp "$root/apps/shop-server/deploy/compose.yaml" "$tmp/compose.yaml"
cp "$public_key" "$tmp/release-public.pem"
if ! "$root/apps/shop-server/deploy/update.sh" --dir "$tmp" --release "$release" --check-only >"$tmp/check" 2>&1; then
    cat "$tmp/check" >&2
    echo "The release did not check out, so no image was tagged." >&2
    exit 1
fi
version="$(json "$release/manifest.json" version)"
if ! printf '%s' "$version" | grep -Eq '^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$'; then
    echo "The release's version ($version) is not YEAR.MONTH.N, so no image was tagged." >&2
    exit 1
fi
echo "Release $version is signed and checks out."

for name in app web db; do
    image="$(json "$release/manifest.json" "images.$name")"
    repository="${image%@*}"
    digest="${image#*@}"
    tag="$repository:$version"
    if [ "$check_only" = 1 ]; then
        echo "Would tag $tag as $digest"
        continue
    fi
    # Only "not found" means there is no tag yet; any other failure stops, so a tag is never moved by mistake.
    if found="$(docker buildx imagetools inspect "$tag" --format '{{json .Manifest.Digest}}' 2>"$tmp/inspect")"; then
        current="$(printf '%s' "$found" | tr -d '"')"
    elif grep -qi 'not found' "$tmp/inspect"; then
        current=""
    else
        cat "$tmp/inspect" >&2
        echo "Could not read $tag from the registry, so it was not tagged." >&2
        exit 1
    fi
    if [ "$current" = "$digest" ]; then
        echo "$tag already names $digest"
        continue
    fi
    if [ -n "$current" ]; then
        echo "$tag already names another image ($current), and a version's tag is never moved." >&2
        exit 1
    fi
    docker buildx imagetools create --tag "$tag" "$image"
    current="$(docker buildx imagetools inspect "$tag" --format '{{json .Manifest.Digest}}' | tr -d '"')"
    [ "$current" = "$digest" ] || { echo "$tag names $current after tagging, not $digest." >&2; exit 1; }
    echo "Tagged $tag as $digest"
done
