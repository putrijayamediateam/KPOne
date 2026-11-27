# Patient Create requirements guidance

Create Patient now shows a read-only requirements/context sidebar beside the existing form on wide screens and after the form on mobile. It reads the four actual organisation flags, always keeps address line2 optional, and uses existing knownMinor with supplied guardianToday for guardian/phone guidance. Optional unknown DOB retains adult-confirmation guidance. Copy distinguishes creating a patient from registering a visit.

Three files: Create.vue wrapper/import, PatientCreateGuidance.vue and four new actual-component SSR tests. Original create submit handler is byte-identical; identifiers, duplicate override, guardian/adult confirmation, errors, create permissions and shared PatientFormFields are unchanged. No backend, schema, grant, Emergency Contact or Family change. No existing assertions changed. No fake counts, legal/compliance claims or inferred settings.

Validation: fullfrontend2,225/2,225PASS with no skips; types/build/full lint/format/diffcheckPASS. Four new tests cover actual optional/mixed flags, address line2, supplied-day eighteenth-birthday boundary, unknown/invalid/future DOB. Actual12browserstates cover initial/minor/invalid at390/1440light/dark; no overflow/JavaScript errors, form precedes mobile guidance, entered DOB updates minor guidance, original invalid submit focuses name and makes zero create requests. Organisation settings were not changed for captures; required-policy combinations are covered by SSR fixtures, not claimed as live configuration screenshots.

The sidebar has no interactive targets. Existing shared form sizing/styles remain inherited; full form restyle, modal and full demo parity are not claimed. Synthetic-only preview.

Frozen patchSHA2028adf7c24511f1a08b2bb389ac2404a992388e619e1f41c2f842ab67cc8e67, stack base Edit1174 headfe8ec2a9991c7a4f7fee8d398fe7ccdebaf78bd7. Final independent visual/hash review pending; standing authorization permits publication afterward, no merge.

Final independent source/visual/hash review CLEAR. Published exact reviewed3files as draft1177 https://github.com/putrijayamediateam/KPOne/pull/1177 head4b1b16b2d0f385619f8c46c3c10d642d1eabc777 stacked1174, normal push and clean tree; no merge. Earlier pending-review status is superseded.
