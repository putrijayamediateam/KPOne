# Tab-count performance measurement clock

Observed #1242 exact2b3f2cd83 CI run45930358407/job140281449653: one failure among1425 tests/25037 assertions, Registration large-fixture query count21 exceeds20. Logs fetched through GitHub connector after gh signed redirects returnedForbidden. Failure excerpt at ../queue-final-screen/CI-1242-FAILURE.txt.

DoctorWarningProjection memoizes isPublished by branch and Y-m-d H:i. The test warms each read then measures it against a warm-query ceiling, but uses the wall clock. Crossing a minute between warm and measured read adds one valid roster query. This is an invalid measurement assumption, not evidence of N+1.

Deterministic proof: an external diagnostic copy of exact published test advances frozen branch-local time by one minute after every warm read. Existing assertions unchanged: fails exactly21>20 (1test/10assertions). /tmp/queue-1242-minute-boundary.log and /tmp/TabCountsMinuteBoundaryDiagnosticTest.php retained. Normal unchanged serial and4-worker class executions each pass5/191. Proposed stable-clock external copy passes5/191 on exact pending stack. No production cache change or query ceiling increase.

Candidate isolated main base d0f37b5c1864b796fc7b4224996bd917551af3e7: two inserted lines freeze the performance method to current branch-local minute. Existing main18/16 ceilings/all assertions unchanged; full class5/190 passes. Pending AUTO recovery is deliberately not imported into main. This fixture stability prerequisite can flow through normal main merges; existing #1242 still needs exact-head CI rerun after integration. Laravel teardown clears Carbon/CarbonImmutable test clocks. No app source or data mutation.

Independent review and publication recorded separately. No merge authorized to this agent.
