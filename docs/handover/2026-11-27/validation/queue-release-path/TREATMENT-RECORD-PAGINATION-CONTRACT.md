# Treatment-room bounded read adaptation — 26 November 2026

Parent: Treatment-call draft #1169, 5025cf3e4b0e36aa455f1323affdc271926f2963. The original approved backend is recovered separately from archive #1142. Exact approved migration remains SHA256 a39e12e7dc83472307dd2cef01d415938c4a2e9aad2e0d963df81097a949041f. No new grant, clinical transition or migration design.

Preflight found unrestricted directory rows/nested steps and record history. The implementation adaptation bounds reads, preserving eligibility, authority, audit and classification. This is not a change to which patients or records qualify.

- `snapshot(actor, page=1)`: 25 independently ordered eligible Serving entries, queue timestamp then ID. `pagination` contains `currentPage,lastPage,perPage,total`; all counts precede limits. Each row has at most 25 newest steps plus `stepsPagination` and full-set `hasStartedStep`. Selected-visit context reaches remaining steps. Nested read is one partitioned SQL query, not a query per row.
- `context(actor, visit, stepsPage=1)`: 25 newest steps for the authorized visit with `stepsPagination`. `hasStartedStep` is queried against the full set, so an off-page active step cannot incorrectly enable Start. Outcome-existence flags concern the selected step IDs and retain original evidence semantics.
- `forVisit(actor, visit, history=false, currentPage=1, historyPage=1)`: independent 25-row current and 25-row history pages; `currentPagination` and `historyPagination` accompany unchanged row arrays. `history` boolean retains its existing authority meaning; it is not a filter. Successor/void classification queries all evidence before selection. A predecessor never revives if its successor is on another page or is voided. `successorCorrectionCode` supplies the existing correction label without extra identity metadata.
- Page numbers clamp to 1..lastPage (empty lastPage is1). Stable descending record/step ID ordering. No mutation, auto acknowledgement or resume on page reads; explicit record-read audit remains fail-closed.

## Required later UI/controller integration

The later scoped UI extraction must validate positive integer `page`, `steps_page`, `current_page`, `history_page` (bounded integer input) and forward them to the respective methods. Directory page and each selected-visit page are independent. Render count/Previous/Next controls; never silently truncate without a route to later entries.

Preserve currently selected page parameters on successful mutation redirects; backend clamping resolves shrinkage. A newly started step may deliberately navigate to steps_page=1 only after success, with the outcome draft reset and the newly returned step selected explicitly. Never reset a pending/uncertain command, or replace its idempotency key, merely to change pages. Disable navigation while busy or outcome uncertain. On explicit step-page/visit/branch change, reconcile or discard the draft through existing confirmation rules; never carry outcome/reason silently to another step. Reset record-reader page cursors on visit/branch change. Use full-set `hasStartedStep`, and `successorCorrectionCode` rather than searching the loaded history array.

Runtime tests and independent review pending at initial creation of this note; this document is a contract proposal/checkpoint, not release clearance.
