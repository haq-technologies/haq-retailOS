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

Phase 0, week 1. There is no application code yet.

Done:
- Spec v1.4 and starter catalogue v1 reviewed, and every decision recorded.
- Spec v1.5 written, with the decisions applied and tagged.
- Starter catalogue v1.1 drafted with 466 phone models, built and verified by script. It waits for the shop owner's re-confirmation.
- Project folder organised: README.md, CLAUDE.md, this plan, docs/ and tools/.
- GitHub: main is pushed to haq-technologies/haq-retailOS, and the first CI run passed. The "Protect main" ruleset allows changes only through a pull request with the docs check green, and blocks force-pushes and deletion. The repository stays public for now (D10), and commits use the GitHub noreply email.
- Design direction decided: Apple's system colours, the Inter typeface, and responsive layouts (D4, D5). The wireframes are in progress on a new canvas, and the counter decisions D7 to D9 are recorded.

Next: the Phase 0 checklist below.

## Team

| Role | Owns | Time |
|---|---|---|
| Developer A, counter and front end | Prototype, counter client, back-office screens, Playwright tests | Full-time |
| Developer B, platform and data | Database roles, sale transaction, audit, printing, backups, install, CI/CD and releases | Full-time |
| Designer | Wireframes, prototype, cashier timing, receipt layout, training card | Full-time to week 6, then half-time |
| Field lead (HAQ) | Shop visits, shelf walk, hardware, install, training, the weekly update and daily reconciliation in the pilot | Part-time; full-time from week 13 |
| Product owner | Decisions, the shop owner relationship, contract and pricing | A few hours a week |

## Phases and gates

Weeks count from kickoff. A gate ends a phase only when its criteria are met.

| Phase | Weeks | Delivers | Gate |
|---|---|---|---|
| 0. Decide and discover | 1–2 | Decisions; spec v1.5; catalogue v1.1 re-confirmed; the shop visit; the bench hardware test; the GitHub repository and first CI workflow | **G0:** decisions recorded; v1.5 and v1.1 signed off; hardware chosen |
| 1. Prototype and time it | 2–4 | Touch-first wireframes on a new canvas; a clickable prototype with the real seed on the chosen hardware and a real scanner; two cashiers timed, with two rounds of changes | **G1:** a 3-item sale under 30 seconds and a known barcode under 8 seconds; the counter interaction model frozen |
| 2. Foundation | 3–8 | Docker stack; database roles, grants and triggers generated from the mutability matrix; the one-shot migration job; identity (name tiles, PINs, acting-user tokens, bound approvals, idle lock); audit chains, roots and spool; settings and the profile tool; catalogue model, name routine and seeder; the multi-process PostgreSQL harness in CI; images pushed to GHCR; the office staging box | **G2:** a clean box installs from a profile; the seed loads twice with no change; append-only holds against a deliberately widened grant |
| 3. Counter and sale core | 6–12 | Counter client; Finish with idempotency, receipt counters, epochs, payments and print jobs; server printing with pending print and the drawer log where a drawer is fitted; Awaiting transfer; catalogue polling; counter journal; speed instrumentation; backups with a test restore; Playwright tests in CI; the release manifest and the updater | **G3 (week 12):** a dress rehearsal on the pilot hardware, installed by pulling from GHCR: 300 scripted sales on two counters, power pulled mid-sale and mid-print, and a restore onto the spare box |
| 4. Shadow pilot, owner features, hardening | 13–20 | Install at the shop in week 13; one counter beside the notebook at agreed hours in weeks 13–18; a weekly release; the Needs-cost queue, backfill preview, three profit buckets, alerts, printed daily summary, review queues with merge and undo, catalogue health, CSV export, restore epochs end to end, the lost-window screen, health page, reboot recovery and catch-up scheduling. In weeks 17–20: the 47 checks automated, the cost-leak scanner, a soak test, and an install rehearsal in under 2 hours | **G4 (week 20):** all 47 checks pass and are written down |
| 5. Measured pilot and decision | 21–22 | Every counter beside the notebook for 14 trading days, measured against the success thresholds | **G5 (week 22):** the owner decides to continue or stop; on continue, the app becomes the record and the notebook stops a week later |

## Timeline

