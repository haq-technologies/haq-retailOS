# CLAUDE.md

Standing instructions for Claude Code in this repository. Keep this file short; the detail lives in the documents it points to.

## The product

HAQ Retail OS, by HAQ Technology: a point-of-sale system for phone-accessory shops in Nigeria. Each branch has one mini-PC server running Laravel and PostgreSQL in Docker on the shop's LAN; counters are Windows touchscreen PCs running Chrome in kiosk mode. A shop must be able to trade with no internet. The tiers are Core and Pro.

## Where we are

Phases 0 and 1 are closed and locked (gates G0 and G1 passed on 6 November 2026, D19). Phase 2, the foundation, was built on 6 November 2026 (D20 to D29), and the product owner passed gate G2 (D27), so Phase 2 is closed. Phase 3, the counter and sale core, was built on 6 November 2026 (D30 to D36); its gate G3 is a software dress rehearsal on two virtual machines (docs/operations/g3-software-rehearsal.md, D60). Phase 4's owner features started on 7 November 2026, before G3, at the product owner's go (D39), and all five are built (D40 to D46): the back office and cost, flags and alerts, the review queues, prices and reports, and recovery (the lost window, server health and reboot recovery); a review against the spec added the audit log to read and the counter's speed (D47), and its open questions are in PLAN.md. The hardening is done (D50, D55): the cost-leak scanner, the off-site copy, the upgrade-path test, and the acceptance checks written down against their tests (docs/operations/acceptance-checks.md). Phase 5's tools were built on 10 November 2026 (D56, D57): the measured pilot in the back office, removed entirely on 19 November 2026 (D95), and the owner's data export. There is no pilot (D67): the shop goes live with a one-week safety net, the notebook kept as a backup (docs/operations/safety-week.md), and the go-live basics (voids, returns, the day-end close with the Z-report, paid-outs, drops and top-ups; checks F1 to F6) were built on 10 November 2026 (D68). A G3 practice on this Mac on 11 November 2026 passed; it found that a restore onto the spare cost the counters their trust and enrolment, so the shop's certificate authority and app key are now kept beside the backups (D69). Release 2026.11.1 was signed and published, its images are tagged by version (D70), and gates G3 and G4 passed on it on 11 November 2026 (D71). A readiness QA review the same day led to release 2026.11.2, the release that goes live (D72 to D75): a counter's clock never stops a sale, a sale is never both voided and returned, `sudo haq users`, `pin` and `deactivate`, and capped logs. The product owner signed and published 2026.11.2 the same day. A review on 14 and 15 November 2026 then found faults in 2026.11.2, so it does not go live (D76 to D79): a field in any sheet lost focus within 3 seconds, a receipt's barcode would not scan, a manager's PIN at Pay joined the cash received, a cost dated today was refused after midnight, and the tiles asked the brand only for power banks. All are fixed; New item is laid out for the screen (D80); releases name their commit and reach shops by channel, with main the only long branch (D81); and the largest front-end files are split (D82). The product owner signed 2026.11.3 on 15 November 2026 as a pre-release. On 17 November 2026 the product owner chose a simpler install: a shop's server may be a Windows 10 or 11 PC, installed by one setup program that hides the server in WSL2 (D86), with USB printers through the HAQ print bridge (D87) and the Windows parts in Go and Inno Setup (D88); the Ubuntu install stays, and go-live waits for the Windows setup (D89). A scanned code longer than 120 characters no longer refuses a sale (D83), and the branch key is made once even when two counters need it at once (D84). The spike on the product owner's own Windows 11 PC (D90) passed its key checks on 18 November 2026: the hidden server starts before anyone signs in, other devices reach it, and it reaches a print bridge. The Windows setup is built (the haq-server distribution, the HAQ Helper and the setup program, in apps/windows/), and main's build installed the shop on that PC on 19 November 2026; a shop with one PC now shares its counter between the cashier and the owner, who opens the back office from the name tiles, and on a Windows server `haq-helper server add-user` asks the PIN itself (D91). The setup now works on any 64-bit Windows 10 or 11, checking the PC itself rather than its version: a counter takes any of them, and the server needs Windows 11 or a Windows 10 updated since November 2022, with virtualisation on (D92). A review of the sale path on 19 November 2026, with the 18 browser tests of sales passing, made New item follow the catalogue and keep a scanned code (D93), and the tiles ask the brand in every category with a brand, its own brands first, ending at a New tile, with search finding categories and brands (D94). On 20 November 2026 three findings on staging changed the tiles (D102): a step's tile never adds an item, so every list shows the item, every step ends with New, and Skip brand shows every brand; and the brands at the tiles follow the shop's own items (D103): outside the five generated categories the brand is asked only when the items left differ, with only their brands, and a brand is added on the go in New item. A test of migrations and seeding on 20 November 2026 led to D104 to D107: the database container runs an init, a code-only rollback starts on a newer schema, a rollback floor at the release after 2026.11.3, and migrations that only go forward. A review of logging and error tracking on 20 November 2026 led to D115 to D119: no query's values or secret in a log, a request ID on every line and a reference in every 500, one fault written a few times a minute, a crash screen that reports its error and reloads, scheduled tasks that keep their reason, one plain error warning for the owner with `haq errors` for support, and `haq support-file` for a shop with no internet. Next: main's new build tried on the Windows 10 PC; the server's memory measured; the release integration (the setup and the distribution in each release, with their hashes in the signed manifest) and the install guide's Windows section; the shop owner's answers; then the release that carries it, signed and rehearsed (G3), the install at the shop, and the safety week. [PLAN.md](PLAN.md) has the phases, gates, status, the checklists and open items.

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
docs/operations/   install, restore, release and operations guides, the 52 checks and the G3 rehearsal
docs/archive/      superseded originals: spec v1.4 (.docx), locked catalogue v1. Read-only
tools/catalogue/   scripts that build and verify the catalogue
tools/docs/        the Markdown checker
apps/shop-server/  the shop server: Laravel, Inertia and React (its README has the commands and modules)
apps/windows/      the Windows setup: the server distribution, the HAQ Helper (Go) and the setup program (being built, D86)
compose.yaml       the development stack for the shop server
```

## How to work with the user

- The product owner builds this alone, working with Claude (D15). There is no other developer, designer or field lead, so never plan or assign work to one.
- Ask, do not assume. Shop practice, prices and what a catalogue entry means come from the user or the shop owner, so ask.
- When there is a better way than the spec, recommend it with its tradeoffs. Never change the design silently.
- Build for the people who use it: cashiers in a busy queue on a touchscreen, owners who are often away, a small support team.
- Software only (D60): never plan work, a check or a gate that needs physical hardware. Virtual machines stand in for the shop server, and the stand-in printer for receipt printers. The Windows setup is the exception: it is tried on the product owner's own Windows PC, never in a virtual machine (D90).
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

Pinned in D20: PHP 8.5, Laravel 13, PostgreSQL 18, Inertia 3, React 19 in TypeScript, Vite 8 and Tailwind CSS 4 (the spec's colour tokens are its only palette). Planned around it: the counter as a local-first client inside one Inertia page, Docker Compose, pgBackRest, MiniSearch, ESC/POS to network printers, Pest, Vitest and Playwright. The Windows parts add Go for the HAQ Helper and Inno Setup for the setup program (D88). Code goes in one GitHub monorepo (public until the owner makes it private, D10), developed trunk-based, with GitHub Actions CI on every pull request and images on GHCR. Releases are signed offline by a person; shop servers pull them against the signed manifest.

## Commands

From the repository root:

```
# Rebuild catalogue v1.1 (seed, workbook, Appendix C) from the locked v1 and the recorded decisions
python3 -I tools/catalogue/build_catalogue_v11.py docs/archive/starter-catalogue-v1.json docs/catalogue/starter-catalogue-v1.1.json docs/catalogue/starter-catalogue-v1.1.xlsx docs/catalogue/appendix-c-starter-catalogue-v1.1.md

