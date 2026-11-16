# V2 HR Memos (feature/v2-hr-memos)

Synthetic data only, `.test` account, no real files (the memo text and audience count are stubbed responses; no PDF is loaded). Shots at 1440 and 390 px, light and dark: `my` (a memo with a PDF still to open, one to read, one read, replaced, closed), `myOpened` (the text shown after Open), `myEmpty`, `manage` (All memos), `manageDetails` (Who hasn't read, with a long name), `manageEmpty`, `publish` (dialog, empty, Publish faded with its reason, audience in words with a count) and `withdraw` (red confirm with a reason). No horizontal scroll and no external request.

Classic compare: Classic Memos built from main and from the branch, same props, 32 states (the same eight at 390 and 1440, light and dark), 0 differing pixels.
