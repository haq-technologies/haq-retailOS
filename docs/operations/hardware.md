# Hardware

What to buy for the bench test now, and what each pilot-shop device must do. The requirements come from spec v1.5, sections 4 and 16. There are no prices here, because they change too fast.

## Bench kit (Phase 0)

Buy one of each, so Developer B and the field lead can prove printing, the drawer, scanning, the kiosk and power behaviour before anything is built on them.

| Device | Must have | Why |
|---|---|---|
| Server mini-PC | x86-64, 4 or more cores, 16 GB RAM, room for two SSDs, wired gigabit Ethernet, Wi-Fi; runs Ubuntu Server LTS | Runs Laravel and PostgreSQL in Docker. The second SSD holds the continuous backup. Wi-Fi joins a phone hotspot for updates (D3) |
| Two SSDs | Power-loss protection (PLP), 256 GB or more each. Enterprise M.2 drives have it; check before buying | A power cut must never corrupt the database (F24) |
| UPS | Line-interactive or online, pure sine wave, about 650 VA or more | Keeps the server, switch and router up, and bridges the inverter's changeover |
| Network switch | Unmanaged, gigabit, 8 ports | Wires the counters and printers; it runs on the UPS |
| Receipt printer | 80 mm thermal, ESC/POS, Ethernet (LAN) port, auto-cutter, cash-drawer port (RJ11 or RJ12), prints Code 128 barcodes | The server prints straight to it on port 9100 (R3), and the receipt number prints as a barcode (S9) |
| Barcode scanner | USB, keyboard mode, reads 1D codes (EAN-13, UPC, Code 128) and QR, with a configurable suffix | Scans products and receipts; the counter tells scans from typing by timing (S1) |
| Counter screen | A touchscreen monitor of 15 inches or more, on a Windows PC with Chrome | Counters are touch-first (Q5) |
| Keyboard and mouse | Any | The fallback on every counter |
| Cash drawer (optional) | Kicks out from the printer's drawer port (usually 24 V) and has a manual key | Drawers are optional per counter (S21); the key is the fallback |
| Flash drives | Two USB drives of 64 GB or more | Off-site backup rotation for a shop without internet (S16) |

## Pilot shop

- One server, one UPS and one switch, plus a basic router if the shop wants local DHCP, all on the UPS.
- One printer, with a fixed IP, for each counter that takes money.
- One counter PC and its printer on the UPS or the inverter, so selling continues in a power cut (S7).
- Touchscreen counters running Chrome in kiosk mode, wired by Ethernet.
- A cash drawer only where the shop wants one (S21).
- A phone hotspot for the weekly update; a MiFi is optional and also enables the cloud backup copy (D3, S16).
- A pre-imaged spare PC (support model, spec section 16).

## Bench test checklist

- [ ] The server prints to the network printer on port 9100: a receipt, a cut and a Code 128 barcode.
- [ ] The printer kicks the drawer, and its status query reports paper out and cover open.
- [ ] Record the scanner's suffix (Enter) and its typing speed, and check that scans are told apart from typing by timing.
- [ ] In Chrome kiosk mode, record which keys reach the page: Ctrl+N, F2, F4, F9, F12 and numpad +.
- [ ] Touch works across the whole counter screen with 56-pixel targets.
- [ ] With the mains pulled, the UPS holds the server, switch and router, and the counter on the inverter keeps selling.
- [ ] The server joins a phone hotspot over Wi-Fi and pulls an image from GHCR.
