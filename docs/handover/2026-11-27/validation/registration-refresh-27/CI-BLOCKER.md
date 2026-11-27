# Registration release blocker

PR1132 head7457a39d90ad0c780bbae2ada79fb044f73bcbc4 failed backend shard1, job140242827028; static, attest and shards2/3 passed. No merge authorized by the failed gate.

The connector log identifies TrialDataCommandTest::test_phase_five_fills_kpone_hr_once_with_fictional_payroll_tables at line420, runPhaseOne expected0 received1. This occurs before the phase5 call. Root cause remains unproven; do not attribute it to the Registration restyle or claim the earlier clock fix solved every failure. Preserve all assertions. Next step: reproduce isolated phase1 failure at the CI branch-local date/time and inspect captured command output.

Five builders/reviewer agents reported usage-limit errors during this checkpoint. Queue context1223 and Registration field groups1224 are published; remaining PO/browser, Billing final pipeline/browser and Dispensary age publication need reconciliation before continuation. No usage reset was performed.
