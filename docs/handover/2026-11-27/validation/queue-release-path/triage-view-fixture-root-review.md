# Independent coordinator source review — PR1194

Reviewed candidate: `92aa0c8eda849fe0777bab6b0209a29011140e57`.
Main contained: `f8dd19cb83a6129479246db1c62d0af12cd28fb5`.
Changed file SHA-256: `8082ced51b4a15e58e638146810914c381ff038a43a735009786a86542d0b07e`.

Read the complete affected test, its existing assertions, TriageTestCase::waitingFixture, QueueTestCase::consultationVisit and VisitTestCase patient/register helpers. The explicit-patient path preserves the same consultation type, assigned doctor, visit reason, service registration and queue entry as the original path. The synthetic recorder is created with the original actor helper; only its display name is then made deterministic. Patient display name is also fixed because it is part of the same scanned projection.

The patch changes only fixture construction in one test. The original whole-projection forbidden-token checks and every other assertion remain unchanged. No application behavior, permission, schema, real data or production operation changes. Frontend styling and transaction/locking controls are outside this test-only patch.

Builder's saved focused result is 11 tests / 103 assertions passing after refresh. Coordinator inspected that result and independently verified the source hash, clean worktree, diff whitespace and current-main ancestry; coordinator did not independently rerun the backend suite. Existing Billing source review remains complementary evidence. Final merge still requires all six remote checks at this exact candidate and a fresh main-ancestry check.

Result: CLEAR for the stated fixture-only scope.
