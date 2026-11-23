#!/bin/bash
# The PIN that the Windows helper sends `haq add-user` and `haq pin` on standard input (--pin-stdin, D99)
# reaches the server's own command, not the audit trail's record that runs first (D110, D143). On 23
# November 2026 the record's `docker compose exec` took the PIN, so every PIN came to shop:add-user empty and
# was refused as the wrong length. A stand-in docker reads its input as the real `compose exec` does.
#
#   tools/ci/haq-pin-test.sh
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

shop="$tmp/shop"
mkdir -p "$shop/bin" "$tmp/fake" "$tmp/log"
cp "$root/apps/shop-server/deploy/haq" "$shop/bin/haq"
printf 'PROJECT=haq-test\nNAME=shop.local\nIP=192.168.1.10\nHTTP_PORT=80\nHTTPS_PORT=443\nPROFILES=\nWINDOWS=1\n' > "$shop/haq.conf"
: > "$shop/compose.yaml"
: > "$shop/.env"

cat > "$tmp/fake/docker" <<'FAKE'
#!/bin/bash
case "$*" in
    info*) exit 0 ;;
    *support:record*) cat > "$HAQ_TEST_LOG/record.stdin" ;;
    *shop:add-user*|*shop:set-pin*) cat > "$HAQ_TEST_LOG/command.stdin"; echo "$*" > "$HAQ_TEST_LOG/command.args" ;;
    *) cat > /dev/null ;;
esac
FAKE
chmod +x "$tmp/fake/docker"

failures=0
check() { # $1: what, then the haq arguments; the PIN 12345 goes on its standard input
    local what="$1"
    shift
    rm -f "$tmp/log/"*
    printf '12345\n' | PATH="$tmp/fake:$PATH" HAQ_TEST_LOG="$tmp/log" HAQ_DIR="$shop" bash "$shop/bin/haq" "$@" >/dev/null 2>&1 || true
    if [ "$(cat "$tmp/log/command.stdin" 2>/dev/null)" = 12345 ] && grep -q -- '--pin-stdin' "$tmp/log/command.args" \
        && [ ! -s "$tmp/log/record.stdin" ]; then
        echo "PASS  $what"
    else
        echo "FAIL  $what: the command read '$(cat "$tmp/log/command.stdin" 2>/dev/null)', the audit record read '$(cat "$tmp/log/record.stdin" 2>/dev/null)'"
        failures=$((failures + 1))
    fi
}
check "add-user's PIN on standard input reaches shop:add-user" add-user "Umar" cashier --pin-stdin
check "pin's PIN on standard input reaches shop:set-pin" pin "Umar" --pin-stdin

[ "$failures" = 0 ] && echo "The haq PIN test passed." || { echo "The haq PIN test failed ($failures)."; exit 1; }
