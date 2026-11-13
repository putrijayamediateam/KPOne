# V2 checklist — Inventory home (`docs/design/V2-CHECKLIST.md`, PR #644)

## Layout
- [x] No cobalt filled button on the page (it has no main action); Apply, Clear, More filters and Filters are outline. The only filled button is Apply inside the phone sheet (`V2Dialog`). No destructive action.
- [x] Gutter 16 px / 24 px through `PageFrame`; no sideways scroll at 320, 390, 768, 1024, 1280, 1440 (measured).
- [x] `V2WorkLayout` title, one description line, crumb through `usePageCrumbs`.
- [x] No rail on this page.
- [x] Tables become cards below 1280 px (`cards="xl"`); no Actions column.
- [x] Normal phone card ≤ 250 px (212 / 212 / 241 px median); the long-name row is 260 px. Label and value in two columns, values right-aligned; the medicine line shows only when the SKU is mapped.
- [x] No danger card on this page.
- [x] No sticky action bar on this page.
- [x] Phone: search plus "Filters (n)" opening a sheet with every filter, Reset and Apply.

## Controls
- [x] 44 px below 1024, 36 px from 1024 (task links, buttons, tabs, toolbar). Exception: the Classic location picker inside the filters keeps its own sizes until it is converted.
- [x] Visible labels in sentence case in the More filters panel and the sheet. The toolbar search uses the shared `V2Toolbar` hidden label, as Registration does.
- [x] No native select or file input.
- [x] No optional form fields on this page.
- [x] No row menu on this page.
- [x] No save button on this page.
- [x] The only dialog is the `V2Dialog` filter sheet (bottom sheet below 640 px).

## Words and numbers
- [x] No system text: "Kuala Lumpur time" instead of the zone name; dates as "31 Dec 2028" and "13 Nov 2026, 12:39 AM"; movement types in sentence case.
- [x] Status is `V2Chip` (dot plus word). No waiting times.
- [x] Chips sit in no-wrap spans; long text wraps on its own line.
- [x] Each help sentence once ("Movement records are read-only.").
- [x] English, as the Classic Inventory screen.

## Accessibility
- [x] Shared tokens and V2 blocks only (no new colours); focus rings from the blocks.
- [x] Keyboard: tabs (arrow keys), disclosure bars, sheet (focus trap and Esc from `V2Dialog`).
- [x] No new motion.
- [x] No icon-only controls added; filter errors use the Classic `ErrorState`.
