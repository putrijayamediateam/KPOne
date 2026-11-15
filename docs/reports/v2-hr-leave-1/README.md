# V2 HR Leave, PR 1: shots

From the real Version 2 shell, with synthetic `.test` data, branch `feature/v2-hr-leave-1`. Each shot is at 390 and 1440 px, in light and dark. No page scrolls sideways (the page's scroll width equals the viewport in every shot).

- `my-leave-*`: balances ("1 day left", "of 8 · 0 taken · 7 booked"; maternity and paternity say "No yearly balance") and My requests.
- `apply-error-*`: the apply sheet after Send request with no leave type.
- `apply-counting-*`: the working-days line while the server is slow (the check was held for 4 s): "Counting…".
- `approvals-*`: approval cards with "Left after approval", a long reason, then Approved leave.
- `reject-dialog-*`: Reject with reasons empty and the filled Reject switched off until one is picked.
- `calendar-*`: November at Cheras: weekends shaded with "Sat"/"Sun", 9 Nov shaded with "Hol", and cells "•" or "½" with spoken words.

Not taken: the evidence sheet with files (no synthetic file was uploaded), and Settings (unchanged in PR 1; it is PR 2).
