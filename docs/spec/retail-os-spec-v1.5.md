# HAQ Retail OS Spec v1.5

HAQ Technology. Laravel and PostgreSQL, offline-first LAN. Replaces v1.4 in full. 5 November 2026; signed off by the product owner on 6 November 2026 [D13].

**Purpose.** This is the complete v1.5 of the product spec. It keeps everything in v1.4 and applies the decisions of the v1.4 review: 20 spec changes (S1 to S21, with S13 not adopted), 8 build changes (R1 to R8), the delivery and design decisions D1 to D38, starter catalogue v1.1 and the answers recorded in docs/decisions.md. The build team works from this one document; PLAN.md holds the schedule.

**How to read it.** A tag such as [F3] marks text that exists because of finding F3 of the engineering teardown (Appendix A). A tag such as [S4], [R3], [C1] or [D2] marks text set by the v1.4 review; Appendix E lists every such change, and docs/decisions.md records where each came from. [Q5] or [FU6] points to an answer in that log; FU1 to FU10 are the review's follow-up questions, not teardown findings.

**New in v1.5.**
- Prices fill in from the item's set price, then the last price sold; the owner and the manager set prices. [S4]
- The server prints to network printers, with no agent on counter PCs, and the cash drawer is optional per counter. [R3, S21]
- Catalogue items are flat rows with a uniqueness key, and generic shapes are built on demand. Starter catalogue v1.1 adds 466 phone models. [R1, R2, C2]
- Code lives in one GitHub repository with CI on every change; shops pull signed releases from GitHub, over a phone hotspot where there is no internet. [D1, D2, D3]
- Counters are touch-first, a scan can never trigger payment, and a slow transfer no longer freezes the counter. [Q5, S1, S2]

# 1. What we are building
HAQ Retail OS is a POS for accessory retail shops (power banks, chargers, earpods, cables and similar) that never lets missing data block a sale. The catalogue builds itself while the shop trades, and profit becomes accurate as the owner supplies cost prices, without ever stopping the counter. [F20]
It is sold as a product to many shops. Each shop has one owner and one or more branches. Each branch runs on its own LAN with no dependence on internet. Your team installs and configures every shop from one sysadmin console.
## The six problems this product exists to solve
Every feature and every engineering practice in this spec must serve at least one of these.

| ID | Problem | Answer |
|---|---|---|
| P1 | Receipts say “Others”, and sales cannot be traced to amounts | Every item has a full descriptive name built by one routine from its category and facets. Receipts never print a generic name, including for items typed in Request-only mode, where the category is mandatory and the typed text is validated. [F20] |
| P2 | The counter must stay fast in a queue | Scan, fuzzy search from a browser cache, or tap the tiles. Price is pre-filled with the item's set price, otherwise the last price sold. [S4] Every counter interaction runs on local state, and only Finish and item creation wait for the server. [F25] |
| P3 | Profit and cost cannot be trusted when cost is unknown | Cost is NULL until the owner enters it, then resolved through an append-only table. Profit is always shown in three separate buckets: confirmed at sale, estimated by backfill, and revenue pending cost. [F14] |
| P4 | Theft and mistakes leave no trail | Cost hidden server-side, hash-chained and anchored audit log, approvals bound to the action, blind cash count at every cashier handover, transfer confirmation with reference, a logged drawer, and database-enforced append-only tables. [F3, F9, F12] |
| P5 | Onboarding hundreds of variants takes weeks | Install to first sale in under 2 hours with your team on site, and a new cashier trained to a first sale in 15 minutes. The catalogue is learned from selling, and a starter catalogue ships with the product. [F25] |
| P6 | A small team must support many offline shops | Signed Docker releases pulled from GitHub, a sysadmin console, health page, automatic recovery, signed releases with code-only rollback, and honest, tested recovery targets. [F2, F16] |

# 2. Design principles
- Missing data never blocks a sale. A missing cost, name, barcode or stock count may not stop a sale. The only hard stops are the deliberate ones listed in section 7, each chosen for a stated reason. [F20]
- Nothing is edited or deleted. Sales, lines, payments, returns, shift events and audit rows are immutable. Corrections are new reversing or resolving records.
- Cost never leaves the server for unauthorised roles. Enforced by policies, API resources and a separate restricted audit stream, never by hiding fields in the UI. Models are never passed straight to the browser. [F10]
- The database enforces what matters. Append-only is enforced by roles, grants and triggers that the running application cannot change. [F3]
- Restores and wrong clocks never rewrite history. A restore starts a new numbering epoch, and a wrong clock is marked, never silently corrected. [F1, F15]
- Everything is configurable by you, in one place. Shops do not self-configure unless you unlock the setting.
- Optional features ship off. Credit, stock tracking and similar are switched on per shop in the console.
- The server is the authority. The browser previews, the server recalculates every price and total and finalises every sale.
- Every bulk change is staged, reviewable and reversible. This covers imports, merges and cost backfills.
- Touch first. Counter screens are designed for touchscreens; keyboard and mouse remain full fallbacks. [Q5]
# 3. Roles and permissions

| Capability | Cashier | Manager | Storekeeper | Owner | Sysadmin (you) |
|---|---|---|---|---|---|
| Sell, type price, set customer type | Yes | Yes | No | Yes | No |
| Set or change an item's set price [S4] | No | Yes | No | Yes | No |
| Hold and resume sales | Yes | Yes | No | Yes | No |
| Discard a parked sale | Own, with reason, below the limit | Yes, with reason | No | Yes | No |
| Create item on the spot | Per setting | Yes | Yes | Yes | No |
| Add a brand to the pick-list | No | Yes | No | Yes | No |
| Create or rename a category | No | No | No | Yes | No |
| See cost price | No | No (per-shop override) | No | Yes | No |
| Enter or change cost | No | Per console setting [FU6] | No | Yes | No |
| Approve return, exchange or void | No | Yes | No | Yes | No |
| Approve price below the floor | No | Yes | No | Yes | No |
| Approve credit sale | No | Yes | No | Yes | No |
| Return without receipt | No | No | No | Yes | No |
| Shift open and close, handover count | When holding the drawer [S11] | All shifts | No | All | No |
| Paid-outs, drops and top-ups from till | Request, with reason | Approve | No | Yes | No |
| Shelf-scan sessions, restock entry | No | Yes | Yes | Yes | No |
| Merge and rename items | No | Yes | Yes | Yes | No |
| Review barcode links and aliases | No | Yes | Yes | Yes | No |
| Reports | Own sales only | Branch, without profit | Stock only | All, with profit | Platform health only |
| Audit log (read) | Own actions, redacted | Branch, redacted | No | All, including sensitive values | Support access with consent |
| Feature flags, policies, licence | No | No | No | Only unlocked settings | Yes |

## Identity and approvals
- Cashier identity is a console setting. Mode A (default) is a name tile followed by a PIN of 4 to 6 digits (exactly 6 for a manager or owner, whose one PIN also approves [D24]), with no logout; after 3 minutes idle (a console setting, 0 turns it off) the counter returns to the name tiles, keeps the cart, and the PIN resumes it [S6]. Because the tile identifies the person first, PINs need not be unique and a collision never leaks. Mode B is one login per PC, and the console warns that per-cashier reports and audit are then less reliable. [F8]
- Acting-user token. The terminal login identifies the device only. Each PIN entry mints a short-lived signed token naming the acting user, bound to that terminal, and every request carries it. Policies, audit rows and cost redaction resolve the actor from the token, never from the shared session. A request already in flight during a PIN switch keeps the token it was sent with. [F9]
- Manager approvals use a 6-digit PIN entered at the same screen after tapping the manager’s name tile. The approval is single-use and bound to the specific action (for example this return line or this price). The server binds it to the cart line, item and price when it is granted; the 60-second limit covers only grant to bind, and the approval then holds while the cart is open or parked. Changing that line voids it. It does not change the cashier's session. [S3] It is stored in an approvals record with the approver, terminal and action. [F9]
- Throttling: five wrong PINs lock that user for a cooldown period (15 minutes). Separately, each terminal backs off exponentially after 10 wrong PINs in 10 minutes across all users (1 second, doubling, at most 5 minutes), so guessing across names is also slowed. [D28] Lockouts and backoffs appear on the owner dashboard. PINs are stored with Argon2id. [F8]
- Approval flagging: an approval by a manager who is not signed in at that branch that day is flagged for the owner. Signed in means the manager entered a PIN at any counter of the branch since the business day opened. [F8, S3]
- Elevated sessions (a manager signed in at a counter) expire back to the cashier after an idle limit you set (2 minutes by default). [D28]
- Back-office devices. The owner's or a manager's phone or laptop on the shop's network is enrolled like a counter, with a code such as D01 instead of T01, and signs in with the same name tile and PIN. It opens the back office, has no cash drawer and never sells: Finish refuses a sale from it. [D29]
- Users are deactivated, never deleted, and the last active owner cannot be deactivated. Deactivation is a high-priority record that propagates with the next sync and can also be applied locally at any branch at once (see section 4). [F18]
# 4. Architecture and deployment
## Runtime shape
- One server per branch: a small always-on mini-PC running Ubuntu Server with Docker. It runs Laravel and PostgreSQL. Counter PCs are wired by Ethernet and open the app in a browser over the LAN with HTTPS. Counter PCs run Windows with Chrome in kiosk mode on touchscreens; keyboard and mouse are fallbacks. [Q5]
- Hardware rules. The UPS powers the server, the network switch and the router, since a dead switch stops the shop as surely as a dead server. At least one counter PC (a laptop works) and its printer also run from the UPS or the shop's inverter, so selling continues in a power cut. [S7] The server uses SSDs with power-loss protection, and PostgreSQL runs with fsync and synchronous_commit on. The server has a fixed IP and a local name, so a DHCP change cannot break the counters. [F24]
- Local certificate. A local certificate authority issues the HTTPS certificate with a long life, and its expiry date is shown on the health page with a warning 60 days ahead. Renewal is a console action. [F24]
- Head office is your central cloud, multi-tenant PostgreSQL with tenant_id and row-level security. It hosts the console, licences, starter catalogue, fleet health and the owner’s cross-branch view.
- Printing. Each paying counter has an 80 mm thermal printer with an Ethernet port and a fixed IP. A queue worker on the server sends ESC/POS straight to the printer's port 9100, with auto-cut and the receipt number as a Code 128 barcode. Printer status (paper out, cover open) is a query on the same socket. There is no native print agent on counter PCs. A shop that already owns USB-only printers uses browser printing through an 80mm stylesheet in Chrome kiosk mode. [F12, R3, S9]
- Cash drawer. Optional per counter: each counter is set to drawer or no drawer in its install profile, changeable from the console. A fitted drawer plugs into the printer and opens with the print job of a sale that takes cash, so the server logs every opening. [D31] Without a drawer, cash sales still record the amount tendered and the change, and cash still reconciles at close; there is no opening log and no No sale button. If a fitted drawer does not open, the sale still completes, and the cashier opens it with the key and taps Opened with key, which is logged. [F12, S21]
- No Redis. Sessions, jobs and cache live in PostgreSQL, so each shop has one service fewer to install, monitor and fail.
## Frontend
Laravel with Inertia and React, written in TypeScript and styled with Tailwind CSS, whose only colours are the tokens in section 15. The shop server's code lives in apps/shop-server/, with its versions pinned. [D20] It is a modular monolith: one app and one database, with the code split by business area into modules whose dependencies run one way, checked by architecture tests. Migrations and the mutability matrix stay whole, because Finish writes across modules in one transaction. [D23] The active catalogue is cached in the browser with client-side fuzzy search. The server finalises every sale, and the browser never decides a total. The back office is ordinary Inertia pages. The counter is one Inertia page that boots a self-contained client: a state store, the catalogue in IndexedDB, search in a Web Worker and a service worker for the app shell, talking to a small JSON API, so a server restart shows Reconnecting with the cart intact. [R4]
- Local state first. Adding lines, search, price edits, hold and totals preview run entirely in the browser. Only Finish, item creation and the search fallback call the server. The 100 ms budget applies to what the cashier sees, not to a server round trip. [F25]
- Catalogue delta feed. The server stamps every catalogue change with a monotonic catalogue_seq, given at commit so that seq order is commit order and no change is ever skipped [D32]. A new counter starts from a paged snapshot. Each counter polls for changes since its last catalogue_seq every 2 seconds, which also serves as its server-reachable heartbeat [R5], so counter B does not create a duplicate of an item counter A made a moment ago. Item creation also runs a server-side similar-item check against the latest data. [F25]
- Counter journal. Each counter keeps, for seven days, a local summary of every sale it finalised (receipt number, time, total, lines). It is used only for restore recovery (section 9) and never as a source of truth. [F1]
- Eloquent models are never passed to the page. Only API Resources with explicit fields reach Inertia, because model hiding is not role-aware. [F10]
## Docker and GitHub delivery
- Compose stack per branch: app (PHP-FPM), web (Nginx), db (PostgreSQL), scheduler and queue workers (the print queue among them), backup (pgBackRest). The migration job is a separate one-shot container that alone holds the owner database role. [F3] Two more one-shot jobs run before the app starts: roles creates the database roles, and certificate makes the HTTPS certificate, so no running container holds the owner's password. The installer makes every secret on the server itself. [D26]
- Pinned versions for every image and framework, a named volume for the database, and no bind mounts in production, so every shop behaves the same and rebuilds are reproducible. Code sits in a thin image layer above a pinned base, so a routine update downloads a few MB, and servers ship pre-imaged with every image loaded. [S18]
- Code lives in one GitHub repository, developed trunk-based: short-lived branches, a protected main, and a merge only with green CI. One person builds the product, so no review is required. The repository is public until the owner makes it private, so nothing secret or personal is ever committed. [D6, D10, D15] GitHub Actions runs the full test suite against a real PostgreSQL on every pull request. Images are built once on merge and pushed to GitHub Container Registry (GHCR), pinned by digest. A release is a semantic-version tag plus a release manifest that lists the image digests, the compose file and the migrations. Signing is not done by CI: the release signing key lives on an offline hardware token, and a person signs the manifest. Config bundles and licences are signed by the same offline process or by a derived key you can revoke. [F17, D1]
- Two embedded public keys (current and next) let you rotate the signing key while shops are offline. A revocation list travels in config bundles. [F17]
- Release channels: stable, beta, pinned. An update goes to a beta shop first, then the fleet. The pilot shop is the beta channel and takes a weekly release. [D2]
- Updates and rollback. Updates are pulled, never pushed: the updater on the shop server checks the signed release manifest, pulls the images from GHCR by digest with a read-only token of its own, and only then backs up, migrates and runs the health check. A shop with no internet connects a phone hotspot for the update; a MiFi is optional. [D2, D3, S18] A backup always precedes an update. Migrations follow expand-then-contract, so the previous image still works against the new schema. A failed post-update health check rolls back the code only. The database snapshot is restored only if the migration itself failed before trading resumed. After trading has resumed, the fix is a new release rolled forward, so no sale made since the update is ever discarded. [F16] The release manifest pins the images by digest and the Compose file by hash, and is signed offline with an Ed25519 key; a server refuses an unsigned, changed or older release. [D36]
- Version skew. A branch and head office are compatible within two minor versions. Every bundle carries its schema version, and a shop that was offline for months upgrades through each intermediate migration in order. [F16]
- Secrets arrive in the config bundle encrypted to that shop’s own key, never in a bundle shared across shops. Nothing sensitive lives in the repository. [F17]
## Data, keys and sync
- ULID primary keys on every application table, with branch_id and terminal_id on every sales row, so branch data merges without collisions. Laravel's own queue, cache and session tables keep Laravel's keys, because they hold no business records and never sync. [D21]
- Receipt numbers have the form T01-E1-000123: terminal, epoch, then a gapless counter. The counter is allocated inside the sale transaction from a locked row, so a failed sale leaves no gap. The epoch increases on every restore, server replacement or reinstall and is recorded as an event signed with the branch's own key (tamper-evident, not proof against root). [S19] The new epoch is one more than the highest found anywhere: the restored database, an epoch file in the backup repository, every counter journal and head office; trading resumes only when each enrolled counter has reported or support overrides. [S14] After a restore the counter also jumps forward by a safety margin of 1,000. A receipt number therefore can never repeat, even if the last minutes of sales were lost. [F1]
- Bundle dedupe keys on the sale ULID plus a content hash, never on the receipt number. A bundle whose ULID matches but hash differs, or whose receipt tuple (branch, terminal, epoch, number) matches a different ULID, goes to a conflicts queue for review. It is never silently dropped. [F1]
- Money is stored as integer kobo. Times are stored in UTC. The business day is assigned when a shift opens and every sale in that shift belongs to it, so a shift that runs past midnight is not split. Daily, weekly and monthly profit cut on that business day (default timezone Africa/Lagos). [F15]
- The MVP ships the conflict-free directions first: branch to head office for branch-owned records, and head office to branch for config, licences and catalogue updates. Two-way sync of shop-global records follows straight after the MVP, because target shops include multi-branch shops. [R7, FU7]
- Sync direction: sales, payments, shifts, stock and audit are branch-owned and flow up only, so no sale conflicts are possible. Catalogue, settings, users and cost are shop-global and flow both ways under these rules: [F18]
  - Every shop-global record carries a hybrid logical clock and its origin branch. Cost entries are append-only and resolve by effective_from, then clock, then branch ID.
  - A user deactivation beats any concurrent edit. Locked settings always follow head office.
  - A catalogue merge is applied only if it creates no cycle. If branch A merges X into Y while branch B merges Y into X, the later arrival becomes a proposal in the owner’s review queue.
  - Credit limits are checked against the balance a branch knows. They are scoped per branch, so shop-wide exposure is at most the limit times the number of branches. The owner sees this at setup.
