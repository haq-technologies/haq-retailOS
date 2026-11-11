# Installing HAQ Retail OS in a shop

These are the official steps, from a bare server to the first customer leaving with a receipt (D59). HAQ support follows them at every shop. They take under 2 hours (spec section 14, check E2).

The steps use example addresses: the server at 192.168.1.10 and the printers at 192.168.1.21 and up. Use addresses in the shop's own range.

To practise first, follow the same steps on a Windows PC with an Ubuntu virtual machine: see [Rehearse on a Windows PC](#rehearse-on-a-windows-pc) at the end.

## What to bring

- The hardware on the locked list ([hardware.md](hardware.md)): the server with its second disk, the counter PCs (Windows, with Chrome), the receipt printers, any cash drawers, the scanner, the switch or router, and the UPS.
- The shop's details:
  - the shop's name and receipt text (address, phone and any returns wording);
  - how many counters there are, and which have a cash drawer;
  - each printer's fixed IP address;
  - how many back-office devices (the owner's laptop or phone);
  - the names and roles of the owner and staff.
- The release to install ([releases.md](releases.md)). Before the first release exists, a rehearsal installs `main`.
- The server's read-only GHCR token (a classic GitHub token with only `read:packages`). HAQ keeps it; it is given once, at install.
- Internet for the install, from the shop's router or a phone hotspot. Trading never needs it afterwards.
- Two flash drives labelled HAQ-OFFSITE-A and HAQ-OFFSITE-B ([offsite-copy.md](offsite-copy.md)).

## 1. Give each machine its address

The counters and printers find the server by its address, so the server and every printer keep a fixed IP address.

1. Choose the server's address, such as 192.168.1.10, outside the range the router hands out by itself.
2. Set each printer's fixed address from its own menu or setup tool, such as 192.168.1.21 for counter 1 and 192.168.1.22 for counter 2. Print the printer's self-test page to confirm it.
3. The counter PCs may take any address from the router.

## 2. Install Ubuntu Server on the server

1. In the server's BIOS, set **Restore on AC power loss** to **Power On**, so it starts by itself after a power cut ([start-up.md](start-up.md)).
2. Install **Ubuntu Server 24.04 LTS** from a USB stick. In the installer:
   - **Network:** edit the wired connection, choose **IPv4 Manual**, and type the subnet (192.168.1.0/24), the address (192.168.1.10), the gateway (the router, such as 192.168.1.1) and the name servers (the router, then 8.8.8.8).
   - **Storage:** use the entire **first** disk. Leave the second disk alone for now.
   - **Profile:** server name `haq-server`, user `haq`, and a strong password that HAQ keeps.
   - **SSH:** install OpenSSH server, so HAQ support can reach the server on the shop's network.
   - **Featured snaps:** choose none. The installer adds Docker itself, from Ubuntu's own packages.
3. Restart, and sign in as `haq`.

## 3. Prepare the backup disk

Backups go to the second internal disk, so a failed main disk loses at most a few minutes of sales (spec section 4).

```
lsblk -o NAME,SIZE,MODEL,MOUNTPOINTS
```

The second disk is the one with no mount points, such as `sdb` or `nvme1n1`. **Check its size and model: the next command erases it.**

```
sudo mkfs.ext4 -L haq-backup /dev/sdb
sudo mkdir -p /mnt/backup
echo 'LABEL=haq-backup /mnt/backup ext4 defaults,nofail 0 2' | sudo tee -a /etc/fstab
sudo mount -a
df -h /mnt/backup
```

The last line shows the disk's size at /mnt/backup.

## 4. Install HAQ Retail OS

```
git clone https://github.com/haq-technologies/haq-retailOS.git
cd haq-retailOS
sudo apps/shop-server/deploy/install.sh --ip 192.168.1.10 --backup-dir /mnt/backup --tag <release>
```

The installer:

1. installs Docker the first time;
2. asks for the GitHub user name and the read-only token (the token is not shown as you type, and it is kept on the server for updates);
3. asks about the shop: its name, the receipt text, how many counters, whether each has a cash drawer, each printer's IP address (press Enter if a printer is not there yet), and how many back-office devices. It shows a summary and asks "Is this right?";
4. downloads the software, starts it, sets up the counters and printers, and loads the starter catalogue;
5. ends with the next steps.

It takes 10 to 20 minutes, mostly the download. Running it again is safe: it keeps the secrets, the token and the shop's details, and asks nothing the second time.

A profile made before the visit with `shop:make-profile` can be given with `--profile shop.profile.json`; the installer then asks nothing about the shop.

## 5. Add the people

```
sudo haq add-user "Halima" owner
sudo haq add-user "Kemi" manager
sudo haq add-user "Amina" cashier
```

Each command asks for the PIN twice. A cashier's or storekeeper's PIN is 4 to 6 digits; a manager's or the owner's is exactly 6, because it also approves. Let each person type their own PIN.

## 6. Set up each counter PC

On each counter, once:

1. **Windows:** a standard local account that signs in by itself, Chrome installed, sleep set to Never and the active hours set to the trading hours ([start-up.md](start-up.md), steps 1, 3 and 4).
2. **On the server**, make the counter's code. It works once, for 30 minutes:

   ```
   sudo haq enrol T01
   ```

3. **On the counter**, open Chrome and go to **http://192.168.1.10/setup**. The page has three steps:
   - **Trust the shop's certificate.** Press **Copy the line**. Right-click the Start button, choose **Terminal (Admin)** and then **Yes**. Right-click in the window to paste, and press Enter. When it says `command completed successfully`, close every Chrome window and open Chrome again.
   - **Register this device.** Press **Open the registration page**, type the code from step 2 and press **Enrol counter**. The counter's name tiles appear.
   - **Open by itself.** Make the start-up shortcut the page shows, so the counter opens full screen after every restart.
4. **The scanner:** plug it into a USB port, tap the search box and scan any barcode. The digits appear and the search runs.
5. **Restart the PC.** It signs in to Windows and opens the counter by itself.

Do the same for T02 and any other counter. A counter registered again stops working on its old PC.

## 7. The owner's back-office device

1. On the server: `sudo haq enrol D01`.
2. On the owner's laptop or phone, on the shop's network, open **http://192.168.1.10/setup**:
   - on a Windows laptop, as for a counter;
   - on a phone or a Mac, open **A phone, a Mac, or doing it by hand** on that page and follow its steps.
3. Register it with the code, then open **https://192.168.1.10/back-office** and sign in as the owner.
4. The owner opens **Shop settings** and sets the closing time and the cashier's discard limit (D37, D38).

A counter opens the back office too, at https://192.168.1.10/back-office, for a manager or the owner.

## 8. Test the printers and make the first sale

1. **The printer, with no sale:** in the back office, **Daily summary**, choose the counter's printer and **Print daily summary**. It prints and cuts.
2. **The first sale** is real: sales can never be deleted, so make it the shop's first customer, not a test.
   1. The cashier taps their name and types their PIN.
   2. **Open the shift:** count the float in the drawer and type it.
   3. Add the items by scanning, searching or tapping the tiles, and type the price where the counter asks.
   4. Press **Pay** (or F8). For cash, tap the amount given; the counter shows the change.
   5. Press **Finish**. The receipt prints with the shop's name, the items and the receipt number as a barcode, and the drawer opens.
   6. Give the change and the receipt, then press **Next customer**. The customer leaves.
3. A transfer sale asks for the alert-seen tick and the reference; a POS sale needs nothing typed. Neither opens the drawer.

## 9. Backups and the off-site copy

```
sudo haq backup
sudo haq offsite-key
```

The first takes a full backup now; the owner sees it under **Server health**. The second makes the off-site key once and its three shares: one for the owner, one for HAQ and one sealed at the owner's bank ([offsite-copy.md](offsite-copy.md)). Then plug in HAQ-OFFSITE-A: the first encrypted copy is written by itself.

## 10. Check and hand over

1. `sudo haq check`. It ends with **Every check passed.**
2. Hand over to the owner: their PIN, their key share, Shop settings, and **Your data** in Shop settings, which downloads all the shop's records whenever they want them.
3. Write down the server's address, each printer's address, the release installed and the date.

## The server's everyday commands

| Command | What it does |
|---|---|
| `sudo haq add-user "Name" cashier` | Adds a person (cashier, manager, storekeeper or owner); asks the PIN twice |
| `sudo haq enrol T01` | A one-time code, for 30 minutes, to register counter T01 (or D01 for a back-office device) |
| `sudo haq certificate` | The setup page's address, and the certificate's thumbprint to compare with the page's |
| `sudo haq check` | Checks the whole install |
| `sudo haq status` | What is running |
| `sudo haq backup` | A full backup now |
| `sudo haq backups` | The last backups and the last test restore |
| `sudo haq export` | All the shop's records as one ZIP, for the owner only: it holds cost |
| `sudo haq update` | Takes a signed release ([releases.md](releases.md)) |
| `sudo haq offsite-key` | Makes the off-site key and its three shares, once |
| `sudo haq logs app` | Follows a service's log, for HAQ support |

## If something goes wrong

| What you see | What to do |
|---|---|
| Chrome says "Your connection is not private" | The certificate step is not finished. Run the setup page's line again in **Terminal (Admin)**, then close every Chrome window and open Chrome again |
| "That code is not valid, or it has expired" | The code was used, or its 30 minutes ran out. Make a new one with `sudo haq enrol T01` |
| The setup page does not open | The counter cannot reach the server. Check that the server is on, its cable is in, and the counter is on the same network |
| "The images did not download" | Check the internet and the token, then run the installer again |
| A receipt did not print | Check the printer is on, has paper and keeps its fixed address. The counter offers **Print pending receipt** |
| `sudo haq` says Docker did not answer | Run it with `sudo`. If it still fails, restart the server |

## Rehearse on a Windows PC

To practise these steps before a shop, a Windows PC can play every part: an Ubuntu virtual machine is the server, and the PC itself is the counter. A rehearsal's sales are real records, so wipe the rehearsal afterwards; never use it for a shop.

1. **VirtualBox:** install it on the PC (free, from virtualbox.org; it works on every Windows edition), and download the Ubuntu Server 24.04 LTS installer (an ISO file).
2. **A new virtual machine:** Linux, Ubuntu (64-bit), 2 processors, 4 GB of memory and a 40 GB disk. In its settings:
   - **Storage:** add a second disk of 10 GB, to practise step 3.
   - **Network:** Adapter 1 is **NAT** (for the internet). Enable Adapter 2 as **Host-only Adapter**, the VirtualBox Host-Only Ethernet Adapter. Windows reaches the virtual machine through it, at 192.168.56.x.
3. **Install Ubuntu** as in step 2, with one change in the network screen: leave the first network card on automatic (DHCP), and set the second to IPv4 Manual with subnet 192.168.56.0/24, address 192.168.56.10, and no gateway or name servers.
4. **Steps 3 to 5 as written**, with these changes:
   - In step 4, use `--ip 192.168.56.10` and `--tag main` until the first release exists.
   - With no receipt printer, also add `--standin-printer`. When the installer asks for each counter's printer, press Enter: it takes `printer`, a stand-in that keeps each receipt instead of printing it.
5. **Steps 6 to 8 on the Windows PC itself:** in Chrome open **http://192.168.56.10/setup**, trust the certificate, and register the PC as T01. Open the back office in a new tab at https://192.168.56.10/back-office.
6. **See the receipts** the stand-in keeps, as they would print:

   ```
   sudo haq receipts
   ```

   A real printer works too, if the PC can reach the printer's address: the virtual machine prints through the PC's connection.

To wipe the rehearsal, delete the virtual machine in VirtualBox. Then remove the rehearsal's certificate from Windows: in **Terminal (Admin)**, run

```
certutil -delstore Root "HAQ Retail OS shop authority"
```

If the second network card was not set in the Ubuntu installer, set it afterwards: in `/etc/netplan/`, give that card (often `enp0s8`) the line `addresses: [192.168.56.10/24]`, then run `sudo netplan apply`.
