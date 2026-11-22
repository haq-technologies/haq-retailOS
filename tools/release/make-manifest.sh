#!/bin/bash
# Writes a release's manifest (spec section 4) [D2, F16]: the commit the release is made from, the three
# images CI built from that commit, pinned by digest as GHCR holds them, and the hash of that commit's
# Compose file. The manifest is then signed offline (sign.sh) and published with the Compose file as a
# GitHub release, tagged on the same commit.
#
#   tools/release/make-manifest.sh --version 2026.11.3 [--commit <commit>] [--upgrade-from 2026.11.1]
#                                  [--windows <folder>] [--registry ghcr.io/haq-technologies] [--out release/2026.11.3]
#
# --commit is the commit to release: one on main, or on a release branch for a fix to a release already
# out. With none it is main as GitHub has it now. A release names its commit, and its images are that
# commit's (sha-<commit>), never whatever the moving tag `main` points at a moment later (D81).
#
# --upgrade-from names the oldest release that can go straight to this one; the updater sends a shop
# further behind to that release first. Give it only when this release finishes a change an earlier one
# began (expand, then contract).
#
# --windows names the folder with the release's Windows setup (HAQ-Retail-OS-Setup-<version>.exe) and its
# haq-server distribution (haq-server.tar.gz), as the workflow windows-release.yml built them from the same
# commit (D86, D99). Both are copied beside the manifest, which names each with its SHA-256, so the signature
# covers them; tools/release/check-windows.sh checks them, and release.yml does when the release is published.
# The distribution names the commit it was built from, and it must be the release's commit (D137).
#
# --local uses images built on this computer (retail-os/retail-os-app:local and the others) by name, for
# the updater's dry run on staging; a shop's manifest always uses digests.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
version=""
commit=""
upgrade_from=""
registry=ghcr.io/haq-technologies
out=""
local=0
windows=""
while [ $# -gt 0 ]; do
    case "$1" in
        --version) version="$2"; shift 2 ;;
        --commit) commit="$2"; shift 2 ;;
        --upgrade-from) upgrade_from="$2"; shift 2 ;;
        --registry) registry="$2"; shift 2 ;;
        --out) out="$2"; shift 2 ;;
        --local) local=1; shift ;;
        --windows) windows="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
[[ "$version" =~ ^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$ ]] || { echo "Give the version as YEAR.MONTH.N, such as 2026.11.1." >&2; exit 2; }
[ -z "$upgrade_from" ] || [[ "$upgrade_from" =~ ^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$ ]] || { echo "Give --upgrade-from as a release, such as 2026.11.1." >&2; exit 2; }
out="${out:-$root/release/$version}"
mkdir -p "$out"
setup_file="HAQ-Retail-OS-Setup-$version.exe"
if [ -n "$windows" ]; then
    for f in "$setup_file" haq-server.tar.gz; do
        [ -s "$windows/$f" ] || { echo "The Windows folder $windows has no $f. Build it with the workflow windows-release.yml for this version and commit." >&2; exit 1; }
    done
fi

# The commit, in full. A local dry run releases the checkout as it is.
if [ "$local" = 1 ]; then
    commit="$(git -C "$root" rev-parse HEAD)"
else
    git -C "$root" fetch --quiet origin
    commit="$(git -C "$root" rev-parse --verify --quiet "${commit:-origin/main}^{commit}")" \
        || { echo "That commit is not in this repository. Give one on main or on a release branch." >&2; exit 1; }
fi
tag="sha-$commit"

# The Windows setup and its distribution are the release's commit's, as its images are (D81, D137): the
# distribution names the commit it was built from, and windows-release.yml builds the setup from the same one.
if [ -n "$windows" ]; then
    built="$(python3 -I "$root/tools/release/distro_commit.py" "$windows/haq-server.tar.gz")" || exit 1
    if [ "$built" != "$commit" ]; then
        echo "The Windows setup in $windows was built from commit $built, not $commit. Give --commit $built, or build the setup for $commit with windows-release.yml." >&2
        exit 1
    fi
fi

image() {
    if [ "$local" = 1 ]; then
        echo "retail-os/retail-os-$1:local"
        return
    fi
    local digest problem
    problem="$(mktemp)"
    digest="$(docker buildx imagetools inspect "$registry/retail-os-$1:$tag" --format '{{json .Manifest.Digest}}' 2>"$problem" | tr -d '"')" || true
    if ! [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
        cat "$problem" >&2
        if grep -qiE 'unauthorized|denied|authorize' "$problem"; then
            echo "GHCR did not let this computer read $registry/retail-os-$1. Sign in first: docker login ghcr.io (a token with read:packages)." >&2
        else
            echo "GHCR has no $registry/retail-os-$1:$tag. CI pushes a commit's images when it reaches main or a release branch; wait for that run to finish." >&2
        fi
        rm -f "$problem"
        exit 1
    fi
    rm -f "$problem"
    echo "$registry/retail-os-$1@$digest"
}

# The Compose file as it is at that commit, not as this checkout happens to have it.
if [ "$local" = 1 ]; then
    cp "$root/apps/shop-server/deploy/compose.yaml" "$out/compose.yaml"
else
    git -C "$root" show "$commit:apps/shop-server/deploy/compose.yaml" > "$out/compose.yaml"
fi
compose_sha="$(openssl dgst -sha256 -r "$out/compose.yaml" | cut -d' ' -f1)"
app="$(image app)"
web="$(image web)"
db="$(image db)"

windows_json=""
if [ -n "$windows" ]; then
    for f in "$setup_file" haq-server.tar.gz; do
        [ "$windows/$f" -ef "$out/$f" ] || cp "$windows/$f" "$out/$f"
    done
    setup_sha="$(openssl dgst -sha256 -r "$out/$setup_file" | cut -d' ' -f1)"
    distro_sha="$(openssl dgst -sha256 -r "$out/haq-server.tar.gz" | cut -d' ' -f1)"
    windows_json="$(printf ',\n    "windows": {\n        "setup": {"file": "%s", "sha256": "%s"},\n        "distribution": {"file": "haq-server.tar.gz", "sha256": "%s"}\n    }' "$setup_file" "$setup_sha" "$distro_sha")"
fi

cat > "$out/manifest.json" <<JSON
{
    "schema": 1,
    "version": "$version",
    "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "commit": "$commit",$( [ -z "$upgrade_from" ] || printf '\n    "upgrade_from": "%s",' "$upgrade_from" )
    "images": {
        "app": "$app",
        "web": "$web",
        "db": "$db"
    },
    "compose_sha256": "$compose_sha"$windows_json
}
JSON
echo "Wrote $out/manifest.json and $out/compose.yaml for commit $commit."
echo "Sign it offline: tools/release/sign.sh $out/manifest.json /path/to/release-private.pem"
assets="$out/manifest.json $out/manifest.json.sig $out/compose.yaml"
if [ -n "$windows" ]; then assets="$assets $out/$setup_file $out/haq-server.tar.gz"; fi
echo "Then publish it on that commit: gh release create $version $assets --title $version --target $commit --prerelease"
