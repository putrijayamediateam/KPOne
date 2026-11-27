# Patient Create guidance independent review

CLEAR for three-file artifact `2028adf7c24511f1a08b2bb389ac2404a992388e619e1f41c2f842ab67cc8e67`. Independently verified patch and all manifest hashes. Source uses only supplied four requirement flags and existing knownMinor/guardianToday, keeps optional address line2 truthful, distinguishes unknown optional DOB/adult confirmation, and explains patient creation is not visit registration. Original submit logic remains unchanged; the form precedes stacked guidance on mobile.

Independently ran all four actual-component tests successfully, including eighteenth-birthday boundary and invalid/future DOB. Inspected final minor1440light and invalid390dark images: guidance agrees with current minor fields, errors/focus remain in the form, sidebar/stacked content fits without obscuring actions. No blocker in the new read-only guidance.

Builder evidence: 2225/2225 frontend tests, types/build/lint and 12 actual initial/minor/invalid viewport/theme captures, zero patient creation and no organisation-setting mutation. Required-policy permutations are component tests rather than screenshots produced by changing settings. Existing form control sizes and pink decorative header are inherited, not certified by this read-only-aside slice. No modal, full-page demo parity, permission/schema change or merge claim.
