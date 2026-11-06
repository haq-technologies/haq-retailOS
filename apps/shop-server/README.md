# Shop server

The HAQ Retail OS server that runs in each branch, on the shop's own network: Laravel 13 and PostgreSQL 18, with the counter and the back office in React through Inertia. The spec is [docs/spec/retail-os-spec-v1.5.md](../../docs/spec/retail-os-spec-v1.5.md), and the build order is in [PLAN.md](../../PLAN.md).

**Status:** Phase 2, the foundation. The stack runs end to end, but there is nothing to sell yet.

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
| `app/` | The modules (D23): `Platform` holds the shared building blocks, such as the mutability matrix and the `Page` helper; `Organisation` holds the shop, its branches and terminals; `Audit` the hash chains, roots and spool; `Settings` the settings, their history and the shop profile tool; `Identity` name tiles and PINs, acting-user tokens, approvals and counter enrolment; `Catalogue` the categories, pick-lists, brands, models, items and the one name routine. `Http/` and `Providers/` are Laravel's glue |
| `config/` | Every setting. Laravel's defaults are not merged in, so Redis and the other unused drivers stay out |
| `database/migrations/` | The schema. Every application table has a ULID key (D21) |
| `resources/js/` | The React side: `app.tsx` boots Inertia, `pages/` holds the pages |
| `resources/css/tokens.css` | The colour, type and layout tokens from spec section 15. Only these colours exist in the app |
| `tests/Arch/` | Architecture tests: module boundaries, the Page rule, strict types |
| `tests/Feature/` | Pest tests, each in a rolled-back transaction, including the product invariants and the mutability matrix |
| `tests/Concurrency/` | The multi-process suite (spec section 14): worker processes released together, committed transactions, a real PostgreSQL, its own database |
| `docker/` | The PHP image, the nginx config, the development database setup and `dev-setup.sh` |

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
