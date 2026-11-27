The invalid-user-ID verification test used `123`, which can equal the factory user's ID after PostgreSQL sequences advance during the full suite. Use the generated user ID plus one so the signed link always targets a different user; both existing no-event and unverified assertions remain unchanged.

Validation: all six email-verification tests plus two forced-ID-123 diagnostic cases passed (8 tests, 28 assertions) in both the integration tree and this current-main candidate. Pint and `git diff --check` passed. No application code, permissions, migrations or assertion changes.
