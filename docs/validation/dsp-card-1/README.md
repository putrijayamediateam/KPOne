# DSP-CARD-1 medicine card screenshots (synthetic preview data)

Code head: `8df679eb` on `feature/dsp-card-1` (shots refreshed; first set was `b1562466`). 1440, 1280 and 390 px, light and dark, deviceScaleFactor 2.
Evidence only; not for merge. Task-owned PostgreSQL 18.7 database `kpone_dspcard_shots`, seeded with synthetic
fixtures through the real services (start, edit, add, send for doctor check); no real data.

States:
- `populated`: started consultation case, five medicines: Omeprazole saved as ordered; Gaviscon not saved yet and
  opened with Edit, frequency changed to "4 times a day" (the card stays open while it has unsaved changes);
  Domperidone edited by the CA and saved (10 of 15 tablets, twice a day, later batch with a FEFO reason);
  Paracetamol not saved yet with a long direction; Simethicone added by the CA.
- `closed`: the same case with every card closed.
- `doctor-check`: the doctor opens a case waiting for their check (cards read only, "CA gives").
  A verified case completes and leaves this page, so it cannot be shown here.

Checks for Daniel:
- DD-125: Batch select width at 1280 px is 514 px (full card width), Frequency 251 px (153 px in the #1139 set);
  1440: 674 and 331 px; 390: 324 px each (measurements.txt).
- Sideways overflow is 0 px at every width and theme.
- Owner decision (27 Nov): "You give" says "Same directions" when the CA's directions match the doctor's order;
  a changed line shows what is given (Domperidone). A changed precaution or route is always named after it
  ("Same directions · Precaution: ..."; Hakim notes this reads slightly oddly, for Daniel's view). Not in this seed.

## Precaution shots (Daniel, 27 Nov)

`precaution-1440-*` and `precaution-390-*`, light and dark, code head `8df679eb`. Two lines keep the doctor's
directions and differ only in the precaution, both saved through the real Save line button:
- Omeprazole, a short precaution (15 characters): "Same directions · Precaution: Take with water".
- Paracetamol, a precaution at the 72-character label limit: "Same directions · Precaution: Avoid alcohol. May
  cause drowsiness; do not drive or use machinery today". It wraps onto three lines at 390 px.

Overflow is 0 px with no console errors (precaution-measurements.txt). Noticed while shooting: at 390 px the
badge column ("Edited by CA") narrows the name and direction line on edited cards, so the direction line wraps early.
