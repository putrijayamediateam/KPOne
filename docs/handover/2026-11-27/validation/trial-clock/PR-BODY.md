The phase-five success test can fail when CI reaches it between 00:00 and 00:02 in Kuala Lumpur: PhaseFiveHr intentionally refuses to invent an earlier session before 00:03. The observed Q16e CI failure occurred at 16:00 UTC (00:00 MYT).

Freeze this success fixture at the latest already-past noon in the branch time zone, matching the existing approach of keeping application test time close to the real database clock. All existing assertions, the four just-after-midnight boundary cases and the production refusal remain unchanged. No runtime, permission or migration changes.

Validation: all five focused PostgreSQL success/boundary cases passed (76 assertions); scoped Pint and git diff --check passed. Independent source review cleared the three-line fixture change. Full CI remains required before merge.
