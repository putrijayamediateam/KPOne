# AG-33 (4c): receiving branch's read-only transfer line on the Daily summary (screenshots)

Evidence for the Lead UI/UX Designer's look at branch `feature/ag-33-transfer-line-receiving-branch` (head d34117034). **Synthetic data only.**

- Task-owned PostgreSQL database `kpone_ag32demo_testing`, local app on 127.0.0.1:8132 with explicit environment variables (never the real `.env`).
- The Daily summary was built by the real command (`agents:daily-run 0700 --branch=PUCHONG`). The demo transfer suggestion is held by Cheras (the sender) and was suggested by Hana's run at Puchong (the receiver), as the contract describes.
- Viewer: `demo.director` (sees every branch). Puchong's card was opened.

What it shows: under Hana's counts, one muted read-only line per sending branch: "Transfer suggested from Cheras, decided there. 1 made, 1 waiting for a decision there." No button or link on the line itself; the existing "Open the approval inbox" button is unchanged and still lists the viewer's active branch.

| File | What it shows |
|---|---|
| `v2-daily-summary-transfer-from-1440-light`, `-390-dark` | V2: the Stock suggestions block of Puchong |
| `v2-daily-summary-puchong-open-*` | V2: the whole page with Puchong opened |
| `classic-daily-summary-transfer-from-1440-light`, `-390-dark` | Classic: the same block |

The sending branch's own summary gets nothing extra (it decides in its inbox). A branch with no incoming transfers shows no line.
