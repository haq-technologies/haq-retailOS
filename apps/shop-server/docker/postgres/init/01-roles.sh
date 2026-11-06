#!/bin/bash
# Creates the three database roles and the databases (spec section 4) [F3]. The PostgreSQL image runs it
# once, when the database volume is first created; CI runs it against its service container.
#
#   retail_owner      owns the tables; used only by the one-shot migration job
#   retail_app        runs the shop: what it may do to each table comes from the mutability matrix
#   retail_reporting  reads cost-safe views only
#
# Passwords come from the environment: RETAIL_OWNER_PASSWORD, RETAIL_APP_PASSWORD and
# RETAIL_REPORTING_PASSWORD. Connection settings come from POSTGRES_USER and the usual PG* variables.
set -euo pipefail

: "${RETAIL_OWNER_PASSWORD:?}" "${RETAIL_APP_PASSWORD:?}" "${RETAIL_REPORTING_PASSWORD:?}"
databases="${RETAIL_DATABASES:-retail retail_test retail_concurrency}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
    -v owner_password="$RETAIL_OWNER_PASSWORD" \
    -v app_password="$RETAIL_APP_PASSWORD" \
    -v reporting_password="$RETAIL_REPORTING_PASSWORD" <<'SQL'
CREATE ROLE retail_owner LOGIN PASSWORD :'owner_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE retail_app LOGIN PASSWORD :'app_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
CREATE ROLE retail_reporting LOGIN PASSWORD :'reporting_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT;
SQL

for db in $databases; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -v db="$db" <<'SQL'
CREATE DATABASE :"db" OWNER retail_owner;
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db" TO retail_app, retail_reporting;
SQL
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<'SQL'
ALTER SCHEMA public OWNER TO retail_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO retail_app, retail_reporting;
SQL
done
