# CLAUDE.md

Standing instructions for Claude Code in this repository. Keep this file short; the detail lives in the documents it points to.

## The product

HAQ Retail OS, by HAQ Technology: a point-of-sale system for phone-accessory shops in Nigeria. Each branch has one mini-PC server running Laravel and PostgreSQL in Docker on the shop's LAN; counters are Windows touchscreen PCs running Chrome in kiosk mode. A shop must be able to trade with no internet. The tiers are Core and Pro.

## Where we are

Phase 0 (decide and discover), as of 5 November 2026. There is no application code yet. [PLAN.md](PLAN.md) has the phases, gates, status, Phase 0 checklist and open items.

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
```

## How to work with the user

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
- Every table has ULID primary keys, and every sales row carries branch_id and terminal_id (section 4).
- There is no Redis: sessions, jobs and cache live in PostgreSQL (section 4).
- Concurrency, crash and restore tests run as multi-process tests on a real PostgreSQL; RefreshDatabase and DatabaseTransactions never count for them (section 14).

## Stack (planned)

Laravel, PostgreSQL, Inertia and React (the counter is a local-first client inside one Inertia page), Docker Compose, pgBackRest, MiniSearch, ESC/POS to network printers, Pest, Vitest and Playwright. Code goes in one GitHub monorepo (public until the owner makes it private, D10), developed trunk-based, with GitHub Actions CI on every pull request and images on GHCR. Releases are signed offline by a person; shop servers pull them against the signed manifest.

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

CI (.github/workflows/ci.yml) runs all three on every pull request. It rebuilds the catalogue and fails if the committed files differ, so after changing the build script, rerun the build and commit its outputs. Application commands will be added here once the code exists.

## Design

Apple's system colours with fixed roles, the Inter typeface, and responsive layouts at three sizes: compact under 600px, medium 600 to 1023px, large from 1024px. The tokens and contrast rules are in spec section 15 (Colour tokens). Never put white text on Apple's light green #34C759, and never let colour alone carry meaning.

## Rules for files

- Never edit anything in docs/archive/.
- Never hand-edit generated files (the catalogue .xlsx and Appendix C). Change the data in tools/catalogue/build_catalogue_v11.py, then rerun the build and verify commands.
- Name new documents in lowercase kebab-case.
- Never commit secrets, signing keys, .env files, shop data or personal data (names, phone numbers, email addresses).

## Git

- Remote: https://github.com/haq-technologies/haq-retailOS. It stays public until the owner makes it private (D10), so every push publishes. Commit with the GitHub noreply email set in this repository's git config, never a personal address, and check each diff for secrets, shop data and personal data before pushing.
- Branch from main and keep branches short-lived. Merge only through a pull request with green CI; never push directly to main. Until the developers join, no approval is required, because GitHub does not let an author approve their own pull request; after that, one review is required (D6). GitHub enforces this with the "Protect main" ruleset: a pull request, the docs check green and up to date, squash merges only, no force-push or deletion, and no bypass.
- End every commit message with the co-author line for Claude.

## Environment notes (5 November 2026)

This Mac has git, Node and the GitHub CLI (gh 2.103.3, installed 5 November 2026). Docker, PHP, Composer and PostgreSQL are not installed, and neither are openpyxl or LibreOffice, which is why the catalogue tools use only the Python standard library.
