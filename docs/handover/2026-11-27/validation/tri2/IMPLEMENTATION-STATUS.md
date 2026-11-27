# TRI-2a implementation — 26 Nov 2026

Worktree: `/workspace/KPOne-tri2`. Incremental feature overlay is relative to the frozen CA integration snapshot in `base-manifest.json`, not the Git HEAD diff (which also includes other recovered slices).

## Authorisation

Owner approval relayed by coordinator: exact migration SHA256 dfff7e9cd1926c5f2060f7356cec4d4980a86db5717654510aad03e9ea979ab6; existing not-ready/lane-alarm/reassignment decisions; observed-time activation implementation and synthetic testing only; settled resident-doctor-only permission; exact existing backend assertion groups A–C. No frontend assertion approval or production activation.

## Behaviour

Episodes preserve immutable source/scope/time and append events. Seen keeps alarm active. Take uses the single existing canonical claim writer and advances current claim only past the episode floor. Reassignment invalidates effective Take; red→lower→red makes a fresh episode. Source hooks close atomically on lower/Call In/cancellation. Retained flags survive Take; setting a new flag while red refuses. Existing narrow recorder read access stays; Seen/Take require active resident-doctor authority and branch access under locks.

Activation is fixed organisation/branch, default dry run, bounded candidate watermark/chunks, per-entry locking/current eligibility, database-returned second-precision observed timestamp, actor-null activation event, no old claim adoption. Execute refuses success if eligible legacy rows or invalid open episodes remain. A concurrently changed assignment may be skipped and reported by remaining count; rerunning safely retries it. No production command executed.

## Review

Independent Billing/Panel source findings corrected: response remains bound to acknowledged episode under outer transaction; inactive parent organisation refused; scoped invalid-open health blocks success. Queue review: `INDEPENDENT-QUEUE-IMPLEMENTATION-REVIEW.md` in review bundle. Neither reviewer claimed independent runtime rerun.

## Rollout boundary

This is backend-only. Existing frontend sends legacy Take payloads and is intentionally not changed without the exact frontend assertion batch. Do not activate/deploy this backend alone as a finished user flow. Compatible client must precede scan; full shared lane/alarm UI requires its own checks and zero legacy/invalid-open release gate. No new clinical timestamp is invented.

## Current validation

- Actual PostgreSQL contention: 10 cases / 58 assertions plus 4 activation/source-transition cases / 28 assertions, all passed. Covers two doctors, exact Take/Seen replay, lowering, Call In, legacy Take versus activation, concurrent activation, cancellation, repeated red retake, actor-role revocation, and activation versus red/lower/call/cancel. Each worker is observed blocked behind the held shared row before release.
- Broad focused backend: 100 cases, 99 passed, 982 assertions; sole failure is the old plain-TRUNCATE SQLSTATE expectation. Existing test is unchanged pending owner approval of the exact supplemental assertion patch.
- New CASCADE guard proof: 1 case / 5 assertions passed; exact triage immutable message, all three evidence table counts preserved.
- Migration fresh twice passed on dedicated synthetic DB; approved file hash unchanged.
- Changed-source PHPStan: 0 errors. Pint and diff whitespace checks passed. Contention suite registration: 2 / 15 passed.
- No frontend source changed; no frontend build claimed. Shared UI integration and exact frontend assertion batch remain separate.

Final frozen source: 22-file overlay SHA256 `d8725e7ed0b3aec9399202e665b361cd61dfae29374549d5c3602f576899581e`; apply-check against frozen baseline passed. Final HTTP/lifecycle/existing Q16b gate: **27 tests / 136 assertions passed**. This includes the approved emergency-permission GET/Take route gates independent of Call In permission, narrow no-store Seen and current-role refusal. No patient identity/clinical payload was added. Exact existing TRUNCATE test adjustment remains pending; backend source/contract is otherwise frozen for the UI team's isolated work.
