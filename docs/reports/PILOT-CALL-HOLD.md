# PILOT-CALL-HOLD — uncommitted local candidate

Date: 2026-10-27. Branch: `fix/pilot-call-hold`. Worktree: `/Users/klinikputrijaya/Herd/KPOne-fix-call-hold`.

Base: rebased onto canonical `origin/main`, `c9596ba009a5bd9f51286b5baf5f0694bfcd5a46` (PR #176). Original implementation base: `8732b5599aefe0becb07080c79716b64055d186c` (PR #175). The owner authorised commit, push and one PR on 2026-10-27; merge remains unauthorised. The served tree remains on main and has not been edited. No `.env` or `.env.preview` was opened or created.

## Owner's dropdown decision

The consultation dropdown remains count-only. Existing callers retain the exact `{branchId, own, branch}` response. The updated dropdown requests `include_readiness=1` and its own/branch count scope; that adds exactly one integer, `notReady`, using the existing authorised Waiting consultation cohort. A client cannot expand an own-only actor's scope. No patient identity, queue number, reason or row appears in this dropdown or its count response. No next-patient or new Call In control is added to consultation.

A separately scoped clear-event signal contains only an in-memory cursor and boolean for consultation. The clear signal is sampled before the count. A generic `A patient is ready to be called` toast requires both a new CA-clear signal and a falling aggregate in that poll; no pending signal is carried into later polls. Cancellation and calling cannot manufacture a clear event. Only queue boards request queue-number details, restricted to the assigned doctor's current Waiting entries in the effective branch. The optional chime persists only one boolean browser preference; event cursors, queue numbers and flags are not retained in browser storage.

Per-entry badges, reason text, disabled Call In and doctor override stay on the Registration/Queue boards (including the doctor's own queue). The initial count-only design stop is resolved by this owner decision; its existing contract tests remain untouched.

## English wording follow-up — owner decision 2026-10-27

All user-facing copy introduced by this slice uses English to match the surrounding screens. Existing copy, including the consultation recall button, is unchanged. No status, permission, reason code, payload or financial/clinical rule changed.

| Surface | English wording |
|---|---|
| Consultation dropdown | `3 waiting, 1 not ready to be called` (counts vary) |
| Generic ready toast | `A patient is ready to be called` |
| Board ready toast | `Ready to be called: A-003` (synthetic example) |
| Chime | `Notification sound: On` / `Notification sound: Off` |
| Active badge and server refusal | `Not ready to call: <reason>` |
| Retained overridden badge | `Overridden by doctor: <reason>` |
| Administrative reasons | `Awaiting Panel confirmation`; `Awaiting patient details`; `Awaiting payment or deposit`; `Other administrative` |
| Override reasons | `Patient is urgent`; `Issue resolved verbally`; `Patient is at the door` |
| CA controls | `Mark not ready`; `Ready to be called`; `Reason`; `Select a reason`; `Cancel`; `Confirm` |
| Doctor override control | `Override flag and call` |

Set/clear success messages, stale/retry/audit refusals, dialog titles/descriptions and validation helpers are also English. New tests pin the labels, notifications and preserved recall copy. Negative language-regression patterns are test guards, not UI wording. No existing test assertion changed.

### Follow-up validation and evidence

The exact workflow command sequence listed below was rerun in full using the same verified task-owned PG18.6 host/port, `kpone_call_hold_final_testing`, approved task-only 1 GiB PHP/Composer settings, and separate `kpone_call_hold_seed_testing`. **1,444 backend passes / 29,703 assertions / two existing skips**, 660.394 seconds; **492 frontend passes** (one additional English-copy test). Pint, PHPStan 7, frontend lint/format/types, build, separate fresh seed and diff checks all passed. Completed 2026-10-27T03:14:31Z. Logs: `/private/tmp/kpone-pilot-call-hold-evidence/english-ci-final/`. No existing assertion failed or changed.

Preliminary attempts are retained: the owned PG restart omitted its explicit port, could not bind the default port and connected to no other database; the intended task port correctly refused the connection. Restarted with explicit 127.0.0.1:58564 and verified the owned cluster. The private task wrapper now also checks that its connection log contains successful JSON, because the framework bootstrap error had exited zero. A separate attempt stopped at seven blank-line ESLint errors in the newly added English-copy test; fixed only that new test's formatting. Its first isolated run also needed a whitespace-aware label regex after Prettier wrapped the new English label. The complete final sequence above passed; no existing test was edited.

**40 fresh screenshots** at 390×844 and 1440×900 CSS px, light and dark: `/private/tmp/kpone-pilot-call-hold-evidence/english-screenshots/`. Manifest: `/private/tmp/kpone-pilot-call-hold-evidence/ENGLISH-EVIDENCE.md`. Measurements: `english-chrome-metrics.json`. Each width/theme includes `registration-badge`, `set-dialog`, `clear-dialog`, `queue-badge`, `disabled-call`, `override-dialog`, `dropdown`, `ready-toast` and `board-ready-toast`. Four extra 390-dark captures cover set validation, the selected payment/deposit reason, override validation and sound Off. Actual CSS viewport and document/body width checks passed; no sideways overflow. Longer helper text wraps and dialog buttons remain visible. Image pixel widths were checked separately with `sips`. All data is synthetic; evidence remains local.

The fresh consultation capture shows `3 waiting, 1 not ready to be called`, the identity-free English ready toast and unchanged existing recall button. Optional sound On and Off were exercised; no physical audio output claim is made. Old wording captures are retained only as historical private evidence and are superseded by this English set. Both owned preview servers and the PG18 cluster were stopped after capture; browser viewport was reset and task tabs closed. No tests ran with a preview live.


## Implementation

- Controlled Consultation-only readiness reasons; no free text or automatic Panel setting.
- CA set/clear dialogs and resident attending-doctor override dialog. Clear/override bind the observed flag id and version, preventing actions against a replacement flag.
- Canonical user/Visit/Queue locks, fresh authority, transaction, optimistic versions, UUID retry keys with payload conflict refusal, required values-free audit.
- Call In checks the active flag within its existing locked transaction; normal Call In retains the existing eligibility, room, doctor-busy and queue-order rules.
- Leaving Waiting closes an active flag with a system event. A model hook supports application audit-failure refusal; a database trigger covers raw status transitions.
- Immutable flag events, override/Serving witness and retry commands; PostgreSQL partial uniqueness and deferred evidence consistency checks.
- Private row projections only. TV and QR source/feed implementations are unchanged.

### Permissions

| Permission | Default holders |
|---|---|
| `queue.call_hold.set.branch` | CA, CA Supervisor |
| `queue.call_hold.clear.branch` | CA, CA Supervisor |
| `queue.call_hold.override.own` | Resident Doctor, attending own entry only |

All other catalogue roles receive none of these grants. Either set/clear permission permits clearing another CA's flag; audit includes setter and clearer ids. No staff-authority rule changes.

Migration: `2026_10_29_000100_create_queue_call_readiness.php`. Adds `queue_call_holds`, `queue_call_hold_events`, `queue_call_hold_overrides`, `queue_call_hold_commands`, checks/indexes/triggers and replay-safe missing-only grants. Missing roles are logged/skipped. `down()` retains records and grants.

## Changed existing assertions

**None.** No existing test file has been edited. Count-only tests and all existing staff authority, queue, TV and QR assertions remain unchanged.

## Initial validation — completed before English follow-up

Task-owned PostgreSQL **18.6**, **127.0.0.1:58564**, cluster `/private/tmp/kpone-call-hold-pg18`. All databases were created by this task with `ENCODING 'UTF8' TEMPLATE template0`. The completed CI replay used **kpone_call_hold_final_testing**; the separate fresh-seed database was **kpone_call_hold_seed_testing**. Other task-created disposable databases: `kpone_call_hold_testing`, `kpone_call_hold_feature_testing`, `kpone_call_hold_ci_testing`, `kpone_call_hold_ci_1g_testing`, `kpone_call_hold_preview_testing`. Effective host, port, name, version, encoding and cluster directory were verified before application use. No non-task-owned database was used.

Owner-approved task-only PHP CLI memory: **1 GiB**, also applied to Composer. Production limits unchanged. No `.env` copy was made: the workflow's placeholder configuration was supplied through explicit process environment variables instead, to honour the owner's prohibition. No `.env` or `.env.preview` was opened or created.

| Check | Result |
|---|---|
| Full backend, through `composer ci:check` | **1,444 passed / 29,703 assertions / two existing public-registration skips**, 661.537 seconds |
| New readiness tests, including real PostgreSQL contention | **20 passed / 321 assertions**, no skips |
| Full frontend | **491 passed**, no skips |
| Pint | Passed |
| PHPStan level 7 | Passed, zero errors |
| Frontend types, lint, format | Passed |
| Production build | Passed |
| Separate `migrate:fresh --seed --force` | Passed |
| `git diff --check` | Passed |

Completed CI-equivalent sequence, in workflow order: `composer install --no-interaction --prefer-dist --no-progress`; `npm ci`; `php artisan wayfinder:generate --with-form --no-interaction`; `node --test tests/Frontend/*.test.mjs`; `php artisan migrate --force`; `npm run build`; `composer ci:check`. Then the full `node --test tests/Frontend/*.mjs`, connection verification for the separate seed database, `php artisan migrate:fresh --seed --force`, and `git diff --check`. All passed. Logs: `/private/tmp/kpone-pilot-call-hold-evidence/completed-ci/`, completed at 2026-10-27T02:14:10Z.

Chrome found two issues confined to the new CA controls: a multiline button's fixed height clipped its label, and the generic dialog close icon was below the requested target size. The button now uses automatic height with a 44 px minimum; the CA dialog hides that icon and retains the 44 px Cancel button, Escape and overlay dismissal. After stopping both preview servers, the final markup-only changes and new frontend assertions passed types, lint, format, all **491 frontend tests**, build and diff again (`touch-*` logs). PHP and migrations were unchanged from the completed full CI run. No tests ran while a preview server was live.

### New test coverage

- Exact missing-only grants across every catalogue role; fresh CA and override revocation; foreign organisation/branch isolation and unknown-resource refusals. Permission middleware executes before Visit binding, so denied actors do not read Visit/Queue rows even for unknown route keys. Domain authority is checked freshly again under locks.
- Set/clear, cross-CA clear, stale queue and flag versions, duplicate active flag, retries/payload conflicts, missing reasons and non-Waiting/OTC refusal. An old flag id cannot clear or override a replacement flag with the same version.
- Attending-doctor own-entry override, required explicit confirmation/reason, retained flag and immutable actual-Serving witness; CA and non-attending doctor refusal; audit failure rolls back set, clear, override, call and retry evidence.
- PostgreSQL refuses event/override/command update, delete and truncate; uniqueness and consistency cannot be bypassed. Waiting departure records a system close, without a ready notification.
- Full ID-ordered snapshots of unrelated tables before/after actions and audit failure; no-flag Call In pins the original transition and exact audit metadata. TV feed and public QR status are unchanged with the flag present and cleared.
- `CallReadinessTest::test_board_projection_uses_two_bounded_queries_for_any_number_of_visible_rows` proves exactly **two batched readiness queries** for both one and twelve rows, without a query per patient.
- `CallReadinessCountTest::test_dropdown_keeps_its_shape_plus_only_one_scoped_aggregate_integer` pins legacy shape and opt-in shape, integer type and own/branch cohort isolation. The other count tests pin identity-free once-only clear signals, cancellation, and reflag suppression.
- `CallReadinessConcurrencyTest::test_concurrent_set_or_clear_versus_call_has_one_consistent_winner_and_no_lost_audit` uses independent PostgreSQL workers, real locks and recursive blocker observation. Set-vs-call and clear-vs-call both finish with one consistent state and no lost audit.
- New frontend guards pin the count-only dropdown, board-only identity details, exact generic toast, clear-before-count ordering/no carried pending event, optional boolean-only chime preference, and minimum-size controls.

### Intermediate failures retained

No existing assertion failed. Own new-code/setup failures were corrected without editing old tests:

- New migration functions initially used `CREATE FUNCTION`, causing 87 setup errors before assertions on repeated `migrate:fresh`. Changed only new functions to `CREATE OR REPLACE FUNCTION`; existing queue suite then passed 87 tests / 635 assertions unchanged.
- An initial frontend run lacked ignored generated Wayfinder routes. Ran the workflow generation command; existing 486 tests then passed unchanged.
- New fixtures initially omitted required removal timestamps, phone/reason values or used the wrong branch helper. Corrected new fixtures only.
- New truncate testing encountered pending deferred trigger events (55006). Made the new consistency constraint immediate before testing; CASCADE then reached the required immutability P0001, with plain truncate separately checked for 0A000.
- The new contention helper first missed buffered readiness output, then treated only direct blockers as sufficient. Corrected the new helper to inspect buffered output and the recursive blocking graph; the actual races passed.
- Task verifier SQL quoting and inet display formatting were corrected; its configured host/port/name guard matched the owned database throughout. No other database was accessed.
- Composer's wrapper initially reported its default 1536 MiB while PHPUnit inherited 1 GiB. The final completed sequence explicitly set Composer and PHP to the approved 1 GiB.
- One intermediate CI replay was deliberately stopped to add the missing permission-before-binding middleware to the new routes. The completed replay above replaced it.
- New alert review removed a carried pending clear signal that could otherwise match a later cancellation. Final tests pin the corrected ordering and no pending carryover.
- Initial browser captures used a background tab, 80% browser zoom or the wrong scroll origin. They are retained separately as superseded evidence, excluded from the final 41 screenshots. Final captures assert actual CSS viewport, theme and no sideways overflow.

## Initial Chrome evidence — superseded for wording review

Private evidence root: `/private/tmp/kpone-pilot-call-hold-evidence`.
Manifest: `EVIDENCE.md`. Final measurements: `chrome-metrics-final.json`; screenshot dimensions verified using `sips`. **41 final screenshots**, synthetic `.test` identities only; none are repository attachments.

For each of **1440×900** and **390×844 CSS px**, in **light and dark**, nine captures show: Registration Waiting board, set dialog, clear dialog, doctor's own queue, blocked Call In, override dialog, consultation count-only dropdown, identity-free ready toast, detailed queue-number ready toast. Names use these prefixes with `-{1440|390}-{light|dark}.jpg`:

`registration-waiting`, `set-dialog`, `clear-dialog`, `queue-own`, `blocked-call`, `override-dialog`, `consultation-count-only`, `generic-ready-toast`, `detailed-ready-toast`.

The mobile boards are full-page captures, preserving 390 CSS px width. Measured document and body widths do not exceed the viewport. Remaining captures: `override-required-390-dark.jpg`, `override-busy-refusal-390-dark.jpg`, `overridden-record-390-dark.jpg`, `denied-technical-admin-390.jpg`, `denied-technical-admin-1440.jpg`. The existing framework 403 is neutral rather than theme-aware; its body contains only `403 Forbidden`, no patient or reason.

Browser actions verified CA set and clear, doctor's visible disabled Call In and reason, missing override reason refusal, and the existing doctor-busy refusal. Only after using the existing Hold action on the separate synthetic current consultation did a valid `patient_at_door` override succeed. That separate setup action is not a readiness side effect. `chrome-override-witness.json` confirms the retained flag is overridden at version 2 and has a matching actual Serving witness. `chrome-audit-keys.txt` records metadata keys without clinical/personal values.

The consultation toast reads exactly **A patient is ready to be called**; queue board toast reads **Ready to be called: A-003** for the synthetic queue. The dropdown contains aggregate counts only. `unsaved-note-proof.json` verifies the unsaved synthetic clinical note remained unsaved and intact across polling and clear alerts. No clinical save was performed. Chime opt-in showed Active on both surfaces; physical sound output was not measured.

Both owned HTTP previews (58565, 58566) and owned PG18 cluster were stopped after evidence. Browser viewport overrides were reset and task tabs closed. Preview storage is separate from test storage; no private disk was reset while a preview was live.

Final patch packaging also checked untracked files using `git diff --no-index --check`: it caught one trailing space in the new frontend test. Removed that whitespace only; all 491 frontend tests and the new-file ESLint check passed again (`final-frontend.log`, `final-new-test-lint.log`). Tracked and new-file whitespace checks now pass.

## Files and scope

New readiness domain classes, private controller, migration, CA actions component, alert composable, three backend test files, PostgreSQL worker and one frontend test file. Existing edits are confined to the permission catalogue, Queue policy/services, private Visit/Queue board projections, Call In request, departure hook, private routes, board/dialog/widget Vue files and their types. No existing test file changed. No dependency was added.

## Not built

No treatment-progress status, chat, free text, automatic Panel rules, TV/QR change, external messaging, websocket, worker, new dependency or cross-branch feature. No doctor-busy, held-cap or Hold/Resume change. No consultation patient list or next-patient calling control.

## PR preparation — rebase onto PR #176

The owner requested base `c9596ba009a5bd9f51286b5baf5f0694bfcd5a46`. Because the candidate was uncommitted, its task-only changes were saved with `git stash --include-untracked`, the branch was rebased onto that exact main SHA, and the saved candidate was restored. One content conflict in `QueueDirectoryService.php` was resolved by retaining main's batched removed-visit hints and adding the batched readiness projection beside them. All other overlaps merged cleanly. The new Registration readiness-id projection now passes main's request-local `queueHints` closure into `visibleQueueEntry`, preserving the batched authority hints instead of repeating branch authority lookups per row. No existing test changed.

Fresh task-owned PostgreSQL 18.6 cluster: `/private/tmp/kpone-call-hold-pr-pg18`, **127.0.0.1:58604**. New UTF8/template0 databases: `kpone_call_hold_pr_testing`, separate `kpone_call_hold_pr_seed_testing`, and synthetic `kpone_call_hold_pr_preview_testing`. Environment, connection verifier, logs and test storage are under `/private/tmp/kpone-call-hold-pr-evidence`. No `.env` or `.env.preview` was read or created. Task-only PHP/Composer memory remains 1 GiB.

The first replay stopped before tests because the new task storage lacked the view-cache directory. Created that private task directory and restarted the complete workflow sequence. No existing assertion failed in that setup attempt.

### Final post-rebase gate and evidence

| Check | Result |
|---|---|
| Exact CI sequence, including `composer ci:check` | Passed |
| Backend | **1,452 passed / 29,926 assertions / two existing public-registration skips**, 667.289 seconds |
| Full frontend | **497 passed**, no skips |
| Pint / PHPStan level 7 | Passed / zero errors |
| Frontend lint / format / types | Passed |
| Production build | Passed |
| Separate `migrate:fresh --seed --force` | Passed |
| Tracked and newly added file whitespace checks | Passed |
| Existing tests/assertions changed | **None** |

Completed at 2026-10-27T03:35:56Z. Logs: `/private/tmp/kpone-call-hold-pr-evidence/ci-final/`. No existing assertion failed. Main's `PilotBoardScaleTest` and all prior count-only, TV, QR, no-flag Call In and staff-authority tests passed unchanged. Main's batched board hints are retained; the new readiness projection still adds exactly two bounded queries rather than a per-row lookup.

After testing, fresh synthetic preview fixtures were created only in `kpone_call_hold_pr_preview_testing`, with the effective host/port/name and cluster identity verified before HTTP. Rechecked all English labels and exact 390 CSS px in light and dark: Registration/doctor Queue badges, set/clear/override dialogs, disabled Call In, consultation dropdown, generic ready toast and board ready toast. **18 fresh post-rebase screenshots**: `/private/tmp/kpone-call-hold-pr-evidence/screenshots`; manifest `EVIDENCE.md`, measurements `chrome-metrics.json`. No document/body overflow, clipped helper text or hidden dialog button was observed. The existing recall label is unchanged. Earlier 1440/390 English evidence remains available in the previous evidence root.

Both preview servers and the fresh PG18 cluster were stopped after capture. No tests ran with a preview live. Browser viewport overrides were reset and task tabs closed. The served tree remains clean on main and untouched. The owner authorised one commit, push of this branch and one PR against main; merge is not authorised.

## Limits and open questions

Alerts are best-effort polling: boards retain their existing three-second refresh and the consultation widget polls every fifteen seconds. A missed alert does not change the authoritative flag or Call In refusal. No durable notification system is introduced. Chime requires browser user activation and remains optional; no audible-device guarantee is claimed. Clear signals and identities are not persisted in browser storage.

Open questions: **None** for this authorised slice. The candidate is prepared for the owner-authorised Job 4 PR. Merge requires separate owner approval. The served tree remains clean on main and untouched.
