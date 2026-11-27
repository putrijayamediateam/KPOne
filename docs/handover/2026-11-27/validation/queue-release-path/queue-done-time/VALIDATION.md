# Done consultation checkout timestamp

Frozen seven-file patch 23ed6cfa2ca26a7ed4d5a1c919c7c9496d4e504a44bf48585f7f23e2020466a1 on Treatment Result #1234 b0ed07f2771533b743a7e30e8226cce5a7625bcb.

The completed workload alone receives nullable removedAt from the exact already loaded authorized QueueEntry, matched by unique visit number after existing row projection. No new query, permission, migration, eligibility or writer. Existing branch-local completed-day membership is unchanged. This timestamp means consultation checkout/handoff to billing or dispensary, not completion of those downstream tasks.

Own Done displays Seen for and Completed (consultation), formatted using the live branch timezone. Missing time is Not available. Default Registration, branch and emergency layouts remain unchanged; no Now at inference is added. Generic page/poll and other workload payloads do not gain removedAt.

Validation: combined backend 11 tests / 177 assertions; new timestamp tests 3 / 21 include actual service checkout, complete MYT day boundaries, other-payload absence/authority, exact identity pairing and equal query counts at 1 versus 26 records. Static analysis zero errors, Pint, scoped ESLint/Prettier and diff checks pass. Full frontend 2,316 tests, types and production build pass. Fourteen actual task component/mapper tests pass, including missing-time/default-layout parity and branch timezone change.

Synthetic preview3194 uses kpone_queue_task_columns_preview_test. Actual NY-timezone browser renders 27 Nov 2026 7:56AM MYT, rather than 26 Nov 6:56PM NY. Both 390x640 dark and1440 light prove27 rows paged25+2, with44/36px focused hit-testable pager below persistent emergency host. Eight entire clinical/stock tables are byte-identical before/after. Screenshots deliberately scroll the relevant card/pager below the host; no full-page parity claim. No mutation invoked.

This is a separately stacked draft, not a merge candidate replacing foundation parents. No archive1142 ancestry or production activation.

## Test-only lint follow-up

After publication, inherited test lint exposed a missing blankline and unused destructured removedAt. The earlier lint-pass report was premature (log read before process finished), and is superseded here. One-file TEST-LINT-FOLLOWUP.patch replaces the unused destructure with shallow copy/delete and fixes the blankline; all assertions unchanged. Independent review clear; actual14tests and lint exit0 confirmed. Normal-pushed #1238 follow-up a4335a6d9; original23ed artifact remains immutable. App/backend/browser source unchanged, no repeat build needed for test-only correction.
