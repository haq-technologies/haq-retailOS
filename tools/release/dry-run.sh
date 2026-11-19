#!/bin/bash
# The signing dry run (PLAN.md, Phase 3), with a throwaway key: a signed release checks out with the
# updater's own checks; a changed manifest, a changed Compose file, a release signed by another key, an
# older release and a release whose images are not pinned by digest are all refused. tag-images.sh (D70)
# tags a release's images only after the same checks. Runs in CI; it touches nothing outside a temporary folder.
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
# Tagging a published release's images with its version (D70) runs the same checks first.
tag() { "$root/tools/release/tag-images.sh" --release "$1" --public-key "$tmp/key/release-public.pem" --check-only; }
expect pass "a signed release's images are tagged with its version" tag "$tmp/good"
expect fail "a release signed by another key is never tagged" tag "$tmp/stranger"
expect fail "a release with an image named by tag, not digest, is never tagged" tag "$tmp/tagged"
release "$tmp/odd" 2026.11
"$root/tools/release/sign.sh" "$tmp/odd/manifest.json" "$tmp/key/release-private.pem" >/dev/null
expect fail "a release whose version is not YEAR.MONTH.N is never tagged" tag "$tmp/odd"

# A release names the commit it is made from, and its tag is on that commit (D81).
add() { # $1: folder, $2: a field to put in the manifest, as "name": "value"
    sed -i.bak "s/\"schema\": 1,/\"schema\": 1, $2,/" "$1/manifest.json"
    "$root/tools/release/sign.sh" "$1/manifest.json" "$tmp/key/release-private.pem" >/dev/null
}
release "$tmp/named" 2026.11.2
add "$tmp/named" '"commit": "1111111111111111111111111111111111111111"'
expect pass "a release that names its commit checks out" check "$tmp/named"
tag_at() { "$root/tools/release/tag-images.sh" --release "$1" --public-key "$tmp/key/release-public.pem" --check-only --tag-commit "$2"; }
expect pass "a release tagged on the commit its manifest names is tagged" tag_at "$tmp/named" 1111111111111111111111111111111111111111
expect fail "a release tagged on another commit than its manifest names is never tagged" tag_at "$tmp/named" 2222222222222222222222222222222222222222
expect pass "a release from before manifests named a commit is still tagged" tag_at "$tmp/good" 2222222222222222222222222222222222222222

# A release that finishes a change an earlier one began names the oldest release that can go straight to it.
release "$tmp/far" 2026.12.1
add "$tmp/far" '"upgrade_from": "2026.11.3"'
expect fail "a shop too far behind is sent to the release in between first" check "$tmp/far"
echo '{"version": "2026.11.3"}' > "$tmp/shop/release.json"
expect pass "a shop on that release goes straight to it" check "$tmp/far"
echo '{"version": "2026.11.1"}' > "$tmp/shop/release.json"

# Channels (D81): a pinned shop stays where it is on a plain update, and still takes a release it is given.
echo pinned > "$tmp/shop/channel"
pinned() { local said; said="$("$root/apps/shop-server/deploy/update.sh" --dir "$tmp/shop" --check-only)" && [[ "$said" == *"pinned to release 2026.11.1"* ]]; }
expect pass "a pinned shop's plain update changes nothing, and says so" pinned
expect pass "a pinned shop still takes a release it is given" check "$tmp/good"
echo sideways > "$tmp/shop/channel"
plain() { "$root/apps/shop-server/deploy/update.sh" --dir "$tmp/shop" --check-only; }
expect fail "a channel that is not stable, beta or pinned is refused" plain
rm -f "$tmp/shop/channel"
named() { "$root/apps/shop-server/deploy/update.sh" --dir "$tmp/shop" --check-only --version "$1"; }
expect fail "a version that is not YEAR.MONTH.N is refused before anything is fetched" named main

