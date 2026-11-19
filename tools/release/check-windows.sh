#!/bin/bash
# Checks a release's Windows setup and its haq-server distribution against the release's signed manifest
# (D86, D99): first the manifest itself, with the updater's own checks against the release public key, then
# each file's SHA-256 as the manifest names it. release.yml runs it when a release is published; HAQ's team
# runs it on a downloaded release before taking its setup to a shop.
#
#   tools/release/check-windows.sh --release release/2026.12.1 [--public-key file.pem]
#
# The folder holds the release's files as GitHub has them: manifest.json, manifest.json.sig, compose.yaml,
# HAQ-Retail-OS-Setup-<version>.exe and haq-server.tar.gz. The signing dry run tries it with a throwaway key.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
public_key="$root/apps/shop-server/deploy/release-public.pem"
release=""
while [ $# -gt 0 ]; do
    case "$1" in
        --release) release="$2"; shift 2 ;;
        --public-key) public_key="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done
if [ -z "$release" ] || [ ! -d "$release" ]; then
    echo "Give the release's folder: --release release/2026.12.1" >&2
    exit 2
fi

# The updater's own checks, as a shop with this public key and no release installed would run them.
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
cp "$release/compose.yaml" "$tmp/compose.yaml"
cp "$public_key" "$tmp/release-public.pem"
if ! "$root/apps/shop-server/deploy/update.sh" --dir "$tmp" --release "$release" --check-only >"$tmp/said" 2>&1; then
    cat "$tmp/said" >&2
    echo "The release's manifest does not check out, so its Windows files cannot be trusted." >&2
    exit 1
fi

python3 -I - "$release" <<'PY'
import hashlib, json, os, re, sys

folder = sys.argv[1]
with open(os.path.join(folder, "manifest.json")) as f:
    manifest = json.load(f)
windows = manifest.get("windows")
if not isinstance(windows, dict):
    sys.exit("The release's manifest names no Windows setup: it was made without --windows.")
problems = []
for part in ("setup", "distribution"):
    entry = windows.get(part)
    name = entry.get("file") if isinstance(entry, dict) else None
    want = entry.get("sha256") if isinstance(entry, dict) else None
    if not isinstance(name, str) or not re.fullmatch(r"[A-Za-z0-9._-]+", name) or not isinstance(want, str) or not re.fullmatch(r"[0-9a-f]{64}", want):
        problems.append(f"The manifest's Windows {part} is not a file name with its SHA-256.")
        continue
    path = os.path.join(folder, name)
    if not os.path.isfile(path):
        problems.append(f"The release has no {name}.")
        continue
    digest = hashlib.sha256()
    with open(path, "rb") as f:
        for block in iter(lambda: f.read(1 << 20), b""):
            digest.update(block)
    if digest.hexdigest() != want:
        problems.append(f"{name} is not the file the manifest signs (SHA-256 {digest.hexdigest()}).")
    else:
        print(f"{name}: SHA-256 {want}, as the manifest signs.")
if problems:
    sys.exit("\n".join(problems))
PY
echo "Release $(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$release/manifest.json")'s Windows setup and distribution are the ones it signs."
