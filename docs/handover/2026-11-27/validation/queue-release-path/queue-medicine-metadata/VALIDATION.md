# Medicine-check structural metadata

Frozen eight-file e81ccbc703bc1fa3d41ba4a4075bc9796d708fdb64e26483e416b3188c263eaf on #1238 a4335a6d9 (includes the separately reviewed Done harness lint correction).

The checks workload alone gains nullable medicineCheck {itemLineCount,sentBy}. One grouped SQL statement repeats fresh assigned-doctor, attending encounter, organisation/branch, registered visit, removed-to-dispensary queue, awaiting case and exact open handoff predicates. At most25 loaded visits can return25 aggregate rows. Ambiguous candidate identity is omitted. Sender and count share one statement snapshot. No medicine names, quantities, case/handoff/staff IDs, clinical lines, permissions, migrations or mutations added.

Item lines counts all current open-handoff review lines, including intentionally removed/not-dispensed lines; the existing doctor-check reader shows all these lines. Sender is the current case.sent_for_check_by_user_id, not original handoff doctor/current handler. UI explicitly explains that count is not supplied quantity. Own checks uses Item lines/Sent by with zero distinguished from unavailable; default Registration/emergency/branch and existing Open Consultation action remain unchanged.

Backend16 tests/100assertions pass; new6 tests include actual26 case service setup, equal1-versus26 query counts,25+1paging/identity, negative roles/foreign subjects, fresh reassignment refusal with stale input, permission/inactive denial, actual sendback/resend with removed0quantity line counted1. New invalid null-sender awaiting fixture was refused by existing database shape guard and removed; no constraint/service weakening. Nullable UI fallback is defensive SSR, not a claim that this invalid database state is accepted. Ambiguity guard is source reviewed, not a fabricated constraint-bypass scenario.

Full frontend2317/types/build pass. Final15 actual component/mapper tests and scoped lint/format/Pint/static0 pass; small inherited Done test-only lint fix preserves all assertions and was independently reviewed. No new existing-assertion replacements.

Actual3202 preview uses existing isolated kpone_queue_checks_preview_test read-only. Two populated own390dark/1440light states prove exact1/currentCA metadata, missing clinical payload fields, explicit explanation,44px hit-testable focused original action below persistent host. Open Consultation menu inspected but not invoked. Eight whole clinical/stock tables remain byte-identical. First harness incorrectly sought Medicine checks before selecting Needs me; its timeout is retained and excluded, corrected real navigation passes. Captures scroll context/action below persistent host; no full-page parity claim.

No archive merge, production activation or clinical action. Separate scoped draft on #1238 after final independent hash review.
