# HAQ Retail OS

A point-of-sale system for phone-accessory shops in Nigeria, by HAQ Technology. A small server in each shop runs everything on the shop's own network, so selling never depends on the internet. Every receipt names the product properly, profit stays honest while cost prices are still being filled in, and every sale survives power cuts and tampering. The tiers are Core and Pro.

**Status:** 6 November 2026. Phases 0 and 1 are closed. Phase 2, the foundation, is built and gate G2's criteria are met; the gate waits for the product owner's pace check before Phase 3.

## Start here

1. [PLAN.md](PLAN.md): the goal, phases and gates, timeline, and what happens next.
2. [docs/spec/retail-os-spec-v1.5.md](docs/spec/retail-os-spec-v1.5.md): the product spec the team builds from.
3. [docs/decisions.md](docs/decisions.md): every decision since spec v1.4, and where it came from.

## What is in this folder

| Path | What it is |
|---|---|
| [PLAN.md](PLAN.md) | The living plan |
| [CLAUDE.md](CLAUDE.md) | Standing instructions for Claude Code sessions |
| [docs/spec/](docs/spec/) | The product spec, v1.5 |
| [docs/decisions.md](docs/decisions.md) | The decision log |
| [docs/catalogue/](docs/catalogue/) | Starter catalogue v1.1: the JSON seed (the master), the review workbook for the shop owner, and Appendix C |
| [docs/operations/](docs/operations/) | The hardware list and the shop-visit checklist |
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
