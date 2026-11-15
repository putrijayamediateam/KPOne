# Pilot setup list

Things that must be set up or checked by hand before real staff use KPOne at the pilot branch (one branch beside
Yezza, synthetic data until the gates close). One line per item, owner area in brackets. Analysis and checklist only:
nothing here authorises anything.

## Staff Experience

- **Layout: pilot staff start on Classic (owner decision, 2026-11-15).** The new layout ("Try the new layout", Version 2)
  stays optional, each person choosing for themselves. Nothing to set up: a new account has no saved preference, which
  reads as Classic (`SpacePreferenceService::state()`: no row means `enabled` false, and `UiVersionResolver` then renders
  the Classic page). The waiting-room TV account is never offered the switch. One thing to know: pages under `/spaces`
  always draw in the new layout, whatever the person's switch says. Do not turn the layout on for a pilot account on
  anyone's behalf; the person does it themselves.

## Payment Methods

- Before PR #796, the "Requires a transaction reference" checkbox never saved: a method created or edited on the Payment Methods screen always saved with the box off, whatever was ticked.
- After that fix is deployed, open each payment method that should need a reference (for example bank transfer, card, e-wallet) and save it with the box ticked. Check the result in a new payment: the reference field must be required.

## Who publishes prices (owner decision, pilot procedure)

- At the pilot, the **Finance Officer** publishes prices. The **CA Supervisor** and the **Director** only check them, using Price list check and Price history.
- This is a working procedure, not a permission change: the CA Supervisor, Finance Officer and Director all still hold the publish and reference permissions, and no grant has been removed. If the owner later wants it enforced in code, that is a separate permission decision.
- Why: one named person publishes, and a different person checks the result, so a wrong price is caught before it reaches an invoice.
