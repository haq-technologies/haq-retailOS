# The Windows spike

Before the Windows setup is built (D86), this spike shows on real Windows that the shop server can run hidden in WSL2: Windows' own Linux, with no Ubuntu to install by hand. It installs release 2026.11.3 the way the setup program will. The server runs as a hidden local account, HAQServer, from Windows' start, before anyone signs in. Its ports are forwarded from the PC's fixed address, and a stand-in print bridge on port 9100 keeps each receipt instead of printing it.

It is throwaway. Its sales are rehearsal records, so remove it afterwards, and never use it for a shop.

## What it must show

Write down a pass or a fail for each, with the check reports. The build waits for these results, and any part that fails takes its fallback (PLAN.md, the Windows setup checklist).

| # | Check | How |
|---|---|---|
| 1 | The server starts at power-on with nobody signed in | Restart Windows and wait 5 minutes at the sign-in screen. Then sign in and run `check`: "Server ready" must be earlier than your "Signed in" time |
| 2 | It stays up | Run `check` again after a day: the distribution's "since" time is unchanged, and `/up` answers 200 |
| 3 | Other devices reach it | From your phone (it is on its own hotspot) or another device on the same network, open `https://<the PC's address>/up` and accept the certificate warning: the page loads and says the application is up |
| 4 | It reaches a program on its own PC | Make a sale on the counter (step 5 below); `check` counts one more receipt at the bridge |
| 5 | It survives a power cut | Optional, since it is a real power cut on your PC: save your work, hold the power button while scripted sales run until the PC switches off, start it again, and `inside "haq artisan shop:rehearsal-check"` passes |
| 6 | It fits an 8 GB PC | Note "WSL memory" from `check` while scripted sales run, against the machine's memory |

Run it on your PC, whichever Windows it has. If a PC with the other version (10 or 11) is to hand, run it there too.

## The machine

Your own Windows PC, as a shop's would be: there is no virtual machine to make (D90). WSL2 is part of Windows, and the spike's first command turns it on. The PC needs:
- virtualisation turned on in its firmware: Task Manager, Performance, CPU shows "Virtualization: Enabled"
- 8 GB of memory or more, and 30 GB of free disk

Everything the spike adds, `remove` takes away again.

## Steps

All commands run in an Administrator PowerShell (right-click Start, then Terminal (Admin)), in this folder.

1. **The files and a fixed address.**
   - Get the repository onto the machine: on its GitHub page, choose Code, then Download ZIP, and unzip it.
   - Open `apps\windows\spike` in the Administrator PowerShell.
   - Give the PC a fixed IPv4 address on the network it is connected to, such as your phone's hotspot:
     1. Run `ipconfig`. Under the adapter that is connected ("Wireless LAN adapter Wi-Fi" for a hotspot), note its IPv4 address, subnet mask and default gateway. The gateway is the phone.
     2. Pick a free address near the top of that range: on an Android hotspot of 192.168.43.x, 192.168.43.200; on an iPhone's, 172.20.10.14 (its range is only .2 to .14).
     3. In Settings, Network & internet, Wi-Fi, open the hotspot's properties, then IP assignment, Edit, Manual, and turn IPv4 on. Give that address, the subnet mask (Windows 10 asks the prefix length instead: 24 for 255.255.255.0, 28 for 255.255.255.240), and the phone's address as the gateway and as the DNS. Windows keeps it for that network only.
     4. Some Android phones give the hotspot a new range each time it starts. Keep the hotspot on for the spike. If the range changes, run `remove`, set the address again, and `install` again: the server's certificate is made for its address.

2. **WSL.** Run the first command below. If Windows asks for one, restart.

   ```
   powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 wsl
   ```

3. **Install**, with the machine's fixed address. It asks for the read-only GHCR token once, and takes 10 to 30 minutes.

   ```
   powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 install -Ip 192.168.43.200
   ```

4. **Check it.** Each report is also saved in `C:\ProgramData\HAQSpike`, to paste back.

   ```
   powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 check
   ```

5. **A counter on the same machine.**
   - Make a one-time code: `powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 inside "haq enrol T01"`
   - In Chrome, open `http://<address>/setup`, paste its one line into the Administrator PowerShell to trust the certificate, and type the code.
   - Sign in as Spike Cashier with PIN 2468, and sell one item for cash. Spike Owner's PIN is 135790.
   - `check` now counts a receipt at the bridge.

6. **Scripted sales**, for checks 5 and 6:

   ```
   powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 inside "haq artisan shop:rehearse --count=100 --pace=2 --confirm"
   ```

   - For check 6, run `check` in a second Administrator PowerShell while the sales run, and note "WSL memory".
   - For check 5, if you choose to do it: save your work, run them again, hold the power button part-way until the PC switches off, start it, wait 5 minutes, and run:

   ```
   powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 inside "haq artisan shop:rehearsal-check"
   ```

7. **Remove it** when every check is written down. This removes the distribution, the tasks, the port forwards, the firewall rules, the HAQServer account and the trusted certificate:

   ```
   powershell -ExecutionPolicy Bypass -File .\haq-spike.ps1 remove
   ```

## If check 1 fails

If the server task's result is not 0 and nothing starts before you sign in, run the fallback, which starts the server when you sign in:
1. Run `remove`.
2. Run `install -Ip <address> -Mode Logon`.
3. Make Windows sign you in by itself: on Windows 11, turn off "For improved security, only allow Windows Hello sign-in" in Settings, Accounts, Sign-in options; then run `netplwiz` and untick "Users must enter a user name and password".

Check 1 then asks whether the server is ready within 5 minutes of Windows' start, with the sign-in done by Windows itself.

## What to send back

- The pass or fail of each check, and on which Windows.
- The check reports from `C:\ProgramData\HAQSpike`.
- If something stopped, the last lines of `C:\ProgramData\HAQSpike\logs\runner.log` and `keepalive.log`.

None of these holds the GHCR token: the server keeps that in its own secrets folder, and the spike deletes the copy it handed over.
