Registration settings now separates fixed system rules, configurable new-patient details and where those rules apply. Four native checkbox switches show the current draft's Required/Optional state; the existing organisation requirements save and separately authorized branch doctor-assignment save remain distinct.

The entire page script is unchanged. Existing permissions, payloads, versions, errors, explicit stale reload and doctor-assignment dirty guard are preserved. Known-minor phone and in-progress QR policy copy follows existing behavior; no new settings, grants, migrations or patient-data changes.

Validation: 2,212 frontend tests, type check, production build, full lint/resources formatting; 8 backend tests/173 assertions. Three new actual-component tests cover native events, separate commands, permission omission and stale reload. One existing fixed-label assertion only normalizes formatting whitespace after independent review.

Synthetic browser verification covers 20 responsive/theme captures, 12 control measurements, keyboard toggles without requests, reduced motion, a real server stale-version refusal and explicit reload. The deliberately altered outgoing version is controlled evidence; settings data remained unchanged. No successful settings save, public-flow run or complete demo parity claim.
