#!/bin/sh
# Runs the browser tests on this computer, all in Docker: builds the assets, starts a fresh test shop in
# retail_e2e, drives the counter in Chromium, then stops the test server. From the repository root:
#   apps/shop-server/e2e/run.sh            (any arguments go to Playwright, such as -g "holds a sale")
set -eu
cd "$(dirname "$0")/../../.."
docker compose rm -sf e2e-server >/dev/null 2>&1 || true
docker compose exec -T vite npm run build
status=0
docker compose run --rm e2e npx playwright test -c e2e/playwright.config.ts "$@" || status=$?
docker compose rm -sf e2e-server >/dev/null 2>&1 || true
exit "$status"