- Sync bundles are signed, append-only and encrypted to the head office key because they carry cost and credit data. They upload automatically when internet is present, or travel on a flash drive. Importing the same bundle twice changes nothing. [F10]
## Security and clock
- Three database roles. The owner role owns the tables and runs migrations from the one-shot job only. The app role has INSERT and SELECT on append-only tables, and UPDATE and DELETE only on tables that are meant to change. The reporting role is read-only and sees cost-safe views. The app role has no TRUNCATE, no GRANT and no superuser rights. The database port is never published. [F3]
- Defence in depth. Statement triggers reject UPDATE, DELETE and TRUNCATE on append-only tables even if a grant drifts. Row-level security at head office uses FORCE ROW LEVEL SECURITY so table owners cannot bypass it. A nightly job compares live grants with a signed expected list and raises a health alert on any difference. [F3]
- Threat model note. A person with root on the server box can still do harm. That risk is bounded by audit anchoring (section 10), by owners not receiving shell access, and by every support session being consented and audited. [F3]
- Clock integrity. Each sale carries a per-terminal monotonic sequence and a clock_state: trusted, unverified or corrected. The server runs a drift check at startup and uses NTP when online. A date earlier than the build date, or a jump backward, puts the server in unverified-clock mode: selling continues, sales are stamped unverified, the owner is asked to confirm the time, and licence rollback checks pause for 48 hours instead of locking the counter. A confirmed correction is stored as an offset over a range of sequence numbers. Reports read a corrected-time view and the original timestamps are never altered. Rollback detection past that grace period still locks, with a manual override code issued by you. [F15]
## Backups and recovery
- pgBackRest archives WAL continuously to a second internal disk (repository 1). When the shop has internet (a MiFi is optional), it also keeps an encrypted copy in cloud object storage (repository 2): WAL every 5 minutes, a weekly full and daily incrementals. A shop without internet rotates two encrypted flash drives, one always away from the shop. Retention is 7 daily and 4 weekly full copies. [S16]
- Honest targets. For loss of one disk: at most 15 minutes of data lost. For loss of the whole machine (theft, fire, surge): up to 24 hours unless the shop has the standby kit, a second device in another room that streams WAL continuously and cuts the whole-machine loss to about 15 minutes. Service is restored within 1 hour on the pre-imaged spare PC in both cases. The contract states which tier the shop bought. With internet and the cloud copy, whole-machine loss falls to about 5 minutes of sales without the standby kit. [F2, S16]
- A weekly automatic test restore loads the latest backup into a temporary database, checks row counts and verifies every audit chain. Scheduled jobs use catch-up scheduling: anything missed because the box was off at night runs at the next idle window. [F24]
- A copy of the branch key and of each counter's highest epoch is kept beside the backups on the second disk, so a restore on the spare box keeps the key and starts a higher epoch. WAL is archived at least every 15 minutes even in a quiet shop. [D35]
- Backup key custody: a per-shop key split into three shares, any two of which restore (Shamir, 2 of 3): the owner, HAQ, and a sealed paper share kept off-site, for example at the owner's bank. Neither party alone can read a shop's off-site backups. The copy on the second internal disk needs no ceremony, because the live database on the same box is readable anyway. There is no shared master key. [F2, S17]
- Reboot recovery: restart policies on every service, auto-sign-in, and a “starting, please wait” page until the counter is ready.
- Health page (sysadmin): last WAL archive, last full backup, last verified restore, free disk, SSD health, database size, app version, certificate expiry, clock state, grant-drift status and chain verification. A warning appears if a backup is older than 48 hours. [F24]
## Why PostgreSQL
- pg_trgm for the server search fallback, so “ora free3c” finds “Oraimo FreePods 3C Earbuds”. [S10]
- Recursive queries for the category tree. [R1]
- Roles, grants and triggers for append-only enforcement.
- The same engine at branch and head office.
## Performance budgets
Local interactions respond in under 100 ms. Search under 100 ms from the browser cache (300 ms server fallback) at 5,000 items. Sale finalisation under 2 seconds at the 95th percentile, with two terminals finalising at once. Power-on to counter under 3 minutes. [F4, F25]
# 5. Data model (core)

