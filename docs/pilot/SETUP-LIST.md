# Pilot setup list

Things people must enter or check at the pilot branch before real staff use KPOne. Synthetic data only until the gates close.

## Payment Methods

- Before PR #796, the "Requires a transaction reference" checkbox never saved: a method created or edited on the Payment Methods screen always saved with the box off, whatever was ticked.
- After that fix is deployed, open each payment method that should need a reference (for example bank transfer, card, e-wallet) and save it with the box ticked. Check the result in a new payment: the reference field must be required.
