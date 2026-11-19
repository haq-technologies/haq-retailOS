#!/bin/bash
# Staging on the product owner's computer (D16): the latest images from main, installed exactly as a shop
# would install them, beside the development stack. It answers at https://localhost:8443.
#
#   apps/shop-server/deploy/staging.sh [--tag main] [--local] [--build] [--fresh]
#
# --local uses images built on this computer (retail-os-app:local and the others) instead of pulling.
# --build builds those images first, from this checkout as it is, so a pull request's branch can be tried
#         before it is merged (test before merge, D96). It implies --local.
# --fresh removes the staging shop and all its data first, so the install is a brand-new shop.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../../.." && pwd)"
tag=main
build=0
fresh=0
args=()
while [ $# -gt 0 ]; do
    case "$1" in
        --tag) tag="$2"; shift 2 ;;
        --local) args+=(--registry retail-os); export SKIP_PULL=1; tag=local; shift ;;
        --build) build=1; args+=(--registry retail-os); export SKIP_PULL=1; tag=local; shift ;;
        --fresh) fresh=1; shift ;;
        *) echo "Unknown option $1" >&2; exit 2 ;;
    esac
done

project=haq-staging
dir="$HOME/.haq-staging"

if [ "$build" = 1 ]; then
    branch="$(git -C "$repo" rev-parse --abbrev-ref HEAD)"
    commit="$(git -C "$repo" rev-parse --short HEAD)"
    # Changes not yet committed are built too, and the version says so.
    if ! git -C "$repo" diff --quiet HEAD --; then commit="$commit-dirty"; fi
    for target in app web db; do
        echo "Building the $target image from $branch at $commit..."
        docker build -q -f "$repo/apps/shop-server/docker/Dockerfile" --target "$target" \
            --build-arg "APP_VERSION=$branch-$commit" --build-arg "SECURITY_UPDATES=$(date -u +%F)" \
            -t "retail-os/retail-os-$target:local" "$repo" >/dev/null
    done
fi

if [ "$fresh" = 1 ]; then
    echo "Removing the staging shop and its data, for a brand-new shop..."
    if [ -f "$dir/compose.yaml" ]; then
        (cd "$dir" && docker compose -p "$project" --profile standin down -v --remove-orphans) || true
    fi
    # Whatever the Compose file no longer names: the stand-in printer, one-shot jobs, volumes, the network.
    label="label=com.docker.compose.project=$project"
    containers="$(docker container ls -aq --filter "$label")"
    # shellcheck disable=SC2086 # one ID per word
    if [ -n "$containers" ]; then docker container rm -f $containers >/dev/null; fi
    for kind in volume network; do
        ids="$(docker "$kind" ls -q --filter "$label")"
        # shellcheck disable=SC2086 # one ID per word
        if [ -n "$ids" ]; then docker "$kind" rm $ids >/dev/null; fi
    done
    rm -rf "$dir"
fi

# Staging prints to a stand-in printer; a shop never runs it.
export COMPOSE_PROFILES=standin

exec "$here/install.sh" \
    --profile "$here/../docker/profile.example.json" \
    --name localhost --ip 127.0.0.1 \
    --dir "$dir" --project "$project" \
    --http-port 8081 --https-port 8443 \
    --tag "$tag" "${args[@]+"${args[@]}"}"