# A new shop's first install checks its release as the updater does, and installs its images by digest (D99).
cp -r "$root/apps/shop-server/deploy" "$tmp/deploy"
cp "$tmp/key/release-public.pem" "$tmp/deploy/release-public.pem"
first() { # $1: the release folder, $2: the version asked for
    env -u SKIP_PULL "$tmp/deploy/install.sh" --dir "$tmp/new-shop" --ip 127.0.0.1 --tag "$2" --release "$1" --check-only
}
expect pass "a new shop's first install takes a release signed with the release key" first "$tmp/good" 2026.11.2
expect fail "a new shop's first install refuses a manifest changed after signing" first "$tmp/changed" 2026.11.9
expect fail "a new shop's first install refuses a Compose file that is not the signed one" first "$tmp/compose" 2026.11.2
expect fail "a new shop's first install refuses a release signed by another key" first "$tmp/stranger" 2026.11.2
expect fail "a new shop's first install refuses an image named by tag, not digest" first "$tmp/tagged" 2026.11.3
expect fail "a new shop's first install refuses a release that is not the one asked for" first "$tmp/good" 2026.11.4
if [ -e "$tmp/new-shop" ]; then
    echo "FAIL  a first install's check made $tmp/new-shop"
    failures=$((failures + 1))
else
    echo "PASS  a first install's check installs nothing"
fi

# A release carries its Windows setup and haq-server distribution, each named in the signed manifest with its
# SHA-256 (D99); check-windows.sh checks them before HAQ's team takes the setup to a shop.
mkdir -p "$tmp/win"
printf 'MZ a stand-in setup' > "$tmp/win/HAQ-Retail-OS-Setup-2026.11.2.exe"
printf 'a stand-in distribution' > "$tmp/win/haq-server.tar.gz"
made() {
    "$root/tools/release/make-manifest.sh" --local --version 2026.11.2 --windows "$tmp/win" --out "$tmp/made" >/dev/null &&
    python3 -I - "$tmp/made" <<'PY'
import hashlib, json, sys
folder = sys.argv[1]
w = json.load(open(folder + "/manifest.json"))["windows"]
for part, name in (("setup", "HAQ-Retail-OS-Setup-2026.11.2.exe"), ("distribution", "haq-server.tar.gz")):
    assert w[part]["file"] == name, w
    assert w[part]["sha256"] == hashlib.sha256(open(folder + "/" + name, "rb").read()).hexdigest(), w
PY
}
expect pass "make-manifest names the Windows setup and distribution with their SHA-256" made
with_windows() { # $1: folder made by release(); adds the Windows files and names them in its manifest
    cp "$tmp/win/"* "$1/"
    python3 -I - "$1" <<'PY'
import hashlib, json, sys
folder = sys.argv[1]
path = folder + "/manifest.json"
m = json.load(open(path))
sha = lambda n: hashlib.sha256(open(folder + "/" + n, "rb").read()).hexdigest()
m["windows"] = {"setup": {"file": "HAQ-Retail-OS-Setup-2026.11.2.exe", "sha256": sha("HAQ-Retail-OS-Setup-2026.11.2.exe")},
                "distribution": {"file": "haq-server.tar.gz", "sha256": sha("haq-server.tar.gz")}}
json.dump(m, open(path, "w"))
PY
}
windows() { "$root/tools/release/check-windows.sh" --release "$1" --public-key "$tmp/key/release-public.pem"; }
release "$tmp/win-good" 2026.11.2
with_windows "$tmp/win-good"
"$root/tools/release/sign.sh" "$tmp/win-good/manifest.json" "$tmp/key/release-private.pem" >/dev/null
expect pass "a release's Windows setup and distribution check out against its signed manifest" windows "$tmp/win-good"
cp -r "$tmp/win-good" "$tmp/win-changed"
printf ' and a change' >> "$tmp/win-changed/HAQ-Retail-OS-Setup-2026.11.2.exe"
expect fail "a Windows setup changed after signing is refused" windows "$tmp/win-changed"
cp -r "$tmp/win-good" "$tmp/win-missing"
rm "$tmp/win-missing/haq-server.tar.gz"
expect fail "a release missing its distribution is refused" windows "$tmp/win-missing"
cp -r "$tmp/win-good" "$tmp/win-stranger"
"$root/tools/release/sign.sh" "$tmp/win-stranger/manifest.json" "$tmp/other/release-private.pem" >/dev/null
expect fail "Windows files under a manifest signed by another key are refused" windows "$tmp/win-stranger"
expect fail "a release whose manifest names no Windows setup is refused" windows "$tmp/good"

[ "$failures" = 0 ] && echo "The signing dry run passed." || { echo "The signing dry run failed ($failures)."; exit 1; }