| Table | Fields and rules |
|---|---|
| categories | id, parent_id, code (permanent), name, word, sellable, aliases. A small tree: a parent is never sellable, and every sellable category has a word. [R1, C1] |
| items | id, category_id, brand_id, brand_text (a typed brand not yet approved), free_text, typed_category_word (when the cashier used Not in the list), item_code (auto, immutable), display_name (cached), search_text, status (active, pending_review, pending_category, archived, merged), merged_into_id, source (starter, owner, cashier, import, generated). A unique index over category, brand, normalised facet values and normalised free text makes a duplicate impossible. [R1, R2] |
| item_facets | item_id, facet_key, picklist_value_id or text_value. Values point at pick-list value IDs, so a rename changes one row. [R1] |
| picklist_values | id (permanent), list, name, aliases, parent_id (a phone model's family), status (active, pending, archived). A typed phone model stays pending until a manager or the owner approves it. [C2, C9] |
| item_prices | item_id, customer_type (retail, wholesale), price_kobo, effective_from, set_by. Append-only; the latest row is the set price. Owner and manager. [S4] |
| brands | id, name (unique ignoring case), aliases, status (approved, pending, merged), merged_into_id, created_by, source (starter, owner, cashier). A typed brand stays pending until a manager or owner approves it. [v1.3] |
| product_models | id, seed_id, brand_id (none for a model code with no brand), name, categories. The seed's models and product lines, offered for an item's model. [D25] |
| merge_events | item_id, merged_into_id, kind (merge, undo), at, by. Append-only: an undo is a new row. Needed for undo and for cycle checks. [F18, S15] |
| category_templates | category, facets, drill order, name order, generator rules (combine, blank allowed, exclude), and whether the counter asks for colour [D9]. Generated shapes are offered at the counter, and an item row is created on the first sale. [R1, R2] |
| barcodes | code, item_id, learned_at, learned_by, status (unconfirmed, confirmed, disabled), confirmations. One code may map to several items. [F13] |
| barcode_ignore | pattern or code, reason. Placeholder and junk codes that never learn. [F13] |
| aliases | item_id, term, hits, distinct_sales, status (candidate, active). Active only after the activation count (default 3). [F13] |
| cost_entries | item_id, unit_cost_kobo, effective_from, entered_by. Never updated, only added. Owner only. |
| cost_resolutions | sale_line_id, unit_cost_kobo, source (backfill_estimate, owner_batch, restock_match), basis_from_date, resolved_by, resolved_at. Append-only. [F14] |
| epochs | terminal_id, epoch, started_at, reason (install, restore, replacement, reinstall), signed record. [F1] |
| receipt_counters | terminal_id, epoch, last_no. Locked inside the sale transaction. [F1] |
| sales | id, branch_id, terminal_id, epoch, receipt_no, seq, idempotency_key, payload_hash, shift_id, cashier_id, customer_id, customer_type, kind (sale, void, back_entry) [S15], total_kobo, business_day, recorded_at, clock_state. Unique on (branch, terminal, idempotency_key) and on (branch, terminal, epoch, receipt_no). [F1, F5, F15] |
| sale_lines | sale_id, item_id, name_snapshot, color_note, qty, unit_price_kobo, cost_snapshot_kobo (NULL if unknown), flags. Immutable. item_id is the item actually selected. |
| print_jobs | sale_id, kind (receipt, duplicate, z_report), status (pending, printed, failed), attempts. Written inside the sale transaction. [F5] |
| sale_timings | sale_id, terminal_id, started_at, finished_at, how each line was added (scan, search, tile, new item). Counter instrumentation with no personal data. [S8] |
| payments | sale_id or customer_id, method (cash, transfer, pos, credit, debt_payment, store_credit), amount_kobo, reference, confirmed_by, confirmed_at. A check constraint requires debt_payment to carry customer_id and no sale_id, and every other method to carry sale_id. [F12, F23] |
| payment_allocations | payment_id, sale_id, amount_kobo. Lets a debt payment settle named sales so debt ageing is exact. [F23] |
| returns | original_line_id, qty, reason_code, approved_by, refund_type (cash, store_credit, exchange), disposition (restock, defective_writeoff, supplier_claim). Cumulative returned qty may never exceed sold qty. [F7] |
| voucher_entries | voucher_code, delta_kobo, ref (return or sale). A store-credit voucher is a printed code with an append-only balance ledger. It needs no customer record. [F7] |
| approvals | action, subject_id (the client line ID for a price), item_id, price_kobo, approver_id, terminal_id, token_hash, issued_at, bound_at. Column-restricted: only bound_at may be set, once, from NULL. [F9, S3, S15] |
| sign_in_attempts | user_id, terminal_id, kind (sign_in, approval), succeeded, attempted_at. Append-only. Lockouts and terminal backoff are read from it, and it feeds the owner dashboard. [F8] |
| drawers | id, branch_id, terminal_id, label, fitted (true or false). A shift belongs to a drawer; a counter with no fitted drawer still has one for cash accountability. [F19, S21] |
| shifts | drawer_id, opened_by, business_day, opening_float, status. Not updatable once closed (trigger). [F3, F19] |
| shift_events | shift_id, type (open, handover_count, close_count, auto_close [S12]), cashier_id, counted_cash, expected_cash, variance. Append-only. [F19] |
| cash_events | shift_id, type (drop, top_up, cash_out_service, no_sale_open, key_open [S21]), amount, reason, approved_by. [F12, F19] |
| paid_outs | shift_id, amount, reason, requested_by. Approval is recorded in approvals. Append-only. [F3] |
| parked_sales and parked_sale_events | branch_id, label, kind (hold, awaiting_transfer), payload; events: parked, resumed, transfer_confirmed, discarded (with reason and actor). Append-only events. [F12, S2] |
| customers and credit_limit_history | name, phone, type; limit per customer per branch, each change a new row. [F3, F18] |
| stock_movements | item_id, branch_id, variant_key (nullable colour), type (opening, restock, sale, return, adjust), qty. [F22] |
| cost_layers (Pro) | item_id, effective_at, qty, unit_cost_kobo. Forward-only weighted average inputs. [F21] |
| restocks | supplier, date, lines (item_id, qty, unit_cost) |
| audit_log | chain_id (one per terminal plus a branch chain), seq, actor, device, action, redacted_before, redacted_after, sensitive_hash, prev_hash, hash. Append-only. [F4, F10] |
| audit_sensitive | audit_id, full before and after, including cost and limits. Owner-readable only. Committed to the chain through sensitive_hash. [F10] |
| audit_roots | seq, hash of all chain heads, prev_root. Created every minute and at each Z-report and daily summary. [F4] |
| sync_conflicts | bundle_id, kind, record ids, status. Never dropped silently. [F1, F18] |
| settings and settings_history | scope (platform, shop, branch), key, value, locked_by_sysadmin. Each change adds a history row. [F3] |
| licenses | shop_id, branch_id, tier, valid_until, hardware_fingerprint, signature. [F17] |
| users, branches, terminals, tenants | Users are deactivated, never deleted. A terminal is a counter (T01) or a back-office device (D01) that cannot sell; its code and kind never change once written. [D29] |

- Sale lines are never updated. A line keeps cost_snapshot_kobo as NULL if no cost was known at the time of sale.
- Effective cost of a line = its cost_snapshot_kobo if present, otherwise its latest cost_resolutions row, otherwise NULL. A database view exposes this together with its source (at_sale, backfill_estimate, owner_batch), so reports never need to know about the split. [F14]
- Merging duplicates sets merged_into_id on the item only, and writes a merge_events row. Reports follow the pointer, and no sale row changes. An undo clears the pointer and writes an undo row to merge_events. [S15] Sales made on the survivor after the merge stay with the survivor, and the undo screen states this before confirming. [F18]
- Payments are separate from sales. One sale can have several payment rows. Split payment, credit, debt collection and cash reconciliation all use this one table.
- Mutability is set table by table in the matrix below. Grants, triggers and the nightly grant check are generated from it, so the rules live in one place. [F3, S15]

| Tables | Mutability | Rule |
|---|---|---|
| sales, sale_lines, payments, payment_allocations, returns, voucher_entries | Append-only | Corrections are new reversing or resolving rows |
| cost_entries, cost_resolutions, item_prices | Append-only | The latest row wins |
| shift_events, cash_events, paid_outs, parked_sale_events, sale_timings, sign_in_attempts | Append-only | |
| merge_events | Append-only | An undo is a new row |
| credit_limit_history, settings_history | Append-only | Each change adds a row |
| audit_log, audit_sensitive, audit_roots, epochs | Append-only | |
| stock_movements | Append-only | Once stock tracking is on |
| approvals | Column-restricted | Only bound_at may be set, once, from NULL |
| shifts, parked_sales | Mutable while open | A trigger locks a closed shift |
| receipt_counters | Mutable | last_no only, inside the sale transaction |
| print_jobs | Mutable | status and attempts |
| categories, items, item_facets, picklist_values, brands, product_models, barcodes, aliases, category_templates | Mutable | Every change is audited. Seed IDs, category codes and item codes never change once written [C9, D25] |
| users, terminals, drawers, branches, tenants, customers, settings, licenses, sync_conflicts | Mutable | Users are deactivated, never deleted |

# 6. Catalogue and onboarding
## The catalogue model and attributes
Categories form a small tree (Wall chargers, then Charger head). Items are flat rows: a category, a brand and facet values that point at pick-list value IDs, with a unique index that makes a second identical item impossible. Each category's template defines its facets, the drill order of the tap-through tiles and the name order; the tiles are generated from the drill order. [R1]
- Attribute types: number with unit (35W, 10000mAh), text, or pick-list. Units and brands come from pick-lists, so “35 w” and “35watt” cannot appear.
- An attribute’s key and type are fixed once any item holds a value, so a later edit cannot silently change past receipt names.
- A pick-list rename changes one row; cached names are rebuilt in the same transaction, with one audit entry. [R1] Archiving hides a value from new choices.
- Item codes are short, auto-generated and immutable. There is no category-prefix scheme, because prefix administration would add the setup work onboarding is meant to remove.
## Display-name grammar
Every printed name is built by one routine in this order: Brand, Model or connector pair, Category word, Specs. A category's name order may put a spec before the category word where the shop says it that way ("iPhone 11 Silicone Pouch"), and every sellable category has a category word, so a name is never blank. [C1, FU4]
- Blank parts are skipped.
- Colour is never part of the name. It prints as a separate note on the line.
- Cable length and rated power are optional facets. A cable sold with no length prints without one. A no-length item mixes lengths in one price history, so its usual price range is less accurate, and the owner can split it later. [v1.3]
- The routine runs again whenever a category, attribute or list value is renamed.
- For cashier-created items the last piece is free text, and the printed name is still the routine’s output, never the free text alone.

| Parts | Printed name |
|---|---|
| Oraimo, FreePods 3C, Earbuds | Oraimo FreePods 3C Earbuds |
| Type-C to Lightning, Cable, 35W | Type-C to Lightning Cable 35W |
| Anker, Type-C to Type-C, Cable, 60W | Anker Type-C to Type-C Cable 60W |
| Anker, Power Bank, 10000mAh | Anker Power Bank 10000mAh |
| iPhone 11, Silicone, Pouch | iPhone 11 Silicone Pouch |

## Starter catalogue (you build it once)
- Version 1.1 is drafted for the owner to re-confirm (v1 was locked on 2 November 2026). It has 34 categories (28 sellable and 6 parents), 73 brands plus No brand, 29 models and product lines, 19 phone families with 466 phone models, and 5 generator rules that offer 127 generic shapes on demand. The seed docs/catalogue/starter-catalogue-v1.1.json is the master; its workbook and Appendix C are generated from it. [S20, C1 to C13]
- Taxonomy only: no prices, no costs, no barcodes.
- Generic shapes come from generator rules (for example connector pair × length for cables) and are built on demand: an item row is created on the first sale, so impossible combinations never reach a report and setup needs no prune review. [R2, S20]
- Brand and model lists are seeded from your first client shop during one deliberate shelf walk.
- It is versioned. A pull never overwrites local items, and matches are proposed as merges. Every seed entry has a permanent ID, so a pull can tell a rename from a new entry. [C9]
## Onboarding phases

| Phase | What happens | Time |
|---|---|---|
| 0. Prepare | Starter catalogue and shop profile file generated before the visit. In the pilot, where the console is not built yet, you generate the profile with a command-line tool (shop:make-profile); the profile carries the shop, the branch, its counters and drawers, and the receipt text, and applying it twice changes nothing. [F25, D14] | Before the visit |
| 1. Install | Your team installs the Docker bundle, applies the profile, creates users, ticks categories and brands, connects printer, drawer and scanner, prints a test receipt, and runs the power-cut drill | About 2 hours |
| 2. Learn while selling | Items and barcodes are learned from real sales | Weeks 1 to 2 |
| 3. Cost queue | The owner prices items sold without cost, sorted by revenue, with bulk apply | From day 2 |
| 4. Shelf-scan sessions | The storekeeper scans items and enters cost and opening quantity outside rush hours | Weeks 2 to 4 |
| 5. Hygiene | Review queue, barcode and alias review, duplicate merging, catalogue health panel | Ongoing |

## The three counter situations

| Situation | What happens | Target |
|---|---|---|
| Barcode known | Scan, done when the item has a set price; otherwise confirm or type the price [D7] | About 3 seconds |
| Barcode unknown, item exists | Search once. The barcode is linked and flagged for review | About 10 seconds the first time |
| Item not in the catalogue | New item [S1], pick category, facet pickers with autocomplete, price, sell | About 20 seconds |

## Barcode rules
- A barcode points to one or more candidate items, never a guaranteed single item.
- One match adds the item instantly. Several matches open a picker of at most 5 items, most recently chosen first, that remembers the last choice. No match starts the learning step.
- A learned link works at once but is unconfirmed. It appears in a review list for the manager or storekeeper with a one-tap undo. It becomes confirmed after two further sales that chose the same item, and it is flagged as conflicted if a different cashier picks a different item for the same code. Undoing a link changes no sale. [F13]
- Junk codes never learn. The ignore list covers codes shorter than 6 characters, repeated-digit codes such as 000000, and any code already linked to more than 5 items. Scanning one opens search with no learning step. [F13]
- QR codes work the same way, because the scanner types the text.
- The starter catalogue ships no barcodes. They are learned only from each shop’s own sales.
## Aliases
- A search term is stored as a candidate alias when a cashier picks an item after searching it. It becomes active only after the same term has led to the same item three times (a setting). Terms shorter than 3 characters are never stored, and the owner (or a manager or the storekeeper, who review aliases) can remove any alias; a removed alias never learns again. [F13, D42]
## Guardrails for cashier-created items
- Category is required. Facets come from pickers. Only the last piece is free text.
- Similar-item warnings appear while typing, checked against the latest catalogue delta.
- The item is marked pending_review and is sellable immediately. A setting controls Cashiers can create items: On or Request-only.
- In Request-only mode the cashier sells a typed line. The category is still mandatory, the typed text needs at least two words and 6 characters, and a denylist (others, unlisted, misc, item, test and similar) is rejected. The line prints as the category word followed by the typed text, never the typed text alone. The owner or storekeeper promotes it later. [F20]
- Cashiers are never asked for cost.
- New brands. A cashier may type any brand. The sale goes through and the receipt prints the brand as typed. The typed brand stays on that item only, and joins the pick-list when a manager or the owner approves it from the review queue. Brand names are unique ignoring case, so “Poga” and “poga” cannot both exist, and the brand box suggests typed brands that are still waiting for approval. [v1.3]
- New phone models. A cashier may type a phone model that is not in the list for a pouch, screen protector or battery. It prints as typed, stays on that item, and joins the list when a manager or the owner approves it. [C2]
- Products that fit no category. The category picker has a “Not in the list” option. The cashier types the product word (for example “Solar light”, at least 3 characters, and a denylist rejects words such as others, misc, item and test). The receipt prints that word as the category word, never “Others”. The item is pending review, and only the owner creates the new category and moves the item into it. [v1.3]
- Item creation per cashier is counted and a high rate is flagged on the owner dashboard. [F11]
- Merging items changes no sale: the merged item points at the one it now counts under, which is never itself merged, so a merge cannot make a loop. Reports, barcode lookup and search follow the pointer, and an undo restores the item. [D42]
## Colours
Colour is a quick-pick note on the sale line, configurable per shop. It prints on the receipt and never creates a separate catalogue item. An optional shop setting, Track colour in stock, off by default, also records the colour as variant_key on stock movements and counts, so Pro stock and low-stock alerts can tell black from white without multiplying catalogue items. [F22] When an item from a category that asks for colour is added, its sale line opens a row of the shop's colours, most used first, then More. One tap sets the colour and closes the row. The next item or Pay closes it with no colour, and tapping the line opens it again. Colour never blocks a sale, and the owner ticks which categories ask for colour at install. [D9]
# 7. Counter flow
- Scan, search or tap the tiles. Tiles step through the category's drill order and show a price only on the last step. An optional facet, such as cable length or rated power, has a Skip tile, and the item prints without it. [D7] Search splits words on letter and digit boundaries, matches prefixes, allows one typo in words of 4 or more letters, treats "1m" as "1 m" and "20w" as "20W", and ranks by sales in the last 30 days. It covers item names, active aliases and the generated shapes not yet sold. [S10, R2]
- Scanner input is detected by timing (keys under 30 ms apart) and goes to the barcode handler wherever focus is, so the Enter a scanner sends never triggers payment. The first three keys of a fast run pass through as typed, so a quick "00" is never lost. [S1, D34]
- An item with a set price for the customer type is added at that price with one tap or scan. Otherwise a price pad opens, and one tap confirms the price it shows, or the cashier types another. [D7] The price field is pre-filled with the item's set price for that customer type. With no set price it shows the last price sold for that item and customer type; a shop setting uses the median of the last 10 sales instead, for shops that haggle. With no sale yet, the field is empty and the cashier types the price. The cashier overrides it freely, and flipping the retail or wholesale toggle pre-fills that type's price. The owner and the manager set prices, and one tap on the first-sale price list turns a first price into the set price. [S4] The retail or wholesale toggle sits in the counter's top bar and covers the whole sale; one sale never mixes the two. [D8]
- Price range and floor. With a set price, the usual price is the set price. Otherwise the usual range is the 10th to 90th percentile of the item's last 20 sales for that customer type, given at least 5 sales; a new item inherits a range from sibling items (the sibling sharing most facets, otherwise the category's 10th to 90th percentile [D30]). If there is still nothing to inherit, no prompt fires, and the item's first sales go on a first-sale price list the owner reviews the same day. [F11, S5]
  - Inside the range: no prompt.
  - Outside the range: “Usual ₦2,500 to ₦3,000. Sell at ₦2,000?” with a one-tap confirm by the cashier. It never reveals cost.
  - Above 3 times the top of the range: the cashier retypes the price instead of tapping, which catches a slipped zero. [S5]
  - Below the floor (default: under 50% of the bottom of the usual range), or after the cashier has used 3 below-range confirms in a shift: a manager PIN is required. Both numbers are console policies. [F11]
