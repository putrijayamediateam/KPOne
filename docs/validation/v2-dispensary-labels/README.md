# V2 drug label page: screen part only (validation shots)

Synthetic `.test` data on a task-owned preview database. Branch under review: `feature/v2-dispensary-labels-screen`.

- `v2-<state>-<width>-<theme>.png`: the V2 screen card at 390 and 1440 px, light and dark. States:
  - normal, reprint, many;
  - the four off states with their reasons: changed (branch switched), retired (the print check refused; the alert has focus), blocked, empty;
  - busy ("Checking saved record…").
- `pdf-classic-*.png` and `pdf-v2-*.png`: page.pdf output, rasterised at 300 dpi.
- `classicmain-*.png`: the Classic page from `main`, for comparison.

| State | Print-media screenshot | PDF at 300 dpi |
|---|---|---|
| normal | 0 px differ | 1 vs 1 page, 0 px differ |
| reprint | 0 px differ | 1 vs 1 page, 0 px differ |
| many (3 labels) | 0 px differ | 3 vs 3 pages, 0 px differ |
