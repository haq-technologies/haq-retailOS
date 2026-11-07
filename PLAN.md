# HAQ Retail OS: Plan

The living plan from today to the pilot go-live decision and on to the MVP. The spec ([docs/spec/retail-os-spec-v1.5.md](docs/spec/retail-os-spec-v1.5.md)) says what to build; this file says when, by whom, and what happens next. Decisions and their sources are in [docs/decisions.md](docs/decisions.md).

Last updated: 6 November 2026.

## Goal

Run the pilot shop on HAQ Retail OS and reach the owner's go-live decision (gate G5) about 22 weeks after kickoff. Then ship the MVP that makes the product sellable, with the first paid shop around month 9.

The pilot succeeds when (spec section 14):
- all 47 pilot acceptance checks pass and are written down;
- on at least 10 of 14 measured days, the app's totals per payment method match the notebook after differences are traced;
- every receipt number is used once, and no sale is lost to a crash or power cut;
- a trained cashier rings a 3-item sale in under 30 seconds on typical days;
- at least 80% of revenue has a cost, at sale or estimated, by day 14.

## Status

Week 1. Phases 0 and 1 are closed and locked: gates G0 and G1 passed on 6 November 2026 (D19). Phase 2, the foundation, was built on 6 November 2026, and the product owner passed gate G2 the same day (D27), so Phase 2 is closed. The pace check changed no date and no scope. Phase 3, the counter and sale core, started on 6 November 2026 at the product owner's go and was built the same day (D30 to D36). Gate G3, the dress rehearsal, waits for the pilot hardware; the release signing key and the pilot server's read-only GHCR token were made by the product owner on 7 November 2026.

Done:
- Spec v1.4 and starter catalogue v1 reviewed, and every decision recorded.
- Spec v1.5 written, with the decisions applied and tagged, and signed off by the product owner on 6 November 2026 (D13).
- Starter catalogue v1.1 drafted with 466 phone models, built and verified by script, and re-confirmed by the shop owner at the shop visit.
- Project folder organised: README.md, CLAUDE.md, this plan, docs/ and tools/.
- GitHub: main is pushed to haq-technologies/haq-retailOS, and the first CI run passed. The "Protect main" ruleset allows changes only through a pull request with the docs check green, and blocks force-pushes and deletion. The repository stays public for now (D10), and commits use the GitHub noreply email.
- Design direction decided: Apple's system colours, the Inter typeface, and responsive layouts (D4, D5). The counter and the owner's back office are drawn on the new canvas (36 boards), and the counter decisions D7 to D9 and D11 are recorded.
- The shop visit is done, with no changes to catalogue v1.1, and the hardware list is locked (D17), so gate G0 is passed (6 November 2026).
- Wireframes signed off by the product owner, so gate G1 is passed (D19). The printed daily summary carries no profit or cost amounts (D18).
- Phase 2, the foundation (D20 to D26): the shop server in apps/shop-server/ as a modular monolith; the three database roles with grants and triggers from the mutability matrix; audit chains, roots and the spool; settings and the shop profile tool; identity with name tiles, PINs, acting-user tokens and bound approvals; the catalogue with the one name routine and the seeder; the multi-process test harness in CI; a shop's Docker stack with images on GHCR, the installer and staging. G2's criteria are met.

- Gate G2 passed on 6 November 2026 (D27); the identity timings accepted (D28); back-office devices for the owner's phone or laptop (D29).
- Phase 3, the counter and sale core (D30 to D36): Finish with gapless receipt numbers and signed epochs; prices and their checks; server printing with pending print and the drawer log; the catalogue feed in commit order; new items, barcode learning and aliases; held sales and Awaiting transfer; the local-first counter client; Playwright browser tests in CI; pgBackRest backups with the verified weekly test restore and the restore script; the signed release manifest and the updater; the G3 tooling. See the Phase 3 checklist below.

Next: gate G3 on the pilot hardware (docs/operations/g3-dress-rehearsal.md), once the pilot hardware is set up. Before it, the first release is made and signed (docs/operations/releases.md), and the GHCR token goes onto the pilot server at install. Phase 4 starts only at the product owner's go.

## Team

One person builds HAQ Retail OS: the product owner, working with Claude (D15). There is no other developer, designer or field lead, and the dates below stay as they are, met with extra hours.

| Who | Owns |
|---|---|
| Product owner | Decisions, the shop owner relationship, contract and pricing; the hardware, shop visits, install, training, the weekly update and the daily reconciliation in the pilot; testing on real hardware; signing releases |
| Claude | Code, tests, CI/CD, documents and wireframes, through pull requests, under the product owner's direction |

## Phases and gates

