# Batch safety holds composition — scoped review

Candidate: feature/stock-batch-holds-demo, stacked on Count case head 0e9349b70cd158d67b0c66e4d111a04c1f39c59e. No backend, permissions, schema, lock/version/retry, or quantity policy change.

The real 25-per-page branch projection now appears as a responsive worklist with batch/SKU, expiry, separately labelled global Batch status and Branch hold, existing authorized actions, server search/page totals, and contextual safety guidance. It supplies no invented quantities, locations, held totals or demo-only filters. The original reason options, mutation handlers, stale errors, independent-release rule and payloads remain intact. Dialog closing remembers the original batch identity and returns focus to its surviving action, row or named list, respecting branch/outside-focus changes.

Evidence: types/full lint/full resource format passed; original backend BatchHoldTest and TransferEligibilityTest 19/203 passed. New focused row/focus tests and existing hold checks 9/9; affected original D010 plus new component 11/11. D010 required a real-child harness import only, independently cleared by Staff; all old assertions unchanged. Final full frontend/build/browser results will be recorded after completion.

This slice covers the separate Batch safety holds page. Unified Stock tab navigation, held-filter read models and unrelated recall/writeoff screens are not certified here. Synthetic own preview3136/database kpone_stock_batch_holds_test; no production or real data.

Final builder evidence: full frontend2245/2245, production build15.08s, all previous static/backend gates passed. Actual UI place/release/competing place/stale refusal/independent release completed using three synthetic actors. Close focus after Place correctly landed on original LI when Release absent; after Release it landed on same-batch Place button. All four390/1440 light/dark views have no overflow or JS/font errors and measured owned controls>=44/36. Before/after stock snapshots exactly match (20 movements, every balance unchanged). No raw quantities are added to holds projection.

Supplement: empty search0rows/no errors; all four mobile dialog controls measure44px. hold-actions.json records only four successful transitions (actors5/7/7/2, versions1–4); rejected stale request did not append an action.

Capture correction: stale refusal dialog was light after page reload reset the locally forced theme; renamed stale-390-light.png. It is evidence of light stale/error behavior, not dark dialog contrast. Separate place-dialog-390-dark.png is the explicitly forced dark dialog capture. Held page four-theme matrix remains correct.
