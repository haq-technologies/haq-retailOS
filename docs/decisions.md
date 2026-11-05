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
| D6 | The repository haq-technologies/haq-retailOS must be private before anything is pushed. Until the developers join, main takes changes only through a pull request with green CI and no approval required, because GitHub does not let an author approve their own pull request; one approval becomes required when they join | Privacy rule superseded by D10; the pull-request rule stands | 5 Nov 2026 | Chat (left to Claude) |
| D7 | At the counter, an item with a set price for the customer type is added at that price with one tap or scan. Otherwise a price pad opens, filled with the last price sold (or the median, by shop setting), or empty for an item that has never sold, and one tap confirms it or the cashier types another. Tiles step through the drill order and show a price only on the last step. An optional facet, such as cable length or rated power, has a Skip tile, and the item prints without it | Accepted | 5 Nov 2026 | Chat (left to Claude) |
| D8 | Retail or Wholesale is set in the counter's top bar and covers the whole sale; one sale never mixes the two | Accepted | 5 Nov 2026 | Chat |
| D9 | When an item from a category that asks for colour is added, its sale line opens a row of the shop's colours, most used first, then More. One tap sets the colour; the next item or Pay closes the row with no colour, and tapping the line opens it again. Colour never blocks a sale. The owner ticks which categories ask for colour at install | Accepted | 5 Nov 2026 | Chat (left to Claude) |
| D10 | The repository stays public for now; the product owner will make it private and move the organisation to GitHub Team later. Until then every push publishes, so commits use the GitHub noreply email, and no secret, signing key, .env file, shop data or personal data is committed. This supersedes D6's privacy rule. D6's pull-request rule stands, and GitHub enforces it free of charge on a public repository | Accepted | 5 Nov 2026 | Chat |

## Answers that set facts or scope

| Ref | Answer | Effect |
|---|---|---|
| Q1 | 2 full-time developers and 1 designer | The 22-week plan |
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
