# AG-25: refused decision sheet title

Code: branch `feature/ag-25-tidy`. The Approval inbox sheet after a refused decision now reads "Could not record your decision" with the existing message ("Someone has already decided this, or it changed meanwhile. The list was updated."), instead of "Approve this restock suggestion?" and "This records your decision. It does not change stock." above it.

Shots: `inbox-refused-{1440,390}-{light,dark}.jpg`, made with a real refusal (the proposal changed while the sheet was open). The Close button is filled in these shots only because this build did not include PR #783 (the outline Close); after #783 merges it is the outline Close.

The Approve and Reject sheets are unchanged. The other half of this tidy (the office uses the shared `titleCaseCode`) has no visible change.
