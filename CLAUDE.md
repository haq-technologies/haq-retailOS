# CLAUDE.md

Standing instructions for Claude Code in this repository. Keep this file short; the detail lives in the documents it points to.

## The product

HAQ Retail OS, by HAQ Technology: a point-of-sale system for phone-accessory shops in Nigeria. Each branch has one mini-PC server running Laravel and PostgreSQL in Docker on the shop's LAN; counters are Windows touchscreen PCs running Chrome in kiosk mode. A shop must be able to trade with no internet. The tiers are Core and Pro.

## Where we are

Phases 0 and 1 are closed and locked (gates G0 and G1 passed on 6 November 2026, D19). Phase 2, the foundation, started on 6 November 2026: the shop server's skeleton and development stack are in apps/shop-server/ (D20 to D22). [PLAN.md](PLAN.md) has the phases, gates, status, the Phase 2 checklist and open items.

## Sources of truth

1. [docs/spec/retail-os-spec-v1.5.md](docs/spec/retail-os-spec-v1.5.md): what to build. Tags say why a rule exists: [F1] to [F25] are engineering-teardown findings (Appendix A); [S#], [R#], [C#] and [D#] come from the v1.4 review; [Q#] and [FU#] are answers to its questions.
2. [docs/decisions.md](docs/decisions.md): every decision with its date and source. If it ever disagrees with the spec, the newer entry wins, and the spec is fixed in the same change.
3. [docs/catalogue/starter-catalogue-v1.1.json](docs/catalogue/starter-catalogue-v1.1.json): the only master for catalogue data. The .xlsx and the appendix beside it are generated.
4. [PLAN.md](PLAN.md): schedule, owners and open items.

The review that produced v1.5 is a Claude Doc: https://claude.ai/artifact/DW5VWtw3J6xgMg6xMSgKSY. Read or edit it only through the Claude Docs connector, never by web fetch. Its follow-up questions are labelled F1 to F10 there and FU1 to FU10 here.

## Folder map

```
README.md          entry point for people
CLAUDE.md          this file
PLAN.md            the living plan
docs/spec/         the product spec (v1.5)
docs/decisions.md  decision log
docs/catalogue/    starter catalogue v1.1: seed (master), review workbook, Appendix C (generated)
docs/operations/   hardware list and shop-visit checklist
docs/archive/      superseded originals: spec v1.4 (.docx), locked catalogue v1. Read-only
tools/catalogue/   scripts that build and verify the catalogue
tools/docs/        the Markdown checker
apps/shop-server/  the shop server: Laravel, Inertia and React (its README has the commands and modules)
compose.yaml       the development stack for the shop server
```

## How to work with the user

- The product owner builds this alone, working with Claude (D15). There is no other developer, designer or field lead, so never plan or assign work to one.
- Ask, do not assume. Shop practice, prices and what a catalogue entry means come from the user or the shop owner, so ask.
- When there is a better way than the spec, recommend it with its tradeoffs. Never change the design silently.
- Build for the people who use it: cashiers in a busy queue on a touchscreen, owners who are often away, a small support team.
- Record every new decision in docs/decisions.md (next ID, date, source), then update the spec and PLAN.md if they are affected.
- Scope follows the swap rule: a feature enters a phase only if something of equal size leaves (spec section 16). The never-cut list in spec section 13 is never traded away.
- Write documents in plain British English (colour, catalogue), in short sentences. Show money as ₦ in text and store it as integer kobo in data; write dates like 5 November 2026.

## Product invariants (never break these)

- Missing data never blocks a sale; the only hard stops are the deliberate ones in spec section 7.
- A receipt never prints a generic name such as "Others"; one name routine builds every printed name (section 6).
- Money is integer kobo, times are UTC, and the business day is set when a shift opens (section 4).
- The server recalculates every total and finalises every sale; Finish is idempotent, with a payload hash (section 7).
- Sales and their records are append-only, enforced by database roles, grants and triggers generated from the mutability matrix (section 5).
- Receipt numbers (T01-E1-000123) never repeat; a restore's epoch is one more than the highest known anywhere (section 4).
- Cost never leaves the server for roles that may not see it: API Resources only, never Eloquent models passed to Inertia; sensitive audit values go in a separate stream (sections 2 and 10).
- Every application table has a ULID primary key, and every sales row carries branch_id and terminal_id (section 4). Only Laravel's own queue, cache and session tables keep Laravel's keys (D21).
- There is no Redis: sessions, jobs and cache live in PostgreSQL (section 4).
- Concurrency, crash and restore tests run as multi-process tests on a real PostgreSQL; RefreshDatabase and DatabaseTransactions never count for them (section 14).

## Stack

Pinned in D20: PHP 8.5, Laravel 13, PostgreSQL 18, Inertia 3, React 19 in TypeScript, Vite 8 and Tailwind CSS 4 (the spec's colour tokens are its only palette). Planned around it: the counter as a local-first client inside one Inertia page, Docker Compose, pgBackRest, MiniSearch, ESC/POS to network printers, Pest, Vitest and Playwright. Code goes in one GitHub monorepo (public until the owner makes it private, D10), developed trunk-based, with GitHub Actions CI on every pull request and images on GHCR. Releases are signed offline by a person; shop servers pull them against the signed manifest.

## Commands

From the repository root:

```
# Rebuild catalogue v1.1 (seed, workbook, Appendix C) from the locked v1 and the recorded decisions
python3 -I tools/catalogue/build_catalogue_v11.py docs/archive/starter-catalogue-v1.json docs/catalogue/starter-catalogue-v1.1.json docs/catalogue/starter-catalogue-v1.1.xlsx docs/catalogue/appendix-c-starter-catalogue-v1.1.md

# Verify the outputs against the seed and against v1
python3 -I tools/catalogue/verify_v11.py docs/archive/starter-catalogue-v1.json docs/catalogue/starter-catalogue-v1.1.json docs/catalogue/starter-catalogue-v1.1.xlsx docs/catalogue/appendix-c-starter-catalogue-v1.1.md

# Check every Markdown file: table columns and relative links
python3 -I tools/docs/check_markdown.py
```

CI (.github/workflows/ci.yml) runs all three on every pull request. It rebuilds the catalogue and fails if the committed files differ, so after changing the build script, rerun the build and commit its outputs.

The shop server runs only in Docker; PHP, Composer and Node are never run on the Mac itself. Its commands are in [apps/shop-server/README.md](apps/shop-server/README.md). The everyday ones, from the repository root:

```
apps/shop-server/docker/dev-setup.sh       # first time: build, install, migrate, start
docker compose up -d                       # start the stack; http://localhost:8080
docker compose exec app composer lint      # Pint
docker compose exec app composer analyse   # Larastan, level 9
docker compose run --rm migrate            # migrate as the owner role, then apply the mutability matrix
docker compose run --rm test               # Pest, against the retail_test database
docker compose exec vite npm run lint      # ESLint (also: npm run types, npm test)
```

CI also runs these on every pull request, with PostgreSQL 18, and builds the PHP image.

## Code structure (D23)

The shop server is a modular monolith. Each module is a namespace under app/ (Platform, Organisation, Audit, Settings, Identity, Catalogue, and later Sales, Printing, Cost, Shifts, Reports, Sync) and owns its models, actions, policies, controllers and API Resources. A module uses only the modules beneath it, as listed in tests/Arch/ArchitectureTest.php; add a module there before writing it. Pages render only through App\Platform\Http\Page. Migrations stay in database/migrations, and every new table gets its rule in the mutability matrix (app/Platform/Database/Mutability/Matrix.php) or the migration fails.

## Design

Apple's system colours with fixed roles, the Inter typeface, and responsive layouts at three sizes: compact under 600px, medium 600 to 1023px, large from 1024px. The tokens and contrast rules are in spec section 15 (Colour tokens). Never put white text on Apple's light green #34C759, and never let colour alone carry meaning.

## Rules for files

- Never edit anything in docs/archive/.
- Never hand-edit generated files (the catalogue .xlsx and Appendix C). Change the data in tools/catalogue/build_catalogue_v11.py, then rerun the build and verify commands.
- Name new documents in lowercase kebab-case.
- Never commit secrets, signing keys, .env files, shop data or personal data (names, phone numbers, email addresses).

## Git

- Remote: https://github.com/haq-technologies/haq-retailOS. It stays public until the owner makes it private (D10), so every push publishes. Commit with the GitHub noreply email set in this repository's git config, never a personal address, and check each diff for secrets, shop data and personal data before pushing.
- Branch from main and keep branches short-lived. Merge only through a pull request with green CI; never push directly to main. No approval is required, because one person builds this (D15) and GitHub does not let an author approve their own pull request. GitHub enforces this with the "Protect main" ruleset: a pull request, the docs check green and up to date, squash merges only, no force-push or deletion, and no bypass.
- End every commit message with the co-author line for Claude.

## Environment notes (6 November 2026)

This Mac is the development, staging and test machine; there is no office mini-PC (D16). It has git, Node and the GitHub CLI (gh 2.103.3, installed 5 November 2026). Docker runs through Colima, installed on 6 November 2026 from the official release files (Colima 0.10.3, Lima 2.2.1, the Docker CLI 29.9.3, Compose 5.7.0 and buildx 0.38.2); after a restart, run `colima start` before using Docker. PHP, Composer and PostgreSQL are not installed on the Mac itself; Phase 2 runs them in containers. Neither openpyxl nor LibreOffice is installed, which is why the catalogue tools use only the Python standard library.