- Payment is cash, transfer, POS or a split. For cash the cashier enters the amount tendered, and change is calculated and printed. For POS the cashier charges the card on the terminal, chooses POS and taps Finish, with nothing to type. [D11] For a transfer, a tick that the bank alert was seen is required (console setting, on by default), and the user and time are stored. The cashier also enters the transfer reference (last digits or sender name). A sale without a reference is allowed but is flagged. The owner can import a bank statement CSV, and the system proposes matches by amount and time within 15 minutes. Unmatched transfer sales are listed. [F12]
- Awaiting transfer. While a transfer alert is slow, the cashier parks the cart as Awaiting transfer, with its amount, a label and a running timer. It shows in a Pending transfers strip on every counter, and the next customer is served. Whoever sees the alert (at the pilot shop, on the POS terminal) confirms it, and the receipt prints; one older than 30 minutes is flagged. [S2, Q13]
- Credit appears only if enabled and needs manager approval. Store-credit vouchers appear only when returns can issue them.
- Finish sends the sale with a client-generated idempotency key. The server recalculates every total and, in one transaction, writes the receipt number, sale, lines, payments, stock, audit entry and a pending print job. [F5]
  - Same key, same payload (a retry or double tap): the original sale is returned. A concurrent duplicate that hits the unique index is caught and answered with the original sale, never an error.
  - Same key, different payload: rejected with a plain message (“This sale changed. Review it and finish again”), and nothing is created.
  - Deadlock retry: only database work is retried. Printing, events and notifications run after commit, never inside the retried closure.
- Print and pending print. The print job is committed with the sale. The receipt prints when the agent acknowledges it. If power is lost after commit but before printing, the sale exists, the receipt is marked unprinted, and the counter offers “Print pending receipt T01-E1-000123” on restart. [F5]
- The receipt prints with full descriptive names, colour notes, receipt number, cashier and payment methods. A reprint reuses the original number, is stamped DUPLICATE and is logged. Every sale gets a printed receipt, and the receipt number also prints as a Code 128 barcode that the scanner reads for returns, reprints and disputes. [S9, Q14] The header (shop name, address and phone) and the footer (returns wording) come from the receipt-text setting, which only the sysadmin changes; a branch can override its address and phone. [D14]
- Hold and resume: one key parks a sale with an optional label. Parked sales are visible to every counter in the branch. Open parked sales must be resumed or discarded at shift close. A discard needs a reason. A discard above the configured value needs a manager PIN; the owner sets the value in Shop settings, and until then it is ₦0, so every cashier discard needs one. A cashier discards only their own parked sale; any other needs a manager PIN. Every discard is counted per cashier on the owner dashboard. [F12, D33, D38]
- Drawer. On a counter with a drawer, every opening is recorded in cash_events: a sale opens it through the print job, a manual “No sale” opening needs a one-tap reason, and a key opening after a failed kick is logged as Opened with key. A counter with no drawer has no No sale button. [F12, S21]
- Cashier switching and the cache. The browser catalogue updates from the delta feed. A cashier switch changes the acting-user token and clears nothing else. [F25]
- A failed finalisation is written to the audit log after the transaction has rolled back, on a separate connection, with a short lock timeout and a local spool file as fallback (section 10). [F4]
## Deliberate hard stops
Missing data never blocks a sale, but these stops are deliberate, and each is a setting or a licence rule. [F20]

| Stop | Reason |
|---|---|
| Counter locked after licence expiry and grace | Commercial. Data, reports and export stay available. |
| Transfer sale without the alert-seen tick | Transfers are the most disputed payment. One tap. Console setting, on by default. |
| A user locked out after wrong PINs | Brute-force protection. |
| Sale on a closed shift or a locked drawer | Reconciliation integrity. |
| No price entered | A line must have a price, which may be typed freely. |
| Below-floor price without manager PIN | Underselling control (section 7). |

A sale made with no open shift is not blocked. The counter opens an unattributed shift with no counted float, and the owner dashboard flags it. [F20]
Speed targets: under 8 seconds per item and under 30 seconds for a 3-item sale, measured on every sale by the counter's own timing. Screens are designed for touch first and work fully with a keyboard. Enter confirms the focused dialog and Esc cancels; Pay has its own key, never bare Enter; New item uses a key Chrome passes to the page (Ctrl+N only if proven on the kiosk build). The keys are F8 Pay, F4 Hold and F2 New item, confirmed on the kiosk build at G3. [S1, S8, Q5, D34]
# 8. Cost, profit and alerts
## Cost
- The owner enters cost through the Needs-cost queue, a restock record or a shelf-scan session. Cost lives in cost_entries, with history. Each entry can carry an as-of date, because naira prices move. A console setting lets the manager enter cost too, off by default; a manager with it sees cost. [FU6]
- Pilot and MVP: a sale line takes the owner’s current cost entry for that item. If none exists, cost_snapshot_kobo is NULL.
- With stock tracking on (Pro): a sale line takes the weighted average cost, calculated forward-only from cost layers. Rules: [F21]
  - A restock never changes the cost of a line already finalised. It affects later sales, and NULL lines through resolutions.
  - If on-hand is zero or negative when a restock arrives, the average resets to the restock cost, and sales made while stock was negative are costed at the last known average and flagged.
  - A backdated restock inserts a layer at its own timestamp and recalculates averages from that point forward for lines that have no cost yet. Finalised lines are not touched.
