# Delegated technical harness adaptation

The initial full frontend run found D010's English hold-reason assertion failing because its generic UI stub rendered only the default slot of the newly extracted StockBatchHoldList. The real page still passes the original status/reason content through scoped row slots.

The harness now compiles the actual StockBatchHoldList SFC for that one import. Every existing assertion remains unchanged, including readable English, no exposed raw reason in visible text, unchanged option value, and deep equality of the input payload. This is a faithful test harness adaptation under the owner's delegated technical authority, not a new owner tick-box approval or reduced behavior contract. Targeted D010 plus new hold tests: 11/11 passed. Final full run and independent review follow separately.

Staff/Design independently reviewed the concrete change and cleared it: the actual child resolves before the generic stub and all expectations remain intact. Targeted 11/11 includes both affected old file and new component tests. One resumed-shell full run failed only because php was missing from PATH; corrected runtime PATH for final full run, with no source workaround.
