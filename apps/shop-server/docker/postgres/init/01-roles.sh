#!/bin/bash
# Creates or updates the three database roles and the databases (spec section 4) [F3]. Safe to run again.
# The development PostgreSQL runs it once, when its volume is first made; CI runs it against its service
# container; a shop runs it as the one-shot `roles` job, so the database container itself never holds
# the owner's password.
#
#   retail_owner      owns the tables; used only by the one-shot migration job
#   retail_app        runs the shop: what it may do to each table comes from the mutability matrix
#   retail_reporting  reads cost-safe views only
#
# Passwords come from RETAIL_OWNER_PASSWORD, RETAIL_APP_PASSWORD and RETAIL_REPORTING_PASSWORD, or from the
# files named by the same variables with _FILE added. The connection comes from POSTGRES_USER and the
# usual PG* variables (and POSTGRES_PASSWORD_FILE for the superuser's password).
set -euo pipefail

for name in RETAIL_OWNER_PASSWORD RETAIL_APP_PASSWORD RETAIL_REPORTING_PASSWORD; do
    file_var="${name}_FILE"
    if [ -n "${!file_var:-}" ]; then
        export "$name"="$(cat "${!file_var}")"
    fi
done
if [ -n "${POSTGRES_PASSWORD_FILE:-}" ] && [ -z "${PGPASSWORD:-}" ]; then
    export PGPASSWORD="$(cat "$POSTGRES_PASSWORD_FILE")"
fi
: "${RETAIL_OWNER_PASSWORD:?}" "${RETAIL_APP_PASSWORD:?}" "${RETAIL_REPORTING_PASSWORD:?}"
databases="${RETAIL_DATABASES:-retail retail_test retail_concurrency retail_e2e}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v owner_password="$RETAIL_OWNER_PASSWORD" \
    -v app_password="$RETAIL_APP_PASSWORD" \
    -v reporting_password="$RETAIL_REPORTING_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I', r) FROM unnest(ARRAY['retail_owner', 'retail_app', 'retail_reporting']) AS r
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) \gexec
ALTER ROLE retail_owner LOGIN PASSWORD :'owner_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE retail_app LOGIN PASSWORD :'app_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
ALTER ROLE retail_reporting LOGIN PASSWORD :'reporting_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
SQL

for db in $databases; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -v db="$db" <<'SQL'
SELECT format('CREATE DATABASE %I OWNER retail_owner', :'db')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO retail_app, retail_reporting;
SQL
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
ALTER SCHEMA public OWNER TO retail_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO retail_app, retail_reporting;
SQL
done
