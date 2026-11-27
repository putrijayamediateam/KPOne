# DSP-CARD-1 medicine card screenshots (synthetic preview data)

Code head: `b1562466` on `feature/dsp-card-1`. 1440, 1280 and 390 px, light and dark, deviceScaleFactor 2.
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
- Question: on a line the CA has not changed, the doctor's directions show both in the direction line and under
  "Doctor ordered". Keep both, or show "Same directions" under "Doctor ordered" when they match?
