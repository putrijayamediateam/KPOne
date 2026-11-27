# Own Treatment/Result columns validation

Frozen six-file58b6ad71ad6829a1b8c4c5b6275a6f3b7aca70c8da9665583a69fb67dfc480fd on #1233 d99e5c4ae61070e723ea066d60fcc218a9d3963f. No backend, permission, migration or clinical mutation change.

Full frontend2314, types, production build, lint/format and30 focused tests pass. Independent source review ran12 actual task/lifecycle tests. New actual SSR covers corrected planned-step/recorded-procedure mismatch, running current step plus multiple waiting completed steps, missing/redacted outcomes, escaping, default badge visible/ARIA evidence, defaulttrue opt-out and preserved unsuccessful warning tone. Q1 strict harness additively compiles the real new renderer; no existing assertion replaced.

The new Result renderer keeps current→waiting order and pairs each step with its supplied outcome, never sorting labels separately. Planned Step and Recorded procedure are explicitly distinct. Missing data says Outcome not available. The dedicated Treatment badge hides only duplicate outcome lines/ARIA in this own task; real outcome still controls warning tone. Existing default badge consumers, controls, parent-owned dialog and action handlers are unchanged. Viewing never resumes/acknowledges or confirms physical return.

Actual preview3190 uses the same isolated synthetic task DB read-only. Four own/supervisor desktoplight/short390dark states prove one actual badge, single visible unsuccessful outcome, dedicated Result own placement, original default branch badge/ARIA, and44px focused action target below persistent host. Eight complete clinical/stock tables remain byte-identical after reads. Source corrections/multiple waiting/unavailable cases are SSR evidence, not claims of additional browser fixtures. Screenshots deliberately scroll relevant card/action below the fixed host and do not prove full-page parity.

No merge readiness claimed. Parents #1229/#1233 retain their own pending remote CI gates; original Returned source-location assertion follow-up is already inherited. Next own Done timestamp contract is a separate read-model proposal only.
