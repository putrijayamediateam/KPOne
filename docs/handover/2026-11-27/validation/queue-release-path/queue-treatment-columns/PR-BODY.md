Own Treatment updates now separates Treatment and Result columns. Each planned step stays paired with its exact recorded procedure, outcome and reason, including corrected procedure labels. Missing or withheld outcomes remain explicitly unavailable; finished steps never imply a successful outcome or physical return.

Stacked on #1233. Only this own task opts in. The existing badge retains its warning tone but hides duplicate outcome text and ARIA in this view; other consumers default to their original complete badge. Safety metadata, controls, the single treatment dialog and existing action handlers remain. No backend, permission, migration or clinical command changes.

Validation: full frontend2314, types/build/lint/format,30 focused tests and independent12 actual component tests pass. Four real synthetic desktop/mobile states verify own Result/default branch placement, single unsuccessful outcome and44px focused action targets. Eight clinical/stock tables remain unchanged after reads. Corrected procedure, multiple waiting and withheld cases have actual SSR coverage.

This is a bounded presentation slice. Done timestamps/current destination and Medicine Items/Sent by remain separate contracts. Parent remote CI remains an independent release gate. No merge or production activation requested; archive #1142 must never be merged.