## Backfill
When the owner enters a cost for an item with NULL lines, the system shows a preview before anything is written: how many lines, how much revenue, and the profit swing. The owner chooses the basis: all earlier lines, lines from a chosen date, or lines since the last restock (once restock records exist, in Pro). The default is from the date of the first NULL line. A line that already has an estimate is not offered again, and the newest estimate for a line counts. [D40] On confirm, the system writes a cost_resolutions row for each selected line with source backfill_estimate (or owner_batch for a confirmed supplier batch), and recalculates below-cost alerts. No sale line is updated. Each change writes an audit entry. Later cost entries apply to later sales only. [F14]
## Profit reporting
- Profit is always shown in three buckets: Confirmed at sale (cost known when the sale was made), Estimated (resolved by backfill, valued at a later cost), and Revenue pending cost (no cost). No margin is ever guessed. [F14]
- The dashboard progress card shows, for example, “At sale 52%, estimated 19%, pending 29% of revenue”. The owner can see the effect of switching estimated lines out.
- The Needs-cost queue is sorted by revenue, highest first, and supports bulk apply of one cost to a group, with the same preview.
## Alerts (on the owner dashboard, since a LAN has no push)
- A sale below cost, when saved and again retroactively after a cost entry turns earlier sales into losses.
- A price outside the usual range, and each below-floor approval.
- Per-cashier counts of below-range confirms, discarded parked sales, no-sale drawer openings, key openings, items created, transfers without reference and transfers awaiting over 30 minutes. [F11, F12, S2, S21]
- A cash variance above your threshold, at shift close and at each handover.
- A shift auto-closed as unclosed, not counted. [S12]
- Returns without receipt, and manager approvals used or used while not signed in.
- A backup older than 48 hours, an unverified clock, a certificate close to expiry, a grant-drift or chain verification failure. [F3, F15, F24]
- Each flag is read from the record that raised it, so it can never be lost or deleted. Marking a flag as seen keeps it on the list with who saw it. [D41]
# 9. Returns, voids, credit, shifts and downtime
## Returns and exchanges
- A return is a reversing record linked to the original receipt line. The sale is never edited.
- The cashier scans the receipt's barcode, or types the receipt number or the paper receipt number, and requests the return. [S9] A manager approves with the 6-digit PIN at the same screen.
- No double return. The transaction locks the original line, sums quantity already returned and rejects the return if the total would exceed the quantity sold. Two counters returning the same line at once cannot both succeed. [F7]
- A reason code is required: wrong item, defective, changed mind. A disposition is also required: restock, defective write-off or supplier claim. Defective items never return to sellable stock automatically. [F7]
- An exchange is a return plus a new sale. The system shows the net difference to collect or refund.
- The return reverses the original effective cost, so profit stays correct. If that cost was NULL, it resolves together with the original sale.
- Void: a manager may void a whole sale within the same shift on the same drawer, recorded as a reversing sale. A void is refused if any return exists against the sale. Anything later is a return. [F7, F19]
- A return without a receipt is allowed only for the owner, and is flagged on the dashboard.
- Store credit is a printed voucher with a code and an append-only balance ledger (voucher_entries). It does not need a customer record and works with credit switched off. [F7]
- Return window and refund type (cash, store credit, exchange only) are console settings. Warranty claims are returns with disposition supplier_claim and a note.
## Credit and part-payment (feature flag, ships off)
- Customer record with name, phone and type. Phone numbers are collected only when credit is on.
- Ledger: balance = credit sales minus payments received.
- Credit sales need manager approval. The limit is per customer, with a shop-wide fallback set by the owner, and is scoped per branch (section 4). [F18]
- No over-limit by race. A credit sale locks the customer row inside its transaction and checks the limit against the balance that includes any other credit sale in flight. [F23]
- A debt payment is a payment row against the customer, not a new sale. It is allocated to named sales through payment_allocations (oldest first by default, or chosen by the owner) and prints a receipt showing the remaining balance. [F23]
- Reports: who owes what, debt ageing from allocations, total outstanding.
## Rounding
Discounts, promotions and tax are computed per line in kobo and rounded half away from zero at line level. Any remainder from splitting an amount is placed on the last line, so line sums always equal the sale total. Tests cover wholesale quantity, discount and tax combinations. [F23]
## Shifts and cash reconciliation
- A shift belongs to a drawer, not to a cashier. Who rang a sale (the acting user, switched by PIN) is separate from who holds the drawer (one accountable person). At the pilot shop customers pay the cashier who served them, so each paying counter's cashier holds its drawer; a shop setting allows a shared drawer with one variance. [F19, S11, FU2]
- Open: the cashier enters the opening float.
- Drawer handover: when the drawer holder changes (or at a manager-set interval), the outgoing holder does a blind count, then sees the variance for their own segment. The incoming holder starts from that counted amount. A PIN switch alone does not trigger a count. Handover counts are shift_events. [F19, S11]
- Expected cash = float + cash sales + cash debt collections + top-ups + cash received as fees on cash-out services − cash refunds − paid-outs − drops to the safe − cash handed out on cash-out services. A cash-out service (customer pays by transfer or POS, cashier hands over cash) is recorded in cash_events so the electronic receipt and the cash outflow match. [F19]
- Close: a blind count. The cashier counts first and then sees the variance.
- Forgotten close: a shift still open 4 hours after the shop's closing time (a setting the owner changes in Shop settings on a back-office device) closes as unclosed, not counted, and is flagged; the next sale opens a new shift on the new business day. [S12, D37]
- Transfers and POS payments are totalled by method. Transfers can be matched to an imported bank statement (section 7). POS payments are not verified against a bank.
- A variance above your threshold, at any handover or at close, goes on the owner’s flag list. Closing prints a Z-report per drawer, with each drawer holder's segment [S11] and the audit root hash, and a closed shift is locked by a trigger.
## Paid-outs, drops and top-ups
The cashier records a small cash expense from the till with a mandatory reason (transport, change and similar). A manager approves above a configurable amount. Cash drops to the safe and float top-ups are recorded the same way. All reduce or increase expected cash and appear in the shift report and the owner’s expense summary.
## Downtime procedure
When the system is down, the shop issues paper receipts from a numbered pad. Afterwards a manager uses the back-entry screen, which records each sale with its original time, the paper receipt number and who wrote it, and flags it as back-dated. The paper number is searchable for later returns. Every back-entry is audited. [F25]
## Restore recovery procedure
After any restore or server replacement, the system starts a new epoch, and the manager opens the lost-window screen. It compares each counter’s journal with the restored database and lists sales that customers may hold receipts for but that are missing. The manager re-enters them through back-entry, which keeps the original receipt number as a reference. Missing sales are therefore recovered from the counters rather than lost. [F1]
# 10. Audit trail
- Every action is logged: who, what, when, which PC, and before and after values.
- Chains. Each terminal has its own hash chain, and each branch has one more for actions that belong to no terminal. A row stores its sequence number and the hash of the previous row in the same chain. Terminals therefore never wait on each other, and a sale takes a lock only on its own terminal’s chain tail, held until its transaction commits; that is fine, because each terminal's work is serial anyway. [F4, S19]
- Roots. A root row is created every minute and at every Z-report and daily summary. It hashes the heads of all chains and the previous root, so the set of chains is itself tamper-evident. [F4]
- Anchoring: the latest root hash is printed on the daily summary and the Z-report and included in every sync bundle held at head office. A rewritten history then fails to match the printed and uploaded anchors.
- Failures are logged without blocking. A failed sale attempt is written after the transaction has rolled back, on a separate connection, to the branch chain, with a lock timeout of 200 ms. If the lock cannot be had in time, the entry goes to a local hash-linked spool file and is ingested later. Logging a failure therefore cannot deadlock or delay the counter. [F4]
- Sensitive values. Cost, credit limits and similar values are stored in audit_sensitive, and the main row carries only a hash of them and a redacted description (“cost changed”). The chain commits to the full values, so verification still works. Only the owner can read the sensitive rows. A cashier’s own history and a manager’s branch history never show cost. [F10]
- The app’s database role cannot UPDATE, DELETE or TRUNCATE audit tables, and triggers reject those statements even if a grant drifts. The UI is read-only, and even the owner cannot delete from it. [F3]
- Cashiers can read their own history. This is framed as protection: it proves a mistake was not theirs.
- Support access by your team requires owner consent and is itself audited.
# 11. Sysadmin console and licensing
## Console (a separate admin app, only you and your team)
- Shop profile: owner, branches, tier, installed version, last sync, last backup.
- Feature flags: credit, stock tracking, track colour in stock, store-credit vouchers, PIN mode, cashier item creation, return window, below-cost alerts, price floor, tax line, transfer-confirmation tick, strict price-approval mode, catalogue contribution, manager sees cost, standby kit, manager may enter cost [FU6], cash drawer per counter [S21], price fill fallback (last price or median) [S4], idle lock minutes [S6], shared drawer [S11]. [F22]
- Policies: variance threshold, paid-out and discard approval limits, price floor percentage and the below-range confirm count per shift, alias activation count, credit defaults, receipt text (the header and footer, with a branch override for the address and phone [D14]), colours list, business day and timezone, and the closing time used by the forgotten-shift rule, which the owner may change by default. [F11, F13, S12, D37]
- Lock control: per setting, you decide whether the owner may edit it.
- Delivery: a signed config bundle, applied at install and pushed automatically when internet is available. Every change is versioned and audited.
- Updates are pulled by the shop server from GitHub whenever a connection is present; config pushes are allowed with owner consent recorded in the contract. Every update and push is audited. [D2]
- Install automation: the console generates a shop profile file and the installer applies it, so installs are repeatable.
- Fleet dashboard: versions, licences, backups, certificate expiry and errors across all shops. Telemetry carries health data only, never sales or cost.
- Key operations: key rotation, revocation list, hardware transfer codes, restore epochs, and the manual clock override code. [F17]
## Licensing
- Pricing: per branch per month, with unlimited cashiers. Per-cashier pricing would push shops to share logins and break the audit trail.
- Tiers: HAQ Retail OS Core (sales, profit, audit, receipts, reconciliation) and HAQ Retail OS Pro (credit ledger, stock tracking and alerts, head office multi-branch view). In conversation and on invoices they are called Core and Pro. [v1.4]
- A one-time setup fee, since your team installs, and an optional annual plan (for example pay 10 months, get 12).
- Offline licence: a signed file with shop ID, branch, tier, valid-until date and a hardware fingerprint, verified with an embedded public key. It renews automatically when internet exists, or by a renewal file sent over WhatsApp. [F17]
- The spare PC. The pre-imaged spare is unlicensed until you issue a one-time transfer code from the console, which moves the licence from the failed machine. This stops the spare being used as a free second copy. [F17]
- Clock and expiry. Expiry is judged from the last trusted time plus monotonic uptime. A wrong or reset clock triggers the unverified-clock grace in section 4, not an immediate lock. [F15]
- Expiry: warnings start 7 days before expiry, then a 14-day grace period. After grace, the counter locks, but data and export never lock. Reports and full export stay available.
## Starter catalogue feedback
- Opt-in per shop, with consent recorded.
- Only normalised names and facet paths are sent. No prices, costs, sales counts or cashier data.
- Contributions land in a staging queue your team curates. Nothing publishes automatically.
# 12. Reports
## Pilot slice
- Daily summary, with sales, the three profit buckets and the percentage in each, by payment method, and the audit root hash. [F14] The printed slip carries no profit or cost amounts; the owner sees profit on screen. [D18]
- Below-cost, price-anomaly and cashier-behaviour flag list (below-range confirms, discards, no-sale openings, transfers without reference).
- Catalogue health: percentage of revenue with cost, items pending review, brands and product words waiting for approval, unconfirmed barcode links, barcode coverage. [v1.3]
- CSV export of items and sales, opening cleanly in Excel. Exports never contain cost for roles that cannot see it. [F10]
## MVP
- Weekly and monthly views, and top items by revenue. These move here from the pilot (see the swap in section 16). [F12]
- Per-cashier sales and variance, including handover variances. Shift (Z) reports per drawer.
- Transfers matched and unmatched against an imported bank statement.
- Return, store-credit and disposition reports.
## Pro tier
- Per-product and per-category margin, labelled by bucket.
- Stock on hand (by colour when tracked), low-stock, dead-stock and stock valuation.
- Debtors ageing from payment allocations.
- Branch comparison and consolidated head office view.
- Cashier performance trends and refund rate per cashier.
- Scheduled report export (PDF, Excel).
# 13. Release scope

| Pilot slice (first shop) | MVP (adds, to be sellable) | Pro tier |
|---|---|---|
| Counter: scan, search, tiles, open price with set-price and last-price pre-fill, price range, sibling-range inheritance, floor prompt and retype check [S4, S5] | Returns, exchanges and voids with manager PIN, line locks and store-credit vouchers | Stock tracking, colour variants, low-stock alerts, forward-only weighted cost |
| Receipts with generated names and a receipt barcode, cash tendered and change, transfer tick and reference, awaiting transfer, DUPLICATE reprints, pending-print recovery [S2, S9] | Shifts per drawer, handover blind counts, Z-report, paid-outs, drops and top-ups, cash-out services | Credit ledger with allocations and statements |
| Create-item on the spot, review queue, merge, barcode learning with confirmation, junk-code ignore, alias activation | Sysadmin console, feature flags, signed config bundles, key rotation | Head office multi-branch view |
| Needs-cost queue, bulk apply, backfill preview, three profit buckets, below-cost alerts | Signed offline licence bound to hardware, transfer codes, expiry behaviour | Advanced print agent (status, multiple printers), customer display |
| Hold and resume, discard reason and limit | One-way sync and cloud head office; two-way sync with conflict rules and cycle checks straight after the MVP [R7, FU7] | Inter-branch transfers, suppliers, purchase orders |
| PIN with name tiles, idle lock, acting-user token, bound approvals, roles (cashier, manager, owner), per-terminal audit chains and roots [S3, S6] | Starter catalogue feedback (opt-in) and versioning | Promotions, loyalty, tax line, discount rounding rules |
| Server printing to network printers, with drawer kick and drawer log where a drawer is fitted [R3, S21] | Bank statement CSV import and matching | Analytics, scheduled exports |
| Three database roles, append-only triggers, grant-drift check, restore epochs, counter journal and lost-window recovery | Per-cashier reports, weekly and monthly views, top items | Billing automation |
| pgBackRest backups with 2-of-3 key shares, restore drill, health page, Docker install and the updater pulling from GHCR, reboot recovery, catch-up scheduling [S16, S17, D2] | Staged rollout, fleet dashboard, standby kit option | Receipt by WhatsApp or SMS |
| Daily summary printout, flag list, catalogue health, CSV export, speed instrumentation [S8] | Back-entry screen with paper receipt numbers | Scheduled export |
| Starter catalogue v1.1 seed with phone models; typed brands, product words and phone models through the review queue [C2] | Phone models moved to the pilot [C2] | No change |

