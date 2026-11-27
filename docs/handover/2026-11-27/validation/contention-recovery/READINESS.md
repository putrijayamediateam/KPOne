# Contention recovery — 26 November 2026

Original run: 314 tests, 248 passed, 8 failures, 58 errors, 3041 assertions. JUnit and JSON agree. 57 errors and 6 failures explicitly reported invalid encryption key length; two Billing child failures only exposed RuntimeException/SQLSTATE0. Coordinator verified the original synthetic command key decoded to 26 bytes. The replacement wrapper generates base64 from exactly 32 zero bytes and its decoded length was checked independently, without reading any .env.

With the corrected key, all 44 Billing and Attendance tests passed (400 assertions), including both opaque Billing worker failures. This supports the key diagnosis for those failures; no application change was needed.

One separate error came from a rollback test deliberately migrated only through the pre-STK-3 schema. Its fixture calls current QueueEntryService, whose newly introduced TRI2 close hook queries a table absent in that historical schema. The scoped fixture correction proxy-mocks only closeLocked, exactly once with a QueueEntry and left_waiting. Real queue/clinical/dispensary fixture creation and all migration cutoff/rollback assertions stay intact. No runtime missing-table guard. Independent Patients review cleared this setup correction; coordinator authorized application. Pint only normalized added imports. Focused historical proof passed 1 test / 169 assertions.

Applied fixture patch SHA256: f8701095149e32dddffb8be592dca0f1c13ef8ebb5826551b8b30b6fd9411739

Full Contention is running in kpone_ca_contention_test with run-env.sh; output full-contention.log and full-contention.xml. No full-suite success is claimed until completion. Unit/Feature runs separately under Patients. No real data, .env access, commits or pushes by this task.

## Final aggregate result

Full Contention PASS: 314 tests, 4271 assertions, zero failures/errors/skips, exit0 (1870.5s). JUnit agrees with JSON log. FINAL-RESULT.json records both artifact hashes and source snapshot hash. All3406 checked source/config/test files remained byte-identical since the during-run snapshot, with zero additions/removals. The selected source-input hashes captured before the run and the separate applied historical fixture patch preserve setup provenance. This passing run supersedes the malformed-key failed run, which remains retained as diagnostic evidence.
