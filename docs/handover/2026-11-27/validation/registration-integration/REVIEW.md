# Registration + Emergency integration — 26 November 2026

Snapshot `/workspace/KPOne-registration-preview`, detached base23639679e, existing frozen Stage0 plus Registration recovery and approved emergency-contact feature. Latest root PageHeader duplicate-branch correction copied before final build. Original standalone worktrees are preserved.

## Export and reconciliation

`registration-emergency-overlay.patch` is an **incremental overlay against the current frozen root working tree**, not against clean main: it intentionally excludes Stage0 changes already in root. `source-manifest.json` records original-root and final-snapshot SHA256 for each of27files. Overlay SHA256 `5da039c4c42dab620cb078ddb8eb7d041ae31357053f8b836bb09b858af2926f`. `git apply --check` against `/workspace/KPOne` passed; no patch applied to root.

`Create.vue`: recovery light/dark cards, field tokens and mobile sizing remain; feature adds contact imports/props/command state, resets on patient selection, unfinished-editor submit guard and the shared contact section between patient and visit sections. No template/style rollback.

`Index.vue`: root query-tab selection and immediate tab load preserved together with visible heading/restyled board and44px mobile select minimum. `Show.vue` retains conditional Edit-outline/Send-to-Waiting primary. Country-code trigger remains its previously pinned40px exact class (no additional assertion permission).

Approved five assertion groups are present. New emergency tests/routes/migration are included. Original source worktrees and standalone emergency patch at `../emergency-contact/standalone-feature.patch` remain intact. No commits/push/PR.

## Validation

- ICU-enabled PG18.1 resumed on55439; approved migration passed on owned `kpone_registration_test` preview database.
- Focused Patient/Visit backend35tests194assertions passed using separate owned `kpone_emergency_test`.
- Focused frontend35/35 passed.
- Production build passed with latest shared PageHeader.
- Exact integrated types check passed.
- Atomic Registration +Patientdetails save/remove browser run passed on3023: partial field errors, staged no-write, actual OTC registration persists contact, Patient details save/removal and retained clear version2.20screenshots1440/390 light/dark, no overflow or JavaScript errors.
- Standalone feature already passed actual first-create/replay contention4tests29assertions, scoped PHPStan, and lost-response changed-retry UI proof; integration does not change backend implementation.

Independent Staff/Design final integrated visual review passed requested staged390dark/errors390light/patienteditor390light states: no blocker. Evidence: ../emergency-contact/INDEPENDENT-UI-REVIEW.md. Full repository release gate remains with coordinator. No production data; all preview fixtures synthetic.

Source reconciliation check: integrated Create script is byte-identical to the standalone emergency feature script; integration changes its presentation template only.

Owner-approved I2 follow-up26Nov: exactly one assertion now expects `h-10 max-lg:min-h-11` for Create/Edit panel selects. Four scoped tests pass in standalone and combined trees; all other assertions unchanged. The initial full rerun exposed the snapshot's unsynchronised original Classic-shell test. Per coordinator instruction, all six exact Stage0 test files from `STAGE0-current-main.patch` were copied verbatim from frozen root; their hashes are in `stage0-test-baseline.json` and they remain excluded from the feature overlay. Focused I2+shell5/5 passed; full current-tree frontend **2193/2193 passed, zero skipped/failures**, in36s. No assertion was newly altered beyond the single owner-approved I2 line. Previous overlay preserved as `registration-emergency-overlay.before-i2.patch`.