```
Week                           1  2  3  4  5  6  7  8  9 10 11 12 13 14 15 16 17 18 19 20 21 22
0 Decide and discover         ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G0
1 Prototype, cashier timing    . ## ## ##  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  .  G1
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

1. **Branch and pull request.** Short-lived branches off main; green CI to merge, plus one review once the developers join (D6); no direct pushes.
2. **CI on every pull request.** Lint and static analysis (Pint, PHPStan, ESLint); unit tests (Pest, Vitest); concurrency, crash and restore tests on a real PostgreSQL; counter flows in Playwright on Docker Compose; the previous release's code against the new schema, and an upgrade from two versions back; the seed validator, the cost-leak scan and an image scan.
3. **Merge to main.** Docker images are built once and pushed to GHCR, pinned by digest.
4. **Staging box.** The office mini-PC, with the same printer and scanner and 5,000 test items, deploys from main.
5. **Tag a release.** A semantic version, weekly in the pilot, with notes generated from the merged pull requests.
6. **Release manifest.** Image digests, the compose file and the migrations, in one file the updater trusts.
7. **Offline signing.** A person signs the manifest with a hardware key; CI never holds the release key.
8. **The shop pulls from GitHub.** Over a phone hotspot where the shop has no internet; a MiFi is optional [D3].
9. **The updater on the shop server.** Verifies the signature, pulls every image, backs up, migrates, runs the health check, and rolls back the code only if the check fails.

| Phase | CI/CD work | Owner |
|---|---|---|
| 0 (weeks 1–2) | GitHub organisation and repository (public for now, D10), branch protection, and the first workflow: lint, unit tests, a PostgreSQL service | Developer B |
| 2 (weeks 3–8) | Dockerfiles and Compose; images to GHCR on merge; the staging box deploying from main; the multi-process PostgreSQL harness in CI | Developer B |
| 3 (weeks 6–12) | Playwright counter tests in CI; the release manifest and the updater with code-only rollback; a signing dry run. G3 installs by pulling from GHCR | Developers A and B |
| 4 and 5 (weeks 13–22) | A weekly signed release pulled at the shop over a phone hotspot; the upgrade-path test; a scheduled restore drill in CI | Developer B and the field lead |

Choices and their tradeoffs (runners, registry, environments, housekeeping) are in the review doc's CI/CD section and in spec section 4.

## Phase 0 checklist

- [x] Review spec v1.4 and catalogue v1, and record every decision (Claude, product owner)
- [x] Write spec v1.5 (Claude)
- [x] Draft starter catalogue v1.1 (Claude)
- [x] Organise the project folder (Claude)
- [ ] Buy the bench hardware: [docs/operations/hardware.md](docs/operations/hardware.md) (product owner). Deferred on 5 November; it is needed before the G3 dress rehearsal in week 12
- [ ] Shop visit: the owner re-confirms catalogue v1.1; shelf walk; 20-item barcode test; two hours of observation; a week of sales counted from the notebook; a power and network survey: [docs/operations/shop-visit-checklist.md](docs/operations/shop-visit-checklist.md) (field lead). Deferred on 5 November; catalogue v1.1 must be re-confirmed before it is loaded at the shop in week 13
- [x] Push the first commit to the public repository and protect main (Claude). The repository stays public for now (D10)
- [ ] Docker Compose for development (Developer B)
- [ ] Bench test: print, cut, barcode, drawer kick, printer status, scanner suffix and speed, Chrome kiosk keys, touch, UPS changeover, phone hotspot (Developer B, field lead)
- [ ] Touch-first, responsive wireframes in Apple's system colours on a new canvas (Claude, designer). The counter screens are drawn, including the price-floor prompt, pending print, lost window, Pending transfers and the idle lock; the owner's back office is next
- [ ] Confirm the team's start dates and GitHub access (product owner)
- [ ] Sign off spec v1.5 and catalogue v1.1: gate G0 (product owner, shop owner)

## After the pilot

- **MVP, about 12 to 16 weeks:** returns, exchanges and voids; shifts per drawer with handover counts and the Z-report; paid-outs, drops and top-ups; the sysadmin console and licences; one-way sync and the cloud head office (hosting decided first); bank statement import; back-entry; staged rollout and the fleet dashboard. The first paid shop lands around month 9.
- **Straight after the MVP:** two-way sync of shop-global records, because target shops include multi-branch shops [R7, FU7].
- **Pro:** stock tracking with colour variants and cost layers; the credit ledger; the multi-branch view; the advanced printer features; promotions and loyalty (spec section 13).

## Estimate

About 42 developer-weeks to gate G4 in week 20, against the 40 that two developers have by then, so the plan has no spare week.

| Work | Developer-weeks |
|---|---|
| Phase 0 and the prototype | 4 |
| Foundation | 8 |
| Counter and sale core | 12 |
| Owner features and operations | 8 |
| CI/CD pipeline and the updater | 4 |
| Acceptance checks and hardening | 4 |
| Pilot support in the shadow weeks | 2 |
| **Total** | **42** |

Assumptions:
- Both developers are full-time and experienced with Laravel, React and PostgreSQL. If not, add 4 to 6 weeks.
- R2, R3, R5 and R6 save about 4 to 6 developer-weeks against the spec as written; S13 is out for now.
- With no spare week, either G5 moves to week 23 or an item of equal size moves to the MVP under the swap rule. Decide at G2, when the real pace is known.
- A third developer saves about 3 weeks, not a third of the time: the counter and the integrity core split badly, and the shop sets the pilot's pace.
- MVP and Pro work, hardware lead times and the legal review are outside the 42.

## Risks

| Risk | Early signal | Response |
|---|---|---|
| Cashiers miss the speed targets on the prototype | G1 timings in week 4 | Change the counter design before the build; two rounds of changes are planned |
| No spare week in the plan | Foundation pace at G2 | Move G5 to week 23, or move an item of equal size to the MVP under the swap rule |
| Catalogue v1.1 re-confirmation slips | G0 | Take the workbook to the first visit; every change is on its Changes tab |
| Fewer barcodes scan than hoped (the spec assumes 40%) | The 20-item scan test | Search and tiles carry the counter; print item-code labels for the 50 best sellers |
| An update fails half-way at the shop | The updater's health check | The signed manifest is checked and every image pulled before anything changes; backup first; code-only rollback to the previous images |
| No connection at update time | A missed weekly update | A phone hotspot by default; a MiFi is optional; trading never depends on it |
| Scope grows before the pilot | Any item added after G0 | The swap rule: an item enters only if one of equal size leaves, recorded in docs/decisions.md |
| Pilot support eats the build | Developer hours spent on shop issues in weeks 13–20 | The field lead takes first-line support; fixes ship in the weekly release |
| Cost coverage stays under 80% by day 14 | The profit-coverage card in the shadow weeks | Two fixed 30-minute cost sessions a week, bulk entry from supplier invoices, or the "Manager may enter cost" setting (FU6) |

## Open items

| Item | Who | Needed by | Default until answered |
|---|---|---|---|
| Barcode scan result (20 items) | Field lead, shop visit | G0 | Assume 40% scannable |
| Busy-day sales volume | Field lead, from the notebook | G0 | Measured in the shadow weeks |
| Brands shown first for six categories (C8) | Field lead, shelf walk | G0 | The full brand list |
| The 16 brands to confirm, and the short codes HF, AI, LP, BM, DV, JZ, EX, UWO | Field lead, shelf walk | G0 | Kept until shown not stocked |
| Phone-model list corrections | Field lead, shelf walk | G0 | The v1.1 draft |
| Screen-protector name order ("Tecno Spark 20 Tempered Glass Screen Protector") and capitals for car mp3 s8 | Shop owner | G0 | As drafted; car mp3 s8 printed as typed |
| Make the repository private and move the organisation to GitHub Team (US$4 per member per month) | Product owner | Recommended before application code lands in Phase 2 (week 3) | Public, documents only (D10) |
| Tile (drill) order per category | Cashier timing | G1 | The v1.1 proposal |
| Receipt header and footer: the shop name, address, phone and any returns wording | Shop owner | Install (week 13) | Placeholders on the receipt wireframe |
| What the shop does today when the PC fails | Field lead | Week 13 | The paper-pad downtime procedure (spec section 9) |
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
| 6 November 2026 | Counter wireframes drawn; a bench check for the ₦ sign; the receipt text added to the open items |
