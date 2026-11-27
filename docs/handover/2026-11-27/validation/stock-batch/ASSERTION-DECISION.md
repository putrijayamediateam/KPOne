# Faithful extraction checks under delegated technical approval

Before edits, the focused30-test suite had exactly two failures because hold-label rendering moved from Index.vue to StockBatchList.vue. No behavior assertion was wrong or removed.

1. classic-only-co6-kept-direct: add the extracted Batch component to the source set checked for both hold-label calls. Keep the same count threshold, route, query order, filters, and permission assertions.
2. ui-leftovers-1 held fallback test: read the Batch component instead of Index for its batch-status held check, combine it with On-hand for the same exact two reason-formatter calls, and retain all null/undefined/known-reason behavior assertions and prohibition on String(holdReason).

These are delegated technical source-location repairs within the approved composition scope, not new owner tickbox approvals and not weaker tests. New rendered Batch tests separately prove expired+held coexistence, zero/fractional quantity, unmapped/null received fallback, expansion reset and stale-loading removal. Independent review and final full suite remain required.

The first full suite found two additional location-only pins (2212/2214 passed):

3. classic-only-co6-kept-v2-inventory-home quantity test now checks the exact quantity+unit interpolation in BOTH extracted balance components and checks both remain mounted in Index; all16 task links/permission guards remain untouched.
4. ui-polish-1 D010 formatter-use list replaces Index with BOTH On-hand and Batch components. Every reason-code label, null/undefined/unknown fallback, reference panel and dispensary check remains intact.

Final four-file proposal is complete from the full scan; rerun full suite and independent review on the frozen source. Seven new rendered tests additionally cover focus surviving reorder, fallback on removal/loading, and avoiding a pending restoration stealing focus moved outside.
