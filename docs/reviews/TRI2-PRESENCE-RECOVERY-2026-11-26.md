# TRI2 recent doctor poll presence recovery

This scoped recovery follows lane PR #1185 on commit ab044ba15e67b024cab745f6267819785ea757d3. It does not import archive PR #1142. Family #1148 remains a separate release dependency for the final combined workflow.

The existing authorized alert endpoint adds only `presence: { available, doctorCount, windowSeconds }`. This means distinct authorized resident doctors who polled this branch within the preceding 30 seconds, not roster membership or clinical availability. No doctor identity is exposed. Cache failure or lock contention returns unknown (`available: false`, `doctorCount: null`) while preserving alerts. Cache state is capped at 512 entries; TTL is 60 seconds. Revoked, inactive, foreign-organisation and no-longer-assigned candidates are revalidated before counting.

The recovered implementation originally queried branch assignments per cached doctor. The additive `BranchAccessService::selectableUserIds` batches effective assignments using the same branch-local calendar date, organisation/branch activity checks, permission bypass and inclusive assignment dates as `canSelect`. Existing single-user APIs remain unchanged. Presence retains its own active-user and resident-doctor permission restrictions; the general batch API does not invent those restrictions.

New tests compare one versus 26 candidates for constant SQL count, batch versus single-user access at local midnight/end-of-day/next-day, foreign organisation/missing profile/inactive branch, and the exact 29/30-second expiry boundary. Recovered tests retain branch, revocation, cache failure/lock/capacity and identity-minimization checks. No existing published assertion changes, permission grants, schema changes or production activation are included.

Validation: focused presence and RedZoneAlert tests, 20 tests / 135 assertions; scoped PHPStan zero errors; PHP formatting and diff checks. This backend-only slice changes no frontend assets. Shared alert UI and emergency lane UI remain separate prerequisites; this is not a full workflow or deployment approval.
