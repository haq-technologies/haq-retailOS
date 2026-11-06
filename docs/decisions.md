# Decision log

Every product and build decision taken since spec v1.4, and where it came from. Spec v1.5 applies all of them; its tags ([S4], [R3], [D2], [Q5], [FU6]) point here.

Sources:
- **Register:** the decision register in the review doc "HAQ Retail OS: Spec v1.4 Review and Build Plan" (https://claude.ai/artifact/DW5VWtw3J6xgMg6xMSgKSY).
- **Q1 to Q24 and FU1 to FU10:** that doc's questions and follow-up questions. The doc labels the follow-ups F1 to F10; here they are FU1 to FU10, so they never clash with the teardown findings F1 to F25 in the spec.
- **Correction:** a fix with no real alternative, listed in the review and adopted because nobody objected.
- **Chat:** decided in conversation with Claude.

To add a decision, give it the next ID in its series, the date and the source, then update the spec (with the tag) and PLAN.md if the schedule changes. Never rewrite an old row: mark it superseded and add a new one.

## Spec changes (S)

| ID | Decision | Status | Date | Source |
|---|---|---|---|---|
| S1 | A scan never triggers Pay: scanner bursts are detected by timing, Pay has its own key, and New item uses a key Chrome passes to the page | Accepted | 5 Nov 2026 | FU9 ("do what is best") |
| S2 | An Awaiting-transfer state with a Pending transfers strip, so a slow transfer never freezes the counter | Accepted | 5 Nov 2026 | Register, Q13 |
| S3 | A manager approval binds to the cart line, item and price when granted and holds while the cart is open or parked | Accepted | 5 Nov 2026 | Register |
| S4 | The set price fills in first, then the last price sold; a shop setting uses the median of the last 10 sales for shops that haggle; the owner and the manager set prices; one tap on the first-sale list sets a price | Accepted, revised | 5 Nov 2026 | Register; revised in chat after Q12 |
| S5 | Price checks: the usual range is the set price, or the 10th to 90th percentile of the last 20 sales (at least 5); retype above 3 times the usual; the 50% floor and 3 confirms per shift stay | Accepted | 5 Nov 2026 | Register |
| S6 | A cashier session returns to the name tiles after 3 idle minutes (a console setting), keeping the cart | Accepted | 5 Nov 2026 | Register |
| S7 | One counter and its printer run from the UPS or the inverter | Accepted | 5 Nov 2026 | Register, Q20 |
| S8 | The counter times every sale and records how each line was added | Accepted | 5 Nov 2026 | Correction |
| S9 | The receipt number prints as a Code 128 barcode for returns and reprints | Accepted | 5 Nov 2026 | Register |
| S10 | One search definition (letter and digit splits, prefixes, one typo, unit normalising, ranked by 30-day sales); check A2 uses a real item | Accepted | 5 Nov 2026 | Correction |
| S11 | Who rang a sale is separate from who holds the drawer; a blind count happens only when the holder changes | Accepted | 5 Nov 2026 | Register, Q11, FU2 |
| S12 | A shift open 4 hours past closing time auto-closes as unclosed and is flagged | Accepted | 5 Nov 2026 | Register |
| S13 | Owner phone summary and read-only cloud view in Core | Not adopted for now | 5 Nov 2026 | FU1, Q6 |
| S14 | A restore's new epoch is one more than the highest known anywhere | Accepted | 5 Nov 2026 | Correction |
| S15 | One mutability matrix generates grants and triggers; approvals are column-restricted; a merge undo is a new row; sales.status becomes kind | Accepted | 5 Nov 2026 | Correction |
| S16 | pgBackRest to a second disk and, with internet, encrypted cloud storage; two flash drives in rotation when offline | Accepted | 5 Nov 2026 | Register, Q21 |
| S17 | The backup key is split into 2-of-3 shares: the owner, HAQ, and a sealed paper share kept off-site | Accepted | 5 Nov 2026 | Register |
| S18 | Thin image layers, a full download before any update, and pre-imaged servers | Accepted | 5 Nov 2026 | Correction; delivery per D2 |
| S19 | Wording fixes: the chain-tail lock is held to commit; epochs are signed with the branch key | Accepted | 5 Nov 2026 | Correction |
| S20 | Spec text left over from before the locked catalogue is corrected | Accepted | 5 Nov 2026 | Correction |
| S21 | The cash drawer is optional per counter; a key opening after a failed kick is logged | Accepted | 5 Nov 2026 | Chat |

## Build changes (R)

| ID | Decision | Status | Date | Source |
|---|---|---|---|---|
| R1 | Categories stay a small tree; items are flat rows with a uniqueness key; tiles come from each template's drill order | Accepted | 5 Nov 2026 | Register |
| R2 | Generic shapes are built on demand from generator rules; no 127-line prune review | Accepted | 5 Nov 2026 | Register |
| R3 | The server prints to network printers; no native print agent | Accepted | 5 Nov 2026 | Register, Q4 |
| R4 | The counter is a local-first client inside one Inertia page | Accepted | 5 Nov 2026 | Correction |
| R5 | Counters poll for catalogue changes every 2 seconds | Accepted | 5 Nov 2026 | Correction |
| R6 | Proven tools: pgBackRest, MiniSearch, an ESC/POS library, Playwright, Pest | Accepted | 5 Nov 2026 | Correction |
| R7 | One-way sync in the MVP; two-way sync straight after it | Accepted | 5 Nov 2026 | Register; timing from FU7 |
| R8 | The spec is Markdown in Git; the JSON seed is the only catalogue master, and its copies are generated | Accepted | 5 Nov 2026 | Register, Q9 |

## Catalogue changes (C), applied in starter catalogue v1.1

v1.1 waits for the shop owner's re-confirmation (see PLAN.md).

| ID | Decision | Status | Date | Source |
|---|---|---|---|---|
| C1 | Five mixed categories become parents with 13 sellable children, so every sellable category has a word | Accepted | 5 Nov 2026 | Register |
| C2 | Phone models become a two-level pick-list, seeded for the pilot (466 drafted, to verify at the shelf walk) | Accepted | 5 Nov 2026 | Register, FU8 |
| C3 | "Made by" defaults to No brand on pouches, protectors and batteries | Accepted | 5 Nov 2026 | Q16 |
| C4 | "iPhone Charger" becomes a search word; DataCable stays a brand | Accepted | 5 Nov 2026 | Q17 |
| C5 | PRO moves to Models; the other short codes are confirmed at the shelf walk | Accepted in part | 5 Nov 2026 | Q18, FU5 |
| C6 | J7 and car mp3 s8 stay under Car chargers, printed as typed; Pro sits under Earbuds | Accepted | 5 Nov 2026 | Q18, FU5 |
| C7 | Wireless chargers use the shared wattage list | Accepted | 5 Nov 2026 | Q19 |
| C8 | Brands shown first for six categories | Open | | Shelf walk |
| C9 | Permanent IDs on every seed entry, and a schema_version | Accepted | 5 Nov 2026 | Correction |
| C10 | Inline value lists become named pick-lists | Accepted | 5 Nov 2026 | Correction |
| C11 | Units are written without a space, and printed values use title case | Accepted | 5 Nov 2026 | Correction |
| C12 | Grey, with gray as a search word | Accepted | 5 Nov 2026 | Correction |
| C13 | A Connectivity facet on Mouse and Keyboard | Accepted | 5 Nov 2026 | Correction |

## Delivery and design decisions (D)

| ID | Decision | Status | Date | Source |
|---|---|---|---|---|
| D1 | CI/CD with Git, GitHub and Docker: one private monorepo, trunk-based, GitHub Actions CI on every pull request with a real PostgreSQL, images on GHCR | Accepted; privacy superseded by D10 | 5 Nov 2026 | Chat |
| D2 | Updates are Docker images pulled from GHCR against a signed release manifest; no USB delivery | Accepted | 5 Nov 2026 | Chat |
| D3 | A shop with no internet connects a phone hotspot for updates; a MiFi is optional per shop | Accepted | 5 Nov 2026 | Chat, FU10 |
| D4 | Apple's system colours, with fixed roles and increased-contrast variants for text (spec section 15, Colour tokens), and the Inter typeface. The earlier proposals (Red Orange #F42902, Snowy White #FDFDFF, #83FD80, and the extra working colours) are dropped | Accepted | 5 Nov 2026 | Chat |
| D5 | Every screen is responsive, from phone width to large monitors, with compact, medium and large layouts | Accepted | 5 Nov 2026 | Chat |
| D6 | The repository haq-technologies/haq-retailOS must be private before anything is pushed. Until the developers join, main takes changes only through a pull request with green CI and no approval required, because GitHub does not let an author approve their own pull request; one approval becomes required when they join | Privacy rule superseded by D10, and the review rule by D15; the pull-request rule stands | 5 Nov 2026 | Chat (left to Claude) |
| D7 | At the counter, an item with a set price for the customer type is added at that price with one tap or scan. Otherwise a price pad opens, filled with the last price sold (or the median, by shop setting), or empty for an item that has never sold, and one tap confirms it or the cashier types another. Tiles step through the drill order and show a price only on the last step. An optional facet, such as cable length or rated power, has a Skip tile, and the item prints without it | Accepted | 5 Nov 2026 | Chat (left to Claude) |
| D8 | Retail or Wholesale is set in the counter's top bar and covers the whole sale; one sale never mixes the two | Accepted | 5 Nov 2026 | Chat |
| D9 | When an item from a category that asks for colour is added, its sale line opens a row of the shop's colours, most used first, then More. One tap sets the colour; the next item or Pay closes the row with no colour, and tapping the line opens it again. Colour never blocks a sale. The owner ticks which categories ask for colour at install | Accepted | 5 Nov 2026 | Chat (left to Claude) |
| D10 | The repository stays public for now; the product owner will make it private and move the organisation to GitHub Team later. Until then every push publishes, so commits use the GitHub noreply email, and no secret, signing key, .env file, shop data or personal data is committed. This supersedes D6's privacy rule. D6's pull-request rule stands, and GitHub enforces it free of charge on a public repository | Accepted | 5 Nov 2026 | Chat |
| D11 | A POS card payment needs nothing typed: the cashier charges the card on the POS terminal, chooses POS and taps Finish. POS payments are totalled by method and are not checked against a bank | Accepted | 6 Nov 2026 | Chat |
| D12 | The clickable prototype and the cashier timing before the build are dropped. Phase 1 is the wireframes, and gate G1 is the product owner's sign-off of them, which freezes the counter interaction model. Real cashiers are first timed on the built counter, by the speed instrumentation (S8) in the shadow pilot, and the drill orders stay the v1.1 proposals until that data says otherwise | Accepted | 6 Nov 2026 | Chat |
| D13 | The product owner signs off spec v1.5 as the baseline for the build. This is the spec half of gate G0 | Accepted | 6 Nov 2026 | Chat |
| D14 | The receipt header (shop name, address and phone) and footer (returns wording) are a shop setting that only the sysadmin changes. A branch can override the address and phone, so each branch's receipts show where they were printed. In the pilot the sysadmin sets them in the shop profile with the command-line tool, and from the MVP in the console | Accepted | 6 Nov 2026 | Chat |
| D15 | One person builds HAQ Retail OS: the product owner, working with Claude, does the development, design, field work and support, with no other developer, designer or field lead. The scope and the dates stay as they are, met with extra hours, and the pace is checked at G2. Pull requests merge on green CI with no approval, because nobody else can review them. This supersedes Q1 and D6's review rule | Accepted | 6 Nov 2026 | Chat |
| D16 | No mini-PC is bought for testing or staging: the product owner's own computer is the development, staging and test machine. G2's clean install runs in a fresh Ubuntu virtual machine on it, because the shop server runs Ubuntu. Checks that need the shop's own hardware (touch and Chrome's kiosk keys on the Windows counters, the UPS and power cuts on the server) run first on the pilot hardware at G3 | Accepted | 6 Nov 2026 | Chat; the G2 virtual machine proposed by Claude |
| D17 | The hardware list in docs/operations/hardware.md is locked as the pilot's hardware. Every device bought for the pilot must meet it, and a change to it is a new decision | Accepted | 6 Nov 2026 | Chat |
| D18 | The printed daily summary shows sales by payment method, revenue in the three cost parts with their percentages, the day's flag counts and the audit code, but no profit or cost amounts. The owner sees profit on screen | Accepted | 6 Nov 2026 | Chat (proposed by Claude) |
| D19 | The product owner signs off the wireframes on the v1.5 canvas, the counter and the back office, so gate G1 passes and the counter interaction model is frozen. Phases 0 and 1 are closed and locked: a change to their decisions or to the wireframes is a new decision. Their remaining items move on: Docker Compose for development opens Phase 2, and buying the pilot hardware and the bench test come before Phase 3 | Accepted | 6 Nov 2026 | Chat |
| D20 | The shop server lives in apps/shop-server/, which leaves room for apps/head-office/ in the MVP. Its versions are pinned: PHP 8.5.12, Laravel 13, PostgreSQL 18.6, nginx 1.30.6 and Node 26.13.0 (the current LTS); Inertia 3, React 19, TypeScript 6.0, Vite 8 and Tailwind CSS 4, with the spec's colour tokens as the only palette and Inter bundled; Pest 5, Larastan at level 9, Pint, ESLint and Vitest. Every PHP file declares strict types. TypeScript 7 waits until typescript-eslint supports it. A version changes only through a pull request with green CI | Accepted | 6 Nov 2026 | Chat: the location chosen by the product owner; the versions and tools proposed by Claude |
| D21 | Laravel's own tables (migrations, sessions, cache, cache_locks, jobs, job_batches and failed_jobs) keep Laravel's keys, because the database queue claims jobs by an incrementing id, and these tables hold no business records and never sync. Every application table has a ULID primary key, and a test fails on any other key | Accepted | 6 Nov 2026 | Correction, proposed by Claude |
| D22 | Application code goes into the public repository: the product owner keeps it public for now at the start of Phase 2 (D10 stands), so nothing secret or personal is ever committed. Claude merges each pull request once CI is green, as D15 allows | Accepted | 6 Nov 2026 | Chat |
| D23 | The shop server is a modular monolith: one Laravel app and one database, with the code split by business area into modules, each a namespace under app/: Platform (shared building blocks), Organisation, Audit, Settings, Identity and Catalogue in Phase 2, and later Sales, Printing, Cost, Shifts, Reports and Sync. A module owns its models, actions, policies, controllers and API Resources, and uses only the modules beneath it; Pest architecture tests enforce the boundaries, and only a Page helper renders Inertia pages, so no Eloquent model reaches one. Migrations stay in one ordered folder and one mutability matrix covers every table, because Finish writes across modules in one transaction. No modules package | Accepted | 6 Nov 2026 | Chat: proposed by Claude, chosen by the product owner |
| D24 | One PIN per person, used both to sign in and, for a manager or the owner, to approve. A cashier's or storekeeper's PIN is 4 to 6 digits; a manager's or owner's is exactly 6, because spec section 3 asks for a 6-digit PIN to approve at the same screen. Until the console exists, PINs are set at install with shop:add-user, asked twice without echoing | Accepted | 6 Nov 2026 | Correction, proposed by Claude from spec section 3 |
| D25 | The catalogue keeps the seed's 29 models and product lines (such as Oraimo FreePods 3C and the no-brand Q10) in a product_models table (brand, name, the categories they suit), as suggestions for an item's model. Spec section 5 counted them but gave them no table. Every seeded row keeps its permanent seed ID beside its ULID key, and that ID, a category's code and an item's code never change once written, enforced by a trigger from the mutability matrix [C9] | Accepted | 6 Nov 2026 | Correction, proposed by Claude |
| D26 | A shop's stack runs three images from GHCR (app, web, db), published on every merge to main and tagged main and with the commit. Three one-shot jobs run before the app starts: roles creates or updates the three database roles, migrate runs the migrations and applies the mutability matrix, and certificate makes the HTTPS certificate. So no running container holds the owner's password. deploy/install.sh makes every secret on the server itself (never in the repository) and is safe to run again. The local certificate authority lasts 10 years and the server certificate 5 | Accepted | 6 Nov 2026 | Chat: proposed by Claude, within spec section 4 |
| D27 | The product owner passes gate G2 on the evidence recorded in PLAN.md (a clean Ubuntu virtual machine installed from a profile; the seed loaded twice with no change; append-only held against a deliberately widened grant). Phase 2 is closed: a change to its decisions is a new decision. The pace check changed no date and no scope: Phase 2 finished in week 1, ahead of its weeks 3 to 8 | Accepted | 6 Nov 2026 | Chat |
| D28 | The product owner accepts the identity timings the spec left open: a 15-minute lockout after five wrong PINs; a manager or owner signed in at a counter returns to the name tiles after 2 idle minutes (a cashier after 3, S6); and after ten wrong PINs on one terminal in 10 minutes, it waits 1 second, doubling with each further wrong PIN, at most 5 minutes. All are locked settings | Accepted | 6 Nov 2026 | Chat |
| D29 | The owner's (or a manager's) phone or laptop reaches the back office on the shop's network as a back-office device: it is enrolled like a counter with a one-time code, signs in with the same name tile and PIN, and has a code such as D01 instead of T01. It has no cash drawer and never sells: Finish refuses a sale from it (Phase 3). A terminal's code and kind never change once written | Accepted | 6 Nov 2026 | Chat: proposed by Claude, accepted by the product owner |
| D30 | How a new item inherits its usual range (spec section 7 says "same category and nearest facets, otherwise the category median"): from the sibling in its category that shares most of its facet values and has at least 5 sales for the customer type, using that sibling's 10th to 90th percentile; otherwise from the 10th to 90th percentile of the category's last 50 sales, given at least 5. A range from one median would be a single price, which would prompt at almost every sale | Accepted | 6 Nov 2026 | Correction, proposed by Claude |
| D31 | Receipts print "NGN" before the total until the bench test shows the pilot printer can print ₦; receipt.currency is a locked setting (NGN, N or ₦), so the change is one setting. Line amounts print without a sign, as the receipt wireframe shows. A fitted drawer opens with the receipt of a sale that takes cash, not with a transfer or POS sale; every opening is logged | Accepted | 6 Nov 2026 | Correction, proposed by Claude from the hardware bench checklist and spec section 4 |
| D32 | The catalogue_seq stamp is given at commit, not when the row is written: a deferred trigger adds each committed catalogue, set-price and sale-line change to an append-only change log under a short lock, so seq order is commit order and a counter can never skip a change that committed late. A counter starts from a paged snapshot and then polls the changes after its seq. The sale that taught a barcode does not count towards its two confirming sales | Accepted | 6 Nov 2026 | Correction, proposed by Claude while building the counter's API (spec sections 4 and 6) |

