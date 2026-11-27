# TRI2 bounded alarm followup — 26 November 2026

The 25-row alarm response previously allowed 25 older taken Waiting episodes to hide a newer untaken emergency. The followup ranks effectively untaken entries first before the existing legacy/age/id order and limit. Taken patients remain in the independent emergency lane; no patient data, authority, DTO or migration changes.

Effective taken status matches the existing response mapper: latest canonical claim belongs to the currently assigned doctor and, when an open episode exists, that episode points to the claim. Historical claims below a newly activated episode floor cannot silence its alarm; reassignment restores untaken rank. Seen alone does not silence or demote an alarm.

Frozen patch: `TRI2-bounded-alarm-followup.patch`, SHA256 `90eae5fd9cd59d8ab0d70f462b62659c69d612acf8fcc536e936884177ac26ec`. Exact file/base hashes are in `manifest.json`. Only one service and one new regression file are included. No existing assertions changed by this patch.

Validation: 30 focused tests / 155 assertions passed, including unchanged alert/lifecycle tests and three new regression cases (26-row cap, mixed legacy/episode plus Seen/reassignment, activation claim floor). PHPStan zero errors; Pint and diff whitespace checks passed. Patch reverse-checks against the isolated source and forward-checks against CA integration. Original 26-row proof failed before the fix; original proof log remains under `../tri2-lane/aggregate-impact/`.

No commit or push. Root owns application to integration and the full aggregate gate. The separately owner-approved three-file aggregate assertion patch was applied unchanged to the isolated tree after alarm tests completed; it is not included in this followup.

Independent Billing review completed: source and all three new tests clear, final frozen hash verified; no scoped blocker. Reviewer read recorded runtime evidence rather than duplicating the run.
