# Auth aggregate failure — fixture collision, 26 Nov 2026

Investigated read-only production/test source in `/workspace/KPOne-ca-integration`. Existing test file is unchanged. All execution used new isolated `kpone_auth_diagnostic_test`, never preview or aggregate worker databases.

The existing invalid-user-ID case constructs a correctly signed verification link with the current user's actual email hash and hardcoded ID123. It does not ensure the current user's generated ID differs from123. PostgreSQL sequences advance outside transaction rollback; `RefreshDatabase` rolls back rows but does not reset nextval between transactional tests. Aggregate ordering can therefore make this test's user123. Fortify's actual `VerifyEmailRequest::authorize()` compares route ID to authenticated user ID with hash_equals, then checks the email hash. When both are123, this is a valid verification request; emitting Verified is correct.

Evidence:

- Unchanged full `EmailVerificationTest.php`, isolated: **6 tests /16 assertions PASS**.
- Separate diagnostic file outside repository source, filtered to2 diagnostic methods: **2 tests /12 assertions PASS**. First sets the isolated users sequence so the next fixture ID is123 and invokes the original unchanged invalid-ID method. It observes that exact assertion failure, confirms created ID123, Verified event, and verified saved user. Second creates user123 but uses userID+1 in the route; it receives403, no Verified event, and the user stays unverified.
- Fortify request compares ID and hash before controller verification. Event fake is local to each process/test; no evidence of cross-worker event leakage. The ID collision alone reproduces the aggregate failure.

Proposed minimal fix, not applied: replace only `['id' => 123, ...]` with `['id' => $user->id + 1, ...]`. This guarantees a different route ID regardless of sequence position. Keep both existing no-event/unverified assertions exactly unchanged. No application fix or authority change is needed. Exact one-line patch is `PROPOSED-FIXTURE-ONLY.patch`.

## Authorised correction completed

After this report, coordinator explicitly authorised the exact fixture-only line. Applied in CA integration and isolated current-main candidate. Corrected diagnostic invokes the original method at forced user123 and asserts it now passes with no Verified event. Both trees passed8/28. Publication packet: `/workspace/kpone-validation/auth-fixture-publication/REVIEW.md`. No commit or push.
