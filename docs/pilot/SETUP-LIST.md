# Pilot setup list

Things people must enter or check at the pilot branch before real staff use KPOne. Synthetic data only until the gates close.

## Payment Methods

- Before PR #796, the "Requires a transaction reference" checkbox never saved: a method created or edited on the Payment Methods screen always saved with the box off, whatever was ticked.
- After that fix is deployed, open each payment method that should need a reference (for example bank transfer, card, e-wallet) and save it with the box ticked. Check the result in a new payment: the reference field must be required.

## Who publishes prices (owner decision, pilot procedure)

- At the pilot, the **Finance Officer** publishes prices. The **CA Supervisor** and the **Director** only check them, using Price list check and Price history.
- This is a working procedure, not a permission change: the CA Supervisor, Finance Officer and Director all still hold the publish and reference permissions, and no grant has been removed. If the owner later wants it enforced in code, that is a separate permission decision.
- Why: one named person publishes, and a different person checks the result, so a wrong price is caught before it reaches an invoice.
