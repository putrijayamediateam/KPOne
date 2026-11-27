# Clean Look Dispensary case screenshots (synthetic preview data)

Code head: 9af07ebd + the dark Save all lines edge fix (shots refreshed; first set was a7e1c4ae) on feature/clean-look-dispensary-publication (PR #1139). 1440, 1280 and 390 px, light and
dark, deviceScaleFactor 2. Evidence only; not for merge. Task-owned PostgreSQL 18 database `kpone_shots`,
seeded with synthetic fixtures; each CA signs in to its own case.

States: `normal` (started consultation case, no known allergies), `allergy` (allergy recorded and reviewed;
possible allergy match on the line), `return` (allergy recorded after the doctor's review: return to doctor
needed; seeded by a direct write to the preview database), `confirm` (line saved, Complete pressed, verify
panel open), `otc` (OTC case, allergy question open).

Checks for Daniel:
- (a) The "Possible allergy match" panel keeps its existing red-50 / red-950 colours (not changed by #1139);
  12 px text. The doctor's attention panel was not captured in this set.
- (b) Outline "Save all lines" edge (--input) on the warning-wash banner: light #8a8a93 on #fff4dc = 3.13:1;
  dark was #6b6b73 on #33240f = 2.84:1; fixed: dark now uses --muted-foreground #a1a1a6 = 5.83:1.
- Sideways overflow is 0 px at every width and theme (measurements.txt).
- Complete Dispensary turns outline while the verify panel is open; Confirm and complete is the filled one.
- "Look up" on both Dispensary scanners is outline (lookupVariant), so Complete Dispensary is the one filled button.
- Line-grid field labels (Quantity, Dosage, Frequency, Batch and the rest) are 13/600 (owner request).
- Label limits sit in a help line under each field ("Label max 96"); labels stay on one line (owner request).
