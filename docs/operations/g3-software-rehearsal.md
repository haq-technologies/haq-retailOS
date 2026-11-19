# Gate G3: the software dress rehearsal

Gate G3 closes Phase 3 (PLAN.md). It runs in software only (D60): two virtual machines play the shop server and its spare, the stand-in printer takes the receipts, and a virtual machine powered off hard stands in for a power cut. No physical device is needed. It covers the soak test and the install rehearsal, and it proves checks E1 and E2 (docs/operations/acceptance-checks.md). Write down each result and the times measured; G3 passes when every check below passes.

The scripted sales are real rows that can never be deleted, so the rehearsal is wiped afterwards and never used for a shop.

Every release carries both ways to install a shop (D86, D89), so G3 passes on a release only when both pass: the mini-PC way below, on virtual machines, and [the Windows way](#the-windows-way), on the product owner's own Windows PC, never a virtual machine (D90).

## Before the day

- Two Ubuntu Server 24.04 LTS virtual machines, each with a second disk for backups: the server and the spare. Set each up as in [Rehearse on a Windows PC](install-a-shop.md#rehearse-on-a-windows-pc); Lima on this computer works too. With Lima, make each backup disk with `limactl disk create <name> --size 5GiB --format raw`. Lima can wipe that disk when the machine starts after a hard power-off (its own start-up script, not the shop's), so check the backups after step 4.
- A release made and signed ([releases.md](releases.md)), or `main` until the first release exists.
- A read-only GHCR token.
- Two counters: two Chrome profiles on one PC, or two PCs, registered as T01 and T02.

## 1. Install from GHCR, timed (the install rehearsal, check E2)

Start the clock. On the server, follow [install-a-shop.md](install-a-shop.md) steps 3 to 9 with the appendix's changes (`--standin-printer` and the virtual machine's address), with two counters and a drawer on T01.

- [ ] `sudo haq check` ends with **Every check passed**, including "every running service restarts by itself" and "Docker starts with the server".
- [ ] From the profile to the first sale, with both counters registered and the receipt shown by `sudo haq receipts`, takes under 2 hours.

## 2. The counters by hand

- [ ] Keys: F8 opens Pay, F4 holds, F2 opens New item, and Enter never pays (D34).
- [ ] A cash sale, a transfer sale and a POS sale each print a receipt with its receipt number, shown by `sudo haq receipts`.
- [ ] A sale parked on T01 resumes on T02.

## 3. 300 scripted sales on two counters (the soak test)

On the server, while the two counters are also used by hand:

```
sudo docker compose --project-name haq-retail-os -f /opt/haq-retail-os/compose.yaml --env-file /opt/haq-retail-os/.env exec app php artisan shop:rehearse --count=300 --pace=2 --confirm
```

It sells on T01 and T02 at once, through Finish and the print queue to the stand-in printer. When it ends:

```
... exec app php artisan shop:rehearsal-check
```

- [ ] Every counter's receipt numbers are gapless, every sale is whole, every sale has its receipt job, every audit chain verifies, and the grants match.

## 4. Power cuts, in software (checks B1, B2 and E2)

- [ ] Start `shop:rehearse` again, and power the server off hard while it runs (VirtualBox: Close, then Power off the machine; Lima: `limactl stop --force`). Start it again. The stack starts by itself; each counter shows Reconnecting and is selling again within 3 minutes, with its open sale kept. `shop:rehearsal-check` passes, and any receipt that did not print is listed.
- [ ] Stop the stand-in printer while receipts print (`... stop printer`), then start it again (`... start printer`). The counter shows "Receipt … did not print", and Print pending receipt prints it as the original, not a duplicate.
- [ ] End Chrome on a counter with a sale open, and open it again: the sale is kept.
- [ ] `sudo haq backups` still lists the backups. On Lima, if the list is empty, the disk was wiped as it started: run `sudo chown 999:999 /mnt/lima-<disk>`, restart the db, backup and scheduler services (`... restart db backup scheduler`), then `sudo haq backup`, before step 5.

## 5. Restore onto the spare (check E1)

Follow [restore-drill.md](restore-drill.md) on the spare, with the server's backup folder copied over (`rsync -a /mnt/backup/ spare:/mnt/backup/`).

- [ ] Service is back within 1 hour; the new epoch is higher; every audit chain verifies; the last receipt is in the copy or re-entered on Lost sales.
- [ ] Each counter reconnects to the spare with no new code and no certificate warning: the restore brought back the shop's app key and certificate authority (D69).

## The Windows way

On the product owner's own Windows PC, with the release's setup checked against its manifest first (`tools/release/check-windows.sh`, [releases.md](releases.md)). The second counter is another PC with the setup, or a second Chrome profile on the same PC registered as T02 from its setup page.

### W1. Install with the setup, timed (check E2)

Start the clock. Follow [The Windows way](install-a-shop.md#the-windows-way-one-setup-program) in install-a-shop.md, with two counters and a drawer on T01, and the receipt printer the PC has, or none yet.

- [ ] `haq-helper server check`, in **Terminal (Admin)**, ends with **Every check passed**.
- [ ] From starting the setup to the first sale, with both counters registered, takes under 2 hours.
- [ ] Windows' **Settings**, **Windows Update**, shows the active hours as 6:00 to 23:00 (D98).
- [ ] `haq-helper server add-user "Test Cashier" cashier` asks the PIN twice, and the new name tile signs in with it (D99).

### W2. The counters by hand, and 300 scripted sales

- [ ] As in steps 2 and 3, with `haq-helper server artisan` in place of the `docker compose ... exec app php artisan` line: `haq-helper server artisan shop:rehearse --count=300 --pace=2 --confirm`, then `haq-helper server artisan shop:rehearsal-check`.
- [ ] While the sales run, Task Manager's **Memory** for **VmmemWSL** (or **vmmem**) is written down against the PC's memory: the server's memory measured (PLAN.md, the Windows setup).

### W3. A restart, and the off-site drive

- [ ] Start `shop:rehearse` again and restart Windows while it runs (**Start**, **Power**, **Restart**). The server is ready before anyone signs in; each counter shows Reconnecting and is selling again within 3 minutes, with its open sale kept; `shop:rehearsal-check` passes. A real power cut, holding the power button, is optional, at the product owner's choice (D90).
- [ ] Plug HAQ-OFFSITE-A into the PC: **Server health** shows its new copy.

The restore is rehearsed the mini-PC way (step 5). Restoring a Windows server from an off-site drive is not yet written down (audit finding AU13).

## 6. After

- Write the results and the times in PLAN.md (the Phase 3 checklist) and against checks E1 and E2 in acceptance-checks.md.
- Wipe the rehearsal: delete both virtual machines, and remove the rehearsal's certificate from each counter PC ([install-a-shop.md](install-a-shop.md#rehearse-on-a-windows-pc)). On the Windows PC, `haq-helper setup remove --delete-shop-and-sales` in **Terminal (Admin)** deletes the rehearsal's shop and sales for good.