The never-cut list for every phase: transaction tests, concurrency and crash tests on a real PostgreSQL, backup and restore, export, audit, and restore epochs. [F6]
# 14. Acceptance criteria
Each check is pass or fail. The pilot slice is finished when all 47 checks in sections A to E pass and are written down. MVP and Pro checks are verified before those releases. Integrity and audit checks that involve concurrency, crashes or restores run as multi-process tests against a real PostgreSQL with committed transactions. Tests that wrap each case in a rolled-back transaction (Laravel RefreshDatabase or DatabaseTransactions) do not count for them. [F6]
## A. Counter and speed (13)
- A trained cashier completes a 3-item sale in under 30 seconds, and a single known-barcode item in under 8 seconds, measured by the counter's own timing over at least 20 sales. [S8]
- Typing “ora free3c” returns Oraimo FreePods 3C Earbuds in under 100 ms from the browser cache with 5,000 items loaded, and a one-letter typo still finds the item. [S10]
- Every receipt line prints the full generated name. A generic “Others” or “Unlisted” never prints. In Request-only mode, a denylisted or too-short name is rejected and an accepted one prints as the category word plus the typed text. The receipt number prints as a Code 128 barcode that scans back to the sale. [F20, S9]
- The price field pre-fills with the item's set price, otherwise the last price sold for that item and customer type; the cashier can override it; the retail or wholesale toggle switches the pre-fill; and one tap on the first-sale list makes a first price the set price. [S4]
- After one search-and-link, scanning an unknown barcode again adds the item in one step. The link shows in the review list as unconfirmed with a working undo. A barcode shared by several items opens a picker (maximum 5) that remembers the last choice. A junk code such as 000000 never learns. [F13]
- A cashier can create an item with a required category, facet pickers and a similar-item warning. It is sellable immediately and appears in the owner’s review queue.
- A parked sale appears on another counter and can be resumed there.
- Every local interaction responds in under 100 ms, and a double tap on Finish creates exactly one sale. [F25]
- An item created at counter A appears in counter B’s search within 5 seconds, and creating a near-duplicate at B shows the similar-item warning. [F25]
- A cashier can sell with a typed brand that is not in the pick-list. The receipt prints it as typed, it is not added to the pick-list until a manager or owner approves it, and a cashier cannot approve it. Typing “poga” when “Poga” exists does not create a second brand. [v1.3]
- Choosing “Not in the list” and typing a product word of at least 3 characters sells the item, prints the word as the category word and marks it pending review. A denylisted word such as “others” is rejected, and a cashier cannot create the category. [v1.3]
- A scan never triggers Pay, wherever focus is; Pay works by its own key and by touch. [S1]
- A cart parked as Awaiting transfer shows on every counter and completes when the alert is confirmed; one older than 30 minutes is flagged. [S2]
## B. Integrity (12)
- Killing power mid-sale before commit leaves no half-sale and no used receipt number. The counter returns by itself within 3 minutes. [F5]
- Killing power after commit and before print leaves the sale intact, the receipt marked unprinted, and the pending receipt offered on restart. [F5]
- Two simultaneous finalisation requests with the same idempotency key create exactly one sale, and the second request receives the original sale, not an error. [F5]
- A retry with the same key and a changed cart is rejected and creates nothing. [F5]
- Receipt numbers are gapless per terminal and epoch across a 50-sale soak test that includes forced failures, and each failure is logged.
- Totals are recalculated on the server, and a tampered browser total is rejected.
- Money is stored as integer kobo, and wholesale quantity pricing tests give exact totals.
- Cash tendered and change are calculated and printed. With the setting on, a transfer sale cannot finish without the alert-seen tick, and the user, time and reference are stored. [F12]
- After a restore, the epoch increases, no receipt tuple is reused, the lost-window screen lists sales present in the counter journals but missing from the database, and head office quarantines any conflicting bundle instead of dropping it. A second restore from the same backup still yields a new, higher epoch. [F1, S14]
- Two terminals finalising at the same moment produce valid hash chains, and sale finalisation stays under 2 seconds at the 95th percentile. [F4]
- The concurrency, crash and restore tests above exist in the repository and run in CI on a real PostgreSQL. [F6]
- A shift left open 4 hours past closing time auto-closes as unclosed, and the next sale lands on the new business day. [S12]
## C. Cost and profit (7)
- A sale of an item with no cost stores a NULL cost snapshot, and the item appears in the Needs-cost queue sorted by revenue.
- Entering a cost shows a preview with the effective-from choice, then writes cost_resolutions with the right source for the selected lines, updates no sale_lines row, and writes one audit entry per change. [F14]
- Reports show Confirmed at sale, Estimated and Revenue pending cost with their percentages, and the totals reconcile to the sales. [F14]
- Cost price appears in no response to the cashier role or, by default, the manager role: not in the API, page source, developer tools, audit views, CSV exports or sync-bundle plaintext. An automated test scans responses and exports for cost fields. [F10]
- A below-cost sale is flagged when saved and again retroactively when a later cost entry turns earlier sales into losses. Both show on the owner dashboard.
- Merging two items changes no sales row, and reports combine their history through the pointer. A merge that would create a cycle is refused, and an undo restores the pre-merge history. [F18]
- A price below the floor needs a manager PIN. A new item inherits its range from sibling items. The fourth below-range confirm in a shift needs a manager PIN, and a price above 3 times the usual must be retyped. [F11, S5]
## D. Audit and access (8)
- The app’s database role cannot UPDATE, DELETE or TRUNCATE any append-only table, cannot GRANT, and the migration credential is absent from every running container. Triggers still reject the statements when a grant is deliberately widened in a test. The hash chains verify, and the daily summary prints the root hash. [F3]
- The nightly grant check raises a health alert when a grant is changed. [F3]
- PIN switching takes under 2 seconds. Five wrong PINs lock the user. Ten wrong PINs across names on one terminal trigger backoff. An elevated session returns to cashier after the idle limit, and an idle cashier session returns to the name tiles with the cart kept. [F8, S6]
- A request in flight during a PIN switch is attributed to the person who sent it. A manager approval binds to its cart line, item and price within 60 seconds, works once, survives parking and a slow transfer, and is voided if that line changes. [F9, S3]
- Every action appears in the audit log with user, device, and redacted before and after values. A cashier reads only their own history. Only the owner can read sensitive values. [F10]
- Two simultaneous returns of the same line: exactly one succeeds, and total returned quantity never exceeds the quantity sold. [F7]
- A parked-sale discard requires a reason, and one above the limit requires a manager PIN. Both appear on the dashboard. [F12]
- On a counter with a drawer, every opening (from a sale, the no-sale key, or the key after a failed kick) appears in the log; a counter with no drawer completes cash sales and reconciles. [F12, S21]
## E. Operations (7)
- A restore onto a clean machine passes within 1 hour, with the last receipt and all audit chains verified. Restoring needs both key halves. The health page shows last WAL archive, last full backup and last verified restore. [F2]
- From a shop profile file to a first sale takes under 2 hours, including printer, drawer and scanner tests. The 80mm receipt prints from the server without a dialog, a hard reboot returns to the counter, and the power-cut drill keeps one counter selling. [R3, S7]
- Setting the server clock to the year 2000 does not lock the counter. Sales continue, stamped unverified, and the owner is asked to confirm the time. [F15]
- A server that was off overnight runs the missed backup and test-restore jobs at the next idle window. [F24]
- An update is pulled from GHCR only after its signed manifest verifies, and a failed post-update health check rolls back the code and keeps every sale made after the update. [D2] A shop two minor versions behind upgrades through each migration in order. [F16]
- The health page shows certificate expiry (warning at 60 days), SSD health and clock state. [F24]
- Loading starter catalogue v1.1 on a clean install creates the 34 categories, the 15 pick-lists with 466 phone models, the 73 brands plus No brand, the 29 models and the 5 generator rules with no duplicates; the generators offer exactly 127 shapes; and running it a second time changes nothing. [v1.3, S20]
## MVP checks
- A licence is rejected on different hardware. A spare PC works only after a console transfer code. [F17]
- Concurrent merges of X into Y and Y into X leave no cycle, and the later one lands in the review queue. A user deactivated at one branch cannot sign in at another after the next sync, and can be deactivated locally at once. [F18]
- Drawer handover produces a blind count and a per-holder variance, and the expected-cash formula matches a scripted day that includes drops, top-ups and a cash-out service. [F19, S11]
- An imported bank statement matches transfer sales by amount and time, and unmatched ones are listed. [F12]
- A store-credit voucher issued from a return can be redeemed once, with an append-only balance. [F7]
- A bundle with a matching sale ULID and a different content hash is quarantined. [F1]
## Pro checks
- A restock arriving at negative on-hand sets the weighted average as specified, and a backdated restock changes no finalised line. [F21]
- With colour tracking on, a sold-out colour shows as out of stock while another colour of the same item shows stock. [F22]
- Two counters approving credit sales for one customer at the same time cannot exceed the limit. Debt ageing equals the sum of unallocated balances by sale date. [F23]
- Discount and tax rounding tests give line sums equal to the sale total. [F23]
## Pilot success thresholds (set with the owner)

| Measure | Threshold |
|---|---|
| Daily totals | On at least 10 of 14 pilot days the app’s total per payment method matches the notebook, after differences are traced and written down |
| Receipts | Every receipt tuple used once, and no committed sale without either a printed or a pending-print record. No sale lost to a crash or power cut |
| Speed | A trained cashier rings a 3-item sale in under 30 seconds on typical days |
| Profit coverage | At least 80% of revenue has a cost (at sale or estimated) by day 14, with the split between the two shown, so that backfilling alone cannot hide a poor result [F14] |
| Owner’s decision | Continue or stop, and either way the owner receives his data export |

# 15. Engineering and design standards
Every practice below is here because it serves one of the six problems (P1 to P6) in section 1.
## Engineering practices adopted

| Practice | Reason | Serves |
|---|---|---|
| Idempotency key per sale, with a payload hash | A retry creates one sale. A changed cart under the same key is rejected. [F5] | P2, P3 |
| Whole sale in one DB transaction, with side effects after commit | A power cut leaves no half-sale or burned number, and a deadlock retry cannot print twice. [F5] | P3, P6 |
| Pending-print job committed with the sale | A cut between commit and print leaves a recoverable receipt. [F5] | P1, P6 |
| Per-terminal gapless receipt numbers with a restore epoch | Several counters need separate sequences, and a restore must never reissue a number. [F1] | P1, P4 |
| Epoch from the highest known anywhere | A second restore from an older backup must not reissue numbers. [S14] | P1, P4 |
| Counter journal and lost-window screen | Sales lost in the last minutes before a failure are recovered from the counters. [F1] | P4, P6 |
| Money in integer kobo | Wholesale quantity times open price never drifts by rounding. | P3 |
| Server recalculates all totals | An open price field makes the browser untrusted. | P3, P4 |
| Three database roles, grants, triggers and a nightly grant check | Append-only must not depend on application rules or on a credential the app holds. [F3] | P4 |
| One mutability matrix that generates grants and triggers | The append-only rules live in one place and cannot contradict the data model. [S15] | P4 |
| Per-terminal audit chains with periodic roots | Tamper evidence without serialising every terminal. [F4] | P4 |
| Failed sales logged after rollback, with a spool fallback | Disputes need evidence, and logging must not deadlock. [F4] | P4 |
| Acting-user token and single-use approval tokens | The terminal login is shared, so identity and approvals must travel with each request. [F9] | P4 |
| Sensitive audit values in a separate restricted stream | The audit trail must not leak cost. [F10] | P4 |
| API Resources only, never models, in Inertia | Model hiding is not role-aware. [F10] | P4 |
| Encrypted sync bundles | Bundles carry cost and credit data on flash drives. [F10] | P4 |
| Concurrency, crash and restore tests on a real PostgreSQL | Rolled-back test transactions hide the very bugs these features exist to prevent. [F6] | P3, P4, P6 |
| Cash tendered and change shown and printed | Fewer change errors and faster cash sales. | P2, P4 |
| Transfer tick with reference and statement matching | Transfers are a common dispute and theft route. [F12] | P4 |
| Drawer opening log through server printing, where a drawer is fitted | Cash discipline needs to see every opening. [F12, R3, S21] | P4 |
| Number-with-unit attributes and pick-lists | Prevents duplicates such as “35 w” and “35watt”. | P1, P5 |
| One display-name routine, regenerated on rename | Receipt names are the heart of the problem. | P1 |
| Attribute key and type fixed once in use | A later edit must not change past receipt names. | P1 |
| Barcode confirmation, junk-code ignore, alias activation | Learning from sales must not let one mistake spread. [F13] | P1, P5 |
| Immutable auto-generated item codes | Stable identity, without prefix administration. | P1, P5 |
| Users deactivated, never deleted, with high-priority propagation | Keeps the audit intact and stops ex-staff at every branch. [F18] | P4, P6 |
| Elevated sessions expire back to cashier | A manager who walks away must not leave the counter in manager mode. | P4 |
| Business day assigned at shift open, UTC storage, clock states | Profit must cut at the right midnight even when a clock is wrong. [F15] | P3 |
| Three-bucket profit and backfill preview | Estimated profit must never pass as confirmed profit. [F14] | P3 |
| Shift per drawer with handover counts | With PIN switching, a per-cashier shift cannot be blind-counted. [F19] | P4 |
| Row locks on returned lines and customer rows | Races must not double-return goods or exceed credit. [F7, F23] | P3, P4 |
| Health page, certificate and SSD checks, reboot recovery, catch-up jobs | You cannot visit every shop, and a power cut must not need a call. [F24] | P2, P6 |
| Pinned versions and no bind mounts in production | Every shop behaves the same, and rebuilds are reproducible. | P6 |
| Offline signing key, two embedded public keys, per-shop secrets | A leaked key must not compromise every shop, and rotation must work offline. [F17] | P6 |
| Expand-then-contract migrations, code-only rollback, version skew support | Updates must never discard sales, and offline shops skip versions. [F16] | P6 |
| Hardware-bound licences with a transfer flow | The spare PC must not become a free second copy. [F17] | P6 |
| Install checklist with power-cut and restore drills, written down | No shop goes live unproven. | P6 |
| Stock ledger with atomic cached balance, cost layers and nightly check (Pro) | Tracking without a migration later. [F21] | P3 |
| CSV export, and CSV import with preview for starter catalogue and bulk cost | Backs the promise that data never locks. Import is not a main path because no shop has an inventory list. | P5, P6 |
| Flat items with a uniqueness key; shapes built on demand | Duplicates become impossible rather than unlikely, and setup needs no prune review. [R1, R2] | P1, P5 |
| Server printing to network printers | Nothing to install or support on counter PCs; pending print and the drawer log live in one place. [R3] | P2, P6 |
| Proven tools: pgBackRest, MiniSearch, an ESC/POS library, Playwright, Pest | The parts that must not fail use tested tools rather than our own. [R6] | P3, P6 |
| CI on every pull request with a real PostgreSQL; images on GHCR; signed release manifests | Every change is tested, and every shop runs the same build. [D1, D2] | P6 |

## Design standards adopted

| Standard | Application | Serves |
|---|---|---|
| Clarity and deference | The counter shows only the sale: items, price field, total. No dashboards or menus in the way. | P2 |
| Touch first | Counters are touchscreens; keyboard and mouse are full fallbacks. [Q5] | P2 |
| Responsive | Every screen works from phone width to large monitors. Breakpoints: compact under 600px (one column; the cart becomes a bottom sheet with a sticky Pay bar), medium 600 to 1023px, and large from 1024px (tiles and cart side by side). Touch targets stay at least 56px at every size. [D5] | P2, P5 |
| Large touch targets | At least 56px, with clear gaps between a primary and a destructive button. | P2 |
| Immediate feedback | Every local interaction responds within 100 ms. Scan success and failure have distinct sounds. | P2, P3 |
| Forgiveness over confirmation | Undo for line removal and held sales. Confirm only for irreversible actions, with verb labels (“Void sale”, not “OK”). | P2, P4 |
| Progressive disclosure | Cashier sees little, owner sees more, configuration lives in your console. | P5 |
| Consistency | Enter confirms the focused dialog, Esc cancels, Pay has its own key, and a scan never triggers Pay. [S1] | P2 |
| Accessible contrast and meaning | Contrast at least 4.5:1. Flags use icon plus text, never colour alone. Full keyboard use with visible focus. | P2, P4 |
| Colour | Apple's system colours with fixed roles (tokens below): blue for actions and selection, green for Pay, orange for warnings, and red only for destructive actions and errors. [D4] | P2, P4 |
| Typography | Inter, bundled with the app (SIL Open Font License), with tabular numerals so prices line up. Body at least 16px, counter text larger, the total largest. Apple's SF fonts are licensed for Apple platforms only, and the counters run Windows. [D4] | P1, P2 |
| Plain-language errors | Each message says what happened, why, and what to do next. | P5, P6 |
| Helpful empty states | A first-run screen guides the first sale. | P5 |
| Minimal motion | No animation on the counter. Reduced motion respected. | P2 |
| Privacy by design | Cost never leaves the server. Catalogue contribution sends names only. Telemetry carries health data only. | P4, P6 |
| Installs that just work | The installer applies a profile file with no manual steps. | P5, P6 |

Not applied: native toolchains, Apple’s own typeface (licence restricted), swipe-to-delete gestures, hiding options (we use the console instead), silent auto-merging of duplicates, dark mode in the MVP, and cloud-first assumptions.

