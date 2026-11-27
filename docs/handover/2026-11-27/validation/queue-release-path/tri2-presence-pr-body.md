The emergency poll now reports a bounded count of authorized doctors who recently polled the same branch. Cache failures remain unknown, and the response exposes no doctor identities or claims about clinical availability.

This scoped recovery batches branch-assignment checks rather than querying once per cached doctor. The additive batch API matches existing `canSelect` authority at branch-local date boundaries; existing access APIs, permissions and schema remain unchanged.

**Stack:** based on #1185 (`release/tri2-lane`), following #1182, #1180, #1172 and #1169. Do not merge archive #1142. Shared alert UI and lane UI remain separate prerequisites; Family #1148 remains an explicit final workflow dependency. No production activation is authorized by this PR.

Validation: 20 focused tests / 135 assertions; one-versus-26 constant-query proof, single-user/batch access parity, exact expiry, revocation and cache failure coverage; PHPStan zero errors; Pint and patch checks pass. Backend-only change; no frontend assets changed.

Frozen patch SHA256: `86b6e8d7ceb8a94a14b498016d60e2ade95f080807e438f37579a15549ebdb0a`. Recovery and test limitations are recorded in `docs/reviews/TRI2-PRESENCE-RECOVERY-2026-11-26.md`.
