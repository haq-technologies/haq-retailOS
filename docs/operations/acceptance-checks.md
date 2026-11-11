# The 52 acceptance checks for go-live

Spec section 14 lists the checks the first release must pass before gate G4: the 46 of sections A to E and the 6 go-live basics of section F (D68). There is no pilot (D67). This page writes down how each is proved, in software only (D60): by an automated test that runs in CI on every pull request, by a step of the G3 software rehearsal on virtual machines, or by measurement in the first live week. No check needs a physical device. When a check passes, write the date and the result in its last column.

Where the tests live, under apps/shop-server: `tests/Feature` (Pest, feature), `tests/Concurrency` (Pest, separate processes and committed transactions on a real PostgreSQL), `e2e` (Playwright in Chromium), `resources/js/**/*.test.ts` (Vitest). Tools: `tools/release/dry-run.sh`, `tools/release/upgrade-path.sh` and `haq-offsite.sh selftest`, all run in CI.

Status: **Automated** means the tests in CI prove the check. **G3** means a step of the software dress rehearsal proves it (docs/operations/g3-software-rehearsal.md). **First live week** means it can only be measured with real cashiers (D67).

## A. Counter and speed (13)

| Check | Proved by | Status | Written down |
|---|---|---|---|
| A1 A 3-item sale under 30 s and one known barcode under 8 s, by the counter's own timing | Every sale is timed (FinishTest); the daily summary shows each cashier's speed (AuditReadTest, owner.spec) | First live week: real cashiers, at least 20 sales (D67) | |
| A2 "ora free3c" finds the item under 100 ms with 5,000 items; a one-letter typo too | `search.test.ts` (both timed) | Automated | |
| A3 Full names on every receipt line, never "Others"; Request-only rules; Code 128 receipt number | NamesTest, CounterApiTest (Request-only, Not in the list), PrintingTest (receipt as the wireframe, barcode) | Automated | |
| A4 Price pre-fill, override, retail and wholesale, one tap on first-sale prices | PricesTest, ReportsTest, counter.spec | Automated | |
| A5 A learned barcode adds in one step, shows unconfirmed with undo; picker of 5; junk never learns | CounterApiTest, ReviewTest, counter.spec (scanner) | Automated | |
| A6 A cashier creates an item: category, facets, similar-item warning; sellable at once; in review | CounterApiTest, ReviewTest, counter.spec (check A9) | Automated | |
| A7 A parked sale resumes on another counter | ParkedTest, counter.spec | Automated | |
| A8 Every local interaction under 100 ms; a double tap on Finish makes one sale | Concurrency SalesTest (six requests, one sale); counter.spec (check A8: a cart tap and opening Pay, timed in the page); counter.spec (D61: a Finish with no answer is never sold twice) | Automated | |
| A9 An item made at counter A is in counter B's search within 5 s; a near-duplicate at B warns | counter.spec (check A9) | Automated | |
| A10 A typed brand sells and prints as typed, joins the list only on a manager's approval; "poga" never makes a second Poga | CounterApiTest, ReviewTest, ItemsTest | Automated | |
| A11 "Not in the list" with a 3-letter word sells and prints it; "others" refused; a cashier cannot make the category | CounterApiTest, ReviewTest | Automated | |
| A12 A scan never triggers Pay; Pay by its key and by touch | `rules.test.ts` (scanner timing), counter.spec (scanner) | Automated | |
| A13 Awaiting transfer shows on every counter, completes when confirmed, flagged after 30 minutes | ParkedTest, FlagsTest, counter.spec | Automated | |

## B. Integrity (12)

| Check | Proved by | Status | Written down |
|---|---|---|---|
| B1 Power cut mid-sale: no half-sale, no used number; the counter is back within 3 minutes | Concurrency SalesTest (a worker killed mid-sale); at G3, the server powered off hard mid-sale | Automated | |
| B2 Power cut after commit, before print: the sale stays, the receipt pending and offered | Concurrency SalesTest, PrintingTest (D63: a send that never reported back reprints stamped DUPLICATE, drawer shut); at G3, the stand-in printer stopped mid-print | Automated | |
| B3 Two requests with one key make one sale; the second gets the original | Concurrency SalesTest | Automated | |
| B4 A retry with a changed cart is refused and makes nothing | FinishTest | Automated | |
| B5 Gapless receipt numbers through a soak with forced failures, each logged | Concurrency SalesTest; `shop:rehearsal-check` in the upgrade-path test; at G3, 300 sales | Automated | |
| B6 Totals recalculated; a tampered browser total refused | FinishTest | Automated | |
| B7 Integer kobo; exact wholesale totals | FinishTest | Automated | |
| B8 Cash and change printed; the transfer tick, reference and who | FinishTest, PrintingTest | Automated | |
| B9 After a restore: higher epoch, no tuple reused, lost window lists journal sales; a second restore from the same backup goes higher | RestoreRecoveryTest, BackupsTest, Concurrency RestoreTest, recovery.spec | Automated. Head office's quarantine of a conflicting bundle comes with sync in the MVP | |
| B10 Two terminals at once: valid chains, finalisation under 2 s at the 95th percentile | Concurrency SalesTest | Automated | |
| B11 The concurrency, crash and restore tests exist and run in CI on a real PostgreSQL | `tests/Concurrency`, run by CI on every pull request | Automated | |
| B12 A shift 4 hours past closing auto-closes; the next sale is on the new day | FinishTest | Automated | |

## C. Cost and profit (7)

