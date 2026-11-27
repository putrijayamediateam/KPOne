# DSP-29 part B screenshots: allergy mark in the doctor's check panel (synthetic data)

Code head: `cc0b84879` (shots refreshed; first set was `d4c6fb2b`) on `feature/dsp-29-doctor-allergy-mark`. Evidence only; not for merge. 1440 px light and dark,
390 px light and dark, deviceScaleFactor 2. Task-owned PostgreSQL 18.7 database `kpone_dsp29_shots`, seeded with
synthetic data through the real services (allergy recorded and reviewed, plan sent, CA prepares and removes a line,
sent for the doctor's check).

`check-panel-*` is the panel alone; `page-*` is the whole consultation page.

The case has three lines:
- **Amoxicillin**: the catalogue drug group "Penicillin" matches the recorded allergy "Penicillin", so the line shows
  the red "Possible allergy match" box (compact form), before the quantities.
- **Paracetamol**: no match, so no mark.
- **Omeprazole**: removed by the CA, so no mark. Omeprazole would not match Penicillin anyway; the feature test
  `test_a_removed_line_shows_no_mark` proves that a removed line that does match still shows none.

Sideways overflow is 0 px and there are no console errors (measurements.txt).

Daniel (27 Nov): OK. The caveat "It can miss an allergy, so always read the full allergy list." now shows once per panel,
as a muted line under "Allergy profile on record: …", on every panel including when nothing matches (not inside the
red box). The wording still needs a doctor's or pharmacist's approval.

The amber header banner on `page-*` ("Allergy information is unavailable for this consultation") is not from the
synthetic data: the header loads allergies only while the queue entry is Serving, and the check panel appears only
after the medicine has gone to Dispensary, so a real doctor sees both. Not changed in this slice (reported to the
coordinator with two options).
