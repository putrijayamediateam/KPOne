# Publication candidate: deterministic invalid verification ID fixture

Branch `fix/auth-verification-fixture-id`, worktree `/workspace/KPOne-auth-fixture`, base current local origin/main `67fcc9105ad6da7adec8383265129fd0ba2a2aba`. Uncommitted, unpushed; no PR created. Root authorised this exact fixture-only correction after cause/proposed patch review.

One line changes the verification test's hardcoded invalid ID123 to `$user->id + 1`. PostgreSQL nextval advances across rolled-back tests, so an aggregate run can create the current user as123, making the supposedly invalid signed link valid. Both existing assertions (no Verified event, user remains unverified) are unchanged. No application code, route, grant or migration changes.

Evidence:

- Before fix, unchanged original isolated6/16 passed, while controlled user123 diagnostic reproduced its unexpected Verified event.
- After fix, full original6 methods plus two forced123 diagnostic methods passed8/28 in CA integration.
- Same8/28 passed independently against the current-main candidate with its own vendor autoload and isolated synthetic database.
- Pint and diff whitespace passed. Source file bytes match between candidate and integration.
- Diagnostics live outside the repository in `/workspace/kpone-validation/auth-diagnostic/`; the publication patch contains only the one-line fixture correction.

Patch `auth-fixture.patch` SHA256 `2e298cf67014418462dd24045ab9bffacb5f8a68a6ad1559156b6bc40c2ca34a`. No commit/push/publication authorised yet.

Suggested title: **Make invalid email verification ID fixture deterministic**

Suggested description: The invalid-user-ID verification test used123, which could equal the factory user's ID after PostgreSQL sequences advanced during the full suite. Use the generated user ID plus1 so the link remains invalid at every sequence position; existing no-event and unverified assertions stay unchanged. Validated all six verification tests and two forced-ID123 diagnostic cases (8 tests,28 assertions), plus Pint and diff checks.
