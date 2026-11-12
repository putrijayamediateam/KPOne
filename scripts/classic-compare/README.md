# Classic look check

Version 2 must never change what Classic users see. This re-runs the same shots on two builds and compares them
pixel by pixel. Run it after every V2 step.

1. **Baseline.** Build main with the restyle files put back to how they were before UI-RESTYLE (`app.css`,
   `app.blade.php`, the top bar and branch switcher, `app.ts`; see PR #597), or, once V2 is the baseline, the build
   before the step.
2. **Shoot.** On a task-owned database with the demo accounts (never a real `.env`), start the app, then:
   `BASE=http://127.0.0.1:8732 PLAYWRIGHT=<path to playwright/index.mjs, only if it is not installed in this project> node scripts/classic-compare/shoot.mjs base`
   Switch to the branch build and shoot again with tag `branch`.
3. **Compare.** `python3 scripts/classic-compare/compare.py shots-base shots-branch`

The clock is frozen (`2026-11-12 10:00 +08`) so live counters such as "Waiting N min" cannot differ. The shots are CA
and Director × 1440 and 390 × light and dark: the Workspace, Registration, Queue, Pricing, Quick create and Billing
work pages, the Registration row menu, the Cancel Visit confirm dialog and the Register Visit sticky card.
