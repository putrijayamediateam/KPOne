# PNL-30 continuation — 26 November 2026

Coordinator: B.E.R.N.I.S.S. Implementer/reviewer: Codex billing_panel (not historical Kumar/Priya signoff).

## Slice plan and specification

Recover PR #1124 head `143ff10d4226cd03fa34fee69d07eb6afeaf1d0d` as a working-tree diff on current main `23639679e` in `/workspace/KPOne-panel30`, branch `validation/pnl-30-current`. Preserve original approval transactions and existing tests. Verify and repair two edge cases: a proposer submitting only missing IDs must receive 403 without a batch audit; UUID case variants must be rejected as duplicates before any approval. Clear the selection after a partially skipped response as the accepted design specifies. No migration, role grant or existing assertion change.

Files: `PanelPriceChangesPage.php` waiting limit; `PanelPriceProposalService.php` bounded bulk operation/authorization/UUID validation; `PanelPriceChangesController.php` count responses; `routes/web.php` endpoint; `resources/js/pages/PanelPriceChanges/Index.vue` selection/confirmation; additive backend and frontend tests; design and continuation notes.

Risks: partial approvals are intentionally committed individually; permission revocation stops later work without rolling back earlier approvals. Existing per-item lock, organisation, branch, four-eyes and price-version boundaries must remain. Final batch audit must itself require fresh approval authority. Browser/proxy timeout remains a bounded-batch operational constraint. Daniel's visual review is still pending.

## Review and validation status

Original PR CI succeeded (run 45586433123), but head lacks current main and needs fresh exact-candidate checks. Formal GitHub reviews list is empty; historical Priya review/fix is documented in the PR body. Focused PostgreSQL test run is in progress on isolated `kpone_panel30_test`; full suites are coordinated separately to avoid contention. Regression results, commands and exact patch will be recorded when complete.

## Adjacent queue

BIL-36 #1112 head `a0faad9b39611af86303b1cf89ec8d4e76d335cf` and BIL-37 #1113 head `29d2e2c0728f909fc3d57f5f2925d5fa02b083bf` both lack current main. Their latest CI failed at integration attestation (runs 45541247453 / 45541254432); static/backend jobs were skipped. Historical owner/Kumar/Daniel acknowledgements are in PR bodies, not formal GitHub review records. Historical backend runs report 140 missing-GD errors, so cannot be called green. Next bounded task: refresh each independently, inspect attestation requirement and run focused tests plus exact-candidate gate with GD installed. No merge readiness claimed.

Suggested commit after review: `feat(panel): approve selected prices with guarded bulk approval`.
Suggested PR: `PNL-30: approve selected Panel price changes` — lets Finance/Director approve up to 200 eligible proposals, preserving per-proposal authority, four-eyes, version checks and audits; reports skipped rows and counts. Include refreshed validation and unresolved visual review explicitly.

## Reproduced findings and correction

The first focused run included the two new regression tests: 10 tests, 8 passed, 2 failed, 43 assertions. The proposer received 302 instead of 403 for absent IDs; a case-variant duplicate was accepted. Both defects are fixed using existing authority checks and case-insensitive duplicate validation. The batch audit also refreshes authority under lock. Partial skips clear selection through `onError(errors.bulk)`; other validation errors keep the user's selection.

Five focused frontend tests pass. Pint, focused Prettier and `git diff --check` pass. Corrected backend results pending below. The full repository gate is not claimed; coordinator schedules it serially after focused team checks.

Commands (task-local tools, synthetic PostgreSQL only):

```sh
PATH=/workspace/kpone-tools/bin:$PATH APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_USERNAME=agent DB_PASSWORD= DB_DATABASE=kpone_panel30_test DB_SSLMODE=disable php artisan test --compact tests/Feature/Billing/PanelPriceBulkApproveTest.php
PATH=/workspace/kpone-tools/bin:$PATH php vendor/bin/pint app/Domain/Visit/Billing/Services/PanelPriceProposalService.php tests/Feature/Billing/PanelPriceBulkApproveTest.php
node --test tests/Frontend/pnl-30-bulk-approve.test.mjs
node_modules/.bin/prettier --check resources/js/pages/PanelPriceChanges/Index.vue tests/Frontend/pnl-30-bulk-approve.test.mjs
git diff --check
```

Logs: `/tmp/kpone-panel30-baseline.log`, `/tmp/kpone-panel30-fixed.log`, `/tmp/kpone-panel30-frontend.log`, `/tmp/kpone-panel30-pint.log`, `/tmp/kpone-panel30-format.log`.

Self-review: no existing assertions, roles, permission catalogue or staff authority code changed. Preflight checks prevent unauthorized batch reads/audits, while per-item checks preserve revocation and branch freshness throughout the deliberately non-atomic batch. Duplicate validation precedes every approval. Full type/static/build/migration checks and visual approval remain outstanding; focused source tests alone do not prove browser behavior.

Validation note: the first post-fix run had 9 passing tests and one fixture error because normal model saving correctly refuses mutation of an existing proposal. A query-builder fixture attempt was also correctly refused by the PostgreSQL immutability trigger. The final fixture submits a fixed absent UUID in both cases; duplicates must be rejected before lookup, and an existing proposal is asserted unchanged. No record mutation or immutability bypass is needed. This changes no existing assertion or application behavior.

Exact review diff: `/workspace/kpone-review-bundles/PNL-30-current-main.patch`, generated from tracked changes plus new files relative to main `23639679e`. No commit, push, PR update or merge has been performed.

Final focused PostgreSQL result: **10/10 tests passed, 45 assertions**, zero failures/errors (`/tmp/kpone-panel30-fixed.log`). Final focused frontend: **5/5 passed**. Final Pint and diff checks pass. Candidate is ready for coordinator's independent diff review and scheduled full gate; visual approval is still outstanding.

## Independent wording review and approved correction

Codex dispensary identified that missing/already-decided skipped entries are not necessarily waiting. Owner explicitly approved the neutral response/confirmation and the one pinned route assertion change on 26 November 2026. Applied exactly as documented in `PNL-30-WORDING-APPROVAL.md`; added route coverage for missing and rejected proposals. Final focused rerun: **11/11 PostgreSQL tests, 50 assertions**, frontend **5/5**, focused Pint/Prettier/diff checks pass. Log `/tmp/kpone-panel30-approved-wording.log`. Original authority/locking behavior remains unchanged.

Independent reviewer Codex dispensary confirmed the neutral wording and closed the finding; no further actionable findings in scoped source review. Final implementation still awaits the coordinated full gate and visual approval.

## Integrated worker timeout follow-up

The combined full suite exposed a leaked process timer: approveSelected unconditionally called set_time_limit(180), changing unlimited CLI workers to180 seconds and later terminating unrelated Attendance coverage. Three new regression methods reproduced this exactly (0→180, and60 not restored after both success and validation failure). The controller now modifies only an already-bounded timeout and restores its original value in finally. Unlimited0 stays untouched; financial service/permission/approval behavior is unchanged. Existing assertions remain unchanged.

Focused14 tests/62 assertions pass; before/after logs `/tmp/kpone-panel30-timeout-before.log` and `/tmp/kpone-panel30-timeout-after.log`. Scoped Pint and diff check pass. Exact two-file overlay `/workspace/kpone-review-bundles/PNL30-timeout-followup.patch` checks clean on the combined tree; SHA2564fb97c175bdb9e6673d06b9868c7f09f491a543dcd5f8abcd5edecb7d4e5706b. Coordinator owns integration and full rerun; independent review requested.
