# Patient Directory #1171 CI investigation

Exact candidate bd00307b1a82c4d675bd149ba5cc05e2dba5aa8a, run45870950187, shard2 job140086390335.

GitHub check presentation marks failure, but Actions job API conclusion is cancelled. Check-run annotation explicitly states: “The job has exceeded the maximum execution time of40m0s”; its second annotation says operation canceled. Candidate workflow sets backend timeout40minutes. Unit/Feature four-worker ParaTest started17:58:52 and reached1281/1331 at18:07:30 with all progress markers dots, then no output until cancellation18:38:20. Contention and shard-count proof never ran. No assertion failure summary or failing class was emitted. Thus the proven immediate cause is timeout/stalled completion; the underlying stalled test/process remains unproven.

The logs do not match the separate TriageDoctorView Faker lock-substring assertion failure, and there is no evidence yet linking the TrialData clock issue. TrialDataCommandTest is among this shard's files, but presence alone is not diagnosis. Previous aggregate evidence ran that class in253.63seconds.

CLI signed log redirects were403; the connected GitHub workflow-job-log tool successfully fetched the actual log. Saved relevant execution tail and API job/annotation records. SQL errors in final PostgreSQL teardown output include intentional invariant-test refusals and are not automatically test failures.

Exact local four-worker shard reproduction started against owned synthetic kpone_patient1171_test with separate worker temp files and JUnit, preserving assertions, schema, candidate and40minuteCI policy. No preview database or source changes. Outcome pending.

Root authorized exactly one same-head failed-job rerun. GitHub connector accepted it; attempt2 job140199703492 runs on unchanged bd00307b, with other successful checks reused. Local exact reproduction session57943 uses four workers and owned base database kpone_patient1171_test; worker snapshots are retained. Local progress passed1323/1331 without F/E markers as of latest snapshot, but no completed-pass claim yet. CI timeout and assertion policy remain unchanged.


Local exact shard completed successfully:1331/1331 tests,25,121 assertions,702.655seconds,exit0. The same-head remote rerun also passed the original Unit/Feature stage and advanced to Contention. The original timeout is unreproduced; underlying cause remains unknown. No source fix, assertion edit, timeout increase or test exclusion was made. Overall remote shard/aggregate outcome remains pending.

Final result: same bd00307b now all six PR checks SUCCESS. Attempt2 shard2 job140199703492 passed in15m05s, including serial Contention and executed-count proof; aggregate job140204853577 also passed. Original40-minute timeout is unreproduced locally and remotely, with underlying cause unknown. No source repair or gate weakening was performed. Candidate worktree remains clean. Investigation closed; modal implementation resumed under previously reviewed contract.
