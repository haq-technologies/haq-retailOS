#!/bin/sh
# The browser tests' server: a fresh shop in retail_e2e, the print queue, the stand-in printer, and the app
# on port 8000 with the built assets. Used by the e2e Compose service and by CI.
set -eu
cd "$(dirname "$0")/.."
php tests/Browser/setup.php
php artisan queue:work --queue=print,default --sleep=1 --tries=3 >storage/logs/e2e-queue.log 2>&1 &
php artisan printing:fake-printer --port=9100 --log=storage/logs/e2e-printer.log >/dev/null 2>&1 &
export PHP_CLI_SERVER_WORKERS=6
exec php artisan serve --host=0.0.0.0 --port=8000