Weeks count from kickoff. A gate ends a phase only when its criteria are met.

| Phase | Weeks | Delivers | Gate |
|---|---|---|---|
| 0. Decide and discover | 1–2 | Decisions; spec v1.5; catalogue v1.1 re-confirmed; the shop visit; the bench hardware test; the GitHub repository and first CI workflow | **G0:** decisions recorded; v1.5 and v1.1 signed off; hardware chosen. Passed on 6 November 2026 |
| 1. Wireframes | 2–4 | Touch-first, responsive wireframes on a new canvas: the counter, then the owner's back office. No clickable prototype and no cashier timing before the build (D12) | **G1:** the product owner signs off the wireframes, and the counter interaction model is frozen. Passed on 6 November 2026 |
| 2. Foundation | 3–8 | Docker stack; database roles, grants and triggers generated from the mutability matrix; the one-shot migration job; identity (name tiles, PINs, acting-user tokens, bound approvals, idle lock); audit chains, roots and spool; settings and the profile tool; catalogue model, name routine and seeder; the multi-process PostgreSQL harness in CI; images pushed to GHCR; staging on the product owner's computer (D16) | **G2:** a clean Ubuntu virtual machine installs from a profile (D16); the seed loads twice with no change; append-only holds against a deliberately widened grant |
| 3. Counter and sale core | 6–12 | Counter client; Finish with idempotency, receipt counters, epochs, payments and print jobs; server printing with pending print and the drawer log where a drawer is fitted; Awaiting transfer; catalogue polling; counter journal; speed instrumentation; backups with a test restore; Playwright tests in CI; the release manifest and the updater | **G3 (week 12):** a dress rehearsal on the pilot hardware, installed by pulling from GHCR: 300 scripted sales on two counters, power pulled mid-sale and mid-print, and a restore onto the spare box. Touch, the kiosk keys and the UPS are first tested here (D16) |
| 4. Shadow pilot, owner features, hardening | 13–20 | Install at the shop in week 13; one counter beside the notebook at agreed hours in weeks 13–18; a weekly release; the Needs-cost queue, backfill preview, three profit buckets, alerts, printed daily summary, review queues with merge and undo, catalogue health, CSV export, restore epochs end to end, the lost-window screen, health page, reboot recovery and catch-up scheduling. In weeks 17–20: the 47 checks automated, the cost-leak scanner, a soak test, and an install rehearsal in under 2 hours | **G4 (week 20):** all 47 checks pass and are written down |
| 5. Measured pilot and decision | 21–22 | Every counter beside the notebook for 14 trading days, measured against the success thresholds | **G5 (week 22):** the owner decides to continue or stop; on continue, the app becomes the record and the notebook stops a week later |

## Timeline

```
Week                           1  2  3  4  5  6  7  8  9 10 11 12 13 14 15 16 17 18 19 20 21 22
0 Decide and discover         ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G0
1 Wireframes                   . ## ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G1
2 Foundation                   .  . ## ## ## ## ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G2
3 Counter and sale core        .  .  .  .  . ## ## ## ## ## ## ##  .  .  .  .  .  .  .  .  .  .  G3
4 Shadow pilot, one counter    .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ## ## ##  .  .  .  .
4 Owner features, weekly       .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ## ## ## ##  .  .  .
4 Hardening, 47 checks         .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ##  .  .  G4
5 Measured pilot, 14 days      .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  . ## ##  G5
CI/CD pipeline set-up         ## ## ## ## ## ## ## ## ## ## ## ##  .  .  .  .  .  .  .  .  .  .
Weekly releases from GitHub    .  .  .  .  .  .  .  .  .  .  .  . ## ## ## ## ## ## ## ## ## ##
```

The notebook stays the shop's record until G5, so real cashiers meet the system early with no real money at stake.

## CI/CD with Git, GitHub and Docker

Every change runs the full test suite against a real PostgreSQL before it can merge, and every release reaches a shop as Docker images pulled from GitHub and checked against a signed release manifest [D1, D2].

1. **Branch and pull request.** Short-lived branches off main; green CI to merge, with no review while one person builds it (D6, D15); no direct pushes.
2. **CI on every pull request.** Lint and static analysis (Pint, PHPStan, ESLint); unit tests (Pest, Vitest); concurrency, crash and restore tests on a real PostgreSQL; counter flows in Playwright on Docker Compose; the previous release's code against the new schema, and an upgrade from two versions back; the seed validator, the cost-leak scan and an image scan.
3. **Merge to main.** Docker images are built once and pushed to GHCR, pinned by digest.
4. **Staging.** The product owner's computer, with the pilot's printer and scanner and 5,000 test items, deploys from main (D16).
5. **Tag a release.** A semantic version, weekly in the pilot, with notes generated from the merged pull requests.
6. **Release manifest.** Image digests, the compose file and the migrations, in one file the updater trusts.
7. **Offline signing.** A person signs the manifest with a hardware key; CI never holds the release key.
8. **The shop pulls from GitHub.** Over a phone hotspot where the shop has no internet; a MiFi is optional [D3].
9. **The updater on the shop server.** Verifies the signature, pulls every image, backs up, migrates, runs the health check, and rolls back the code only if the check fails.

