# AG-21: why you cannot decide an agent suggestion

Code: PR #752, branch `feature/ag-21-cannot-decide-reason`, head d2649189. Real V2 shell, task-owned `*_testing` database, Director, who started the run that made the suggestion (seeded through the real services).

Shots: `cannot-{1440,390,320}-{light,dark}`. The first card is the seeded suggestion: "You started the run that made this suggestion, so someone else must decide." The second card, from another run, still shows Approve and Reject.

Measured: the paragraph wraps normally at 390 and 320, no sideways overflow at any width, no console errors.

Limits: only the "started the run" case is shown; the other two reasons (role, not assigned) are covered by PHP tests, not by a shot. The unit in this data is already plural ("tablets"), so the "Fill to maximum" line is the current main wording; AG-22 changes it separately.
