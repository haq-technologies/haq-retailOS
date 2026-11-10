#!/bin/bash
# The signing dry run (PLAN.md, Phase 3), with a throwaway key: a signed release checks out with the
# updater's own checks; a changed manifest, a changed Compose file, a release signed by another key, an
# older release and a release whose images are not pinned by digest are all refused. Runs in CI; it touches nothing outside a temporary folder.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
failures=0
expect() { # $1: pass or fail, $2: what, then the command
    local want="$1" what="$2"
    shift 2
    if "$@" >"$tmp/out" 2>&1; then got=pass; else got=fail; fi
    if [ "$got" = "$want" ]; then echo "PASS  $what"; else echo "FAIL  $what"; cat "$tmp/out"; failures=$((failures + 1)); fi
}

"$root/tools/release/new-key.sh" "$tmp/key" >/dev/null
"$root/tools/release/new-key.sh" "$tmp/other" >/dev/null

# A pretend installed shop: its Compose file and this key's public half.
mkdir -p "$tmp/shop"
cp "$root/apps/shop-server/deploy/compose.yaml" "$tmp/shop/compose.yaml"
cp "$tmp/key/release-public.pem" "$tmp/shop/release-public.pem"
echo '{"version": "2026.11.1"}' > "$tmp/shop/release.json"

release() { # $1: folder, $2: version, $3: how the web image is named (by digest unless given)
    mkdir -p "$1"
    cp "$root/apps/shop-server/deploy/compose.yaml" "$1/compose.yaml"
    sha="$(openssl dgst -sha256 -r "$1/compose.yaml" | cut -d' ' -f1)"
    d="@sha256:$(printf '%064d' 0)"
    w="${3:-$d}"
    printf '{"schema": 1, "version": "%s", "created_at": "2026-11-06T00:00:00Z", "images": {"app": "ghcr.io/haq-technologies/retail-os-app%s", "web": "ghcr.io/haq-technologies/retail-os-web%s", "db": "ghcr.io/haq-technologies/retail-os-db%s"}, "compose_sha256": "%s"}\n' "$2" "$d" "$w" "$d" "$sha" > "$1/manifest.json"
}
check() { "$root/apps/shop-server/deploy/update.sh" --dir "$tmp/shop" --release "$1" --check-only; }

release "$tmp/good" 2026.11.2
"$root/tools/release/sign.sh" "$tmp/good/manifest.json" "$tmp/key/release-private.pem" >/dev/null
expect pass "a release signed with the release key checks out" check "$tmp/good"

cp -r "$tmp/good" "$tmp/changed"
sed -i.bak 's/2026.11.2/2026.11.9/' "$tmp/changed/manifest.json"
expect fail "a manifest changed after signing is refused" check "$tmp/changed"

cp -r "$tmp/good" "$tmp/compose"
echo "# changed" >> "$tmp/compose/compose.yaml"
expect fail "a Compose file that is not the signed one is refused" check "$tmp/compose"

cp -r "$tmp/good" "$tmp/stranger"
"$root/tools/release/sign.sh" "$tmp/stranger/manifest.json" "$tmp/other/release-private.pem" >/dev/null
expect fail "a release signed by another key is refused" check "$tmp/stranger"

release "$tmp/older" 2026.10.4
"$root/tools/release/sign.sh" "$tmp/older/manifest.json" "$tmp/key/release-private.pem" >/dev/null
expect fail "an older release than the installed one is refused" check "$tmp/older"

release "$tmp/tagged" 2026.11.3 ":main"
"$root/tools/release/sign.sh" "$tmp/tagged/manifest.json" "$tmp/key/release-private.pem" >/dev/null
expect fail "a signed release with an image named by tag, not digest, is refused" check "$tmp/tagged"

[ "$failures" = 0 ] && echo "The signing dry run passed." || { echo "The signing dry run failed ($failures)."; exit 1; }