| Phase | CI/CD work | Owner |
|---|---|---|
| 0 (weeks 1–2) | GitHub organisation and repository (public for now, D10), branch protection, and the first workflow: lint, unit tests, a PostgreSQL service | Claude |
| 2 (weeks 3–8) | Dockerfiles and Compose; images to GHCR on merge; staging on the product owner's computer, deploying from main; the multi-process PostgreSQL harness in CI | Claude |
| 3 (weeks 6–12) | Playwright counter tests in CI; the release manifest and the updater with code-only rollback; a signing dry run. G3 installs by pulling from GHCR | Claude; the product owner holds the signing key |
| 4 and 5 (weeks 13–22) | A weekly signed release pulled at the shop over a phone hotspot; the upgrade-path test; a scheduled restore drill in CI | Claude and the product owner |

Choices and their tradeoffs (runners, registry, environments, housekeeping) are in the review doc's CI/CD section and in spec section 4.

## Phase 0 and 1 checklist (closed and locked on 6 November 2026, D19)

- [x] Review spec v1.4 and catalogue v1, and record every decision (Claude, product owner)
- [x] Write spec v1.5 (Claude)
- [x] Draft starter catalogue v1.1 (Claude)
- [x] Organise the project folder (Claude)
- [x] Choose the hardware and lock the list: [docs/operations/hardware.md](docs/operations/hardware.md) (product owner, 6 November 2026, D17). No mini-PC is bought for testing; the product owner's computer stands in (D16)
- [x] Shop visit: the owner re-confirms catalogue v1.1; shelf walk; 20-item barcode test; two hours of observation; a week of sales counted from the notebook; a power and network survey: [docs/operations/shop-visit-checklist.md](docs/operations/shop-visit-checklist.md) (product owner). Done by 6 November 2026
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
- [x] The G3 tooling: `shop:rehearse` (scripted sales on two counters at once, through the print queue) and `shop:rehearsal-check` (gapless numbers, whole sales, receipts, audit chains, grants), the power-cut procedure and the restore onto the spare box (Claude, 6 November 2026; docs/operations/g3-dress-rehearsal.md). Rehearsed on staging with the database and app killed mid-run
- [ ] Gate G3: the dress rehearsal on the pilot hardware (product owner, Claude). It waits for the pilot hardware; the signing key and the GHCR token are ready (7 November 2026)

## Phase 4 owner features (started early, D39)

Phase 4's owner features started on 7 November 2026, before gate G3, at the product owner's go; the shadow pilot and the hardening wait for G3. In order:

- [x] The back office and cost: the back-office app (dashboard, navigation, Shop settings), the three profit buckets, the Needs-cost queue with the backfill preview and bulk apply, and who sees cost (Claude, 7 November 2026; D40)
- [ ] Flags and alerts: the flag list (mark as seen, never deleted), the dashboard's Needs your attention, CSV export of flags (Claude)
- [ ] Review queues: brands, phone models and product words; new items and typed lines; learned barcodes, conflicts and search words; duplicates, with merge and undo (Claude)
- [ ] Prices and reports: first-sale prices, catalogue health, CSV export of items and sales, the daily summary on screen and printed (Claude)
- [ ] Recovery: the health page, the lost-window screen with back entry, restore epochs end to end, reboot recovery and catch-up scheduling (Claude)

## Before Phase 3

- [ ] Buy the pilot hardware (product owner): the receipt printer and the scanner by week 6, when printing is built, and the rest before the G3 dress rehearsal in week 12
- [ ] Bench test (product owner, Claude): print, cut, barcode, drawer kick, printer status, scanner suffix and speed, and the phone hotspot on the product owner's computer once the printer and scanner arrive; Chrome kiosk keys, touch and the UPS changeover on the pilot hardware at G3 (D16)

## After the pilot

- **MVP, about 12 to 16 weeks:** returns, exchanges and voids; shifts per drawer with handover counts and the Z-report; paid-outs, drops and top-ups; the sysadmin console and licences; one-way sync and the cloud head office (hosting decided first); bank statement import; back-entry; staged rollout and the fleet dashboard. The first paid shop lands around month 9.
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
| Pilot support in the shadow weeks | 2 |
| **Total** | **40** |

