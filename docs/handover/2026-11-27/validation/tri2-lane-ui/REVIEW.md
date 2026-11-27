# TRI2b emergency lane UI — 26 November 2026

Adds the separately scoped emergency lane above the ordinary Queue and Registration patient boards. It includes today's and carry-over waiting emergencies supplied by the backend and has independent 25-row pagination. Ordinary filters and pages do not hide emergencies. The backend excludes every emergency from ordinary rows/counts, including emergencies on another lane page.

## Ownership and contract

Source: `/workspace/KPOne-tri2-ui`. Baseline originals/hashes captured before these changes in `baseline/` and `baseline-manifest.json`. Overlay is `TRI2b-UI.patch`; final hashes in `source-manifest.json`. Current patch SHA256: `e2d68d2b37405c69527cced5eed4deafc5ee3ff0ad71cc070d7b7d23c2054d18` (14 files). Coordinator already integrated the earlier13-file patch: apply only `TRI2b-UI-FOLLOWUP.patch`, SHA256 `d4d050dd05a5197b0ab8b7d63f86ef33a31eb6121a393305fa6f228a20a0084b`, to that integrated state. Apply this overlay only, not the entire tree's diff: this tree also contains the separately owned shared alert UI.

Queue owns the backend contract and tests. The optional `emergency` snapshot contains `data,total,currentPage,lastPage,perPage,hasMore`; `data[].boardRow` is the exact existing QueueRow/VisitRow authorized projection. Both POST search routes accept independent `emergency_page`. Backend clamps disappearing/out-of-range pages and excludes all eligible emergency IDs, not only the displayed page. No domain/migration/permission code is included in this UI overlay.

## UI behavior and design

Both pages factor their existing row mapping into a single `toBoardRow`; ordinary and emergency rows use that same function. Existing handlers, original source object/lock versions, readiness guards and row permissions remain intact. No client-side emergency privilege inference or duplicate exclusion is added. The cross-doctor shared alert remains number-only and is untouched.

`EmergencyLane` wraps the real existing PatientBoard. It shows a descriptive Emergency heading with icon/red border, total, and separate Previous/Next controls. Normal filters/pages are retained when changing lane page; lane page is retained when ordinary filters/polls change. Missing optional lane data stays compatible with older snapshots. The ordinary board's existing paused/error handling still reports refresh failures.

Patient name, queue number, assigned doctor and waiting duration remain in their existing columns. Optional row metadata distinguishes Alert observed at (activation) from Alert recorded at (clinical), uses the explicit branch timezone, and shows Taken by/time or Not taken. Lane-only desktop widths allocate19% to duration/alert and7% to payment instead of8%/18%; ordinary board columns are unaffected. Responsive existing board cards remain in use.

## Validation

- New runtime tests8/8 pass: execute both real page mappers, preserve source/version/readiness/permissions, separate timestamp provenance, render real lane pagination and block busy/last-page navigation. A real Registration search invocation proves lane paging preserves applied filters/ordinary page, retains an unfinished draft, adopts the server-clamped lane page and uses it for the next poll.
- Final full frontend suite with `PATH=/workspace/kpone-tools/bin:$PATH`: **2260/2260 passed**, zero skipped (`frontend-final.log`). Includes approved co4 adaptation and new manual/automatic failure regressions. Earlier PHP PATH and source-order failures were resolved without weakening assertions.
- Four existing Registration test import maps and treatment mapper whitespace locator were adapted with coordinator authorization; behavioral assertions untouched. Owner approved the exact co4 assertion adaptation on26November; applied only that remaining hunk. The proposal remains recorded in `EXISTING-TEST-ADAPTATIONS-PROPOSED.patch`.
- Typecheck and production build passed. Scoped ESLint, formatting and patch whitespace checks recorded in local logs. Final build follows the lane-only width adjustment.
- Real component browser at390/768/1440 light/dark:6/6 combinations with no page overflow or JavaScript errors, observed/recorded/taken metadata, existing menu Call In row emission, unavailable Call In absent, next/back navigation. `browser/results.json` and six PNGs. This is actual EmergencyLane+PatientBoard with synthetic props, not a Laravel endpoint/browser mutation; two illustrative page1 rows and one page2 row represent a26-row total. Backend pagination/authority is separately tested by Queue.

## Review and integration limits

Staff independent visual/source review requested; Inventory follow-up review covers the manual-failure fix. The full Laravel combined smoke and final integrated gates belong to the coordinator. The production build passed before the final one-line manual-failure reset; that reset is covered by the final full frontend suite, and the coordinator should rebuild integrated assets. No commit, push or PR was created. Inventory identified stale emergency identities/actions after manual Registration request failure. Fixed `clearBoard` to clear emergency rows as well, with two new runtime regressions distinguishing manual clearing from automatic paused/last-known behavior. The existing tab-counts assertion remains unchanged by keeping its original first reset statement.

Suggested commit: `feat(queue): show independently paginated emergency lanes`

Suggested PR body:

> Show waiting emergency patients above the ordinary Queue and Registration boards, including carry-over entries, using the backend's existing authorized board-row projections. Independent pagination keeps every emergency reachable while ordinary filters/pages remain intact. Reuse existing mapping/actions/readiness guards and distinguish waiting duration from alert observation/recording and taking times.
>
> Validation: new runtime8, full frontend scan with owner-approved existing-assertion adaptation, types/static/build and six responsive component browser states. Requires the separately reviewed TRI2b backend lane/pagination contract; no permissions or migrations in this slice.
