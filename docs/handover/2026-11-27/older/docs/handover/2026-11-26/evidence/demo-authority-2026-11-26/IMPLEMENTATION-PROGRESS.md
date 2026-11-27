# Demo implementation progress — 2026-11-26

These are bounded implementation milestones, not whole-page parity. Plan review below is independent design feedback within coordinator-assigned scope, not new business authorization. Existing grants, clinical contracts and assertion approval rules remain binding.

| Area | Owned files / tree | Current evidence | Remaining gate |
|---|---|---|---|
| Queue | Five-file v4 isolated /workspace/KPOne-queue-demo | Independent source/visual review closed both findings; six new tests independently pass; builder71 focused tests and eight states pass | Whole held/back/check datasets, final labels, interaction/animation parity; no full-parity claim |
| Pharmacy | Dispensary/Index.vue + new test/docs; /workspace/KPOne-dsp-workspace | Plan reviewed: three existing states/counts, guarded Returns route, contextual support disclosure; current search/poll/actions preserved | Independent final seven-image/source review clear; three runtime tests independently pass. Broader filters/workflow/animation parity and shared rail highlighting remain separate |
| Stock on hand | Inventory/Index.vue + inventory/StockOnHandList.vue + new tests/docs; /workspace/KPOne-stock-workspace | Plan reviewed: real batch/location rows, actual three summaries/view controls, expanded existing details/actions; no invented SKU aggregate or nearest expiry | Four actual states/source reviewed; four tests independently pass. Mobile44/loading fixes verified; five runtime tests independently pass. New desktop disclosure target minima followup pending; exact old assertion batch remains owner gate |
| Registration | Registration/Index.vue, Patient/Index.vue, registration/RegistrationWorkspaceNav.vue + new test; /workspace/KPOne-registration-workspace | Plan reviewed: route-based Today/Patients/QR only, actual permission filtering, existing handlers and payloads | Bounded source/three-image review clear; five tests independently pass. Builder twelve route states pass; populated QR followup underway. Wider workspace/modal remains deferred |
| Billing | Existing PayLaterCollections.vue/CashClosings.vue + billing/BillingBoardFrame.vue + tests/docs; /workspace/KPOne-billing-demo | Plan reviewed: actual collect/cash/month/terminal links, real totals/actions, contextual disclosure; no placeholder tabs | Compact four-file candidate independently clear; twelve main/readonly captures plus detail/error inspected, three tests independently pass. Two existing layout assertions await approval |

## Coordination

No team owns shared primitive changes in these first slices. All shared-file needs must be raised before editing; page-local components can reuse existing buttons, fields, cards and token classes without introducing a new framework. Existing pinned assertions remain unchanged unless their exact replacement has owner approval.

Each final candidate needs its exact patch/source SHA, scoped gates and settled screenshot evidence. Review must distinguish applied implementation, tested runtime states and known remaining gaps. Palette/size-only fixes and passing builds are foundation evidence, not demo completion.

## Standing parallel-work rule — owner directive

All teams continue parallel work unless a concrete dependency blocks that particular step. A slice waiting for review, approval or a build slot does not block independent preparation, focused tests or peer review of the next authorized work. Do not claim continuous background work without observed activity. Existing assertion replacements, grants and migrations still require their applicable approvals; unaffected work continues.

Resource queue communicated by coordinator: DSP → Billing → Registration → Inventory for heavy frontend type/build steps only. Slot owners must hand off completion; subsequent scheduling updates take precedence. Source editing, bounded tests, plan work and review run in parallel. Followup short rebuilds may be queued separately by the coordinator.

| Team | Observed active work | Concrete dependency | Next independent task |
|---|---|---|---|
| Queue | New workload backend projection/contract and five tests reported ready; first UI v4 independently clear | Independent source/contract review clear; exact existing UI assertion changes still need approval | Prepare real held/treatment/check UI binding and independent boundary tests within approved scope |
| Patients | Registration six-file navigation source ready;83 focused tests/lint/format pass | Final short rebuild before fresh screenshots after omitted-Boolean QR fix | Count preservation and omitted-Boolean QR gate fixed with five SSR cases; prepare final route/back/forward capture |
| Inventory | Index+StockOnHandList source and own synthetic nonempty preview fixture prepared; focused runtime tests underway | Type/build slot; exact existing assertion-pin batch needs owner approval before changing assertions | Finish exhaustive pin inventory and new behavioral tests; existing tests stay intact pending approval |
| Dispensary | Final seven-state/source review independently clear; three runtime tests independently pass; builder full gates reported green | No current local gate; publication approval remains separate | Prepare saved-line card disclosure mapping for next authorized case slice without editing current branch beyond scope |
| Billing | Eight actual initial states captured; populated receivable and disclosure-arrow refinement underway | Heavy gate slot for final candidate capture; other work proceeds | Populated and error/readonly capture; bounded Queue workload peer review |
| Staff/design | Registration nav review clear; Queue workload source review clear; DSP seven-state closure complete; Stock local target finding issued | Stock desktop disclosure target followup pending; Billing compact candidate independently clear | Maintain exact coverage/decision boundaries, review source and first available visuals |
