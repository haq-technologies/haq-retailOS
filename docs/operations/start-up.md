# Starting by itself after a power cut

How the shop server and the counters come back after a power cut or a reboot with nobody touching them (spec section 4, reboot recovery; check E2: a hard reboot returns to the counter, and power-on to counter takes under 3 minutes). Set each part up at install. Decision D46.

## The server

- **BIOS:** set "Restore on AC power loss" (some boards call it "AC back" or "After power failure") to **Power On**, so the server starts when the power comes back, even if the UPS ran flat.
- **Docker** starts with the server: `install.sh` enables it, and `check-install.sh` checks it.
- **Every long-running service** (db, backup, app, queue, scheduler, web) has the restart policy `unless-stopped`, so Docker starts each one again by itself; `check-install.sh` checks this too. The one-shot jobs (roles, migrate, certificate) ran at install and do not need to run again.
- **While it starts:** until PHP and the database answer, nginx shows "Starting, please wait" in place of any page, and opens the page that was asked for by itself once the server is ready. The app answers 503 rather than an error while the database is still starting.
- **What runs late:** a backup, test restore or nightly check missed while the server was off runs at the next quiet moment (catch-up, D45).

## Each counter PC (Windows)

1. **Sign in by itself.** Use a standard local Windows account for the counter, and set it to sign in automatically with Microsoft's Sysinternals Autologon tool. Nobody types a Windows password after a power cut; the cashier still signs in to HAQ Retail OS with their own name tile and PIN.
2. **Open the counter at start-up.** Press Windows+R, type `shell:startup` and press Enter. In that folder, make a shortcut whose target is:

   ```
   "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk https://haq-server.local/counter --no-first-run --noerrdialogs
   ```

   Use the server's name from the install.
3. **Never sleep.** In Settings, Power, set sleep to Never when plugged in. The screen may turn off; a touch wakes it.
4. **Updates outside trading hours.** In Settings, Windows Update, set the active hours to the shop's trading hours, so Windows never restarts a counter in a queue.
5. **BIOS:** the counter on the UPS or the inverter rarely loses power, but set "Restore on AC power loss" to Power On on every counter too.
6. **Trust the shop's certificate authority** once, so HTTPS opens with no warning: open http://<server>/setup in Chrome and paste its one line into Terminal (Admin) (docs/operations/install-a-shop.md, step 6).

## What people see

- A counter that was open shows **Reconnecting** with its sale kept, and carries on when the server answers. A counter switched on while the server is still starting opens from its saved copy and shows Reconnecting too; one that has never opened the counter shows "Starting, please wait".
- After a restore, each counter reports its journal by itself before it sells again (D44).
- If "Starting, please wait" is still there after ten minutes, check that the server is on, then call HAQ support.
