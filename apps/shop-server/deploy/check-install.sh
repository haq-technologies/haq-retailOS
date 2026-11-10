#!/bin/bash
# Checks an installed shop server (gate G2; spec section 14, checks D and E). Run it on the server after
# install.sh; it changes nothing it does not put back. Exits 1 if any check fails.
#
#   sudo apps/shop-server/deploy/check-install.sh [--dir /opt/haq-retail-os] [--project haq-retail-os]
set -uo pipefail

dir=/opt/haq-retail-os
project=haq-retail-os
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir="$2"; shift 2 ;;
        --project) project="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

compose() { docker compose --project-name "$project" --project-directory "$dir" -f "$dir/compose.yaml" --env-file "$dir/.env" "$@"; }
env_value() { grep -E "^$1=" "$dir/.env" | head -1 | cut -d= -f2-; }
failures=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; failures=$((failures + 1)); }
as_superuser() { compose exec -T db psql -v ON_ERROR_STOP=1 -U postgres -d retail -tAc "$1" 2>&1; }
as_app() { compose exec -T -e PGPASSWORD="$(env_value RETAIL_APP_PASSWORD)" db psql -v ON_ERROR_STOP=1 -h 127.0.0.1 -U retail_app -d retail -tAc "$1" 2>&1; }

echo "Checking the shop server in $dir"

# 1. Every service runs, and every one-shot job finished cleanly.
for service in db backup app queue scheduler web; do
    if [ "$(compose ps --status running -q "$service" | wc -l)" -ge 1 ]; then pass "$service is running"; else fail "$service is not running"; fi
done
for job in roles migrate certificate; do
    status="$(compose ps -a --format '{{.Service}} {{.State}} {{.ExitCode}}' | awk -v s="$job" '$1 == s { print $2 " " $3 }')"
    if [ "$status" = "exited 0" ]; then pass "the $job job finished cleanly"; else fail "the $job job did not finish cleanly ($status)"; fi
done

# Reboot recovery (D46): every long-running service restarts by itself, and Docker starts with the server.
norestart=""
for id in $(compose ps --status running -q); do
    policy="$(docker inspect "$id" --format '{{.Name}} {{.HostConfig.RestartPolicy.Name}}')"
    case "$policy" in *" unless-stopped"|*" always") ;; *) norestart="$norestart ${policy%% *}" ;; esac
done
if [ -z "$norestart" ]; then pass "every running service restarts by itself"; else fail "these services do not restart by themselves:$norestart"; fi
if ! command -v systemctl >/dev/null || systemctl is-enabled docker.service >/dev/null 2>&1; then pass "Docker starts with the server"; else fail "Docker does not start with the server (systemctl enable docker)"; fi

# 2. No running container holds the owner's password (check D).
owner_password="$(cat "$dir/secrets/owner_password")"
leaked=0
for id in $(compose ps --status running -q); do
    if docker inspect "$id" --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -q -F "$owner_password"; then leaked=1; fi
    if docker inspect "$id" --format '{{range .Mounts}}{{println .Source}}{{end}}' | grep -q 'owner_password'; then leaked=1; fi
done
if [ "$leaked" = 0 ]; then pass "no running container holds the owner's password"; else fail "a running container holds the owner's password"; fi

# 3. HTTPS answers with the shop's own certificate.
https_port="$(env_value HTTPS_PORT)"
name="$(env_value SERVER_NAME)"
ca="$(mktemp)"
compose exec -T web cat /etc/haq/tls/ca.crt > "$ca"
code="$(curl -s -o /dev/null -w '%{http_code}' --cacert "$ca" --resolve "$name:$https_port:127.0.0.1" "https://$name:$https_port/up")"
if [ "$code" = 200 ]; then pass "HTTPS answers with a certificate the shop's authority signed"; else fail "HTTPS did not answer as expected (HTTP $code)"; fi

# New devices fetch the setup page and the authority's certificate over plain HTTP, before they trust it (D59).
http_port="$(env_value HTTP_PORT)"
served="$(mktemp)"
setup="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${http_port:-80}/setup")"
curl -s -o "$served" "http://127.0.0.1:${http_port:-80}/ca.crt"
if [ "$setup" = 200 ] && cmp -s "$ca" "$served"; then pass "new devices get the setup page and the shop's certificate at http://<server>/setup"; else fail "the setup page or the certificate is not served over HTTP (setup: HTTP $setup)"; fi
rm -f "$ca" "$served"

# 4. The starter catalogue loads a second time with no change (check E, gate G2).
counts() { as_superuser "SELECT (SELECT count(*) FROM categories), (SELECT count(*) FROM picklist_values), (SELECT count(*) FROM brands), (SELECT count(*) FROM product_models), (SELECT count(*) FROM category_templates)"; }
before="$(counts)"
output="$(compose exec -T app php artisan catalogue:seed 2>&1)"
after="$(counts)"
if [ "$before" = "$after" ] && echo "$output" | grep -q 'Nothing to change'; then
    pass "the seed loads a second time with no change ($after)"
else
    fail "loading the seed again changed something ($before -> $after)"
fi
if [ "$after" = "34|568|74|29|28" ]; then pass "the catalogue holds 34 categories, 568 pick-list values, 74 brands, 29 models and 28 templates"; else fail "the catalogue counts are $after"; fi

# 5. Append-only holds against a deliberately widened grant (check D, gate G2).
as_superuser "GRANT UPDATE, DELETE, TRUNCATE ON audit_log, audit_sensitive TO retail_app" > /dev/null
for statement in "UPDATE audit_log SET action = 'tampered'" "DELETE FROM audit_log" "TRUNCATE audit_log CASCADE"; do
    # psql exits non-zero when the trigger refuses, so read its answer before matching it.
    answer="$(as_app "$statement")"
    if echo "$answer" | grep -q 'the table is append-only'; then
        pass "with the grant widened, the trigger still refuses: $statement"
    else
        fail "with the grant widened, this went through: $statement"
    fi
done

# 6. The nightly grant check notices the widened grant (check D).
if compose exec -T app php artisan db:mutability --check > /dev/null 2>&1; then
    fail "the grant check missed the widened grant"
else
    pass "the grant check reports the widened grant"
fi

# Put the grants back by rerunning the migration job, which applies the mutability matrix.
compose run --rm migrate > /dev/null 2>&1
if compose exec -T app php artisan db:mutability --check > /dev/null 2>&1; then pass "rerunning the migration job puts the grants back"; else fail "the grants are still wrong"; fi

# 7. The audit trail verifies, untouched by the attempts above.
if compose exec -T app php artisan audit:verify > /dev/null 2>&1; then pass "every audit chain and root verifies"; else fail "the audit trail does not verify"; fi

echo
if [ "$failures" = 0 ]; then
    echo "Every check passed."
else
    echo "$failures checks failed."
    exit 1
fi
