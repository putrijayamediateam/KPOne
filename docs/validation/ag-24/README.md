# AG-24: outline Close on info-only sheets

Code: branch `feature/ag-24-outline-close`. Uses `V2Dialog`'s `singleActionStyle="outline"` (PR #769).

Shots (`office-close-*` and `inbox-refused-*`, 1440 and 390 px, light and dark): the agent office detail dialog and the Approval inbox sheet shown after a refused decision (made with a real stale decision: the proposal changed while the sheet was open). Each has one outline Close and nothing else filled.

Measured: footer Close is white (light) or the card colour (dark) with a border, 36 px on desktop and 44 px on phones. The Approve and Reject confirm sheet is not a single-action sheet, so it keeps its filled button.
