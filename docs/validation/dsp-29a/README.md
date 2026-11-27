# DSP-29 part A screenshots: read-only allergy mark on the view-only case card (synthetic data)

Code head: `484074bf` on `feature/dsp-29a-view-only-case-mark`. Evidence only; not for merge. 1440 and 390 px,
light and dark, deviceScaleFactor 2. The same synthetic case as DSP-29 part B (database `kpone_dsp29_shots`): a
recorded Penicillin allergy; the medicine waiting for the doctor's check.

- `card-*`: the Amoxicillin card on the Resident Doctor's view-only Dispensary case page. Its catalogue drug group
  "Penicillin" matches the recorded allergy, so the card shows the red "Possible allergy match" box with its caveat
  line, read only: no tick box, and it never says anyone checked the match.
- `page-*`: the whole view-only page. Paracetamol has no match and shows no mark; the removed Omeprazole line is in
  the removed list with no mark.

Each width shows one mark and no CA warning; overflow 0 px; no console errors (measurements.txt).

For Daniel: the card uses the full form of the mark (with "It can miss an allergy, so always read the full allergy
list.") because the case page has no panel-level caveat line like the doctor's check panel.
