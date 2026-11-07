# Shop server

The HAQ Retail OS server that runs in each branch, on the shop's own network: Laravel 13 and PostgreSQL 18, with the counter and the back office in React through Inertia. The spec is [docs/spec/retail-os-spec-v1.5.md](../../docs/spec/retail-os-spec-v1.5.md), and the build order is in [PLAN.md](../../PLAN.md).

**Status:** Phases 2 and 3 are built (the foundation, then the counter and sale core); gate G3 waits for the pilot hardware. Phase 4's owner features are built, recovery included; the shadow pilot and the hardening wait for G3 (PLAN.md).

## Run it on the development computer

Everything runs in Docker (through Colima on the product owner's computer: run `colima start` after a restart). PHP, Composer, Node and PostgreSQL are never installed on the computer itself.

From the repository root:

```
# First time only: build the image, install packages, create .env and the app key, start, migrate
apps/shop-server/docker/dev-setup.sh

# Every day
docker compose up -d          # start; open http://localhost:8080
docker compose run --rm migrate   # migrate as the owner role, then apply the mutability matrix
docker compose down           # stop (the database volume is kept)
docker compose logs -f app    # follow a service's log
```

## Set up a shop

A shop profile describes the shop, this server's branch, its counters (and which have a cash drawer) and the receipt text. It holds shop data, so it never goes into Git (`*.profile.json` and `profiles/` are ignored).

```
docker compose exec app php artisan shop:make-profile                      # before the visit; asks for what it needs
docker compose exec app php artisan shop:apply-profile storage/app/profiles/<shop>.json   # at install; safe to run again
```

`docker/profile.example.json` shows the format with placeholder values.

Load the starter catalogue (safe to run again; a second run changes nothing):

```
docker compose exec app php artisan catalogue:seed
```

Then add the people and enrol each counter (spec section 3):

```
docker compose exec app php artisan shop:add-user "Amina" --role=cashier   # asks for the PIN twice
docker compose exec app php artisan shop:enrol-terminal T01                # a one-time code; type it at /enrol on that counter
```

A cashier's or storekeeper's PIN is 4 to 6 digits; a manager's or owner's is 6, because it also approves (D24).

The owner's phone or laptop reaches the back office as a back-office device (D29): list it in the profile with a code such as D01 and `"kind": "back_office"` (or use `shop:make-profile --back-office=1`), then enrol it like a counter. It signs in the same way and never sells.

## Install a shop's server

A shop's server runs the images CI publishes to GHCR on every merge to main (D26). On the server (Ubuntu Server with Docker), from a checkout of this repository:

```
sudo apps/shop-server/deploy/install.sh --profile shop.profile.json --name haq-server.local --ip 192.168.1.10
```

It makes every secret on the server, pulls the images, starts the stack (the roles, migration and certificate jobs run first and exit), applies the profile and loads the starter catalogue. It also adds smartmontools and a systemd timer, haq-host-health, that writes the server's own report (SSD health and its time source) for the health page every 15 minutes (`deploy/host-health.sh`, D45). It is safe to run again. If the images are private, set `GHCR_USER` and `GHCR_TOKEN` (a read-only token) first.

Then check the install (gate G2; spec section 14, checks D and E):

```
sudo apps/shop-server/deploy/check-install.sh
```

It checks every service and one-shot job, that no running container holds the owner's password, HTTPS against the shop's authority, a second catalogue load changing nothing, append-only holding against a deliberately widened grant (and the grant check reporting it), and the audit trail. It puts back what it touches.

## Staging on this computer

```
apps/shop-server/deploy/staging.sh           # the latest images from main, at https://localhost:8443
apps/shop-server/deploy/staging.sh --local   # images built here: docker build ... --target app|web|db -t retail-os/retail-os-<target>:local
```

## The counter

Open `/counter` on an enrolled counter (home goes there). It signs in with the name tiles, keeps its own copy of the catalogue in the browser, and sells from it: the tiles, search, the scanner, the price pad, Pay, held sales and Awaiting transfer. Pay is F8, Hold F4 and New item F2 (D34).

The browser tests drive it in Chromium against a fresh test shop in the `retail_e2e` database, never the development one. From the repository root:

```
apps/shop-server/e2e/run.sh                 # all of them
apps/shop-server/e2e/run.sh -g "holds"      # the ones whose name matches
```

## The back office

Open `/back-office` on a back-office device (the owner's phone or laptop, enrolled with a code such as D01) or from a counter. It signs in with the same name tiles, and each person sees only what their role may open (spec section 3): the dashboard with profit in three parts and Needs your attention, Needs cost with the backfill, Prices (first sales and set prices), Review (typed brands and words, new items, barcodes, duplicates with merge and undo), Catalogue health, Flags, the Daily summary (on screen, printed on a counter's printer, and the sales exports), Lost sales after a restore (D44), the Audit log (D47), Server health for the owner (D45) and Shop settings. On the counter, My history shows each person their own actions (D47). Cost and profit reach only those who may see cost, in the API and in every export. The browser tests in `e2e/owner.spec.ts` drive it.

## Backups

pgBackRest archives WAL to the backup volume (in a shop, the second internal disk given to `install.sh --backup-dir`), and the `backup` service takes a weekly full and daily incrementals, with a weekly test restore that the scheduler verifies (D35). From the repository root, in development:

```
docker compose exec backup haq-backup.sh full            # a full backup now
docker compose exec backup haq-backup.sh test-restore    # the test restore now (the scheduler verifies it)
docker compose exec scheduler php artisan backups:status
docker compose exec scheduler php artisan health:check   # what Server health shows (the scheduler runs it every minute)
```

Server health in the back office (the owner's, D45) shows the WAL copy, the last backup and test restore, the disks and SSDs, the certificate, the clock, the version, the grant check and the audit chains, with a Back up now button; the owner's dashboard repeats its warnings. The nightly grant and audit checks run in the night window or at the next quiet moment if the server was off (catch-up), as the backups do. A clock before this version was made, or one that jumped back, stamps sales as unverified until the owner confirms the time there.

A shop's restore is `deploy/restore.sh`; docs/operations/restore-drill.md walks through it. After a restore each counter reports its journal (its last seven days of sales) before it sells again, and a manager re-enters the sales the restored copy lacks on Lost sales in the back office, under their original receipt numbers (D44). Updates come as signed releases through `deploy/update.sh` (docs/operations/releases.md).

## Printing

The server prints receipts to each counter's network printer on port 9100 (spec section 4); the profile gives each counter's printer. Until the pilot's printer arrives, the development stack runs a stand-in printer that writes each receipt as text to `storage/logs/fake-printer.log`, and staging runs one too. A shop never does.

## Check it

From the repository root, with the stack running. CI runs the same checks on every pull request.

```
docker compose exec app composer lint       # Pint (code style)
docker compose exec app composer analyse    # Larastan, level 9
docker compose run --rm test               # Pest: architecture and feature tests, against retail_test
docker compose run --rm test test:concurrency   # the multi-process suite, against retail_concurrency
docker compose exec vite npm run lint       # ESLint
docker compose exec vite npm run types      # TypeScript
docker compose exec vite npm test           # Vitest
```

Other commands run the same way: `docker compose exec app php artisan …`, `docker compose exec app composer …` and `docker compose exec vite npm …`. For the database, use `docker compose exec db psql -U postgres -d retail`.

## What is where

| Path | What it is |
|---|---|
| `app/` | The modules (D23): `Platform` holds the shared building blocks, such as the mutability matrix and the `Page` helper; `Organisation` holds the shop, its branches and terminals; `Audit` the hash chains, roots and spool; `Settings` the settings, their history and the shop profile tool; `Identity` name tiles and PINs, acting-user tokens, approvals and counter enrolment; `Catalogue` the categories, pick-lists, brands, models, items, the one name routine, new items made at the counter and what the counter learns (barcodes, aliases); `Cost` cost entries; `Shifts` shifts per drawer; `Sales` Finish, receipt numbers, epochs, prices, and the counter journal's report, lost sales and back entries after a restore; `Printing` receipts, the printers and the drawer log; `Counter` the counter's catalogue feed and API; `Backups` backup status and the after-restore epochs; `Health` the server's health page, the clock check and catch-up of the nightly checks; `Reports` the dashboard, profit in three buckets, Needs cost, flags, first-sale prices, catalogue health, the daily summary and the CSV exports; `BackOffice` the back office's API and who may open what. `Http/` and `Providers/` are Laravel's glue |
| `config/` | Every setting. Laravel's defaults are not merged in, so Redis and the other unused drivers stay out |
| `database/migrations/` | The schema. Every application table has a ULID key (D21) |
| `resources/js/` | The React side: `app.tsx` boots Inertia, `pages/` holds the pages |
| `resources/css/tokens.css` | The colour, type and layout tokens from spec section 15. Only these colours exist in the app |
| `tests/Arch/` | Architecture tests: module boundaries, the Page rule, strict types |
| `tests/Feature/` | Pest tests, each in a rolled-back transaction, including the product invariants and the mutability matrix |
| `tests/Concurrency/` | The multi-process suite (spec section 14): worker processes released together, committed transactions, a real PostgreSQL, its own database |
| `docker/` | The Dockerfile for every image (dev, app, web, db), the nginx and PHP settings, the database role script and `dev-setup.sh` |
| `deploy/` | A shop's Compose stack, `install.sh` and `staging.sh` |

## Rules built in so far

- PostgreSQL holds sessions, jobs and the cache; there is no Redis (spec section 4). A test fails if a Redis setting or client appears.
- Every application table has a ULID primary key. A test fails on any other key.
- Three database roles (spec section 4). The app runs as `retail_app`; only the one-shot `migrate` job holds the owner's password. Grants and triggers come from the mutability matrix in `app/Platform/Database/Mutability/Matrix.php`, applied after every migration; a table missing from it stops the migration. Triggers hold the rules even against a widened grant, and `db:mutability --check` runs nightly to report any drift.
- Times are UTC, in PHP and in PostgreSQL. A test checks both.
- Every action can be written to the audit trail (spec section 10): a hash chain per terminal and one per branch, a root over every chain head each minute, sensitive values in their own table, and failures spooled when their chain is busy. `audit:verify` checks it all, nightly.
- Tests only ever rebuild a database whose name ends in `_test` or `_concurrency`.
- Queued jobs dispatch only after the transaction commits, so a rolled-back sale never prints. A test checks the setting.
- Every PHP file declares strict types (Pint enforces it), so a value such as a kobo amount is never coerced silently.
- On a shop server, commands that wipe the database refuse to run.
- Pages receive explicit arrays or API Resources, never Eloquent models, so cost never reaches a role that may not see it. Pages render only through `App\Platform\Http\Page`, which refuses a model, and an architecture test stops any other use of Inertia.
