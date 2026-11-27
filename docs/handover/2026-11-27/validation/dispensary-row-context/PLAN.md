# F07 — bounded existing visit context disclosure

Proposed next slice after Doctor-filter1231; no implementation yet. Base1231 head4a88fb3cb64abaf9258a2f26b772c101fefd2c24. Standing coordinator continuation applies after independent plan review.

## Evidence and bounds

Locked Pharmacy.dc.html Prepare rows have an expandable patient/visit context region. Current real Index rows expose name/KP/visit/type/doctor/medicine count/time and actions but no disclosure. Existing DispensaryDirectoryService::board already exposes visitReasonExcerpt (VisitReasonService summary limited80) and coverageLabel (saved panel name or Self-pay) under the same existing canView active-branch gate for pending/dispensing/awaiting-check cases. These are existing authorized operational facts, not invented clinical values.

Implement only Prepare and Waiting row disclosures with the existing visit number/type/doctor and those same two authorized board fields. Done remains unchanged because the cited board contract excludes completed cases. Age/sex, allergies, last BP, additional clinical history, mock call/return actions and Whose cases remain separate unresolved scope; do not infer their authority from this proposal. No medicine contents, private panel note, phone number or patient-directory link is added.

## Source plan

- DispensaryListService: eager-load the existing visit reason relation only for Prepare/Waiting, reuse VisitReasonService summary and80-character excerpt contract, expose nullable visitReasonExcerpt and coverageLabel. Do not select extra patient demographics or change predicates/counts/order/paging.
- Index.vue: native details/summary in each desktop patient cell and corresponding mobile card, with labels Reason/Pays/Visit/From and honest absent-value text. Preserve Open case/label actions outside the disclosure and existing permission predicates. Native focus remains on the trigger; min44 below1024 and36 desktop; long values wrap. The disclosure is optional supporting context, never a clinical safety gate.
- New backend/runtime tests and TEAM.md only. Inspect exact existing assertions before faithful adaptations under delegated scope.

Prefer two bounded eager relation queries across the page over per-row lazy queries or a new endpoint. Payload remains at most25 cases; no expanded staff directory, no new grants/migrations/mutations. Relationship/query bounds and existing reason presentation rules must be inspected by backend peer before coding; if reason assignment cardinality is not bounded, narrow that contract rather than loading an unbounded history.

Source follow-up: VisitReasonService::resolve rejects more than5 reasons; migration2026_09_06_000100 has a position1..5 constraint. Verify its per-visit uniqueness before relying on the maximum. Design plan review is clear with explicit null handling: a panel coverage row with missing saved panel name remains unknown, never becomes Self-pay. Native disclosure state must persist for the same case during ordinary polling, while removed/other-context cases cannot keep stale content. If polling removes a currently focused row, move focus to a connected stable cases-region fallback only for that focused removal; never autofocus on routine polls. Keep actions outside disclosures and all clinical/mutation controls unchanged.

## Validation

Backend: existing role/branch/organisation scope, snapshot panel name versus current panel rename, truthful null/legacy reason, Done omission,25-row paging and constant query growth (not N+1). Frontend: actual disclosure keyboard/collapse, long context/empty/null, CA/readonly permissions, no accidental navigation or network mutation;390/1440 light/dark plus stable native state on ordinary list refresh. Existing clinical controls, Verify completes and case-only return checks unchanged. Separate independent source/visual review and scoped draft; no full Pharmacy parity claim.