# Verify the outputs against the seed and against v1
python3 -I tools/catalogue/verify_v11.py docs/archive/starter-catalogue-v1.json docs/catalogue/starter-catalogue-v1.1.json docs/catalogue/starter-catalogue-v1.1.xlsx docs/catalogue/appendix-c-starter-catalogue-v1.1.md

# Check every Markdown file: table columns and relative links
python3 -I tools/docs/check_markdown.py

# The release signing dry run (a throwaway key; the updater's own checks)
tools/release/dry-run.sh
```

CI also runs the upgrade-path test (tools/release/upgrade-path.sh, check E5, D55) as its own job: it builds a shop with sales on the end-of-Phase-3 code and upgrades it to the code under review. The restore drill (tools/backup/restore-drill.sh, check E1, D65) backs up a shop with sales, restores it and verifies the copy, in its own Compose project, so it also runs safely on this Mac. CI audits the PHP and JavaScript packages, scans every image, keeps a coverage floor and runs every Monday too (D65).

CI (.github/workflows/ci.yml) runs all four on every pull request. Publishing a signed release runs .github/workflows/release.yml, which tags its images with its version on GHCR (D70); it never runs on a pull request, so it is never a required check. It rebuilds the catalogue and fails if the committed files differ, so after changing the build script, rerun the build and commit its outputs.

The shop server runs only in Docker; PHP, Composer and Node are never run on the Mac itself. Its commands are in [apps/shop-server/README.md](apps/shop-server/README.md). The everyday ones, from the repository root:

```
apps/shop-server/docker/dev-setup.sh       # first time: build, install, migrate, start
docker compose up -d                       # start the stack; http://localhost:8080
docker compose exec app composer lint      # Pint
docker compose exec app composer analyse   # Larastan, level 9
docker compose run --rm migrate            # migrate as the owner role, then apply the mutability matrix
docker compose run --rm test               # Pest: architecture and feature tests, against retail_test
docker compose run --rm test test:concurrency   # the multi-process suite, against retail_concurrency
docker compose exec backup haq-backup.sh full  # a full backup now (also: test-restore)
docker compose exec vite npm run lint      # ESLint (also: npm run types, npm test)
apps/shop-server/e2e/run.sh                # Playwright: the counter in Chromium, against a fresh test shop in retail_e2e
```

CI also runs these on every pull request, with PostgreSQL 18, and builds every image (dev, app, web, db); on each merge to main it publishes app, web and db to GHCR. `apps/shop-server/deploy/staging.sh` installs the latest images on this Mac at https://localhost:8443, as a shop would (D16, D26); `--build` builds and installs the branch checked out, so a pull request is tried before it merges, and `--fresh` starts a brand-new shop (D96).

## Code structure (D23)

The shop server is a modular monolith. Each module is a namespace under app/ (Platform, Organisation, Audit, Settings, Identity, Catalogue, Cost, Shifts, Sales, Printing, Counter, Backups, Health, Reports and BackOffice, and later Sync) and owns its models, actions, policies, controllers and API Resources. A module uses only the modules beneath it, as listed in tests/Arch/ArchitectureTest.php; add a module there before writing it. Pages render only through App\Platform\Http\Page. Migrations stay in database/migrations, and every new table gets its rule in the mutability matrix (app/Platform/Database/Mutability/Matrix.php) or the migration fails.

## Design

Apple's system colours with fixed roles, the Inter typeface, and responsive layouts at three sizes: compact under 600px, medium 600 to 1023px, large from 1024px. The tokens and contrast rules are in spec section 15 (Colour tokens). Never put white text on Apple's light green #34C759, and never let colour alone carry meaning.

## Rules for files

- Never edit anything in docs/archive/.
- Never hand-edit generated files (the catalogue .xlsx and Appendix C). Change the data in tools/catalogue/build_catalogue_v11.py, then rerun the build and verify commands.
- Name new documents in lowercase kebab-case.
- Never commit secrets, signing keys, .env files, shop data or personal data (names, phone numbers, email addresses).

## Git

- Remote: https://github.com/haq-technologies/haq-retailOS. It stays public until the owner makes it private (D10), so every push publishes. Commit with the GitHub noreply email set in this repository's git config, never a personal address, and check each diff for secrets, shop data and personal data before pushing.
- Main is the only long branch (D81): no develop branch, and no test or staging branch, because a release passes through its stages as the same images. Branch from main and keep branches short-lived. Merge only through a pull request with green CI, and only after the product owner has tried its build on staging (`apps/shop-server/deploy/staging.sh --build`) and said to merge it (test before merge, D96); never push directly to main. Shops never run main: they take only the releases the product owner signs. A fix for a release already out, when main cannot be released, is merged to main first and cherry-picked to a `release/YEAR.MONTH` branch cut from that release's tag ([docs/operations/releases.md](docs/operations/releases.md)). No approval is required, because one person builds this (D15) and GitHub does not let an author approve their own pull request. GitHub enforces this with the "Protect main" ruleset: a pull request, every CI check green and up to date (D66), squash merges only, no force-push or deletion, and no bypass. A new CI job becomes a required check once it is on main; add it to the ruleset then, never before, or every pull request waits for it.
- End every commit message with the co-author line for Claude.

## Environment notes (6 November 2026)

This Mac is the development, staging and test machine; there is no office mini-PC (D16). It has git, Node and the GitHub CLI (gh 2.103.3, installed 5 November 2026). Docker runs through Colima, installed on 6 November 2026 from the official release files (Colima 0.10.3, Lima 2.2.1, the Docker CLI 29.9.3, Compose 5.7.0 and buildx 0.38.2); after a restart, run `colima start` before using Docker. PHP, Composer and PostgreSQL are not installed on the Mac itself; Phase 2 runs them in containers. Neither openpyxl nor LibreOffice is installed, which is why the catalogue tools use only the Python standard library.
