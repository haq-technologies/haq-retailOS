# HAQ Retail OS: Plan

The living plan from today to the pilot go-live decision and on to the MVP. The spec ([docs/spec/retail-os-spec-v1.5.md](docs/spec/retail-os-spec-v1.5.md)) says what to build; this file says when, by whom, and what happens next. Decisions and their sources are in [docs/decisions.md](docs/decisions.md).

Last updated: 15 November 2026.

## Goal

Put the first shop live on HAQ Retail OS, with no pilot (D67): from install the app is the shop's record, with a one-week safety net in which the shop also keeps its notebook. Then ship the MVP that makes the product sellable, with the first paid shop around month 9.

Go-live needs (spec section 14, Go-live criteria):
- the go-live basics built: voids, returns, the day-end close with the Z-report, and paid-outs, drops and top-ups (D68);
- all 52 acceptance checks passing and written down (gate G4): the 46 of sections A to E (the original check D6 returns as F3, D49) and the 6 go-live basics of section F;
- gate G3, the software dress rehearsal, passed on the release that goes live;
- every receipt number used once, and no sale lost to a crash or power cut;
- in the safety week, the notebook and the app agree each evening, every difference traced, and anything traced to the app fixed before the notebook stops; then the owner's go to stop the notebook.

## Status

Week 1. Phases 0 and 1 are closed and locked: gates G0 and G1 passed on 6 November 2026 (D19). Phase 2, the foundation, was built on 6 November 2026, and the product owner passed gate G2 the same day (D27), so Phase 2 is closed. The pace check changed no date and no scope. Phase 3, the counter and sale core, started on 6 November 2026 at the product owner's go and was built the same day (D30 to D36). Gate G3 is a software dress rehearsal on virtual machines (D60), ready to run; the release signing key and the pilot server's read-only GHCR token were made by the product owner on 7 November 2026. Phase 4's owner features started on 7 November 2026, before G3, at the product owner's go (D39); all five are built and merged (D40 to D46): the fifth, recovery, at the product owner's go the same day (D44 to D46). A review against the spec added the audit log to read and the counter's speed (D47). Phase 5's tools were built on 10 November 2026 at the product owner's go (D56, D57): the measured pilot in the back office and the owner's data export; the 14 measured days themselves follow G4. The same day the plan became software only: no work, check or gate depends on physical hardware (D60). On 11 November 2026 release 2026.11.1 was published, and gates G3 and G4 passed on it (D71).

Done:
- Spec v1.4 and starter catalogue v1 reviewed, and every decision recorded.
- Spec v1.5 written, with the decisions applied and tagged, and signed off by the product owner on 6 November 2026 (D13).
- Starter catalogue v1.1 drafted with 466 phone models, built and verified by script, and re-confirmed by the shop owner at the shop visit.
- Project folder organised: README.md, CLAUDE.md, this plan, docs/ and tools/.
- GitHub: main is pushed to haq-technologies/haq-retailOS, and the first CI run passed. The "Protect main" ruleset allows changes only through a pull request with every CI check green (D66; the docs check alone until 10 November 2026), and blocks force-pushes and deletion. The repository stays public for now (D10), and commits use the GitHub noreply email.
- Design direction decided: Apple's system colours, the Inter typeface, and responsive layouts (D4, D5). The counter and the owner's back office are drawn on the new canvas (36 boards), and the counter decisions D7 to D9 and D11 are recorded.
- The shop visit is done, with no changes to catalogue v1.1, and the hardware list is locked (D17), so gate G0 is passed (6 November 2026).
- Wireframes signed off by the product owner, so gate G1 is passed (D19). The printed daily summary carries no profit or cost amounts (D18).
- Phase 2, the foundation (D20 to D26): the shop server in apps/shop-server/ as a modular monolith; the three database roles with grants and triggers from the mutability matrix; audit chains, roots and the spool; settings and the shop profile tool; identity with name tiles, PINs, acting-user tokens and bound approvals; the catalogue with the one name routine and the seeder; the multi-process test harness in CI; a shop's Docker stack with images on GHCR, the installer and staging. G2's criteria are met.

