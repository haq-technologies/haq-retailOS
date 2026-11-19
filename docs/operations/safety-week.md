# The safety week and gate G5

There is no pilot (D67), and the measured pilot is removed (D95). The shop goes live after gate G4: from the first sale the app is the shop's record. For the first week the shop also writes each sale in its notebook, as a backup. The app keeps no comparison with the notebook. At the end of the week the owner confirms the notebook can stop (gate G5).

## Each evening of the first week

At closing, a manager or the owner:

1. Closes each drawer for the day and prints the Z-report (D68), and prints the daily summary as usual.
2. Reports anything in the app that looks wrong to HAQ the same day: a sale missing or rung twice, a wrong price, or the wrong payment. It is fixed in a release before the notebook stops.

## After the seventh trading day: gate G5

1. Go through the week with the owner, and any fault that was reported.
2. If every fault found in the app is fixed, the owner confirms the notebook can stop. Write the date in PLAN.md (Phase 5).
3. The owner can download all the shop's data at any time: *Download all the shop's data*, in Shop settings. It is one ZIP file of spreadsheets, with a README listing each file and its SHA-256. It holds cost, so it is for the owner only. HAQ can make the same file on the server:

```
sudo haq export shop-data.zip
```
