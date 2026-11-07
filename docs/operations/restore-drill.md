# Restore drill

How a shop's server is restored from its backup disk, on the same box or on the pre-imaged spare PC (spec section 9). Gate G3 runs it on the pilot hardware, and the target is service back within 1 hour (spec section 14, check E). It was rehearsed on staging on 6 November 2026: the restore took about a minute, a change made after the last backup came back through the archived WAL, and a second restore from the same backup started a higher epoch.

## What is backed up, and where

- pgBackRest archives every WAL segment to the second internal disk as it fills, and at least every 15 minutes in a quiet shop (D35).
- The backup service takes a full backup each week and an incremental each day, in the night window from 01:00, or at the next quiet moment if the box was off (catch-up). It keeps four weeks.
- Each week it also restores the latest backup into a temporary database, and the scheduler checks its row counts and every audit chain. `php artisan backups:status` shows the last backups and the last test restore.
- The scheduler keeps a copy of the branch's signing key and each counter's highest epoch beside the backups, so a restore elsewhere carries on both.
- The cloud copy and the encrypted flash drives come with the MVP (spec section 9).

## Before the drill

- The spare PC has Ubuntu Server, Docker and this repository, as in the hardware list (docs/operations/hardware.md).
- The shop profile file, kept off the server.
- The backup disk: move it into the spare, or copy its folder over, for example `rsync -a /mnt/backup/ spare:/mnt/backup/`.
- Write down the last receipt number each counter printed, from the receipts or the counter's screen.

## The drill

1. Start the clock.
2. On the spare, install the stack with the same profile, pointing at the backup disk:

   ```
   sudo apps/shop-server/deploy/install.sh --profile shop.profile.json --name haq-server.local --ip 192.168.1.10 --backup-dir /mnt/backup
   ```

3. Restore:

   ```
   sudo apps/shop-server/deploy/restore.sh
   ```

   It stops the stack, restores the latest backup and replays every archived WAL segment, gives the database roles this machine's own secrets, brings back the branch key, starts a new signed epoch on every counter (receipts number from 1,001), then starts everything and verifies the audit chains and the grants.
4. Move the server's fixed IP to the spare, or give the spare the old server's IP, so the counters find it.
5. On each counter, open the counter page. It reports its journal (the last seven days of its sales) by itself before anyone signs in, and it sells again only once it has; a counter that was left open reports when it reconnects. If its journal shows sales newer than the restored copy, its epoch moves past them. The first receipt is, for example, T01-E2-001001 (D44).
6. In the back office, a manager or the owner opens Lost sales. It shows which counters have reported, and every sale a counter's journal holds and the restored copy lacks. Re-enter each one: it keeps the receipt number the customer holds, prints nothing, and is flagged as re-entered. If a line names an item the copy lacks (one made at the counter after the last backup), choose the item to record it under; the line keeps its printed name.
7. Compare the last receipt each counter printed with the restored sales and the re-entered ones.
8. Stop the clock and write down the time and the result.

## Pass

- Service is back within 1 hour.
- No receipt number repeats: the new epoch is higher than any before it.
- `php artisan audit:verify` reports that every chain and root verifies, and `php artisan db:mutability --check` reports no drift.
- The last receipt before the failure is in the restored copy, or is re-entered from Lost sales.
- Every counter shows as reported on Lost sales, and `php artisan shop:rehearsal-check` finds every counter's numbers gapless in each epoch.

## A reinstall with no backup

If the server is reinstalled with nothing to restore, run `php artisan shop:after-restore --reason=reinstall` before the counters sell. Each counter's journal then moves its epoch past its last sales when it reports, so no receipt number repeats; its last seven days of sales appear on Lost sales.
