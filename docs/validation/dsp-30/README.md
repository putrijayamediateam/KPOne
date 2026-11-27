# DSP-30 screenshots: header allergies read only while the doctor's check waits (synthetic data)

Code head: `8498494b` on `feature/dsp-30-header-allergies-during-check`. Evidence only; not for merge. 1440 px light
and 390 px dark, deviceScaleFactor 2. The same synthetic case as DSP-29 (database `kpone_dsp29_shots`): a recorded
Penicillin allergy, the medicine sent for the doctor's check.

- `header-allergies-*`: the consultation's allergy and Problem List section. It now reads "Allergies recorded · 1
  active" with "Read only while the medicine waits for your check." instead of "Allergy information is unavailable
  for this consultation". No add, edit or review controls.
- `page-*`: the whole page. The check panel's "Allergy profile on record: Penicillin" now agrees with the header. The
  treatment plan's medicine search is disabled while the list is read only.
- `check-panel-*`: the doctor's check panel (DSP-29 part B is not on this branch, so it has no red match box here).

Overflow is 0 px and there are no console errors (measurements.txt).

For Daniel:
- In read-only mode the amber "Review required" chip still shows when the allergy profile changed after the doctor's
  review. It is accurate, but there is nothing to press; keep it, or reword it (for example "Changed since your
  review")?
- The Problem List beside it still says "Structured Problem List information is unavailable for this consultation"
  (not in this slice; owner follow-up).