- Gate G2 passed on 6 November 2026 (D27); the identity timings accepted (D28); back-office devices for the owner's phone or laptop (D29).
- Phase 3, the counter and sale core (D30 to D36): Finish with gapless receipt numbers and signed epochs; prices and their checks; server printing with pending print and the drawer log; the catalogue feed in commit order; new items, barcode learning and aliases; held sales and Awaiting transfer; the local-first counter client; Playwright browser tests in CI; pgBackRest backups with the verified weekly test restore and the restore script; the signed release manifest and the updater; the rehearsal tooling. See the Phase 3 checklist below.
- Phase 5's tools, built early at the product owner's go (D56, D57): the measured pilot (the notebook's totals beside the app's, each difference traced, the five success measures read live, the owner's decision with the scorecard as it stood) and the owner's data export as one ZIP of spreadsheets. The measured pilot was removed entirely on 19 November 2026 (D95); the safety week's procedure is in docs/operations/safety-week.md.
- Phase 4's owner features, started before G3 at the product owner's go (D39), all five built on 7 November 2026 (D40 to D46): the back office with the owner's dashboard and cost entry with backfill; flags and Needs your attention; the review queues with merge and undo; first-sale prices, catalogue health, the daily summary on screen and printed, and the CSV exports; and recovery: restore epochs end to end with the lost window, server health with catch-up and the server clock, and reboot recovery. See the Phase 4 section below.

- A QA review of the whole codebase (10 November 2026; the QA report, a Claude Doc) found 10 product defects, 8 pipeline findings and 6 places where the documents drifted. Every one in the code and the documents is fixed on the branch fix/qa-report-findings, with a test for each (D61 to D65): manager approvals that survive parking and a slow transfer, a Finish with no answer that can never be sold twice, receipt counters that only go up, printing that never opens the drawer twice, the enrolment and PIN races, the Content Security Policy, digest-pinned updates, and in CI the image scan, the dependency audits, the coverage floor, the restore drill and a weekly run. Every CI job became a required check on main the same day (D66); the restore drill joins the list when this branch merges. Squash-only merges stay with the product owner.

- The pilot is gone (D67, 10 November 2026): no shadow pilot and no 14 measured days. The shop goes live with a one-week safety net, and the go-live basics move into the first release (D68).

Gates G3 and G4 passed on 11 November 2026 on release 2026.11.1 (D71): every acceptance check is written down, with A1 measured in the safety week (docs/operations/acceptance-checks.md).

A readiness QA review on 11 November 2026 (a Claude Doc) passed every suite and found that a counter whose clock is 25 or more days behind could not finish a sale, that a void and a return of one sale could both go through, that no PIN could be changed, and that the server's logs grew without limit. Release 2026.11.2 fixes all four (D72 to D75); its verdict was green for the safety week on three conditions: counter clocks set at install, 2026.11.2 installed before G5 and within 25 days of the install, and nothing merged to main but fixes until G5.

The product owner signed and published release 2026.11.2 the same day, the release that goes live; each of its images carries the version tag on GHCR, on the digests the manifest signs.

A review on 14 and 15 November 2026, asked for by the product owner after New item's fields lost focus, found five faults at the counter in release 2026.11.2 (D76): a field in any sheet lost focus within 3 seconds, a receipt's barcode would not scan on a real scanner, a manager's PIN typed at Pay joined the cash received, an open Returns refused a scanned receipt, and unprinted receipts were checked every 15 seconds. All five are fixed with tests. Two more faults showed while fixing them: a cost dated today was refused in the first hour after midnight in Lagos (D77), and the images' security updates were only as new as CI's build cache (D78). The tiles now ask the brand in every category with generated shapes, and ask it first (D79); until now only power banks did. New item is laid out for the screen, with no long scroll (D80), at the product owner's word before G5. The releases now reach shops in stages, by channel, and each names its commit (D81). The largest front-end files are split by what they do, with nothing redesigned (D82). So release 2026.11.2 does not go live: release 2026.11.3 does, once the product owner signs it and the G3 rehearsal passes on it.

The product owner signed and published release 2026.11.3 on 15 November 2026, as a pre-release.

On 17 November 2026 the product owner asked for a simpler install: a small shop may have only one Windows desktop, a scanner and a USB receipt printer. So a shop's server may now be a Windows 10 or 11 PC, installed by one setup program that hides the server in Windows' own Linux, WSL2 (D86). USB receipt printers work through the HAQ print bridge (D87), and the Windows parts are written in Go with an Inno Setup installer (D88). The Ubuntu mini-PC install stays as it is, and every release carries both ways. Go-live waits for the Windows setup: the first shop installs with the first release that carries it, once G3 passes on it (D89). While planning it, a scanned QR code longer than 120 characters was found to refuse the whole sale (D83), and two counters making their first sales at once could each make a branch key, so one's signed epochs no longer verified (D84); both are fixed.

Next: the Windows setup's last steps (the checklist below): main's build tried on the Windows 10 PC, the server's memory measured, the release integration and the install guide's Windows section; the shop owner's answers (Open items), including the brands of the 11 categories with none of their own (D94); then the release that carries the Windows setup, signed and rehearsed (G3), the install at the shop with it (docs/operations/install-a-shop.md), the safety week (docs/operations/safety-week.md) and G5.

## Team

One person builds HAQ Retail OS: the product owner, working with Claude (D15). There is no other developer, designer or field lead, and the dates below stay as they are, met with extra hours.

| Who | Owns |
|---|---|
| Product owner | Decisions, the shop owner relationship, contract and pricing; shop visits, install, training, the weekly update and the daily reconciliation in the pilot; signing releases |
| Claude | Code, tests, CI/CD, documents and wireframes, through pull requests, under the product owner's direction |

## Phases and gates

Weeks count from kickoff. A gate ends a phase only when its criteria are met.

| Phase | Weeks | Delivers | Gate |
|---|---|---|---|
| 0. Decide and discover | 1–2 | Decisions; spec v1.5; catalogue v1.1 re-confirmed; the shop visit; the GitHub repository and first CI workflow | **G0:** decisions recorded; v1.5 and v1.1 signed off; hardware chosen. Passed on 6 November 2026 |
| 1. Wireframes | 2–4 | Touch-first, responsive wireframes on a new canvas: the counter, then the owner's back office. No clickable prototype and no cashier timing before the build (D12) | **G1:** the product owner signs off the wireframes, and the counter interaction model is frozen. Passed on 6 November 2026 |
| 2. Foundation | 3–8 | Docker stack; database roles, grants and triggers generated from the mutability matrix; the one-shot migration job; identity (name tiles, PINs, acting-user tokens, bound approvals, idle lock); audit chains, roots and spool; settings and the profile tool; catalogue model, name routine and seeder; the multi-process PostgreSQL harness in CI; images pushed to GHCR; staging on the product owner's computer (D16) | **G2:** a clean Ubuntu virtual machine installs from a profile (D16); the seed loads twice with no change; append-only holds against a deliberately widened grant |
| 3. Counter and sale core | 6–12 | Counter client; Finish with idempotency, receipt counters, epochs, payments and print jobs; server printing with pending print and the drawer log where a drawer is fitted; Awaiting transfer; catalogue polling; counter journal; speed instrumentation; backups with a test restore; Playwright tests in CI; the release manifest and the updater | **G3 (week 12):** a software dress rehearsal on two virtual machines, installed by pulling from GHCR in under 2 hours: 300 scripted sales on two counters to the stand-in printer, the server powered off hard mid-sale and the printer stopped mid-print, and a restore onto the spare virtual machine (D60) |
| 4. Owner features, hardening, go-live basics | 13–20 | Install at the shop in week 13; one counter beside the notebook at agreed hours in weeks 13–18; a weekly release; the Needs-cost queue, backfill preview, three profit buckets, alerts, printed daily summary, review queues with merge and undo, catalogue health, CSV export, restore epochs end to end, the lost-window screen, health page, reboot recovery and catch-up scheduling. In weeks 17–20: the 46 checks automated and the cost-leak scanner, started before G3 (D50); the soak test and the install rehearsal run in software at G3 (D60) | **G4 (week 20):** all 46 checks pass and are written down |
| 5. Go-live and the safety week | after G4 | Install at the shop; the app is the record from the first sale; for one week the notebook too, compared each evening (D67) | **G5:** at the end of the safety week the owner confirms the notebook can stop |

## Timeline

```
Week                           1  2  3  4  5  6  7  8  9 10 11 12 13 14 15 16 17 18 19 20 21 22
0 Decide and discover         ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G0
1 Wireframes                   . ## ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G1
2 Foundation                   .  . ## ## ## ## ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G2
3 Counter and sale core        .  .  .  .  . ## ## ## ## ## ## ##  .  .  .  .  .  .  .  .  .  .  G3
4 Owner features, weekly       .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ## ## ## ##  .  .  .
4 Hardening, 46 checks         .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ##  .  .  G4
5 Go-live, safety week         .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  . ##  .  G5
CI/CD pipeline set-up         ## ## ## ## ## ## ## ## ## ## ## ##  .  .  .  .  .  .  .  .  .  .
Weekly releases from GitHub    .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ## ## ## ## ## ## ##
```

There is no pilot (D67): the shadow-pilot row is gone, and go-live follows G4, with the notebook kept for the first week only.

## CI/CD with Git, GitHub and Docker

Every change runs the full test suite against a real PostgreSQL before it can merge, and every release reaches a shop as Docker images pulled from GitHub and checked against a signed release manifest [D1, D2].

1. **Branch and pull request.** One long branch, main; short-lived branches off it; green CI to merge, with no review while one person builds it (D6, D15); no direct pushes. No develop branch and no branch per stage: a release passes through its stages as the same images (D81; docs/operations/releases.md).
2. **CI on every pull request.** Lint and static analysis (Pint, PHPStan, ESLint); unit tests (Pest, Vitest); concurrency, crash and restore tests on a real PostgreSQL; counter flows in Playwright on Docker Compose; the previous release's code against the new schema, and an upgrade from two versions back; the seed validator, the cost-leak scan, the dependency audits, an image scan, a coverage floor and the restore drill. The same run happens every Monday, with third-party actions pinned by commit (D65).
3. **Merge to main.** Docker images are built once and pushed to GHCR, pinned by digest.
4. **Staging.** The product owner's computer, with the stand-in printer and 5,000 test items, deploys from main (D16, D60).
5. **Tag a release.** A version, YEAR.MONTH.N, on one commit of main; its tag, its manifest and its images all name that commit (D81). A fix for a release already out, when main cannot be released, comes from a short release branch cut from that release's tag.
6. **Release manifest.** The commit, its image digests and its Compose file's hash, in one file the updater trusts.
7. **Offline signing.** A person signs the manifest with a hardware key; CI never holds the release key. Publishing the signed release tags its images with its version on GHCR, after the same checks the updater makes (D70).
8. **The shop pulls from GitHub.** Over a phone hotspot where the shop has no internet; a MiFi is optional [D3]. A release goes to the beta shop first, as a pre-release, then to every shop once it is marked the latest; each shop is on the stable, beta or pinned channel (D81).
9. **The updater on the shop server.** Verifies the signature, pulls every image, backs up, migrates, runs the health check, and rolls back the code only if the check fails.

| Phase | CI/CD work | Owner |
|---|---|---|
| 0 (weeks 1–2) | GitHub organisation and repository (public for now, D10), branch protection, and the first workflow: lint, unit tests, a PostgreSQL service | Claude |
| 2 (weeks 3–8) | Dockerfiles and Compose; images to GHCR on merge; staging on the product owner's computer, deploying from main; the multi-process PostgreSQL harness in CI | Claude |
| 3 (weeks 6–12) | Playwright counter tests in CI; the release manifest and the updater with code-only rollback; a signing dry run. G3 installs a virtual machine by pulling from GHCR | Claude; the product owner holds the signing key |
| 4 and 5 (weeks 13–22) | A weekly signed release pulled at the shop over a phone hotspot; the upgrade-path test; the restore drill in CI on every pull request and every Monday (built, D65) | Claude and the product owner |

Choices and their tradeoffs (runners, registry, environments, housekeeping) are in the review doc's CI/CD section and in spec section 4.

## Phase 0 and 1 checklist (closed and locked on 6 November 2026, D19)

- [x] Review spec v1.4 and catalogue v1, and record every decision (Claude, product owner)
- [x] Write spec v1.5 (Claude)
- [x] Draft starter catalogue v1.1 (Claude)
- [x] Organise the project folder (Claude)
- [x] Choose the hardware and lock the list: [docs/operations/hardware.md](docs/operations/hardware.md) (product owner, 6 November 2026, D17). No mini-PC is bought for testing; the product owner's computer stands in (D16)
- [x] Shop visit: the owner re-confirms catalogue v1.1; shelf walk; 20-item barcode test; two hours of observation; a week of sales counted from the notebook; a power and network survey (product owner). Done by 6 November 2026
- [x] Push the first commit to the public repository and protect main (Claude). The repository stays public for now (D10)
- [x] Touch-first, responsive wireframes in Apple's system colours on a new canvas (Claude, 6 November 2026): the counter, including the price-floor prompt, pending print, lost window, Pending transfers and the idle lock, and the owner's back office
- [x] Sign off the wireframes: gate G1 (product owner, 6 November 2026, D19)
- [x] Sign off spec v1.5 (product owner, 6 November 2026, D13)
- [x] Re-confirm catalogue v1.1 at the shop visit (shop owner). With the spec sign-off and the hardware choice, gate G0 is passed (6 November 2026)

## Phase 2 checklist

Closed on 6 November 2026, when the product owner passed gate G2 (D27). Phase 2 started the same day, at the product owner's go. Before it started:

- [x] Docker running on the product owner's computer, through Colima (Claude, 6 November 2026)
- [x] Enough free space on that computer for Docker and the Ubuntu virtual machine used at G2: with the development stack, staging and the virtual machine all in place, 33 GB were still free on 6 November 2026 (product owner)

Then, by week 8:

- [x] Docker Compose for development, with the shop server's skeleton in apps/shop-server/: Laravel, Inertia, React in TypeScript and the colour tokens, PostgreSQL for sessions, jobs and the cache, and CI for PHP, the frontend and the image (Claude, 6 November 2026, D20 to D22)
- [x] The Docker stack, with images pushed to GHCR and staging on the product owner's computer (Claude, 6 November 2026, D26). CI publishes app, web and db to GHCR on each merge to main, and staging at https://localhost:8443 pulls them from GHCR and passes every install check
- [x] Database roles, grants and triggers generated from the mutability matrix, and the one-shot migration job, with the nightly grant check and the code laid out as a modular monolith (Claude, 6 November 2026, D23)
- [x] Identity: name tiles, PINs, acting-user tokens, bound approvals and the idle lock, with counter enrolment, lockouts and terminal backoff (Claude, 6 November 2026, D24)
- [x] Audit chains, roots and the spool, with the shop, branches and terminals they hang on (Claude, 6 November 2026)
- [x] Settings and the profile tool, including the receipt text: `shop:make-profile` before the visit, `shop:apply-profile` at install, idempotent (Claude, 6 November 2026, D14)
- [x] The catalogue model, the name routine and the seeder for catalogue v1.1: every seed example prints exactly, the generators offer 127 shapes, and a second load changes nothing (Claude, 6 November 2026, D25)
- [x] The multi-process PostgreSQL test harness in CI: worker processes released together, committed transactions, its own database (Claude, 6 November 2026)
- [x] Gate G2, passed by the product owner on 6 November 2026 (D27), on this evidence (Claude):
  - A clean Ubuntu virtual machine installs from a profile: Ubuntu 24.04.5 LTS in Lima on the product owner's computer (D16), with Docker 29.1.3 from Ubuntu's packages. deploy/install.sh installed from the example profile in 14 seconds, with the images built from main at d4aebba (the GHCR packages are private; see open items). A second run changed nothing.
  - The seed loads twice with no change: 34 categories, 568 pick-list values, 74 brands, 29 models and 28 templates, unchanged by the second load.
  - Append-only holds against a deliberately widened grant: with UPDATE, DELETE and TRUNCATE granted on the audit tables, the triggers refused all three; the nightly grant check reported the grant; rerunning the migration job put it back; the audit trail verified.
  - deploy/check-install.sh ran all 18 checks, which passed, including that no running container holds the owner's password and that HTTPS verifies against the shop's own authority. A cashier enrolled a counter and signed in over HTTPS in production mode.

## Phase 3 checklist

Phase 3, the counter and sale core, started on 6 November 2026 at the product owner's go. In weeks 6 to 12:

- [x] Finish, with idempotency and a payload hash, gapless receipt numbers per terminal and epoch, signed epochs, payments, a pending print job, timings and the audit entry in one transaction; minimal shifts per drawer that set the business day and close themselves four hours after closing time; the multi-process crash and concurrency tests (Claude, 6 November 2026)
- [x] Prices: set prices, the last price or median pre-fill, the usual range with sibling inheritance, the floor and its bound manager approval, the confirm count per shift and the retype check, all enforced again at Finish (Claude, 6 November 2026, D30)
- [x] Server printing: ESC/POS receipts to port 9100 laid out as the receipt wireframe, with the Code 128 receipt number; pending print and retry; DUPLICATE reprints, logged; the drawer kick with cash sales, No sale and Opened with key, all logged; printer status; a stand-in printer for development and staging (Claude, 6 November 2026, D31)
- [x] The counter's API: a paged catalogue snapshot and the change feed in commit order; prices in bulk; new items with their guardrails (category first, similar items, typed brands and phone models pending, "Not in the list", Request-only mode and the denylist); barcode learning with the picker, junk codes, confirmation, conflicts and undo; aliases; opening a shift with its float (Claude, 6 November 2026, D32)
- [x] Held sales and Awaiting transfer on the server: park, the branch's strip with the 30-minute flag, resume on any counter, finish from any counter, and discards with a reason and a bound manager approval above the limit (Claude, 6 November 2026, D33)
- [x] The counter client: one Inertia page with the catalogue in IndexedDB, search (MiniSearch 7.2) in a Web Worker, the tiles with Skip and generated shapes, the price pad and its checks with manager approval, the colour row, Retail or Wholesale, cash, transfer, POS and split payments, Finish with its idempotency key, After Pay with reprint and Opened with key, hold and resume, Awaiting transfer, pending print, scanner detection and barcode learning, new items, No sale, opening the shift, the idle lock that keeps the sale, Reconnecting, the seven-day counter journal, the sale timings and a service worker for the app shell (Claude, 6 November 2026, D34)
- [x] Backups with pgBackRest 2.60: WAL to the second disk, a weekly full and daily incrementals with catch-up, the weekly test restore verified by the scheduler (row counts and every audit chain), the key and epochs kept beside the backups, `shop:after-restore`, and deploy/restore.sh, rehearsed on staging (Claude, 6 November 2026, D35; docs/operations/restore-drill.md)
- [x] Playwright 1.65 tests in CI: the counter in Chromium against a fresh test shop in retail_e2e, on every pull request; `apps/shop-server/e2e/run.sh` runs them on this computer (Claude, 6 November 2026)
- [x] The release manifest, offline signing and the updater: images by digest and the Compose file by hash, signed with an offline Ed25519 key; `update.sh` checks, pulls, backs up, switches, health-checks and rolls back the code only; a signing dry run in CI; rehearsed on staging (Claude, 6 November 2026, D36; docs/operations/releases.md). The product owner makes the real key
- [x] The rehearsal tooling: `shop:rehearse` (scripted sales on two counters at once, through the print queue) and `shop:rehearsal-check` (gapless numbers, whole sales, receipts, audit chains, grants) (Claude, 6 November 2026). Rehearsed on staging with the database and app killed mid-run; CI's upgrade-path test runs both
- [x] Gate G3: the software dress rehearsal on two virtual machines (product owner, Claude; D60; docs/operations/g3-software-rehearsal.md). Passed by the product owner on 11 November 2026 on release 2026.11.1, installed by its version tag on two VirtualBox machines with a Windows counter PC; every limit met, times not recorded (D71). The signing key and the GHCR token are ready (7 November 2026). Claude's practice on this computer (11 November 2026, main's images on two Lima machines) passed all five steps: installed in 6 minutes and the first sale at 12; 300 scripted sales in 5 min 19 s, gapless; after a hard power-off the counter sold again 58 s after power returned, with its open sale kept and the cut-off receipt reprinted as DUPLICATE; the restore onto the spare took 2 min 7 s, with the two lost sales re-entered from T01's journal. It found that the restore cost the counters their trust and enrolment (fixed, D69)

