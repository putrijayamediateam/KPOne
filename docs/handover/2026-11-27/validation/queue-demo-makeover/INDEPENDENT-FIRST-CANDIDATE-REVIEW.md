# Independent Queue first-candidate review

Reviewed exact v2 patch SHA5009128fbc45506793e1974efae900ee79150a482e121bf4f7adaae840ead3a4, its four scoped files, all eight settled doctor/supervisor390/1440 light/dark candidate screenshots, canonical MyQueue runtime, and NEXT-DATA-SLICE. The worktree contains inherited integration changes; this review covers the exported patch only.

Two findings block freezing v2:

1. PatientBoard own layout suppresses the entire Doctor cell, including server-supplied doctorWarning and Reassign. QueueDirectoryService emits that warning based on actions.update, independently of queue scope. A legitimate own-scope viewer with visit-update authority loses an existing warning/action. Render supplied warnings in the own-layout Patient cell, without widening permission/data. Add actual SSR coverage with and without a warning.
2. Branch1440 Emergency table compresses Waiting/status into roughly70px columns because the new auto table lets a long Doctor pill consume roughly250px. In both settled themes, Mark not ready wraps three lines and the first emergency row grows roughly320px tall. Bound the doctor pill/column and preserve useful Waiting/status widths in queue mode; leave default Registration layout intact. Recapture branch1440 after correction.

Otherwise the visible heading, header Call next, real-count cards, support disclosures and mobile stacking establish a clear first implementation of the demo composition. The dark captures are settled and readable. The new branch/scope watcher resets Doctors selection; it does not change backend authority. Metric selection delegates to the existing tab-selection function. Native details and explicit expanded-state help toggle preserve meaningful controls. Builder58 tests/types/build/browser results are reported evidence, not rerun by this reviewer.

Not a full-parity verdict: complete held/back-from-treatment/own-check datasets and final labels remain absent as recorded. The live Emergency alert/lane reflects later approved requirements and must remain. Arrival/dialog animation parity and all remaining workflow states are not demonstrated by these still screenshots. Do not count this first preview as completion of Queue.

No source/assertion/database changes by reviewer. Both concrete findings sent to Queue owner and coordinator; closure pending corrected source and focused proof.

## Final v4 closure

Reviewed final five-file artifact SHA2561a0924e754fc5c26d444e6e453f1636dd5ce37492fd132f7433bbba20238c787. Both findings above are **closed**:

- Own mode now renders the supplied warning and Reassign below Patient. No new permission inference or payload was added. Independently reran `node --test tests/Frontend/queue-demo-workspace.test.mjs`: all six tests pass, including actual SSR warning-present/warning-absent and branch/scope reset tests.
- Queue uses controlled fixed columns and bounded Doctor content. EmergencyLane's old nth-child widths now target only `.patient-board:not([data-queue-layout])`, preserving the legacy nine-column path. Fresh supervisor1440 light/dark screenshots show readable Waiting/status and single-line Mark not ready; the previously excessive row height is resolved. Candidate DOM evidence reports Doctor163px, Waiting181px, Status181px, Actions127px. Fresh own390dark preserves stacked cards and readable controls. These specific screenshots were independently inspected; the complete eight-state rerun remains builder evidence.

No remaining blocker found for this bounded first Queue presentation preview. Remaining full-demo gaps are still those in GAP-MATRIX/NEXT-DATA-SLICE: complete held/back/check data sections, final labels, and full interaction/animation parity. This closure is not full-demo completion or publication approval. No source edits, commit, push or merge by reviewer.
