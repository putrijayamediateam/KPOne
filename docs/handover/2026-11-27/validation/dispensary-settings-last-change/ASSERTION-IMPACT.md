# Faithful assertion adaptation under delegated owner authority

Existing test affected: tests/Frontend/dsp-settings-context-runtime.test.mjs, authorized setting explanation does not invent last-change identities or navigation. Prior slice deliberately had no Last changed label, so it asserted /Last changed|<a |Follow-up/ absent.

New real reader requires the label. Exact adaptation: retain prohibition of links and Follow-up (`/<a |Follow-up/`), add assertions Last changed and Not available for the null/default fixture. Supply settingsChange:null in renderer setup. This preserves no-fabricated-attribution behavior while permitting authorized existing data. Add a separate real attribution/escaping test. No permission/role fixture or previous backend assertion changes. Root explicitly delegated faithful existing assertion adaptation after exact inventory (26Nov2026); no further owner question required.

Existing seven DSP23 frontend assertions remain unchanged. Existing backend DispensaryDoctorCheckTest asserts settings.doctorCheckRequired only; separate settingsChange prop preserves the original two-field settings DTO, no changes needed.
