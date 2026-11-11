# The off-site copy

How a shop keeps a copy of its backups away from the shop, so a theft or a fire that takes the server and its backup disk loses nothing (spec sections 4 and 9; check E1). Decision D53.

## What it is

- Two USB flash drives of 64 GB or more (docs/operations/hardware.md), formatted **exFAT** and labelled **HAQ-OFFSITE-A** and **HAQ-OFFSITE-B**. One is always away from the shop, for example at the owner's home.
- Plugged into the server, a drive gets an encrypted copy of every backup, with the branch's signing key, the shop's certificate authority, the app key and the epochs, by itself (D69). The server checks the copy, keeps the newest two copies on the drive, and unmounts it. The health page shows each drive's last copy and warns when no drive has had one for 8 days.
- The copy is encrypted to a public key. The server cannot read it. The private key is locked with a random key that is split into three shares; **any two** unlock it, one alone cannot. The unlocked key is never stored.

## At install (once)

1. Format both drives exFAT and label them HAQ-OFFSITE-A and HAQ-OFFSITE-B (on Windows: Format, File system exFAT, Volume label; on Ubuntu: `sudo mkfs.exfat -n HAQ-OFFSITE-A /dev/sdX1`).
2. Make the key on the server:

   ```
   sudo /opt/haq-retail-os/bin/offsite.sh key
   ```

   It prints three shares and the key's fingerprint, once. Write each share on its own sheet with the shop's name and the fingerprint:
   - the shop owner keeps one;
   - HAQ keeps one;
   - one is sealed in an envelope and kept at the owner's bank.
3. Plug in drive A and wait for the health page to show "Flash drive A, copied …". Take it away; drive B stays at the shop for next week.

## Every week

- Bring the away drive to the shop, plug it in, and wait until the health page shows its new copy (a few minutes). Unplug it and take it away; the other drive stays at the shop.
- If the health page says a copy failed, plug the drive in again; if it fails again, use the other drive and call HAQ support.
- A lost drive cannot be read without two shares. Label a new drive with the lost one's name and plug it in; the key stays the same.

## Restoring from a drive

When the server and its backup disk are both lost, see docs/operations/restore-drill.md, "Restoring from a flash drive". It needs the newest drive and two of the three shares.

## Checked

- `haq-offsite.sh selftest` runs in CI on every pull request, in the db image: the key, an encrypted copy with the branch key and epochs, the newest two kept, two shares open it and one does not.
