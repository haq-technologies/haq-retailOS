# HAQ Retail OS

A point-of-sale system for phone-accessory shops in Nigeria, by HAQ Technology. A small server in each shop runs everything on the shop's own network, so selling never depends on the internet. Every receipt names the product properly, profit stays honest while cost prices are still being filled in, and every sale survives power cuts and tampering. The tiers are Core and Pro.

**Status:** 10 November 2026. Phases 0, 1 and 2 are closed: gate G2 passed (D27). Phase 3, the counter and sale core, is built (D30 to D36); its gate G3, the dress rehearsal on the pilot hardware, waits for the hardware. Phase 4's owner features and its hardware-free hardening are built (D39 to D55), and so are Phase 5's tools: the measured pilot and the owner's data export (D56, D57). What is left needs the pilot hardware or the shop (PLAN.md).

## Start here

1. [PLAN.md](PLAN.md): the goal, phases and gates, timeline, and what happens next.
2. [docs/spec/retail-os-spec-v1.5.md](docs/spec/retail-os-spec-v1.5.md): the product spec the team builds from.
3. [docs/decisions.md](docs/decisions.md): every decision since spec v1.4, and where it came from.

## Try it on this computer

Everything runs in Docker through Colima. From this folder, in Terminal:

1. Start Docker (after a restart only), then the shop server:

   ```
   colima start
   docker compose up -d
   docker compose run --rm migrate
   ```

2. Set up a test shop, the first time only. It is safe to run again:

   ```
   docker compose exec app php artisan shop:apply-profile docker/profile.example.json
   docker compose exec app php artisan catalogue:seed
   ```

3. Add people. Each command asks for the PIN twice: 4 to 6 digits for a cashier, exactly 6 for a manager or the owner:

   ```
   docker compose exec app php artisan shop:add-user "Amina" --role=cashier
   docker compose exec app php artisan shop:add-user "Kemi" --role=manager
   docker compose exec app php artisan shop:add-user "Halima" --role=owner
   ```

4. Make Chrome a counter. Get a one-time code, open http://localhost:8080/enrol and type it:

   ```
   docker compose exec app php artisan shop:enrol-terminal T01
   ```

   Tap a name, type the PIN, and sell: tap the tiles or search, type a price, Pay (F8), cash, Finish. The stand-in printer writes each receipt to apps/shop-server/storage/logs/fake-printer.log.

5. Make a second Chrome window (a private window, or another Chrome profile) the back office, and sign in as the owner:

   ```
   docker compose exec app php artisan shop:enrol-terminal D01
   ```

   Open http://localhost:8080/enrol, type the code, then http://localhost:8080/back-office. Try the dashboard, Needs cost (enter a cost, then Undo it), Prices, Review, Flags, the Daily summary, the Measured pilot (start the 14 days, type the notebook's totals, trace a difference), the Audit log, Server health and Shop settings, where the owner downloads all the shop's data. On the counter, My history shows the cashier's own actions.

6. Stop it with `docker compose down`; the data is kept for next time.

The automatic tests run with `docker compose run --rm -e COMPOSER_PROCESS_TIMEOUT=0 test` and `apps/shop-server/e2e/run.sh`; apps/shop-server/README.md lists the rest. Docker needs free disk space: keep 15 GB or more free on this computer.

## What is in this folder

| Path | What it is |
|---|---|
| [PLAN.md](PLAN.md) | The living plan |
| [CLAUDE.md](CLAUDE.md) | Standing instructions for Claude Code sessions |
| [docs/spec/](docs/spec/) | The product spec, v1.5 |
| [docs/decisions.md](docs/decisions.md) | The decision log |
| [docs/catalogue/](docs/catalogue/) | Starter catalogue v1.1: the JSON seed (the master), the review workbook for the shop owner, and Appendix C |
| [docs/operations/](docs/operations/) | The hardware list, the shop visit, the G3 rehearsal, restores, releases, start-up, the off-site copy, the 46 checks and the measured pilot |
| [docs/archive/](docs/archive/) | Superseded originals: spec v1.4 and the locked catalogue v1. Read-only |
| [tools/catalogue/](tools/catalogue/) | Scripts that build and verify the catalogue |
| [apps/shop-server/](apps/shop-server/) | The shop server: Laravel, Inertia and React, with its own README |
| [compose.yaml](compose.yaml) | The development stack for the shop server |

## Links

- Spec v1.4 review and build plan, the Claude Doc where the decisions were made: https://claude.ai/artifact/DW5VWtw3J6xgMg6xMSgKSY
- Wireframes v1.5, the current design canvas: https://claude.ai/artifact/2Z3qtkr9F4AM9k9PmuRqh1
- POS pilot wireframes v1.4 (superseded): https://claude.ai/artifact/UwPPkLC7LDwxCBLSzMCK7i

## How we work

- One GitHub repository, public for now (D10), developed trunk-based: short-lived branches, and merges only through pull requests with green CI. Nobody pushes directly to main.
- The JSON seed is the only master for catalogue data. Never hand-edit the generated workbook or appendix; change the build script and rerun it (see CLAUDE.md).
- Every decision goes into docs/decisions.md first, then into the spec.

## Copyright

Copyright © 2026 HAQ Technology. All rights reserved. This repository is public for now, but it is not open source: no licence is granted to use, copy, modify or distribute its contents.