Assumptions:
- The work is sized for developers experienced with Laravel, React and PostgreSQL. One person covers it, working extra hours with Claude writing much of the code (D15); G2 shows whether that pace holds.
- The field work in the pilot weeks (install, training, the weekly update and the daily reconciliation) comes out of the same person's hours.
- R2, R3, R5 and R6 save about 4 to 6 developer-weeks against the spec as written; S13 is out for now.
- With no spare week, either G5 moves to week 23 or an item of equal size moves to the MVP under the swap rule. Decide at G2, when the real pace is known.
- MVP and Pro work, hardware lead times and the legal review are outside the 40.

## Risks

| Risk | Early signal | Response |
|---|---|---|
| Cashiers miss the speed targets | The speed instrumentation in the shadow weeks; there is no prototype to time first (D12) | Changes ship in the weekly release. They cost more than on a prototype, because the counter is already built |
| One person does the work planned for two developers and a field lead, with no spare week (D15) | Foundation pace at G2 | Move G5 later, or move an item of equal size to the MVP under the swap rule |
| Touch, the Windows kiosk keys and the server's power behaviour are first tested at G3, because development and testing run on one computer (D16) | The G3 dress rehearsal in week 12 | Set up the pilot hardware as soon as it arrives, and test the printing code against the real printer from week 6 |
| Fewer barcodes scan than hoped (the spec assumes 40%) | The 20-item scan test | Search and tiles carry the counter; print item-code labels for the 50 best sellers |
| An update fails half-way at the shop | The updater's health check | The signed manifest is checked and every image pulled before anything changes; backup first; code-only rollback to the previous images |
| No connection at update time | A missed weekly update | A phone hotspot by default; a MiFi is optional; trading never depends on it |
| Scope grows before the pilot | Any item added after G0 | The swap rule: an item enters only if one of equal size leaves, recorded in docs/decisions.md |
| Pilot support and field work eat the build | Hours spent on shop issues in weeks 13–20 | Batch fixes into the weekly release, and train the shop's manager to handle the first checks: the paper pad, printer paper and a reboot |
| Cost coverage stays under 80% by day 14 | The profit-coverage card in the shadow weeks | Two fixed 30-minute cost sessions a week, bulk entry from supplier invoices, or the "Manager may enter cost" setting (FU6) |

## Open items

| Item | Who | Needed by | Default until answered |
|---|---|---|---|
| Barcode scan result (20 items) | Product owner, from the shop visit | Install (week 13) | Assume 40% scannable |
| Busy-day sales volume | Product owner, from the notebook | Install (week 13) | Measured in the shadow weeks |
| Make the repository private and move the organisation to GitHub Team (US$4 per member per month) | Product owner | When the product owner chooses; application code is public from Phase 2 (D22) | Public (D10) |
| A read-only GHCR token for each shop server's updater: the image packages are private. Staging on this computer already pulls from GHCR | Product owner, Claude | The G3 install pulls from GHCR (week 12) | The pilot server's token was made by the product owner on 7 November 2026 (classic, read:packages only); it is given to install.sh on the pilot server, which keeps it in secrets/ |
| Tile (drill) order per category | Speed data from the shadow pilot (D12) | The measured pilot (week 21) | The v1.1 proposal |
| The pilot shop's receipt text: shop name, address, phone and any returns wording, for the sysadmin to enter in its shop profile (D14) | Shop owner | Install (week 13) | Placeholders on the receipt wireframe |
| What the shop does today when the PC fails | Product owner | Week 13 | The paper-pad downtime procedure (spec section 9) |
| The pilot shop's closing time, for the forgotten-shift rule: a shift still open four hours later closes as unclosed (S12) | Shop owner, in Shop settings on a back-office device (D37) | Install (week 13) | None set: no shift closes by itself |
| The pilot shop's discard limit: the most a cashier may discard of their own parked sale without a manager (F12) | Shop owner, in Shop settings on a back-office device (D38) | Install (week 13) | ₦0: every cashier discard needs a manager (D33) |
| Where head office and cloud backups are hosted, and data residency | Product owner, lawyer | Before the MVP | Not needed for the pilot |
| Bundles or packs (phone plus case, wholesale carton) | Product owner | MVP | Not supported in the pilot |
| Warranty claims | Product owner | MVP | Returns with the supplier_claim disposition |
| Bank statement formats | Product owner, from the pilot shop's banks | MVP | One CSV mapping per bank |
| Whether the storekeeper may also add brands | Product owner | MVP | Managers and the owner only |
| Monthly prices for Core and Pro, and the standby kit | Product owner | Before the first paid shop | A proposal after shop cost discovery |
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
