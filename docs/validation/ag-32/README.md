# AG-32: Approval inbox "Approve and create ..." (screenshots)

Evidence for the Lead UI/UX Designer's look at branch `feature/ag-32-inbox-prepare-buttons` (head 1383d27f). **Synthetic data only.**

- Task-owned PostgreSQL database `kpone_ag32demo_testing` (127.0.0.1:55418), local app on 127.0.0.1:8132 with explicit environment variables (never the real `.env`).
- `agents.prepare_enabled` was **on** for these shots. With it off (the default) the inbox looks exactly as before.
- The Inventory preparer (step 3) is not on `main` yet, so these shots use a throwaway stand-in preparer that creates nothing. The words "SR-000123" and "PR-000045" are fake numbers.
- Users: `demo.ca_supervisor` (can prepare), `demo.director` (can decide but not prepare), `demo.ca_supervisor2` (left in the Classic layout).

Files (`<width>-<theme>`, widths 1440 and 390, light and dark):

| File | What it shows |
|---|---|
| `v2-pending-*` | V2 inbox: the notice that changes only while something can prepare, three pending suggestions |
| `v2-approve-restock-*` | Restock sheet: the two choices, the open-order warning, the confirm button naming the action |
| `v2-approve-transfer-*` | Transfer sheet: quantity (down only), sending and receiving location |
| `v2-approve-transfer-decision-only-*` | The same sheet after choosing "Approve (decision only)" |
| `v2-approve-cannot-prepare-*` | The Director: "Approve and create" is off with the reason, decision-only is chosen |
| `v2-approved-created-*` | Approved tab: "Purchase request PR-000045 created" |
| `classic-pending-*`, `classic-approve-transfer-*` | The same on the Classic Inbox (1440 light, 390 dark only) |

Not shown: the receiving branch's read-only "Transfer suggested from <branch>, decided there" line (planned as 4c, a Daily summary change).
