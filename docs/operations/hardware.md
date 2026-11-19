# Hardware

What each pilot-shop device must do: the list a shop buys before its install. The requirements come from spec v1.5, sections 4 and 16. There are no prices here, because they change too fast.

Nothing here is tested on the devices themselves. Development and every test run in software on the product owner's computer, with virtual machines and the stand-in printer (D16, D60).

## Devices

The hardware is chosen and this list is locked (6 November 2026, D17): every device bought for the pilot must meet it, and a change to it is a new decision. D86 and D87 (17 November 2026) added the Windows PC server and USB receipt printers.

| Device | Must have | Why |
|---|---|---|
| Server mini-PC | x86-64, 4 or more cores, 16 GB RAM, room for two SSDs, wired gigabit Ethernet, Wi-Fi; runs Ubuntu Server LTS | Runs Laravel and PostgreSQL in Docker. The second SSD holds the continuous backup. Wi-Fi joins a phone hotspot for updates (D3) |
| Two SSDs | Power-loss protection (PLP), 256 GB or more each. Enterprise M.2 drives have it; check before buying | A power cut must never corrupt the database (F24) |
| Or a Windows PC server | 64-bit Windows 11, or 64-bit Windows 10 20H2 to 22H2 updated since November 2022, in any edition, with virtualisation turned on in its firmware; 4 or more cores; 16 GB RAM (tested with 8 GB; less is a warning until it is measured, D92); an SSD with 64 GB free; on the UPS. It is also the shop's first counter | The setup program runs the same server hidden in WSL2 (D86). A desktop seldom has PLP SSDs, so the UPS is required |
| UPS | Line-interactive or online, pure sine wave, about 650 VA or more | Keeps the server, switch and router up, and bridges the inverter's changeover |
| Network switch | Unmanaged, gigabit, 8 ports | Wires the counters and printers; it runs on the UPS |
| Receipt printer | 80 mm thermal, ESC/POS, Ethernet (LAN) port or USB port, auto-cutter, cash-drawer port (RJ11 or RJ12), prints Code 128 barcodes | The server prints straight to it on port 9100 (R3); a USB printer is reached through the print bridge on its counter PC (D87). The receipt number prints as a barcode (S9) |
| Barcode scanner | USB, keyboard mode, reads 1D codes (EAN-13, UPC, Code 128) and QR, with a configurable suffix | Scans products and receipts; the counter tells scans from typing by timing (S1) |
| Counter screen | A touchscreen monitor of 15 inches or more, on a Windows PC with Chrome | Counters are touch-first (Q5) |
| Keyboard and mouse | Any | The fallback on every counter |
| Cash drawer (optional) | Kicks out from the printer's drawer port (usually 24 V) and has a manual key | Drawers are optional per counter (S21); the key is the fallback |
| Flash drives | Two USB drives of 64 GB or more | Off-site backup rotation for a shop without internet (S16) |

## Pilot shop

- One server, one UPS and one switch, plus a basic router if the shop wants local DHCP, all on the UPS. A one-PC shop needs only the Windows PC, its printer and the UPS (D86).
- One printer, with a fixed IP, for each counter that takes money.
- One counter PC and its printer on the UPS or the inverter, so selling continues in a power cut (S7).
- Touchscreen counters running Chrome in kiosk mode, wired by Ethernet.
- A cash drawer only where the shop wants one (S21).
- A phone hotspot for the weekly update; a MiFi is optional and also enables the cloud backup copy (D3, S16).
- A pre-imaged spare PC (support model, spec section 16).
