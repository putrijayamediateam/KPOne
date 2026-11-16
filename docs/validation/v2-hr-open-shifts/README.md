# V2 HR Open shifts (feature/v2-hr-openshifts)

Synthetic data only, `.test` account, stubbed props; nothing is posted. Shots at 1440 and 390 px, light and dark, 11 states: a locum's `available`, `availableEmpty`, `notLinked` and `mine`; a manager's `open` (several asks, an own ask, a started shift), `closed` (Needs posting again), `openEmpty`; and the dialogs `post`, `confirm`, `decline` (reason empty, red) and `withdraw` (red). No horizontal scroll and no external request.

Classic compare: Classic Open shifts built from main and from the branch, same props, 44 states (the same eleven at 390 and 1440, light and dark), 0 differing pixels.

After Daniel's look: Decline on each ask row is a plain outline button (red stays for Withdraw shift and the filled Decline in the dialog); a started shift shows a neutral "Started" chip; Confirm and Decline stay right-aligned with the reason sentence below them. The grey "SUNGAI BESI" chip in the first post shot was hover left over from the capture; the retake moves the pointer away and shows none. All V2 shots retaken. Classic compare unchanged (PR #889).
