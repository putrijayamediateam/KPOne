# CL2-HOME-TILES shots, round 2 (evidence only)

PR #1295, head `8e71f5e14` (tiles fill the row and hide at 0; still not links). My day as the demo Director on a task-owned PostgreSQL 18 database, at 1440 light, 1440 dark and 390 light, no sideways scroll at any size (measured).

- `populated-*`: all three tiles (Waiting for my approval 3, Next things 3, Credentials due 1).
- `zero-*`: all counts at 0, so no tile row is shown.

**These are mocked-props shots.** The demo data has no approvals, next things or due credentials, so the page's own props were replaced in the browser (Playwright rewrites the `my-day` page's Inertia JSON before it renders; the server and database are untouched, and the 15-second poll was blocked). The three tiles, their layout and the cards that read the same props are real renders; the numbers and names are invented.

The first round's shots (before this change) are in the parent folder.