## Colour tokens

Apple's long-standing system colours (light appearance) and their increased-contrast variants, frozen here as our own tokens, so a later change at Apple never moves ours. Contrast is measured against white. [D4]

| Role | Apple colour | Fill | Text and icons on white | Rule |
|---|---|---|---|---|
| Background | System background | #FFFFFF | | Every screen |
| Panels | System gray 6 | #F2F2F7 | | Cart panel, sheets, grouped lists |
| Borders | System gray 4 | #D1D1D6 | | Tile outlines and dividers |
| Text | Label | | #000000 (21:1) | All primary text |
| Secondary text | System gray, increased contrast | | #6C6C70 (5.2:1) | Hints, captions, units |
| Actions and selection | System blue | #007AFF | #0040DD (7.6:1) | Primary buttons, links, the selected tile and the focus ring. A white label on the blue fill must be at least 18.66px bold (4.0:1) |
| Pay and success | System green | #248A3D for the Pay button; #34C759 for tints only | Large text only | The Pay label is white, bold and at least 18.66px (4.4:1). Smaller success messages use black text with a green icon. Never white text on #34C759 (2.2:1) |
| Warning | System orange | #FF9500 with black text (9.6:1) | #C93400 (5.3:1) | Unusual price, awaiting transfer; always with an icon |
| Destructive and error | System red | #D70015 with white text (5.4:1) | #D70015 (5.4:1) | Void, discard and errors only; always with an icon and a verb |
## Considered and rejected

| Practice | Reason rejected |
|---|---|
| Fixed catalogue price with manager approval for corrections | Conflicts with open pricing. It stays as an optional strict mode in the console. |
| Username and password pop-up for approvals | Too slow in a queue. A PIN is the default. |
| Windows with Docker Desktop as the base | Ubuntu Server was chosen. |
| One cost field on the item | We keep cost history so we can resolve and recalculate. |
| Category-prefix item codes | Adds setup work that onboarding is meant to remove. |
| Values overview page | The review-queue merge tool covers cleanup. Revisit later. |
| Restoring the database snapshot on a failed update after trading resumed | It discards real sales. Rollback is code-only, and a fix rolls forward. [F16] |
| One shared backup master key | One compromise would expose every shop. Keys are per shop and split. [F2] |
| A single global audit chain | Serialises every terminal and risks deadlock. [F4] |
| A native print agent on each counter PC | Server printing to network printers needs nothing installed on counters. [R3] |
| Pre-generated catalogue lines with a prune review | Shapes are built on demand. [R2] |
| A blind count at every PIN switch | Counts happen when the drawer holder changes. [S11] |
| USB update bundles | Updates are pulled from GitHub over a phone hotspot or an optional MiFi. [D2, D3] |
| Owner phone summary and read-only cloud view in Core | Not in Core for now; the pilot shop has no internet. [S13] |

# 16. Build process and pre-launch work
## Build phases
Phases, gates and the timeline live in PLAN.md at the repository root, so the schedule can change without a spec release. Phase scope follows section 13.

## Process rules
- A weekly proof at the end of each build week, checked the same day.
- The never-cut list in section 13 is never traded away.
- Swap rule: a feature enters a phase only if something of equal size leaves, with the swap recorded.
  - Swap recorded for v1.2: the minimal print agent with drawer kick and drawer log enters the pilot slice. Weekly and monthly report views and the top-items report leave the pilot slice for the MVP. The advanced print agent features stay in Pro. [F12] Superseded in v1.5: printing goes from the server to network printers, so there is no agent. [R3]
- Scripted migrations and seeders, so a rebuild is fast. Migrations follow expand-then-contract. [F16]
- Concurrency, crash and restore tests run on a real PostgreSQL in CI and are never replaced by rolled-back test transactions. [F6]
- One GitHub monorepo, public until the owner makes it private, trunk-based: short-lived branches, a protected main, and green CI to merge, with no review while one person builds it. [D1, D6, D10, D15]
- The spec is Markdown in Git, and the JSON seed is the only master for catalogue data; its workbook and appendix are generated. Every decision is recorded in docs/decisions.md. [R8, Q9]
## Work that must finish before the first shop goes live

| Area | Deliverable |
|---|---|
| Wireframes and speed test | The five v1.4 screens predate the catalogue and are redrawn touch-first on a new canvas, adding the price-floor prompt, pending print, lost window, Pending transfers and the idle lock. There is no clickable prototype: the product owner signs off the wireframes at gate G1, and the speed instrumentation times real cashiers on the counter from the shadow pilot. Returns, shift close and the console are drawn in the MVP phase. The product owner signed the wireframes off on 6 November 2026. [Q10, D12, D19] |
| Release policy | GitHub Actions CI on every pull request with a real PostgreSQL; images on GHCR; a release manifest signed offline with a hardware token; staging on the product owner's computer with 5,000 test items [D16]; the pilot shop as the beta channel with a weekly release; automatic code rollback on a failed health check. [D1, D2] |
| Hardware specification | Server with SSDs that have power-loss protection. UPS covering server, switch and router; one counter and its printer on the UPS or the inverter. Network thermal printers; cash drawers optional. Fixed IP and local name. Local certificate authority and renewal steps. Optional standby device. The tested list lives in docs/operations/hardware.md. [F24, S7, R3, S21] The list in docs/operations/hardware.md is locked. [D17] |
| Support model | Remote access when online. A send-diagnostics bundle. A runbook for printer, PC start, wrong-date and restore problems. A pre-imaged spare PC with a licence transfer procedure. |
| Starter catalogue | Seed v1.1 is drafted. Before go-live the owner re-confirms it, and the shelf walk confirms the 16 brands marked to confirm, the short codes HF, AI, LP, BM, DV, JZ, EX and UWO, the brands shown first for six categories, and the phone-model list. [C1 to C13] |
| Commercial and legal | Contract covering price, data ownership, cancellation, data retention, use of data, the recovery tier bought and key custody. Privacy notice. Lawyer review under Nigerian data protection law. Accountant check on tax and e-invoicing for target shops. Trademark: confirm HAQ is registered in software classes 9 and 42 under HAQ Technology, and register it if not. [v1.4] |

# 17. Risks
- Cashier discipline in the first two weeks. Mitigated by required category, facet pickers, similarity warnings, the merge tool and the cashier-behaviour flags. Plan a hands-on first week per shop.
- Barcode reality. Untested until the 20-item scan at your first shop. It decides how much scanning carries the counter.
- Installer reliability. The Docker host, auto-start, backups and updates are where non-technical shops fail. Budget real time for them.
- Single server per branch. Mitigated by WAL archiving, the standby kit, the pre-imaged spare PC and the 1-hour restore target. The 15-minute data-loss figure applies to disk failure, and the contract states the whole-machine figure. [F2]
- Insider with host access. A person with root on the box can still act. Detection rests on anchored audit roots and on consented, audited support access. [F3]
- Cost leaking through side doors (audit, exports, bundles, page props). Mitigated by restricted audit streams, encrypted bundles, Resources only and a scanning test. [F10]
- Wrong clocks and long-offline shops. Mitigated by clock states, a grace period before any lock, and version-skew support. [F15, F16]
- Signing key compromise. Mitigated by an offline key, two public keys and a revocation list. [F17]
- Scope creep before the pilot. Pro features wait until the pilot slice is proven in one shop. The pilot slice grew in v1.2 (section 16 records the swap), so watch it.
- Data leaving a shop. Catalogue contribution stays opt-in, names only and curated.
# 18. Open items

| Item | Needed from | Default if no answer |
|---|---|---|
| Barcode scan result (20 items) | You, at the first shop | Assume 40% scannable |
| Bundles or packs (phone plus case, wholesale carton) | You | Not supported in the pilot slice |
| Warranty claims | You | Handled as returns with disposition supplier_claim |
| What the shop does today when the PC fails | You | The downtime procedure in section 9 |
| Monthly prices for Core and Pro, and for the standby kit | You | Proposal after shop cost discovery |
| Bank statement formats to support | You, from the first shop’s banks | One CSV mapping per bank, configured by you |
| Price floor percentage and below-range confirm count | Owner at the pilot shop | 50% of the bottom of the usual range, and 3 per shift |
| Standby kit as default or paid option | You | Paid option for Core, included in Pro |
| Whether the storekeeper may also add brands | You | Managers and owner only |
| Trademark status of HAQ (classes 9 and 42) | You, with a trademark agent | Proceed with the name and register before the first paid install |
| Say-it-aloud test of HAQ with five shop owners (does it sound like “hack”) | You | Proceed with HAQ unless owners object |
| Where head office and cloud backups are hosted, and data residency | You, with the lawyer | Not needed for the pilot; decide before the MVP [Q7] |
| Tile (drill) order per category | Speed data from the shadow pilot | The v1.1 proposal [R1, D12] |

# 19. Decisions recorded

| Topic | Decision |
|---|---|
| Database | PostgreSQL |
| Branch server | Mini-PC, Ubuntu Server, Docker, SSDs with power-loss protection, UPS covering server and network gear, wired counters, fixed IP |
| Head office | Central cloud, multi-tenant with row-level security (FORCE) |
| Cost method | NULL cost with append-only resolutions (pilot and MVP), forward-only weighted average from layers (Pro) |
| Profit reporting | Three buckets: confirmed at sale, estimated, pending cost |
| Manager and cost | Hidden by default, per-shop override |
| Approvals | 6-digit manager PIN on a name tile; a single-use approval bound to the cart line when granted (60 seconds to bind). Strict price-approval mode optional [S3] |
| Identity | Name tile plus PIN, acting-user token per request |
| Receipt numbers | T01-E1-000123: terminal, restore epoch, gapless counter. Epoch increases on every restore or replacement |
| Append-only enforcement | Three database roles, grants, statement triggers and a nightly grant check |
| Audit | Per-terminal chains, periodic roots, restricted sensitive stream, failures spooled if the lock is busy |
| Shifts | One shift per drawer; a blind count when the drawer holder changes; forgotten shifts auto-close [S11, S12] |
| Store credit | Printed voucher with an append-only ledger |
| Colours | Note on the sale line. Optional variant_key on stock only |
| Printing | The server prints to network printers; no native agent; the cash drawer is optional per counter [R3, S21] |
| Updates | Pulled from GHCR against a signed manifest, over a phone hotspot where there is no internet (MiFi optional); expand-then-contract migrations, code-only rollback, two-minor-version skew [D2, D3] |
| Keys | Offline release signing, two embedded public keys, per-shop secrets, a per-shop backup key in 2-of-3 shares [S17] |
| Licence | Bound to hardware, transfer code for the spare PC |
| Clock | Unverified-clock mode with a 48-hour grace before any lock |
| Expiry | Counter locks after grace. Data and export never lock |
| Remote push | Config pushes allowed, consent in contract, audited; updates are pulled by the shop [D2] |
| Paid-outs | Included, with drops and top-ups |
| Credit limit | Per customer, shop-wide fallback, scoped per branch |
| Language | English |
| Installer | Your team |
| Delivery | Docker bundle, code on GitHub, signing offline |
| Currency and tax | Naira, no tax by default |
| Backup targets | 15 minutes maximum data loss for disk failure, up to 24 hours for whole-machine loss without the standby kit, 1 hour to restore; about 5 minutes of whole-machine loss with internet and the cloud copy [S16] |
| Wall chargers | A parent category with two sellable children: Charger head (no cable) and Complete charger (head plus cable). The receipt prints those words |
| Brands typed by cashiers | Allowed. Prints as typed. Joins the pick-list only when a manager or owner approves it |
| Products that fit no category | Cashier types the product word, it prints as typed, the owner creates the category later |
| Cable length and rated power | Optional facets |
| Model codes with no brand | Pro, Pro 5s, Q10, S195, G133, VIP, J7 and car mp3 s8 are filed as models under No brand [FU5] |
| Starter catalogue | v1 locked on 2 November 2026 (docs/archive); v1.1 drafted on 5 November 2026 for the owner's re-confirmation (docs/catalogue) |
| Product name | HAQ Retail OS. Company HAQ Technology, product Retail OS. Say “HAQ” in conversation and write the full name on receipts, screens and contracts |
| Tier names | HAQ Retail OS Core and HAQ Retail OS Pro |
| Catalogue model | A category tree plus flat items with a uniqueness key; generic shapes built on demand [R1, R2] |
| Prices | The set price fills in first, then the last price sold (median as a shop setting); the owner and the manager set prices [S4] |
| Cash drawer | Optional per counter; a key opening after a failed kick is logged [S21] |
| Counters | Windows touchscreens running Chrome in kiosk mode; keyboard and mouse as fallbacks [Q5] |
| Receipts | Printed for every sale, with the receipt number as a Code 128 barcode [Q14, S9] |
| Sync | One-way in the MVP; two-way straight after [R7, FU7] |
| Code and CI | One GitHub monorepo, public until the owner makes it private, trunk-based; GitHub Actions CI on every pull request with a real PostgreSQL; images on GHCR [D1, D10, D22] |
| Shop server stack | apps/shop-server/: PHP 8.5, Laravel 13, PostgreSQL 18, Inertia 3, React 19 in TypeScript, Vite 8 and Tailwind CSS 4, with pinned versions [D20] |
| Code structure | A modular monolith: modules by business area with one-way dependencies, one database, one ordered set of migrations [D23] |
| Spec and records | Markdown in Git; the JSON seed is the catalogue master; decisions in docs/decisions.md [R8, Q9] |
| Owner remote view | Not in Core for now [S13] |

