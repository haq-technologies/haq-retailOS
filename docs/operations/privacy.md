# Privacy and data protection

How a shop on HAQ Retail OS keeps the personal data it holds, under the Nigeria Data Protection Act 2023 (NDPA) and the Nigeria Data Protection Commission's General Application and Implementation Directive (GAID 2025, in force since 19 September 2025). It follows the privacy checks of 20 November 2026 (findings DP1 to DP9) and decisions D108 to D114.

The notices and contract terms below are **drafts for the lawyer's review** (PLAN.md, "Commercial and legal"). They are not legal advice, and none goes to a shop before that review.

## Who is responsible

- **The shop owner is the data controller.** They decide why staff and sales records are kept, and they answer to their staff, their customers and the NDPC.
- **HAQ Technology is the shop's data processor** whenever it installs, supports, updates, restores or exports a shop's server. The shop contract says so (the processor terms below).
- A phone-accessory shop is not among the sectors GAID names for registration as a controller "of major importance" (banks, telecoms, fintechs, hospitals, schools and small hotels, among others). The lawyer confirms this for the shop, and for HAQ as its number of shops grows.

## What is kept, and for how long (D109)

Sales and their records are append-only (spec section 5): the app never edits or deletes them. So the schedule below is what the app does, not what a person must remember.

| Record | About whom | Kept | Why |
|---|---|---|---|
| Sales, payments with their bank reference, returns, shifts, cash events | Staff, by name | For the tax-record period the accountant confirms; the app never deletes them | The shop's accounts and tax; fraud prevention |
| The audit trail, sign-in attempts and flags | Staff | As long as the sales they explain | Who did what is the point of the trail |
| Staff names and roles | Staff | As long as the records that name them. A person who leaves is deactivated, never deleted; a wrong name is corrected with `sudo haq rename` (D113) | The trail must still say who did what |
| A transfer sender's name and phone | Customers | 90 days (`payments.transfer_contact_days`), then deleted every night (D112) | Only to reach the sender if the bank reverses the transfer |
| A held sale's label | Customers, if a cashier types a name | While the sale is held. A finished awaiting transfer's label is cleared; a discarded one keeps its label, which the owner's flag shows | Finding the customer again |
| Sessions: a device's address and browser | Devices | 2 hours | Signing in |
| Each counter's journal of recent sales | Staff, customers | 7 days, in that counter's browser | Recovery after a restore |
| Web server logs | Devices | 5 files of 10 MB at most (D75) | Faults |
| Backups | Everyone above | 4 full backups on the backup disk; the newest 2 copies on each off-site drive | Recovery |
| The owner's data export | Everyone above except transfer senders | The owner's own copy; the server keeps none | The owner's data is theirs |

The accountant's answer sets the first row's period. Until then nothing is deleted, which keeps every record the tax rules could ask for.

## Staff notice (draft)

Given to each person when their name tile is made, and kept at the shop.

> **How [shop name] uses your information at the counter**
>
> The shop's till records your name on your tile and on every receipt you ring. It records each thing you do on it (sales, returns, prices you change, cash you count, the PIN you type, right or wrong), with the time and the counter. It times sales, and it shows the owner and the managers some events, such as a price below the usual range or a drawer opened with no sale.
>
> The shop keeps this to run its accounts and taxes and to prevent loss. It also protects you: My history on the counter shows your own last seven days, and the record proves a mistake was not yours.
>
> The owner sees everything; a manager sees the branch, without cost; you see your own. The records are kept as long as the shop's tax records, and are not deleted when you leave, because they must still say who did what. HAQ Technology, who installed the till, sees them only when the owner asks it for support, and each such visit is recorded.
>
> You may ask the owner for a copy of what the till holds about you, and have your name corrected. If you have a concern the owner cannot settle, you may complain to the Nigeria Data Protection Commission.

## Customer notice (draft)

For a sign at the counter, or the receipt's footer (D14).

> If you pay by bank transfer we may ask your name and phone number. You can say no. We keep them for 90 days, only to reach you if the bank reverses the payment, and then delete them. They are never printed. Ask at the counter to see or correct them.

A receipt with a sender kept also says so under the transfer (D112). The till keeps no other customer details while credit is off.

## Processor terms for the shop contract (draft)

1. HAQ processes the shop's records only to install, support, update, restore or export the shop's server, at the owner's request, and for no other purpose.
2. The owner consents to HAQ support reaching the server. Every SSH sign-in and every server command that reaches the shop's records is written to the audit trail and shown on the owner's flag list (D110). HAQ signs in with its own named keys, never a shared password.
3. HAQ holds one of the three shares of the shop's off-site backup key; the owner holds one and a sealed paper share is kept away from both (D53). HAQ alone cannot read an off-site drive, and uses its share only to restore the shop at the owner's request.
4. The shop's records stay on the shop's server. Updates are fetched from GitHub with nothing sent; there is no telemetry today, and any later telemetry carries server health only (spec section 11). HAQ uses no sub-processor for the shop's records.
5. HAQ keeps what it sees confidential, and its staff are bound to do the same.
6. If HAQ learns of a breach of the shop's records, it tells the owner at once and helps with the steps below (NDPA section 40).
7. At the end of the contract the records stay with the shop, and the owner keeps their export (spec section 11).