## Phase 4 owner features (started early, D39)

Phase 4's owner features started on 7 November 2026, before gate G3, at the product owner's go. In order:

- [x] The back office and cost: the back-office app (dashboard, navigation, Shop settings), the three profit buckets, the Needs-cost queue with the backfill preview and bulk apply, and who sees cost (Claude, 7 November 2026; D40)
- [x] Flags and alerts: the flag list (mark as seen, never deleted), the dashboard's Needs your attention, CSV export of flags (Claude, 7 November 2026; D41)
- [x] Review queues: brands, phone models and product words; new items and typed lines; learned barcodes, conflicts and search words; duplicates, with merge and undo (Claude, 7 November 2026; D42)
- [x] Prices and reports: first-sale prices, catalogue health, CSV export of items and sales, the daily summary on screen and printed (Claude, 7 November 2026; D43)
- [x] Recovery (Claude, 7 November 2026, at the product owner's go; D44 to D46). Already in place from Phase 3: the counter journal, shop:after-restore with signed epochs and the 1,000 jump, the backup status files and the weekly verified test restore.
  - [x] Restore epochs end to end and the lost window: each counter reports its journal before it sells again after a restore, its epoch raised past anything the journal shows; Lost sales in the back office, where a manager re-enters each lost sale as a back entry under its original receipt number; a fresh catalogue copy on every counter after a restore (D44)
  - [x] Server health: the backup, WAL, test restore, disk, SSD, certificate, clock, version, grants and audit checks, with Back up now, for the owner until the console exists; their warnings on the dashboard; catch-up of missed nightly checks at the next quiet moment; the server clock's unverified mode, which the owner confirms (D45)
  - [x] Reboot recovery: the "starting, please wait" page, a 503 while the database starts, restart policies and Docker at boot checked at install, the counter opening from its saved copy, and the server and counter PC start-up steps (D46; docs/operations/start-up.md)

- [x] Review of the owner features against the spec and the pilot checks (Claude, 7 November 2026). Two pilot items no phase item covered are built: reading the audit log (check D5) and the counter's speed (check A1) (D47). The rest are open questions below.

## Phase 4 hardening (started before G3, D50)

- [x] The cost-leak scanner (check C4): a test calls every GET route as a cashier and as a manager, at a counter and a back-office device, and fails if a planted cost appears in any answer, page or CSV export; it fails too on a route it cannot call, so a new route is never skipped (Claude, 7 November 2026)
- [x] The off-site copy with its 2-of-3 key shares (check E1; D53): two labelled flash drives copied by themselves when plugged in, encrypted to a key the server cannot use, its health on the health page, restoring from a drive, and a self-test in CI (Claude, 7 November 2026; docs/operations/offsite-copy.md)
- [x] The upgrade-path test (check E5; D55): CI makes a shop with sales on the end-of-Phase-3 code, upgrades it through every migration in order, sells on the older code against the newer schema, then sells and runs the rehearsal check on the newer code (Claude, 10 November 2026; tools/release/upgrade-path.sh)
- [x] Automating the checks, each written down against its check: 43 of the 46 are proved by tests in CI, E1 and E2 at the G3 software rehearsal, and A1 by real cashiers in the pilot (D60); new tests for search at 5,000 items (A2) and an item reaching the other counter within 5 seconds (A9) (Claude, 10 November 2026; docs/operations/acceptance-checks.md)
- [x] The official install steps, made simple (D58, D59): the installer asks the shop's details and installs Docker, a `haq` command on the server, each device trusts the certificate from http://<server>/setup, and device codes last 30 minutes; rehearsed on this computer from install to a printed receipt (Claude, 10 November 2026; docs/operations/install-a-shop.md)

Answered on 7 November 2026: undoing a cost is built (D48); check D6 moves to the MVP checks (D49); the hardening work that needs no hardware starts now (D50); the corrected-time view waits for the MVP (D51).

Also answered: a cashier's item creation counts as high at 10 a day, which the owner changes in Shop settings (D52); and the off-site copy with its key shares is built in Phase 4, before G4, as Claude recommended (D53).

## Go-live basics (D68)

Built before go-live, because the app is the shop's record from day one (D67):

- [x] Voids: a manager voids a whole sale within its shift, on its drawer, as a reversing sale with its own receipt number (check F1) (Claude, 10 November 2026)
- [x] Returns: by receipt number or barcode, a manager's PIN, a reason and a disposition, refunded by cash, transfer or POS, the cost reversed, no line returned twice even from two counters, and the owner alone with no receipt (checks F2 to F4) (Claude, 10 November 2026)
- [x] The day-end close: the blind count, the variance against expected cash, the flag above the threshold, the Z-report with the audit code (check F5) (Claude, 10 November 2026)
- [x] Paid-outs, drops and top-ups, with a reason and a manager above the set amount (check F6) (Claude, 10 November 2026). The owner sets three new things in Shop settings: the cash limit without a manager (₦0 until set), the drawer difference that is flagged (₦1,000 until set) and the return window (no limit until set); see Open items

## Windows setup (D86 to D89)

Built on main in apps/windows/, one short pull request per step, each with its own CI jobs; the Ubuntu install stays as it is. About 5 to 6 developer-weeks.

- [x] A scanned code never blocks a sale, however long it is (Claude, 17 November 2026; D83)
- [x] The branch key is made once, even when two counters need it at once (Claude, 17 November 2026; D84)
- [ ] The spike on the product owner's own Windows PC, with no virtual machine (D90): the server starts at power-on with nobody logged in, stays up for 24 hours, is reachable from another machine through the port forward, reaches a print bridge on the PC, survives a hard power-off during `shop:rehearse` (optional: a real power cut), and fits an 8 GB PC. A part that fails takes its fallback, and this plan is updated before the build (product owner runs it; Claude prepared it on 17 November 2026: [apps/windows/spike/](apps/windows/spike/README.md), with a CI job for the Windows scripts). The first run, on Windows 11 Pro 24H2 on 18 November 2026, stopped before the import: Windows refused the hidden account's sign-in for its task (status 0xC000015B), because it lacked "Log on as a batch job" and Task Scheduler does not grant it. The spike now grants that one right, and the setup program must too; CI tests it on a Windows runner. The second run imported the distribution, then stopped on a fault in the spike script itself: a variable holding inside.sh's text had the same name as the one holding its path, and PowerShell's names ignore case. Fixed, a second install now keeps a distribution already imported, and CI checks every Windows script for such a clash. The third run installed release 2026.11.3 and passed checks 1 (the server was ready at 11:05, before sign-in at 11:09), 3 (the phone on the hotspot loaded `/up`) and 4 (a counter sale reached the bridge), so the hidden account needs no fallback. Checks 2 and 6 are still to be read, and 5 is optional; the build goes ahead meanwhile (product owner, 18 November 2026)
- [x] The server's small changes: install.sh's Windows mode (`--windows`), `haq printer` to point a counter at its bridge, the off-site copy from a drive Windows has mounted (`offsite.sh copy-windows`), the enrol page taking its one-time code from a link (`/enrol#code=`), and `haq host-report` for the PC's disk and clock report (Claude, 17 November 2026)
- [x] The haq-server distribution, built by CI from each commit (Claude, 18 November 2026; [apps/windows/distro/](apps/windows/distro/README.md)): Ubuntu 24.04 with systemd, Docker and the commit's installer, about 170 MB; kept 90 days from main for the release to carry
- [ ] The HAQ Helper in Go: keeps the server running, the print bridge, the health report, the off-site drives; tested in CI on Linux and on a Windows runner (Claude). The print bridge, the printer list and the test receipt are built (17 November 2026; [apps/windows/helper/](apps/windows/helper/README.md)). The service, the keep-alive, the PC's report and the off-site drives are built (18 November 2026); the bridge on the server's PC lets in only WSL's own network
- [x] The install engine in the HAQ Helper (`haq-helper setup`): the server's install as the spike proved it, a counter's install, update, stop and remove; CI tries the account, the runner and a whole counter install on a Windows runner (Claude, 18 November 2026)
- [x] The setup program in Inno Setup: "This PC runs the shop" and "This PC is a counter", a second run as an update, and uninstall keeping the shop; built in CI with the commit's helper and distribution, and tried silently as a counter on a Windows runner; the one from main is kept 90 days to try on a PC (Claude, 19 November 2026; [apps/windows/setup/](apps/windows/setup/README.md))
- [ ] The setup tried on the product owner's own PC, from main's build, before the release (product owner; D90). It installed and ran on Windows 11 on 19 November 2026, with the owner. Two gaps it showed are fixed (D91): a cashier could not be added on a Windows server, because `haq add-user` asked its PIN through a runner with no terminal, and the counter had no way into the back office. On Windows 10 the setup refused the PC, with Inno Setup's own words for a version below 22H2 or a 32-bit Windows. The product owner wants it to work on any Windows 10 or 11 (D92): it now checks the PC itself and says what to do, so the next try is main's new build on that same PC
- [ ] The server's memory measured on the product owner's Windows 11 PC while scripted sales run, then the least it needs set from it with a margin; until then less than 8 GB is a warning (D92; product owner runs it, Claude prepares it)
- [ ] Each release carries the setup and the distribution, with their hashes in the signed manifest (Claude)
- [ ] The documents: install-a-shop.md's Windows section first, the Windows path in g3-software-rehearsal.md (Claude)
- [ ] The release that carries it, signed (product owner), and G3 on it for both ways (product owner, Claude)

## Phase 5: go-live and the safety week (D67)

The measured pilot's tools were built on 10 November 2026 (D56, D57). The measured pilot was removed entirely on 19 November 2026 (D95): the safety week keeps the notebook as a backup, with no comparison in the app.

- [x] The measured pilot in the back office: the owner starts the 14 days, a manager or the owner types the notebook's totals each evening and traces each difference to the notebook or the app, the five measures are read live, and the owner records continue or stop with the scorecard as it stood (Claude, 10 November 2026; D56). Removed entirely on 19 November 2026 (D95)
- [x] The owner's data export: the whole record, cost included, as one ZIP of CSV files with each file's SHA-256, from the back office or `shop:export` (Claude, 10 November 2026; D57)
- [x] The procedure for the safety week (Claude; docs/operations/safety-week.md, rewritten for D67 and D95)
- [x] Gate G4: every acceptance check written down, A1 to be measured in the safety week (product owner, 11 November 2026, D71)
- [x] Release 2026.11.2's fixes from the readiness QA review: a counter's clock never stops a sale (D72), a sale is never both voided and returned (D73), `sudo haq users`, `pin` and `deactivate` (D74), and capped logs (D75) (Claude, 11 November 2026)
- [x] Sign and publish release 2026.11.2 (product owner, 11 November 2026; docs/operations/releases.md)
- [ ] Install at the shop with the first release that carries the Windows setup (D89), by the setup program or `install.sh --tag <version>`, setting each counter's date and time (product owner)
- [ ] The safety week: the notebook kept beside the app as a backup for seven trading days (shop owner and manager)
- [ ] Gate G5: the owner confirms the notebook can stop (shop owner, product owner)

## After go-live

- **MVP, about 12 to 16 weeks:** store credit vouchers and the one-step exchange screen; drawer handover counts and cash-out services; the sysadmin console and licences; one-way sync and the cloud head office (hosting decided first); bank statement import; back-entry; staged rollout and the fleet dashboard. The first paid shop lands around month 9.
- **Straight after the MVP:** two-way sync of shop-global records, because target shops include multi-branch shops [R7, FU7].
- **Pro:** stock tracking with colour variants and cost layers; the credit ledger; the multi-branch view; the advanced printer features; promotions and loyalty (spec section 13).

## Estimate

About 40 developer-weeks to gate G4 in week 20. The plan was made for two developers, who would have had 40 by then, so it has no spare week. One person now builds it with Claude (D15), and the dates stay as they are, met with extra hours; the pace is checked at G2.

| Work | Developer-weeks |
|---|---|
| Phase 0 and the wireframes, with no prototype (D12) | 2 |
| Foundation | 8 |
| Counter and sale core | 12 |
| Owner features and operations | 8 |
| CI/CD pipeline and the updater | 4 |
| Acceptance checks and hardening | 4 |
| Go-live basics (D68) in the place of pilot support (D67) | 2 |
| The Windows setup and USB printers (D86 to D89), before go-live | 6 |
| **Total** | **46** |

Assumptions:
- The work is sized for developers experienced with Laravel, React and PostgreSQL. One person covers it, working extra hours with Claude writing much of the code (D15); G2 shows whether that pace holds.
- The field work in the pilot weeks (install, training, the weekly update and the daily reconciliation) comes out of the same person's hours.
- R2, R3, R5 and R6 save about 4 to 6 developer-weeks against the spec as written; S13 is out for now.
- With no spare week, either G5 moves to week 23 or an item of equal size moves to the MVP under the swap rule. Decide at G2, when the real pace is known.
- MVP and Pro work and the legal review are outside the 40.

## Risks

| Risk | Early signal | Response |
|---|---|---|
| Cashiers miss the speed targets | The speed instrumentation in the first live week; there is no prototype to time first (D12) | Changes ship in the weekly release. They cost more than on a prototype, because the counter is already built |
| One person does the work planned for two developers and a field lead, with no spare week (D15) | Foundation pace at G2 | Move G5 later, or move an item of equal size to the MVP under the swap rule |
| An update fails half-way at the shop | The updater's health check | The signed manifest is checked and every image pulled before anything changes; backup first; code-only rollback to the previous images |
| No connection at update time | A missed weekly update | A phone hotspot by default; a MiFi is optional; trading never depends on it |
| Scope grows before the pilot | Any item added after G0 | The swap rule: an item enters only if one of equal size leaves, recorded in docs/decisions.md |
| Pilot support and field work eat the build | Hours spent on shop issues in weeks 13–20 | Batch fixes into the weekly release, and train the shop's manager to handle the first checks: the paper pad, printer paper and a reboot |
| Cost coverage stays low after go-live | The profit-coverage card in the first live weeks | Two fixed 30-minute cost sessions a week, bulk entry from supplier invoices, or the "Manager may enter cost" setting (FU6) |

## Open items

| Item | Who | Needed by | Default until answered |
|---|---|---|---|
| The owner's three go-live settings: cash out of or into the drawer without a manager, the drawer difference that is flagged, and how many days a sale can be returned (D68) | Shop owner, in Shop settings | Install | ₦0 (every paid-out, drop and top-up needs a manager), ₦1,000, and no return limit |
| Busy-day sales volume | Product owner, from the notebook | Install | Measured in the first live week |
| Make the repository private and move the organisation to GitHub Team (US$4 per member per month) | Product owner | When the product owner chooses; application code is public from Phase 2 (D22) | Public (D10) |
| A read-only GHCR token for each shop server's updater: the image packages are private. Staging on this computer already pulls from GHCR | Product owner, Claude | Install | The shop server's token was made by the product owner on 7 November 2026 (classic, read:packages only); it is given to install.sh on the shop's server, which keeps it in secrets/ |
| Tile (drill) order per category | Speed data from the first live weeks (D12, D67) | A month after go-live | The v1.1 proposal, with the brand first in the five generated categories (D79) and asked in every category with a brand (D94) |
| The brands of the 11 categories with none of their own: wireless chargers, phone holders, memory cards, flash drives, batteries, selfie sticks, ring lights, tripods, mice, keyboards and USB hubs (D94) | Shop owner, through the product owner | Go-live | The whole brand list, A to Z, after Skip brand; a brand sold there joins that category's own |
| The updater and the other tools in a shop's install folder come from the release it was installed from; a fix to the updater itself needs `install.sh` run again. Carry the tools in each signed release | Claude | Before the second shop | `install.sh` is run again from a newer release |
| Which shop runs which release: today `sudo haq version` and the server health page, shop by shop. A list kept outside this public repository, then a fleet view | Product owner, Claude | Before the third shop | Asked shop by shop |
| The shop's receipt text: shop name, address, phone and any returns wording, for the sysadmin to enter in its shop profile (D14) | Shop owner | Install | Placeholders on the receipt wireframe |
| What the shop does today when the PC fails | Product owner | Install | The paper-pad downtime procedure (spec section 9) |
| The shop's closing time, for the forgotten-shift rule: a shift still open four hours later closes as unclosed (S12) | Shop owner, in Shop settings on a back-office device (D37) | Install | None set: no shift closes by itself |
| The shop's discard limit: the most a cashier may discard of their own parked sale without a manager (F12) | Shop owner, in Shop settings on a back-office device (D38) | Install | ₦0: every cashier discard needs a manager (D33) |
| Where head office and cloud backups are hosted, and data residency | Product owner, lawyer | Before the MVP | Not needed for the pilot |
| Bundles or packs (phone plus case, wholesale carton) | Product owner | MVP | Not supported in the pilot |
| Warranty claims | Product owner | MVP | Returns with the supplier_claim disposition |
| Bank statement formats | Product owner, from the shop's banks | MVP | One CSV mapping per bank |
| Whether the storekeeper may also add brands | Product owner | MVP | Managers and the owner only |
| Monthly prices for Core and Pro, and the standby kit | Product owner | Before the first paid shop | A proposal after shop cost discovery |
| A code-signing certificate for the setup program, or a cloud signing service (D88) | Product owner | Before shops download the setup themselves | Unsigned: Windows asks to run it anyway, acceptable while only HAQ's team installs |
| Trademark status of HAQ (classes 9 and 42) | Product owner, trademark agent | Before the first paid install | Proceed with the name and register |
| Say-it-aloud test of HAQ with five shop owners | Product owner | Before launch | Proceed unless owners object |

## Change log

| Date | Change |
|---|---|
| 5 November 2026 | First version, built from the v1.4 review; spec v1.5 and catalogue v1.1 written; the folder organised |
| 5 November 2026 | Counter decisions D7 to D9 recorded; the repository stays public for now (D10) |
| 6 November 2026 | Counter wireframes drawn; a bench check for the ₦ sign; the receipt text added to the open items; a POS payment needs nothing typed (D11) |
| 6 November 2026 | Spec v1.5 signed off (D13); the clickable prototype dropped, so G1 is the wireframe sign-off (D12); the receipt text is a sysadmin setting with a branch override (D14); one person builds it, and the dates stay (D15) |
| 6 November 2026 | Shop visit done and hardware chosen, so G0 is passed; the product owner's computer is the staging and test machine (D16) |
| 6 November 2026 | Catalogue v1.1 confirmed with no changes, which closes its shelf-walk items; the hardware list locked (D17) |
| 6 November 2026 | Back-office wireframes drawn: the dashboard, Needs cost, first-sale prices, review and merging, catalogue health, flags, the daily summary printout and server health |
| 6 November 2026 | Wireframes signed off, so G1 passes and Phases 0 and 1 are closed and locked (D19); the printed daily summary carries no profit or cost amounts (D18); a Phase 2 checklist |
| 6 November 2026 | Docker runs on the product owner's computer through Colima |
| 6 November 2026 | Phase 2 starts: the shop server's skeleton and development stack in apps/shop-server/, and CI for it (D20 to D22) |
| 6 November 2026 | The modular monolith (D23); the three database roles, with grants and triggers from the mutability matrix |
| 6 November 2026 | Audit chains, roots and the spool; the multi-process test harness in CI |
| 6 November 2026 | Settings with history and sysadmin locks; the shop profile tool |
| 6 November 2026 | Identity: name tiles and PINs, acting-user tokens, approvals, enrolment (D24) |
| 6 November 2026 | The catalogue model, the name routine and the starter-catalogue seeder (D25) |
| 6 November 2026 | A shop's Docker stack, images on GHCR, the installer and staging (D26); Phase 2's work is done and G2's criteria are met on a clean Ubuntu virtual machine |
| 6 November 2026 | Gate G2 passed and Phase 2 closed (D27); the identity timings accepted (D28); back-office devices (D29); staging pulls from GHCR |
| 6 November 2026 | Phase 3 starts at the product owner's go: the sale core (Finish, receipt numbers, epochs, minimal shifts) |
| 6 November 2026 | Prices: pre-fill, the usual range, the floor and the price checks (D30) |
| 6 November 2026 | Server printing, pending print, reprints and the drawer log (D31) |
| 6 November 2026 | The counter's API: the catalogue feed, new items, barcodes and aliases (D32) |
| 6 November 2026 | Held sales and Awaiting transfer on the server (D33) |
| 6 November 2026 | The counter client and the browser tests in CI (D34) |
| 6 November 2026 | Backups, the weekly test restore and the restore script (D35) |
| 6 November 2026 | The release manifest, offline signing and the updater (D36) |
| 6 November 2026 | The G3 tooling: scripted sales, the rehearsal check and the procedure; Phase 3 built, G3 waits for the pilot hardware |
| 6 November 2026 | The owner sets the closing time in Shop settings (D37) |
| 6 November 2026 | The owner sets the cashier's discard limit in Shop settings (D38) |
| 7 November 2026 | The release signing key made by the product owner, kept offline on two drives; its public half committed (D36) |
| 7 November 2026 | Phase 4's owner features start before G3, at the product owner's go (D39) |
| 7 November 2026 | The back office and cost: the dashboard, Needs cost with the backfill, profit in three buckets (D40) |
| 7 November 2026 | Flags and alerts: the flag list, Needs your attention, the cashiers' day and the CSV export (D41) |
| 7 November 2026 | Review queues: names, new items, barcodes and search words, duplicates with merge and undo (D42) |
| 7 November 2026 | Prices and reports: first-sale prices with undo, catalogue health, the daily summary on screen and printed, CSV exports (D43) |
| 7 November 2026 | Recovery starts at the product owner's go: restore epochs end to end and the lost window (D44) |
| 7 November 2026 | Server health, its dashboard warnings, catch-up of the nightly checks and the server clock (D45) |
| 7 November 2026 | Reboot recovery: the starting page, restart policies and the start-up steps (D46) |
| 7 November 2026 | Review of Phase 4's owner features: the audit log to read and the counter's speed (D47), and the open questions it raised |
| 7 November 2026 | The product owner's answers: undoing a cost (D48), check D6 to the MVP (D49), hardware-free hardening starts now (D50), the corrected-time view in the MVP (D51) |
| 7 November 2026 | Hardening: the cost-leak scanner (check C4) |
| 7 November 2026 | High item creation at 10 a day, an owner setting (D52); the off-site copy to build before G4 (D53) |
| 7 November 2026 | Hardening: the off-site copy with its key shares (D53) |
| 8 November 2026 | The counter's brand step offers every listed brand, found while trying the counter (D54) |
| 10 November 2026 | Hardening: the upgrade-path test and the 46 checks written down (D55) |
| 10 November 2026 | Phase 5's tools at the product owner's go: the measured pilot (D56) and the owner's data export (D57); the procedure for the 14 days and G5 |
| 10 November 2026 | The official install steps, made simple: device codes last 30 minutes (D58); the installer's questions, the `haq` command and the setup page for the certificate (D59) |
| 10 November 2026 | Software only: the bench test, the hardware checks and the shop-visit checklist removed; G3 is a software dress rehearsal on virtual machines (D60) |
| 10 November 2026 | No pilot (D67): go-live with a one-week safety net; the go-live basics (voids, returns, the day-end close, paid-outs, drops and top-ups) move into the first release, with checks F1 to F6 (D68) |
| 11 November 2026 | The G3 practice on this computer passes all five steps; it finds that a restore onto the spare cost the counters their trust and enrolment, fixed by keeping the shop's certificate authority and app key beside the backups (D69) |
| 11 November 2026 | Release 2026.11.1 signed and published by the product owner; publishing a release now tags its images with its version on GHCR, so a shop installs it by version (D70) |
| 11 November 2026 | Gates G3 and G4 pass on release 2026.11.1 (D71); next, the owner's answers and the install at the shop |
| 11 November 2026 | Readiness QA review: release 2026.11.2 fixes the counter clock that stopped sales, a void racing a return, PIN changes and staff removal, and the log growth (D72 to D75) |
| 11 November 2026 | Release 2026.11.2 signed and published by the product owner, with its images tagged by version; it is the release that goes live |
| 17 November 2026 | A scanned code never blocks a sale (D83); the branch key is made once (D84); CI's JavaScript audit allows the picomatch advisory by ID, with its reason (D85). A shop's server may be a Windows PC, installed by one setup program with the server hidden in WSL2 (D86); USB printers through the print bridge (D87); Go and Inno Setup for the Windows parts (D88); go-live waits for the Windows setup, and the GHCR token is typed once in the setup (D89) |
| 18 November 2026 | The Windows setup is tried on the product owner's own Windows PC, never in a virtual machine (D90) |
| 19 November 2026 | One PC shares its counter: Open the back office on the name tiles, and on a Windows server `haq-helper server add-user` asks the PIN itself (D91) |
| 19 November 2026 | The Windows setup checks the PC itself, not its version: any 64-bit Windows 10 or 11 as a counter, the server on Windows 10 updated since November 2022 or Windows 11 (D92) |
| 19 November 2026 | A review of the sale path (the 18 browser tests of sales pass): New item keeps a scanned code, makes no second item with "No brand", asks "Made by" on pouches, shows the category's own brands first, chooses phone models by family and offers the listed models (D93) |
| 19 November 2026 | The brand step is asked in every category with a brand, Skip brand first, the category's own brands, then More brands; the tiles end with New; search finds categories and brands; an approved brand is shown first where it was sold (D94). C8 recorded as settled at the shop visit, and the plan's Next line and open items brought up to date |
| 19 November 2026 | The measured pilot removed entirely (D95): the safety week keeps the notebook as a backup, with no comparison in the app (docs/operations/safety-week.md) |
| 19 November 2026 | Test before merge (D96): each pull request is tried by the product owner on staging (`staging.sh --build`, `--fresh` for a new shop) and merged only on their word; main stays the only long branch |
| 20 November 2026 | Three findings on staging fixed (D102): a step's tile no longer adds the one item it leaves, so every list shows the item with its price and ends with New; Skip brand shows every brand, so an item made with a brand after Skip brand is found there too |
