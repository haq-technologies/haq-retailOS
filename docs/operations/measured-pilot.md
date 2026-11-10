# The safety week and gate G5

There is no pilot (D67). The shop goes live after gate G4: from the first sale the app is the shop's record. For the first week the shop also writes each sale in its notebook, and each evening a manager compares the two in the back office's **Measured pilot** section (built for the measured pilot, D56, and kept for this). At the end of the week the owner confirms the notebook can stop (gate G5). This file keeps its old name so links to it still work.

## Before the first day

1. Gates G3 and G4 are passed (PLAN.md).
2. Check with the shop owner that the notebook records how each sale was paid: cash, transfer or POS. If it does not, the cashiers add that column for the week. Without it, the totals per payment method cannot be compared.
3. The owner signs in on a back-office device, opens **Measured pilot** and chooses **Start the 14 days** with the first live day. The section counts 14 days; the safety week uses the first seven, and its other measures are not a gate.

## Each evening of the first week

At closing, a manager or the owner:

1. **Adds up the notebook first**, for cash, transfer and POS, before looking at the app's totals.
2. Opens **Measured pilot**, chooses the day, types the three totals and saves them. A mistake is put right by saving again; the newest counts, and the first stays on record.
3. **Traces each difference**: for each one choose **Trace**, then how much it explains, whose record was wrong (**the notebook** or **the app**), what happened in a few words, and the receipt number when one sale explains it. A trace written by mistake is withdrawn, and stays on record as withdrawn.
4. Closes each drawer for the day and prints the Z-report (D68), and prints the daily summary as usual.

A difference traced to **the app** (a sale not rung, rung twice, at the wrong price or as the wrong payment) is reported to HAQ the same day and fixed in a release before the notebook stops.

## After the seventh trading day: gate G5

1. Finish any tracing that is left.
2. Go through the week with the owner: the days, each difference and why.
3. If every difference traced to the app is fixed, the owner confirms the notebook can stop. Write the date in PLAN.md (Phase 5). The **Measured pilot** section's decision can record it too: **Continue**, with a few words.
4. The owner can download all the shop's data at any time: *Download all the shop's data*, in Shop settings. It is one ZIP file of spreadsheets, with a README listing each file and its SHA-256. It holds cost, so it is for the owner only. HAQ can make the same file on the server:

```
sudo haq export shop-data.zip
```