## Support access (D110)

- **Ubuntu server:** SSH takes keys only (install guide, step 2). `deploy/host-health.sh` reads each accepted sign-in from the journal every 15 minutes; the scheduler writes each once to the audit trail as `support.signed_in` and shows it on the flag list as "Someone signed in to the server".
- **Every server:** `sudo haq` (or `haq-helper server` on Windows) records `add-user`, `users`, `pin`, `deactivate`, `rename`, `enrol`, `export`, `receipts`, `logs`, `artisan` and `restore` as `support.command` before it runs, with who ran it and the address they came from. A server that is restoring cannot record it; the command still runs.
- **What the owner sees:** each visit under **HAQ support on the server** on the flag list and in Needs your attention, until they mark it as seen.

## Disk encryption (D111)

A stolen server can be read unless its disk is encrypted. The health page and `sudo haq check` warn while the server's own disk is not encrypted; it never stops the shop.

- **Windows server:** before the setup, turn on BitLocker on the system drive with the TPM only (Windows Pro), or Device encryption (Windows Home, where Windows offers it). The PC then starts by itself after a power cut. Keep the recovery key with the shop's off-site key shares, never on the PC. The HAQ Helper reports BitLocker's state; on a Windows server the backups share that drive (D97).
- **Ubuntu server with a TPM 2.0:** encrypt the disk in the installer (install guide, step 2), then bind it to the TPM so it unlocks itself at power-on:

  ```
  sudo apt install clevis clevis-luks clevis-tpm2 clevis-initramfs
  sudo clevis luks bind -d "$(sudo blkid -t TYPE=crypto_LUKS -o device | head -1)" tpm2 '{"pcr_ids":"7"}'
  sudo update-initramfs -u
  ```

  Restart and check that it starts with no passphrase. A firmware update can change the TPM's measurements; the server then asks for the passphrase at its screen, which HAQ keeps with the key shares.
- **Ubuntu server with no TPM:** leave the disk unencrypted. A server that waits for a passphrase after every power cut stops the shop. The health page warns, and the owner knows why.
- **The backup disk** is not encrypted yet. Its key cannot live on the main disk, or losing that disk would lose the backups too. Encrypting it changes the restore drill, so it is an open item for G3 (PLAN.md). The health page shows its state.

These steps are written from the Ubuntu and clevis documentation and are tried on the shop's own server at install (D60). The Windows steps are tried on the product owner's PC (D90).

## When something goes wrong: the 72-hour steps

A breach is any loss, theft or exposure of the shop's records: a stolen server, a lost export file, someone signing in who should not.

1. **Contain it at once.** Change the PINs of anyone affected (`sudo haq pin`), deactivate a person who should not have access, and ask HAQ support to check the flag list for sign-ins.
2. **Write down** what happened, when it was found, whose data it holds, and what was done.
3. **Judge the risk.** An off-site drive is encrypted, and its key needs two shares, so a lost drive alone is a low risk. A stolen server whose disk is not encrypted, or a lost export file, exposes staff names, sales, transfer senders and cost.
4. **Within 72 hours of finding it,** the shop tells the NDPC of a breach the Act requires it to report. HAQ helps write it.
5. **Tell the people affected** without undue delay when the risk to them is high.
6. **Keep the record,** whatever was decided.

## Requests from staff and customers

| Request | How |
|---|---|
| A copy of their data | The owner's export holds every person's records (`people.csv`, `receipts.csv`, `audit-log.csv`); a cashier also has My history |
| Correct a name | `sudo haq rename "Name" "Right name"` (D113) |
| Correct or delete a transfer sender's details | The owner opens the sale in the back office; HAQ support deletes the contact on request. It goes by itself after 90 days |
| Delete a person | Not possible while the records that name them are kept for tax and fraud prevention; the staff notice says so |

## Sources

- [FPF: Nigeria's new Data Protection Act, explained](https://fpf.org/blog/nigerias-new-data-protection-act-explained/) (sections 24, 25, 27, 28, 29, 39, 40, 41 to 43 and 44)
- [Legal500: GAID 2025 unpacked](https://www.legal500.com/intelligence/nigeria/privacy/gaid-2025-unpacked-critical-updates-on-data-processing-individual-obligations-and-compliance-measures)
- [Digital Policy Alert: GAID 2025 enters into force on 19 September 2025](https://digitalpolicyalert.org/event/33616-data-protection-commissions-general-application-and-implementation-directive-including-registration-as-a-data-controller-or-data-processor-of-major-importance-requirement-ndpcndp-act-gaid012025-enters-into-force)
- The privacy checks of 20 November 2026 (a Claude Doc; the link is kept outside this public repository)
