#!/bin/bash
# Staging on the product owner's computer (D16): the latest images from main, installed exactly as a shop
# would install them, beside the development stack. It answers at https://localhost:8443.
#
#   apps/shop-server/deploy/staging.sh [--tag main] [--local]
#
# --local uses images built on this computer (retail-os-app:local and the others) instead of pulling.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
tag=main
args=()
while [ $# -gt 0 ]; do
    case "$1" in
        --tag) tag="$2"; shift 2 ;;
        --local) args+=(--registry retail-os); export SKIP_PULL=1; tag=local; shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

# Staging prints to a stand-in printer; a shop never runs it.
export COMPOSE_PROFILES=standin

exec "$here/install.sh" \
    --profile "$here/../docker/profile.example.json" \
    --name localhost --ip 127.0.0.1 \
    --dir "$HOME/.haq-staging" --project haq-staging \
    --http-port 8081 --https-port 8443 \
    --tag "$tag" "${args[@]+"${args[@]}"}"
