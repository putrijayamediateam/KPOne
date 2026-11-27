# Synthetic test guideline — NOT FOR CLINICAL USE

The preview guideline uses the fictional labels and test thresholds already present in `TriageTestCase`. It exists only to exercise the approved application's two-person enter/approve workflow on isolated `kpone_ca_preview_test`.

The HR and Director actors are existing fictional `.test` accounts. The signing-doctor label is “Dr Synthetic Triage”. Application fields such as `approved` and `matches_signed_copy=true` are synthetic test inputs: they do not represent an actual signed document, real medical review, actual clinician approval, or a medically validated guideline. Do not export or use these values for clinical care or production.

No existing guideline draft is discarded or replaced. No real patient or staff data, production database, notification, activation command or external integration is involved.
