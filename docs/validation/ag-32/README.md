# AG-32: Approval inbox "Approve and create ..." (screenshots, second round)

Evidence for the Lead UI/UX Designer's second look at branch `feature/ag-32-inbox-prepare-buttons` (head c2bbebb8f, main merged). **Synthetic data only.**

- Task-owned PostgreSQL database `kpone_ag32demo_testing` (127.0.0.1:55418), local app on 127.0.0.1:8132 with explicit environment variables (never the real `.env`).
- `agents.prepare_enabled` was **on** for these shots. With it off (the default) the inbox looks exactly as before.
- **The real Inventory preparer (step 3, PR #974) is used.** The purchase request numbers on the Approved tab are real (`PR-202611-...`). The demo reorder levels for the two restock items were set so the restock is genuinely due.
- **One difference from main:** the open-order warning shots use the preparer with `WarnsBeforePreparing` added in the preview copy only. Zulkifli's small follow-up PR adds the same two lines (`warning()` returns `openOrderWarning()`); until it merges, the warning does not show on main.
- Users: `demo.ca_supervisor` (can prepare), `demo.director` (can decide but not prepare), `demo.ca_supervisor2` (left in the Classic layout).

Files (`<width>-<theme>`, widths 1440 and 390, light and dark unless noted):

| File | What it shows |
|---|---|
| `v2-pending-*` | V2 inbox: the notice that changes only while something can prepare, three pending suggestions |
| `v2-approve-restock-*` | Restock sheet: two full-width choices with a tick, the confirm button naming the action |
| `v2-approve-transfer-*` | Transfer sheet: quantity (down only, with its unit), sending and receiving location |
| `v2-approve-transfer-decision-only-*` | The same sheet after choosing "Approve (decision only)" |
| `v2-approve-cannot-prepare-*` | The Director: the unavailable choice stays readable ("Not available") with the real reason directly under it |
| `v2-approved-created-1440-light`, `-390-dark` | Approved tab after two real approvals: the intro matches (no "nothing changed" text) and each card names its purchase request |
| `v2-approve-open-order-warning-1440-light`, `-390-dark` | Restock sheet with the open-order warning above the action |
| `classic-pending-*`, `classic-approve-transfer-*` | The same on the Classic Inbox (1440 light, 390 dark only) |
| `classic-approve-open-order-warning-1440-light`, `-390-dark` | Classic restock sheet with the open-order warning |

Not shown: the receiving branch's read-only "Transfer suggested from <branch>, decided there" line (planned as 4c, a Daily summary change). The two approved cards have different numbers; the 390 pass re-approves the same two suggestions and gets the same two requests back (retry-safe).
