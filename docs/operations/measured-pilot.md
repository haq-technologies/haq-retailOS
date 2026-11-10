# The measured pilot and gate G5

Phase 5 (PLAN.md, weeks 21 and 22): every counter runs beside the shop's notebook for 14 trading days, measured against the pilot success thresholds in spec section 14. At the end the shop owner decides to continue or stop (gate G5). The back office's **Measured pilot** reads the measures live (D56), and the owner takes all the shop's data either way (D57).

The product owner runs it with the shop's owner and manager. It starts only after gate G4, when all 46 acceptance checks have passed and are written down.

## What is measured

| Measure | Met when | Where it comes from |
|---|---|---|
| Daily totals | At least 10 of the 14 days match the notebook | The notebook's totals, typed each evening, and each difference traced |
| Receipts | Every receipt number used once with no gap, every sale printed or waiting to print, and no lost sale left to re-enter | The sales, print jobs and lost sales |
| Speed | The day's typical 3-item sale is under 30 seconds on 10 of every 14 days that had timed 3-item sales, over at least 20 of them | The counter's own timing of each sale |
| Revenue with a cost | At least 80% of the 14 days' revenue has a cost, at sale or estimated, at the end of day 14 | Costs at sale, and estimates from Needs cost |
| Owner's decision | Continue or stop, recorded with the scorecard as it stood | The owner, in the back office |

A **trading day** is a business day with a sale in the app or a total in the notebook. A day the shop is shut does not count; a day the app missed altogether does, and it fails.

A day **matches** when the app's total for cash, transfer and POS each equals the notebook's, or once every difference is traced to the notebook. A difference traced to the app (a sale never rung, rung twice, at the wrong price or as the wrong payment) means the day does not match, however small it is.

## Before day 1

1. Gate G4 is passed (PLAN.md).
2. Check with the shop owner that the notebook records how each sale was paid: cash, transfer or POS. If it does not, the cashiers add that column for the 14 days. Without it, the totals per payment method cannot be compared.
3. Agree the first day with the owner. It is a normal trading day, not a public holiday or a stock-take.
4. The owner signs in on a back-office device, opens **Measured pilot** and chooses **Start the 14 days** with that day. It can be set ahead and moved until it comes. Once it has begun, it can only start again with a reason, from that day on.
5. Hold the weekly release during the 14 days unless a fix is needed for a sale or a receipt. Any update follows docs/operations/releases.md as usual.

## Each trading day

At closing, a manager or the owner:

1. **Adds up the notebook first**, for cash, transfer and POS, before looking at the app's totals.
2. Opens **Measured pilot**, chooses the day, types the three totals and saves them. A mistake is put right by saving again; the newest counts, and the first stays on record.
3. **Traces each difference.** The day's sales are listed by receipt number, time, cashier and payment; tick them against the notebook to find what differs. For each difference choose **Trace**, then:
   - how much it explains;
   - whose record was wrong: **the notebook** (a sale not written down, written wrongly or added wrongly) or **the app** (a sale not rung, rung twice, at the wrong price or as the wrong payment);
   - what happened, in a few words, and the receipt number when one sale explains it.
   A trace written by mistake is withdrawn, and stays on record as withdrawn.
4. Enters costs on **Needs cost**, so the revenue with a cost reaches 80% by day 14. The coverage card shows how far there is to go.
5. Prints the daily summary as usual and keeps the slip.

The menu's badge on **Measured pilot** counts the days that still wait for the notebook's totals or for tracing. Today is left out until it closes.

If the server is restored during the 14 days, a manager re-enters each lost sale on **Lost sales** (docs/operations/restore-drill.md). A lost sale not re-entered fails the receipts measure.

## After day 14: gate G5

1. Finish any tracing that is left. The days still waiting count as not matching.
2. Go through the scorecard with the owner: the four measures, and the days that did not match and why.
3. The owner chooses **Continue** or **Stop** under *The owner's decision*, with a few words if they wish, and confirms. That records the scorecard as it stood and closes the pilot's record, so nothing in it changes afterwards. Changing the decision later needs a reason, and both stay on record.
4. **Either way, the owner downloads their data**: *Download all the shop's data*, under the decision or in Shop settings. It is one ZIP file of spreadsheets that open in Excel, with a README listing each file and its SHA-256, so the owner can show that none changed. Copy it onto the owner's own drive. It holds cost, so it is for the owner only.

HAQ can make the same file on the server, for example onto a flash drive when no back-office device is to hand:

```
docker compose exec -T app php artisan shop:export - > shop-data.zip
```

### On continue

The app becomes the shop's record from the decision. The notebook carries on for 7 more days as a fall-back and then stops; the page shows the date. Training, the weekly release and the daily reconciliation move to the MVP plan (PLAN.md, After the pilot).

### On stop

The owner keeps their export. What happens to the server, its backups, the off-site drives and the key shares follows the agreement with the shop (an open item in PLAN.md).
