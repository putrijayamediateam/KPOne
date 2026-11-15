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
