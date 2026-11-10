# Gate G3: the dress rehearsal

Gate G3 closes Phase 3 (PLAN.md). It runs on the pilot hardware, installed by pulling a signed release from GHCR: 300 scripted sales on two counters, the power pulled mid-sale and mid-print, and a restore onto the spare box. Touch, the kiosk keys and the UPS are first tested here (D16). Write down each result; G3 passes when every check below passes.

The scripted sales are real rows that can never be deleted, so the rehearsal install is wiped and installed again before the shop trades.

## Before the day

- The pilot hardware is set up (docs/operations/hardware.md): the server with its second disk for backups, two counters with their printers, a drawer where the shop wants one, the scanner, the UPS and the spare PC.
- The release signing key exists and its public half is committed (D36); a release is made and signed (docs/operations/releases.md).
- The server has a read-only GHCR token of its own, in `secrets/ghcr_user` and `secrets/ghcr_token` in the install folder.
- A rehearsal shop profile, with the two counters and their printers' fixed IPs.

## 1. Install by pulling from GHCR

Follow [install-a-shop.md](install-a-shop.md), steps 1 to 7, with the rehearsal's own details:

```
sudo apps/shop-server/deploy/install.sh --profile rehearsal.profile.json --ip 192.168.1.10 --backup-dir /mnt/backup --tag <release>
sudo haq check
```

Add a cashier and a manager (`sudo haq add-user`), and register both counters (`sudo haq enrol T01`, then http://192.168.1.10/setup on each counter).

## 2. The counters by hand (touch, keys, scanner, printer)

- [ ] Chrome in kiosk mode opens the counter on start-up, and a hard reboot comes back to it (each counter set up as in docs/operations/start-up.md).
- [ ] Touch: the tiles, the price pad, Pay, the colour row and the dialogs work with a finger alone.
- [ ] Keys: F8 opens Pay, F4 holds, F2 opens New item, and the scanner's Enter never pays (D34).
- [ ] The scanner: an unknown barcode is learned; the next scan of it adds the item at once.
- [ ] A cash sale prints the receipt with the Code 128 receipt number, cuts, and opens the drawer; the receipt barcode scans.
- [ ] A transfer sale and a POS sale print without opening the drawer.
- [ ] Time three ordinary sales of three items each: under 30 seconds each (spec section 7) [S8].

## 3. 300 scripted sales on two counters

On the server, while the two counters are also used by hand:

```
sudo docker compose --project-name haq-retail-os -f /opt/haq-retail-os/compose.yaml --env-file /opt/haq-retail-os/.env exec app php artisan shop:rehearse --count=300 --pace=2 --confirm
```

It sells on T01 and T02 at once, through Finish and the print queue to the real printers. When it ends:

```
... exec app php artisan shop:rehearsal-check
```

- [ ] Every counter's receipt numbers are gapless, every sale is whole, every sale has its receipt job, every audit chain verifies, and the grants match.

## 4. The power pulled mid-sale and mid-print

- [ ] Start `shop:rehearse` again, and pull the server's power cord (not the UPS's output: the cord between the UPS and the wall first, to see the UPS carry it; then the server's own cord). Power it back on. The stack starts by itself; the counters show Reconnecting and then Online, each with its open sale kept. Run `shop:rehearsal-check`: it passes, and any receipt that did not print is listed.
- [ ] Pull a printer's power while receipts print. The counter shows "Receipt … did not print" and Print pending receipt prints it as the original, not a duplicate.
- [ ] Pull a counter PC's power with a sale open. On reboot the counter comes back with the sale kept.
- [ ] The UPS: with the wall power off, one counter, its printer and the server keep selling (spec section 9) [S7].

## 5. Restore onto the spare box

Follow docs/operations/restore-drill.md with the backup disk moved to the spare.

- [ ] Service is back within 1 hour; the new epoch is higher; every audit chain verifies; the last receipt is in the copy or re-entered.

## 6. After

- Write the results in PLAN.md, with the times measured.
- Wipe the rehearsal install (`docker compose ... down -v` and remove the install folder and the backup folder), then install the pilot shop fresh with its real profile.