# Appendix A. Change register
Each review finding from the engineering teardown, and where v1.2 fixes it.

| ID | What was broken in v1.1 | What v1.2 does, and where |
|---|---|---|
| F1 | A restore rewinds the receipt counter and reissues numbers customers already hold | Restore epoch in the receipt number, counter jump, ULID plus hash dedupe with a conflicts queue, counter journal and lost-window screen. Sections 4, 5, 9, 14 |
| F2 | The 15-minute data-loss target is false for whole-machine loss, and one backup key half could decrypt every shop | Honest two-tier targets, standby kit, per-shop split backup key. Sections 4, 14, 17, 19 |
| F3 | Append-only was not really enforced: owner role, TRUNCATE, RLS bypass, tables missing from the list | Three roles, triggers, TRUNCATE block, FORCE RLS, grant check, longer append-only list. Sections 4, 5, 10, 14 |
| F4 | A single hash chain serialises terminals and can deadlock with failure logging | Per-terminal chains, roots, spool fallback. Sections 5, 10, 14 |
| F5 | Idempotency holes: duplicate race, changed payload, retried side effects, commit-then-power-cut | Payload hash, explicit 23505 handling, after-commit effects, pending-print job. Sections 5, 7, 14 |
| F6 | Laravel test tooling hides concurrency, crash and restore bugs | Real-PostgreSQL multi-process tests in CI and a never-cut item. Sections 13, 14, 16 |
| F7 | Returns can double-spend, void scope is unclear, store credit has no ledger | Line locks, cumulative quantity check, per-drawer voids, voucher ledger, dispositions. Sections 5, 9, 14 |
| F8 | PIN-only identity makes lockout meaningless and allows unthrottled guessing | Name tiles, per-user and per-terminal throttling, approval flagging. Sections 3, 14 |
| F9 | Laravel policies see the terminal login, not the cashier | Acting-user token, single-use action-bound approval tokens. Sections 3, 5, 14 |
| F10 | Cost leaks through audit before/after values, bundles, exports and page props | Sensitive audit stream, encrypted bundles, Resources only, export rules, scanning test. Sections 4, 10, 12, 14 |
| F11 | A cashier can undersell by tapping their own confirm | Manager PIN below a floor and after N confirms, sibling range inheritance, behaviour flags. Sections 6, 7, 8 |
| F12 | Parked-sale discards, transfer honour tick and manual drawer opens are open theft routes | Discard reason and limit, transfer reference and statement matching, drawer log via minimal print agent. Sections 4, 7, 9, 12, 16 |
| F13 | Barcode links and aliases can be poisoned and spread | Unconfirmed links with undo, junk-code ignore, alias activation count. Sections 5, 6, 14 |
| F14 | Backfilled profit was presented as confirmed | Three buckets, backfill preview with basis date, source on resolutions, stricter threshold. Sections 5, 8, 12, 14 |
| F15 | A wrong clock poisons immutable data or locks the counter | Clock states, sequences, corrected-time view, business day at shift open, 48-hour grace. Sections 4, 5, 11 |
| F16 | A failed update rollback could discard sales, and offline shops skip versions | Expand-then-contract, code-only rollback, two-version skew with sequential migrations. Sections 4, 14 |
| F17 | One signing key and shared secrets risked every shop, and the spare PC was a licence clone | Offline signing key, two public keys, revocation, per-shop secrets, hardware-bound licence with transfer. Sections 4, 11 |
| F18 | Sync lacked conflict rules, merge cycles, deactivation lag and credit-limit scope | Hybrid clocks, cycle check, merge events, deactivation priority, branch-scoped limits. Sections 3, 4, 5, 9 |
| F19 | Per-cashier shifts cannot be blind-counted on a shared drawer, and expected cash missed real cash events | Shift per drawer, handover counts, cash_events, extended formula. Sections 5, 9 |
| F20 | “Never blocks a sale” was contradicted, and Request-only mode printed bare typed text | Principle reworded, hard stops listed, unattributed shift, category and denylist rules. Sections 1, 2, 6, 7 |
| F21 | Weighted average cost fails at zero or negative stock and with backdated restocks | Forward-only layers with explicit rules. Sections 5, 8 |
| F22 | Colour as a note cannot support stock | Optional variant_key on stock only. Sections 5, 6, 12 |
| F23 | Credit races, missing payment allocation, an unconstrained payments table and no rounding rule | Customer row lock, payment_allocations, check constraint, rounding rule. Sections 5, 9 |
| F24 | Environment assumptions: box off at night, switch not on UPS, consumer SSDs, certificate expiry, DHCP | Catch-up scheduling, UPS scope, power-loss-protected SSDs, certificate monitoring, fixed IP. Sections 4, 14, 16 |
| F25 | Smaller contradictions: 15 minutes vs 2 hours, pilot needing the console, back-entry paper numbers, cache invalidation, 100 ms claim | Reconciled targets, pilot profile tool, paper number field, delta feed, local-state budget. Sections 1, 4, 6, 7, 9 |

# Appendix B. Decisions made for you
Kept for history. Where the v1.4 review changed a default here (the print agent became server printing [R3]; backup keys moved to 2-of-3 shares [S17]), the sections above win.
Several fixes needed a design choice. These are the defaults chosen in v1.2. Please confirm or change each one before build starts.

| Decision | Default chosen | Alternative |
|---|---|---|
| Receipt format with epoch | T01-E1-000123, counter jump of 1,000 after a restore | Epoch only, no jump. Shorter, but less protection if the epoch record is lost |
| Whole-machine recovery | Honest 24-hour figure, standby kit as an option | Make the standby mandatory and claim 15 minutes for all cases |
| Backup key custody | Per-shop key split in two halves, both needed | Owner escrow envelope, you hold no half. Stronger privacy, harder support |
| Print agent | Minimal agent moves into the pilot, with a recorded swap | Keep Chrome kiosk printing in the pilot and log only manual drawer opens in the app |
| Price floor | Manager PIN under 50% of the range bottom, or after 3 confirms a shift | Looser or stricter numbers, set per shop |
| Alias and barcode learning | Aliases active after 3 uses, barcode links confirmed after 2 further sales | Faster learning with more cleanup |
| Credit limits | Scoped per branch, worst case limit times branches | A single shop-wide limit with stale balances between syncs |
| Merge undo | Pre-merge history restores, later sales stay on the survivor | Record the intended node on every line and allow a full split |
| Store credit | Voucher with its own ledger | Remove store credit as a refund type |
| Profit threshold | 80% coverage by day 14 including estimated lines, with the split shown | A separate minimum for at-sale confirmed profit |
| Version skew | Two minor versions | Wider support, at more test cost |
| Unverified clock grace | 48 hours before any lock | Shorter grace, with more false lockouts |

# Appendix C. Starter catalogue
The starter catalogue is data, so its appendix is generated from the seed and kept beside it: docs/catalogue/appendix-c-starter-catalogue-v1.1.md (v1.1, drafted on 5 November 2026 for the owner's re-confirmation). The locked v1 is in docs/archive. If an appendix and its seed differ, the seed wins; regenerate rather than edit. [R8]

# Appendix D. What changed in v1.3 and v1.4
## v1.4

| Area | Change |
|---|---|
| Product name | The product is HAQ Retail OS, from the company HAQ Technology. The page header, title, section 1 and the seed file carry the name. |
| Tiers (section 11) | The tiers are named HAQ Retail OS Core and HAQ Retail OS Pro. |
| Decisions (section 19) | New rows for the product name and tier names. |
| Open items (section 18) | New rows for the HAQ trademark status and the say-it-aloud test with shop owners. |
| Pre-launch (section 16) | The commercial and legal row now includes trademark registration in classes 9 and 42. |
| Seed file | starter_catalogue_v1.json now names the product, company and tiers. |

## v1.3

| Area | Change |
|---|---|
| Roles (section 3) | New rows: add a brand to the pick-list (manager and owner), and create or rename a category (owner only). |
| Data model (section 5) | New brands table. catalog_nodes gains brand_id, brand_text, typed_category_word and the pending_category status. |
| Catalogue (section 6) | Locked starter catalogue v1. Typed-brand rule. Not-in-the-list rule. Optional cable length and rated power. |
| Reports (section 12) | Catalogue health now counts brands and product words waiting for approval. |
| Release scope (section 13) | Pilot slice loads the v1 seed and runs the typed-brand and typed-product-word rules. Phone model lists move to a second pass in the MVP. |
| Acceptance (section 14) | Three new pilot checks, so the pilot gate is now 44 checks (A: 11, B: 11, C: 7, D: 8, E: 7). |
| Pre-launch (section 16) | New starter catalogue row: shelf walk confirms 16 brands, the owner prunes 127 generated lines, short names are checked. |
| Open items and decisions (sections 18 and 19) | New rows for phone models, brands to confirm, pruning, who adds brands, wall charger split, optional length, model codes and the locked catalogue. |
| Appendix C | New. The full locked starter catalogue. |

# Appendix E. What changed in v1.5
Every change below comes from the v1.4 review of 5 November 2026 or a decision after it; docs/decisions.md records the source of each.

| Area | Change | Tags |
|---|---|---|
| Prices (sections 3, 7, 14) | The set price fills in first, then the last price sold, with the median as a shop setting; the owner and the manager set prices; one tap on the first-sale list sets a price; the price checks have numbers, with a retype above 3 times the usual | S4, S5 |
| Counter (sections 2, 7, 15) | Touch first; scanner input detected by timing; Pay on its own key; Awaiting transfer; a receipt barcode; search defined; speed measured on every sale | Q5, S1, S2, S8, S9, S10 |
| Identity (section 3) | Approvals bound to the cart line when granted; an idle lock | S3, S6 |
| Cash (sections 4, 7, 9) | The drawer holder is separate from the acting user; forgotten shifts auto-close; the cash drawer is optional, with a logged key fallback | S11, S12, S21, FU2 |
| Printing (section 4) | The server prints to network printers; no agent on counter PCs | R3 |
| Catalogue (sections 5, 6, Appendix C) | A category tree plus flat items with a uniqueness key; shapes built on demand; starter catalogue v1.1 with phone models | R1, R2, C1 to C13 |
| Data integrity (sections 4, 5, 10) | The epoch comes from the highest known anywhere; one mutability matrix; two wording fixes | S14, S15, S19 |
| Recovery (section 4) | pgBackRest to a second disk and, with internet, the cloud; flash-drive rotation offline; 2-of-3 key shares | S16, S17 |
| Delivery (sections 4, 11, 16) | One GitHub monorepo with CI on every pull request; images on GHCR; updates pulled against a signed manifest over a phone hotspot or an optional MiFi; thin image layers; pre-imaged servers | D1, D2, D3, S18 |
| Frontend and sync (section 4) | The counter is a local-first client in one Inertia page; catalogue changes by polling; one-way sync first, two-way straight after the MVP | R4, R5, R7, FU7 |
| Power (section 4) | One counter and its printer on the UPS or the inverter | S7 |
| Cost (sections 3, 8, 11) | A console setting lets the manager enter cost | FU6 |
| Process (section 16) | The spec in Markdown in Git; the seed as the catalogue master; the schedule in PLAN.md | R8, Q9 |
| Acceptance (section 14) | 47 pilot checks: A12, A13 and B12 are new, and the wording of others follows the changes above | S1, S2, S12 |
| Design (section 15) | Apple's system colours as fixed tokens, the Inter typeface, and responsive layouts from phone to large monitor | D4, D5 |
| Counter (sections 6 and 7) | One tap adds an item with a set price; otherwise a price pad confirms the pre-filled price. Optional facets have a Skip tile, and tiles show a price only on the last step. Retail or Wholesale covers the whole sale from the top bar. A colour row opens on a new line for categories that ask for colour. A POS payment needs nothing typed | D7, D8, D9, D11 |
| Repository (section 4) | Public until the owner makes it private; nothing secret or personal is committed; green CI to merge, with no review while one person builds it | D10, D15 |
| Receipts (sections 7 and 11) | The header and footer are a shop setting that only the sysadmin changes, with a branch override for the address and phone | D14 |
| Hardware (section 16) | The hardware list is locked | D17 |
| Delivery (section 16) | No clickable prototype: gate G1 is the product owner's sign-off of the wireframes, and real cashiers are first timed on the counter in the shadow pilot. The product owner's computer is the staging and test machine, with no office mini-PC. The wireframes are signed off | D12, D16, D19 |
| Reports (section 12) | The printed daily summary carries no profit or cost amounts | D18 |
| Build (sections 4 and 19) | The shop server in apps/shop-server/ with pinned versions, TypeScript and Tailwind CSS; Laravel's own tables keep their keys; application code in the public repository; a modular monolith | D20, D21, D22, D23 |
| Not adopted | An owner phone summary and read-only cloud view in Core | S13 |
