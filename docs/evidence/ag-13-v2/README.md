# AG-13 office control room: Version 2 shots and the Classic compare

Evidence only, never merged. Branch head under test: `a1d4715f` (PR #576). Synthetic demo data only (six AI agents).

- `v2/`: the agent office with "Try the new layout" on. `office-<width>-<light|dark>-page.jpg` is the whole page; `-panel.jpg` has an agent panel open. Widths 1440 and 390 px.
- `classic-main/` and `classic-branch/`: the same page in Classic, built from `main` and from the branch. Compared pixel by pixel, they differ only where the scene animates (at most 53 pixels off by more than 24/255 at 1440 px, 21 at 390 px). Two runs of `main` against each other differ by the same amount (100 and 24 pixels), so the difference is animation, not the change.

The gate: `useUiVersion` in `Office.vue`, `OfficeScene.vue` and `AgentFigure.vue`; `tests/Frontend/ag-13-office-version-gate.test.mjs` pins it.

- `v2-round2/`: the same V2 shots after Daniel's changes (head under test: see PR #576): plain-word steps and branch names in the panel, bordered AI-agent notice, 44 px close button at 390 px.