## Answers that set facts or scope

| Ref | Answer | Effect |
|---|---|---|
| Q1 | 2 full-time developers and 1 designer | The 22-week plan. Superseded by D15: one person builds it, and the dates stay |
| Q2 | No target date | 22 weeks is the working target |
| Q4 | Buy network printers | R3 |
| Q5 | Windows counters, touch first, keyboard and mouse as fallbacks | Spec sections 2, 4, 15 |
| Q6 | No owner phone summary | S13 not adopted |
| Q7 | Hosting not decided | Decide before the MVP; not needed for the pilot |
| Q8 | Catalogue v1 may reopen as v1.1 | v1.1 drafted |
| Q9 | The spec moves to Markdown in Git, with .docx exports | R8 |
| Q10 | Draw the wireframes on a new canvas | Phase 1 |
| Q11 | 3 cashiers; the drawer practice was unknown | S11 |
| Q12 | Cashiers do not haggle | S4 falls back to the last price sold |
| Q13 | Transfers are confirmed on a POS terminal | S2 |
| Q14 | Every sale gets a printed receipt | The pending-print rule stands |
| Q16 | "Apple" on cases means the phone; the shop says "pouch" | C3, FU4 |
| Q17 | "iPhone Charger" is a description; "DataCable" is a printed label | C4 |
| Q18 | J7, car mp3 s8, Pro and Pro 5s are product names; Pro is not Pro 5s | C5, C6 |
| Q19 | Wireless wattages are in the shared list | C7 |
| Q20 | Inverter and solar | S7 |
| Q21 | No internet at the pilot shop; some shops may have it | D3; S16's offline path |
| Q22 | Cashiers are comfortable with English | No translation for the pilot |
| Q23 | Busy-day volume unknown | Counted on the shop visit |
| Q24 | The owner enters costs as the schedule allows | FU6 |
| FU2 | Customers pay the cashier who served them | A printer per paying counter; the drawer optional (S21) |
| FU3 | Cashiers type the selling price; only the owner sets cost | Open pricing stays |
| FU4 | Receipts say "Pouch" | "iPhone 11 Silicone Pouch" |
| FU5 | Pro is an earpods product | C5, C6 |
| FU6 | A trusted person may enter cost, through a console setting | "Manager may enter cost", off by default |
| FU7 | Shops have several cashiers, and some have several branches | Two-way sync straight after the MVP |
| FU8 | No figures for pouch and protector sales | C2 stands |
| Prices (chat) | The owner and the manager set prices; adopt the set-price and last-price rule, with the median as a shop setting | S4 revised |
| Shop visit and hardware (chat) | The product owner reports the shop visit done, including the shop owner's re-confirmation of catalogue v1.1, and the pilot hardware chosen (6 November 2026) | Gate G0 passes |
| Shop visit results (chat) | No changes to catalogue v1.1 | Its shelf-walk items keep their drafted values: the six C8 categories show the full brand list, with none first; the 16 brands and the short codes are kept; the v1.1 phone models and the name orders stand |
