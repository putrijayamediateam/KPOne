# STK-2 — branch batch holds, eligibility and FEFO exceptions

Date: 2026-10-27. Branch `feature/stk-2-batch-holds`; isolated worktree `/Users/klinikputrijaya/Herd/KPOne-stk-2`. Rebased base `bc614ac11e8eb37b4340c50995dd0b4358606033` includes STK-1 (#180) and unknown-DOB confirmation (#181). The owner authorised commit, push and one PR on 2026-10-27; no merge is authorised. Not production-approved. The served tree remains on main and untouched. Only fictional synthetic data is used.

## Owner decision and deliberate save-time behaviour change

The pre-build survey found that pickers and Complete excluded expired/unavailable batches, but allocation SAVE did not. The owner authorised closing this gap on 2026-10-27. Save now refuses ineligible batches with an English validation error naming the medicine and reason. Existing `DispensaryController::stayOnCase` gives an explicit case redirect, even with an unrelated Referer. Complete keeps its eligibility recheck: a previously saved batch that expires at local midnight or becomes held is refused before stock debit.

`InventoryBatchEligibility` defines the reason-producing predicate once. Allocation save, Complete, dispensing availability and the batch picker call it. The rule is active item, active SKU, batch status available, expiry strictly after branch-local today, and no active hold at that branch. The local-date expression is unchanged. Missing/month-only expiry remains refused by reference validation; no date is invented. Existing location, mapping, unit, quantity and balance checks remain contextual checks.

The survey below refers to **original survey base `97e2bdba7f189dffb0ec4e78fcfe265c4097d743` file line numbers**, before this candidate. It is source evidence, not a claim that an expired batch was posted in production.

## Verified survey (base files and lines)

| Path | Selection / eligibility today |
| --- | --- |
| `app/Domain/Organisation/Inventory/Services/InventoryAvailabilityService.php:14–31` | Reads positive balances, active locations, available batches with expiry strictly greater than the branch-local date. Orders by location priority, then expiry, received date and batch id. This is an ordered availability list, not automatic allocation. |
| `resources/js/pages/Dispensary/Show.vue:1060–1079,1326–1343` | Existing manual Batch dropdowns for editing a line and adding a medicine. User chooses an availability index; no FEFO exception reason is collected. |
| `resources/js/pages/Dispensary/Show.vue:449–463,473–488,610–630` | Builds a single submitted allocation from the selected location/batch and entered quantity. Both edit and add use the manual selection. |
| `resources/js/pages/Dispensary/Show.vue:525–544` | Restore uses the first availability entry. This limited default does not make the other manual paths strict automatic FEFO. |
| `app/Domain/Clinical/Dispensary/Services/DispensaryService.php:63–93` | Legacy `updateItem` deletes/replaces allocations by calling the common `allocation` helper for each submitted allocation. |
| Same file `:102–112,219–234,251–261` | `editItem` → `saveLine` → `replaceAllocations` → common `allocation` helper, including restored lines. |
| Same file `:120–160` | `addItem` → `replaceAllocations` → common helper; shared by ordinary and OTC cases. |
| Same file `:705–718` | Common helper locks and validates the branch dispensary location, SKU, medicine mapping and selected batch; checks positive quantity; inserts allocation. **No batch available-status, expiry or earlier-eligible-batch check.** A submitted expired/non-available batch is not refused here by those rules. This is a static source finding, not a reproduced database test result. |
| Same file `:524–619` | Shared Complete path, including OTC, locks allocation/location/SKU/mapping/batch records. Lines 595–617 calculate branch-local today and refuse status other than available or expiry today/earlier, before the stock debit at 619. Existing refusal: `A selected inventory reference is no longer eligible for dispensing.` |
| `app/Domain/Organisation/Inventory/Services/InventoryMovementService.php:109–119` | `debitForDispense` checks unit contract and balance but relies on its caller for batch eligibility. The only application caller found is the shared Complete method. |
| `app/Domain/Organisation/Inventory/Services/InventoryPickerService.php:66–70,93–98` | Inventory batch choices require available status and future expiry; ordered by expiry, bounded by limit. No allocation is written here. |
| `database/migrations/2026_09_04_000100_create_dispensary_inventory_foundation.php:123,352–354,359–398` | Batch expiry is a non-null PostgreSQL date. Allocation quantity and completed allocation/movement consistency are protected; these constraints are not an allocation-save expiry guard. |
| `app/Domain/Organisation/Inventory/Services/InventoryReferenceAdministrationService.php:574–588,723–737` | Normal reference writes require a full valid `YYYY-MM-DD` expiry date; missing/month-only input is refused, not converted into an invented date. No policy change proposed. |

### Every application allocation writer found

Repository search for `DispensaryItemBatchAllocation`, `dispensary_item_batch_allocations` and `debitForDispense` found one allocation insertion point: `DispensaryService::allocation` at 717–718. Its writer entry points are:

1. `updateItem` at 63–93 (legacy fulfilment patch).
2. `editItem` at 102–112, through `saveLine` and `replaceAllocations` (doctor-origin or CA-origin line, including restore).
3. `addItem` at 120–160, through `replaceAllocations` (ordinary and OTC add).
4. `complete` at 524–619 consumes/rechecks the retained allocations and is the only caller of `debitForDispense`.

Routes are in `routes/web.php:516–521,536–537`. `OtcDispensaryService` opens the OTC case; it does not independently select or insert batches. Remove-line deletes saved allocations and does not allocate new ones. No separate inventory reservation table/service or independent dispensing allocation writer was found in the surveyed domain code. Purchase/transfer/count operations use batch references and stock movements; they are not patient-dispensing allocation writers and remain outside the pilot scope.

## Changes and evidence model

- Branch-specific place/release actions with controlled reason codes, one active hold per batch and branch. A multi-branch recall needs separate actions at each branch. No global hold or patient tracing.
- `inventory_batch_hold_actions` is immutable append-only action/retry evidence. PostgreSQL refuses update, delete and truncate. `inventory_batch_hold_states` is a guarded, versioned current-state projection, not rewritten action history; unique batch/branch plus a partial active-hold index prevents multiple active holds. Database guards enforce tenant consistency, action/state versions and independent release. Projection deletion/truncation is refused.
- Transactions, fresh actor/permission/effective-branch checks, SKU then batch locks, projection row lock, optimistic version, UUID retry and payload-conflict refusal. Hold/save/Complete serialize on the SKU and batch. Audit failure rolls back action and projection. Audit metadata contains action ids only; no free-text reason or personal content.
- Hold/release creates no stock movement and changes no quantity. The full-table test compares every unrelated public table in ID order before/after both actions and verifies old audit rows remain identical.
- Existing manual batch selection is retained. When an earlier eligible batch has positive dispensary stock, choosing a later-expiring eligible batch requires one of `earlier_batch_damaged`, `earlier_batch_insufficient`, `earlier_batch_held`, `other_approved`. The new nullable allocation field stores that reason; mandatory audit stores allocation id only. Completed reasons cannot be changed. No backfill or retroactive FEFO rule on old saved lines.
- A private Inventory link opens the bounded branch hold review: search across the branch's batches, 25 per page, controlled dialogs, permission-filtered actions, branch-switch reset. Held stock is labelled in stock/batch projections and batch choices; held dispensing choices are disabled. The dispensing choice cap of 100 is stated on screen.

### Files and migrations

New domain services: `InventoryBatchEligibility`, `InventoryBatchHoldService`. New controller: `InventoryBatchHoldController`. Shared predicate wired into `DispensaryService`, `InventoryAvailabilityService`, `InventoryPickerService`, `InventoryDirectoryService` and the reference batch projection. The three dispensing item requests accept the optional controlled FEFO field. Private routes, `Inventory/BatchHolds.vue`, Inventory link/status labels, existing picker labels and Dispensary form are updated. No new dependency, public route, job or outbound application call.

| Migration | Effect |
| --- | --- |
| `2026_11_01_000100_create_branch_batch_hold_evidence.php` | Two new hold tables, restrictive FKs, constraints/guards, immutable actions, guarded projection, nullable controlled FEFO reason on new allocation evidence. No old row backfill. `down()` retains evidence. |
| `2026_11_01_000200_grant_branch_batch_hold_permissions.php` | Additive `firstOrCreate`, grant only missing permissions, log/skip missing roles, flush permission cache. `down()` never revokes. |

Existing databases need `php artisan migrate`. No migration was run on a non-task-owned database.

## Permissions

| Permission | Exact default holders |
| --- | --- |
| `inventory.batch_holds.place.branch` | `ca_supervisor`, `finance_officer`, `director` |
| `inventory.batch_holds.release.branch` | `finance_officer`, `director` |

Release also requires an actor different from the placer. Every mutation requires a current effective assignment to the selected branch. Administrator, Technical Admin, CA, Resident Doctor, Panel Officer, HR, Marketing, BD and queue display gain neither permission. Viewing reuses `inventory.view.branch`; no new clinical access. The catalogue and replay-safe migration agree. No staff-authority rule changes.

## Tests and every changed existing assertion

**Existing assertions changed: none. Existing fixtures changed: none.** No test name, expected value or skip was changed. In particular the existing save-eligible-then-expire Complete test remains untouched. No existing test expecting successful save of an ineligible batch was encountered in the completed regression runs.

New tests are `tests/Feature/Inventory/BatchHoldTest.php` (12 methods), `PostgresBatchHoldTest.php` (separate-connection PostgreSQL race), `tests/Frontend/batch-holds.test.mjs` (2 tests), and two synthetic support helpers. Coverage includes every catalogue role, fresh revocation/ended assignment, independent release, foreign tenant/branch, replay/conflict/stale version, audit rollback, expiry today/local midnight, unavailable status, inactive item/SKU, held save refusal, earlier/later eligible selection, FEFO evidence, post-save hold/expiry refusal at Complete, explicit redirects, missing/month-only expiry, migration replay/no revoke, bounded reads and full unrelated-row invariance.

The PostgreSQL contention test creates its own UTF8/template0 database on the task cluster. Hold placement retains the SKU lock while a separate connection tries save, then Complete. The worker is observed waiting on a PostgreSQL lock; after hold commit it refuses with the held validation, with no losing allocation/debit and exactly one hold audit. It drops only its own child database.

The bounded review test creates 32 synthetic batches, returns 25, and asserts at most 18 SQL queries independent of rows; query-log assertions exclude patient/visit/encounter/invoice/payment reads. Picker/availability tests retain Held labels while the common predicate refuses allocation.

## Validation before the main rebase

Task-only PostgreSQL 18.6 at `127.0.0.1:58654`, owned cluster `/private/tmp/kpone-stk2-pg18`. Focused database `kpone_stk2_testing`; full gate `kpone_stk2_gate_testing`; separate fresh seed `kpone_stk2_seed_testing`. All UTF8/template0. Effective host, port, database, version, encoding and data directory checked before application use. Explicit placeholder environment, no `.env` or `.env.preview` read/created. Task-only PHP/Composer 1 GiB configuration; production settings unchanged.

Early PHP syntax, Pint, PHPStan level 7 and TypeScript checks passed. Relevant unchanged regressions passed **127 tests / 1,364 assertions**. The new suite passed 13 tests / 140 assertions before two additional old-audit-prefix checks; the final isolated invariance method passed 1 test / 16 assertions. The full frontend run passed **507 tests**. The first full CI replay stopped at formatting in the new page; Prettier fixed that new-code issue. No assertion was changed.

The full exact CI-command replay and gate passed. Logs: `/private/tmp/kpone-stk2-evidence/gate/`.

| Check | Result |
| --- | --- |
| `php -l` on 19 new/changed PHP files | Pass |
| Workflow dependency install, Wayfinder, frontend behaviour tests, migrate, build in workflow order | Pass |
| `composer ci:check`: ESLint, Prettier, Vue types, config clear, Pint, PHPStan level 7, backend | Pass |
| Backend | **1,493 passed / 30,451 assertions / two existing skips** (1,495 tests reached), 695.446 seconds |
| `node --test tests/Frontend/*.mjs` | **507 passed**, zero skipped |
| Separate `migrate:fresh --seed` | Pass, `kpone_stk2_seed_testing` |
| `git diff --check` | Pass |

No test was weakened, removed or newly skipped. No code changed after the passing gate; only this report and private evidence were completed. The workflow's environment-copy step is represented by explicit `.env.example` placeholder settings rather than creating `.env`. The owner's task-only 1 GiB setting is used throughout. No tests ran with the preview live.

The disposable preview seed initially hit existing balance constraints when its temporary helper attempted a direct balance insert. Only that private helper was corrected to use `InventoryMovementService::openingBalance`, and only the newly created preview DB was reset, before any browser request. No candidate code or test changed; no non-task database/storage was involved.

## Screenshots

Real Chrome: **24 screenshots**, exact 390 × 844 and 1440 × 1000 CSS px. Hold review, place and independent-release dialogs were checked in light/dark; Held stock and batch views in both widths/themes; successful release and Technical Admin 403 captured. Every capture measured `document.documentElement.scrollWidth === innerWidth`. The Inventory table retains its existing internal horizontal scroll on narrow screens. The release submit button measured 44 CSS px high. No captured browser console errors.

Preview connection was verified before the first HTTP request: owned `kpone_stk2_preview_testing` on the same PG18 cluster, separate `/private/tmp/kpone-stk2-evidence/preview-storage`. Only synthetic `.test` accounts/data. UI action evidence shows place/recall version 1 then independent release/inspection_passed version 2, with exactly the two values-free hold audits; both balances remain `10.000`.

Private manifest: `/private/tmp/kpone-stk2-evidence/chrome-manifest.json`. Screenshots are local private artifacts, not tracked files. The preview server and task PG cluster were stopped after capture; viewport override reset and task Chrome tab closed.

| Screenshot | Width / theme |
| --- | --- |
| `/private/tmp/kpone-stk2-evidence/screenshots/01-holds-1440-light.jpg` | 1440 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/02-place-1440-light.jpg` | 1440 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/03-holds-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/04-place-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/05-holds-390-dark.jpg` | 390 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/06-place-390-dark.jpg` | 390 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/07-holds-1440-dark.jpg` | 1440 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/08-place-1440-dark.jpg` | 1440 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/09-held-1440-dark.jpg` | 1440 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/10-held-390-dark.jpg` | 390 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/11-held-1440-light.jpg` | 1440 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/12-inventory-1440-light.jpg` | 1440 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/13-inventory-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/14-batches-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/15-batches-1440-light.jpg` | 1440 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/16-batches-1440-dark.jpg` | 1440 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/17-batches-390-dark.jpg` | 390 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/18-release-390-dark.jpg` | 390 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/19-release-1440-dark.jpg` | 1440 / dark |
| `/private/tmp/kpone-stk2-evidence/screenshots/20-release-1440-light.jpg` | 1440 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/21-release-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/22-released-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/23-denied-390-light.jpg` | 390 / light |
| `/private/tmp/kpone-stk2-evidence/screenshots/24-denied-1440-light.jpg` | 1440 / light |


## Known limits and deferred scope

- Holds require that the batch already has a stock-balance reference at this branch (zero quantity is sufficient). They do not propagate across branches or future receipts. No multi-branch recall automation.
- The state projection is mutable only through its guarded version/action transition; action history stays immutable.
- FEFO is an exception record on manual selection, not automatic allocation. The check runs at save; Complete rechecks eligibility, without retroactively demanding FEFO reasons for legacy saved lines. The edit form does not prefill a previously recorded exception reason. New saves carry their own reason.
- Legacy dispensing endpoints retain their existing case/item optimistic-version contract; no new UUID contract was added to those endpoints. New hold/release commands have UUID idempotency.
- Dispensing choices are capped at 100, visibly stated; branch hold review is searched and paged. No production-load SLA is claimed.
- Reference setup can still select a held batch for reference/opening work; patient allocation/Complete cannot. Receiving, transfers, stocktake and low-stock operational workflows remain parked and are not certified by this slice.
- STK-3 through STK-10, imports, costs/valuation, disposal, patient tracing, notifications/messaging, new document types and professional policy remain deferred.
- Responsible clinician, regulated items, professional FEFO policy, storage/cold chain, registers and retention remain **to confirm with a pharmacist or the regulator**. No answer is encoded here.

## Rebase and final PR validation

The owner authorised opening the STK-2 PR on 2026-10-27. `git fetch origin main` returned `bc614ac11e8eb37b4340c50995dd0b4358606033`. The uncommitted candidate was saved/restored around the rebase. Rebase and restore were conflict-free; main's unknown-DOB code and tests remain intact. All 25 STK-2 application/test files are byte-identical to the reviewed pre-rebase candidate; no overlap resolution was needed. Existing test files have no diff against the new base.

The task-owned PostgreSQL restart initially attempted its default listening port and failed to bind before any client connection. It was restarted with explicit task port `58654`, loopback-only binding and its own socket directory. No connection was made to the occupied default port or to another database. The rebase gate uses newly created UTF8/template0 databases `kpone_stk2_rebase_testing` and `kpone_stk2_rebase_seed_testing`; the effective cluster identity was verified first. Early PHP syntax, Pint and PHPStan passed. The complete post-rebase run is green:

| Post-rebase check | Result |
| --- | --- |
| `php -l` on 19 new/changed PHP files | Pass |
| Early Pint / PHPStan level 7 | Pass |
| Exact workflow command replay and `composer ci:check` | Pass |
| Backend | **1,502 passed / 30,517 assertions / 2 existing skips** (1,504 tests reached), 707.338 seconds |
| Full frontend behaviour suite | **509 passed**, zero skipped |
| Frontend types, ESLint, Prettier and production build | Pass |
| Separate `migrate:fresh --seed` | Pass, `kpone_stk2_rebase_seed_testing` |
| `git diff --check` | Pass |

Logs: `/private/tmp/kpone-stk2-evidence/gate-rebase/`. No existing assertion or fixture changed relative to the new main. No test weakened, removed or newly skipped. The task cluster was stopped after this gate; no preview server was started for the rebase validation.

The earlier 24 screenshots remain applicable: the rebased main changed patient creation/confirmation, not any STK-2 screen, hold service or dispensing allocation/Complete path. They are recorded evidence from the prior green candidate, not newly captured screenshots after rebase.

The PR must call out the deliberate save-time eligibility change and survey. Owner merge approval remains unticked. Professional questions remain unanswered; the gate is not production approval.
