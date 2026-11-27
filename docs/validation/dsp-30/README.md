# DSP-30 screenshots: header allergies read only while the doctor's check waits (synthetic data)

Code head: `7f9b048e` (shots refreshed after Daniel's two changes; first set was `8498494b`) on `feature/dsp-30-header-allergies-during-check`. Evidence only; not for merge. 1440 px light
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

## After Daniel's two changes (owner ship-it, 27 Nov)

- `header-allergies-*-reviewed` and `page-*-reviewed`: the doctor's review is current, so the chip is green
  ("Reviewed for this consultation").
- `header-allergies-*-changed` and `page-*-changed`: the allergy profile changed after the review (the shots database's
  profile version was raised by one), so the amber chip now reads "Changed since your review." above "Read only while
  the medicine waits for your check.".
- `find-medicine-closed-1440-light`: the treatment plan's "Find medicine" field looks disabled, with "Medicine search
  is closed while the medicine waits for your check.".

Noticed, not changed (both predate DSP-30; reported to the coordinator as follow-ups): in the changed state the
treatment plan still shows "New or changed medicine instructions require a current Allergy Profile review… Review
allergies", which cannot be done read only; and the plan's Edit, reorder and remove buttons look active while the
check waits.
