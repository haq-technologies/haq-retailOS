#!/bin/bash
# The support file holds what HAQ support needs and nothing secret (finding LG7). It runs against a made-up
# install folder holding everything that must never leave the shop, with a stand-in for docker that answers as a
# shop's stack would and plants a PIN's hash in the app's log. CI runs it in the docs job.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

shop="$tmp/shop"
mkdir -p "$shop/secrets" "$shop/bin" "$tmp/status"
printf 'APP_KEY=base64:%s\nRETAIL_APP_PASSWORD=never-in-the-file\nAPP_IMAGE=ghcr.io/haq/retail-os-app@sha256:abc\nRELEASE_VERSION=2026.12.1\n' "$(head -c 32 /dev/urandom | base64)" > "$shop/.env"
echo never-in-the-file > "$shop/secrets/postgres_password"
echo '{"shop": "never-in-the-file"}' > "$shop/shop.profile.json"
printf 'PROJECT=haq-test\nNAME=shop.local\nIP=192.168.1.10\nSTATUS_DIR=%s\n' "$tmp/status" > "$shop/haq.conf"
echo '{"written_at": 1, "disks": []}' > "$tmp/status/host.json"
touch "$shop/compose.yaml"

cat > "$tmp/docker" <<'STUB'
#!/bin/bash
# A stand-in for docker: a line per service's log, the app's with a PIN hash in it, as a failed query once wrote.
args="$*"
case "$args" in
    *" logs "*app) echo '2026-11-21T09:00:00Z production.ERROR: update "users" set "pin_hash" = $argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA where "id" = 01abc' ;;
    *" logs "*) echo "2026-11-21T09:00:00Z a line from ${args##* }" ;;
    *"exec -T app php artisan health:errors"*) echo "Errors the server logged:     3" ;;
    *"exec -T scheduler"*) printf '== storage/logs/schedule-abc.log\nok\n' ;;
    *" ps --all"*) echo "haq-test-app-1   running" ;;
    "version --format"*) echo "29.9.3" ;;
    *) exit 1 ;;
esac
STUB
chmod +x "$tmp/docker"

out="$tmp/haq-support.zip"
HAQ_DOCKER="$tmp/docker" "$root/apps/shop-server/deploy/support-file.sh" --dir "$shop" --project haq-test --out "$out" >/dev/null

python3 -I - "$out" <<'PY'
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
names = set(z.namelist())
need = {'logs/app.log', 'logs/scheduler.log', 'errors.txt', 'versions.txt', 'host.txt', 'host.json', 'containers.txt', 'scheduled-tasks.txt'}
missing = need - names
assert not missing, f'the support file lacks {sorted(missing)}'
assert not any(n.startswith(('secrets', '.env', 'shop.profile', 'compose')) for n in names), f'it holds {sorted(names)}'
text = '\n'.join(z.read(n).decode() for n in sorted(names))
for secret in ['never-in-the-file', 'base64:', '$argon2id$', 'RETAIL_APP_PASSWORD']:
    assert secret not in text, f'{secret} is in the support file'
assert '[a PIN hash, left out]' in text, 'the PIN hash was not marked as left out'
assert 'RELEASE_VERSION=2026.12.1' in text, 'the release is missing'
print(f'The support file holds {len(names)} files and nothing secret.')
PY

perm="$(stat -c %a "$out" 2>/dev/null || stat -f %Lp "$out")"
if [ "$perm" != 600 ]; then echo "The support file is mode $perm, not 600." >&2; exit 1; fi

if HAQ_DOCKER="$tmp/docker" "$root/apps/shop-server/deploy/support-file.sh" --dir "$shop" --project haq-test --out "$out" >/dev/null 2>&1; then
    echo "It wrote over a file that was there." >&2
    exit 1
fi
echo "Support file self-test passed."
