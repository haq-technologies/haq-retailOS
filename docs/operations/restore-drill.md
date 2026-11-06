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
5. On each counter, open the counter page and sign in. The first receipt is, for example, T01-E2-001001.
6. Compare the last receipt each counter printed with the restored sales. Any sale a customer holds a receipt for but the copy lacks is re-entered as a back entry (the lost-window screen that lists them from the counter journals comes with the MVP).
7. Stop the clock and write down the time and the result.

## Pass

- Service is back within 1 hour.
- No receipt number repeats: the new epoch is higher than any before it.
- `php artisan audit:verify` reports that every chain and root verifies, and `php artisan db:mutability --check` reports no drift.
- The last receipt before the failure is in the restored copy, or is re-entered.