| Check | Proved by | Status | Written down |
|---|---|---|---|
| C1 No cost stores NULL; the item is in Needs cost by revenue | FinishTest, CostTest | Automated | |
| C2 A cost shows a preview, writes estimates with the right source, changes no sale line, audits each | CostTest, CostUndoTest | Automated | |
| C3 Three buckets with percentages that reconcile to the sales | CostTest, ReportsTest | Automated | |
| C4 Cost reaches no cashier or (by default) manager anywhere: API, pages, audit views, exports | CostLeakTest (every GET route as each, and the answers to what each sends), FlagsTest, ReportsTest, AuditReadTest | Automated. Sync bundles come with the MVP | |
| C5 Below cost flagged when saved, and again after a later cost entry | FlagsTest | Automated | |
| C6 A merge changes no sale, reports combine; a cycle refused; undo restores | ReviewTest, Concurrency CatalogueTest | Automated | |
| C7 Below the floor needs a manager; sibling ranges; the fourth confirm needs a manager; 3 times the usual is retyped | PricesTest, ApprovalsTest, counter.spec | Automated | |

## D. Audit and access (7)

| Check | Proved by | Status | Written down |
|---|---|---|---|
| D1 The app role cannot change append-only tables or grant; triggers hold against a widened grant; no running container holds the owner's password; chains verify; the summary prints the root | MutabilityTest, AuditTamperTest, ReportsTest; `check-install.sh` at the G3 install | Automated | |
| D2 The nightly grant check raises a health alert | MutabilityTest, HealthTest | Automated | |
| D3 PIN switch under 2 s; lockout after 5; terminal backoff after 10; idle limits | SignInTest (timed against a PIN hashed at a shop's full Argon2id cost); Concurrency IdentityTest (eight wrong PINs at once still lock after five); ApprovalsTest (wrong approval PINs count too) | Automated | |
| D4 An in-flight request keeps its sender; approvals bind once to line, item and price, survive parking, void on change | SignInTest, ApprovalsTest, PricesTest (5 minutes open; a slow transfer finished on the other counter; used once), counter.spec (check D4) | Automated | |
| D5 Every action in the audit log with user, device and redacted values; a cashier reads only their own; only the owner sees sensitive values | AuditTrailTest, AuditReadTest, AccessMatrixTest (every back-office route for every role), owner.spec, counter.spec | Automated | |
| D6 A discard needs a reason, a manager above the limit; both on the dashboard | ParkedTest, FlagsTest | Automated | |
| D7 Every drawer opening is logged; a counter with no drawer sells cash and reconciles | PrintingTest, FlagsTest | Automated | |

## E. Operations (7)

| Check | Proved by | Status | Written down |
|---|---|---|---|
| E1 Restore onto a clean machine within 1 hour, last receipt and chains verified; needs two key shares; the health page shows WAL, backup and restore | BackupsTest (with the keys a restore elsewhere needs, kept and brought back, D69), CertificateTest, RestoreRecoveryTest, HealthTest; `haq-offsite.sh selftest`; `tools/backup/restore-drill.sh` in CI (a backup restored and verified, D65); the restore onto the spare virtual machine within the hour (docs/operations/restore-drill.md) | G3 | |
| E2 Profile to first sale under 2 hours; 80mm prints without a dialog; a hard reboot returns to the counter | ProfileTest, RebootRecoveryTest; the timed install and the hard power-off (docs/operations/start-up.md) | G3 | |
| E3 The clock at the year 2000 does not lock the counter; sales stamped unverified; the owner asked | HealthTest (check E3) | Automated | |
| E4 Missed backup and test-restore jobs run at the next idle window | HealthTest (check E4) for the nightly checks; the backup service's own catch-up in `haq-backup.sh` | Automated | |
| E5 Updates only after the signed manifest verifies; a failed health check rolls back the code and keeps every sale; two versions behind upgrades in order | `tools/release/dry-run.sh`, `tools/release/upgrade-path.sh` | Automated | |
| E6 The health page shows certificate expiry (60 days), SSD health and clock state | HealthTest, owner.spec | Automated | |
| E7 Starter catalogue v1.1 loads exactly, offers 127 shapes, and a second load changes nothing | StarterCatalogueTest | Automated | |

## F. Go-live basics (6) [D68]

There is no pilot (D67), so these are built before go-live (D68).

| Check | Proved by | Status | Written down |
|---|---|---|---|
| F1 A void within its shift, on its drawer, prints with its own number and nets every total; after the close, on another drawer or after a return it is refused | ReversalsTest (the void, its slip, the totals; each refusal) | Automated | |
| F2 A return by receipt number or barcode: manager's PIN, reason, disposition, refund by cash, transfer or POS, a return slip, the cost reversed | ReversalsTest (the return, its slip, the cost and the totals; over HTTP), counter.spec (check F2) | Automated | |
| F3 No line returned beyond the quantity sold, even from two counters at once | Concurrency ReturnsTest (two counters at once, three rounds), ReversalsTest | Automated | |
| F4 A return with no receipt: the owner only, flagged | ReversalsTest (a manager refused, the owner allowed, the flag) | Automated | |
| F5 The day-end close: blind count, variance, a flag above the threshold, the Z-report with the audit code, a closed shift locked | DrawerTest (expected cash, the variance, the flag, the Z-report, the lock), counter.spec (check F5) | Automated | |
| F6 Paid-outs, drops and top-ups: a reason, a manager above the set amount, expected cash changed | DrawerTest (the limit, the manager, a retry once, expected cash; over HTTP with the drawer) | Automated | |

## In numbers

- 43 checks are proved by automated tests in CI.
- 2 are proved at the G3 software rehearsal: E1 (a restore onto a clean machine within 1 hour) and E2 (profile to first sale under 2 hours, and the hard reboot).
- 1 is measured in the first live week: A1 (real cashiers' speed, D67).
- 6 more, F1 to F6, are proved by tests in CI too, so 49 of the 52 are automated (D68).

G4 passes when every row, F1 to F6 included, has a result written in its last column.
