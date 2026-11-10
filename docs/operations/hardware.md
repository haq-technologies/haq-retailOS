# Hardware

What each pilot-shop device must do: the list a shop buys before its install. The requirements come from spec v1.5, sections 4 and 16. There are no prices here, because they change too fast.

Nothing here is tested on the devices themselves. Development and every test run in software on the product owner's computer, with virtual machines and the stand-in printer (D16, D60).

## Devices

The hardware is chosen and this list is locked (6 November 2026, D17): every device bought for the pilot must meet it, and a change to it is a new decision.

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
