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
docker compose down           # stop (the database volume is kept)
docker compose logs -f app    # follow a service's log
```

## Check it

From the repository root, with the stack running. CI runs the same checks on every pull request.

```
docker compose exec app composer lint       # Pint (code style)
docker compose exec app composer analyse    # Larastan, level 9
docker compose exec app composer test       # Pest, against the retail_test database
docker compose exec vite npm run lint       # ESLint
docker compose exec vite npm run types      # TypeScript
docker compose exec vite npm test           # Vitest
```

Other commands run the same way: `docker compose exec app php artisan …`, `docker compose exec app composer …` and `docker compose exec vite npm …`. For the database, use `docker compose exec db psql -U retail`.

## What is where

| Path | What it is |
|---|---|
| `app/` | The Laravel application |
| `config/` | Every setting. Laravel's defaults are not merged in, so Redis and the other unused drivers stay out |
| `database/migrations/` | The schema. Every application table has a ULID key (D21) |
| `resources/js/` | The React side: `app.tsx` boots Inertia, `pages/` holds the pages |
| `resources/css/tokens.css` | The colour, type and layout tokens from spec section 15. Only these colours exist in the app |
| `tests/Feature/` | Pest tests, including the product invariants |
| `docker/` | The PHP image, the nginx config, the development database setup and `dev-setup.sh` |

## Rules built in so far

- PostgreSQL holds sessions, jobs and the cache; there is no Redis (spec section 4). A test fails if a Redis setting or client appears.
- Every application table has a ULID primary key. A test fails on any other key.
- Times are UTC, in PHP and in PostgreSQL. A test checks both.
- Queued jobs dispatch only after the transaction commits, so a rolled-back sale never prints. A test checks the setting.
- Every PHP file declares strict types (Pint enforces it), so a value such as a kobo amount is never coerced silently.
- On a shop server, commands that wipe the database refuse to run.
- Pages receive explicit arrays or API Resources, never Eloquent models, so cost never reaches a role that may not see it. For now it is a convention; an automated check comes with the first model.
