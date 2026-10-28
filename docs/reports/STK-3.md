# STK-3 — verified receiving and order evidence (local candidate)

Date: 2026-10-28. Branch `feature/stk-3-verified-receiving`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-stk-3`. Base `dded476d813ccb0d9a3909ef4191f19a1d231838` (STK-2, #182). Original implementation `9bd63cd391f2104212c15737755c62e8f95f5bc1`, PR #183. The test-isolation and deterministic-fixture CI follow-ups are recorded below. Not production-approved.

## PR #183 second CI follow-up — deterministic receiving fixture branch

CI run `40643458823`, head `14b557e`, failed **one test / 1,519 passed / two existing skips / 30,784 assertions**, 2,013.67 s. This is a reproducible fixture defect, not a correction-rule failure. `DispensaryAuthorityService.php:20–23` compares the actor's effective active branch with `expected_branch_id`. The stack's `InventoryMovementService.php:26` is **openingBalance during fixture construction**, before the test reaches its reversing correction or audit assertions.

### Root cause

The new `VerifiedReceivingFixture` delegated to `BatchHoldFixture`. That existing generic helper chooses actors' branches with unordered `Branch::firstOrFail()` (`tests/Support/BatchHoldFixture.php:35`), while its registration uses `VisitFactory` without an explicit branch (`:50–52`). `database/factories/VisitFactory.php:24` independently chooses a branch with a differently shaped unordered `value('id')` query. Ordinary PostgreSQL updates can change heap order, so the queries need not pick the same branch. In the controlled reproduction a normal update of CHERAS's `updated_at` made the actor query choose SUNGAI_BESI (id 2) while the Visit query chose CHERAS (id 1). The opening location and expected branch followed the Visit. The actor had no CHERAS assignment, so `BranchAccessService` correctly rejected that session selection, fell back to the actor's primary SUNGAI_BESI branch and cached that result; the authority guard correctly refused the mismatch. These are observed local ids, not recovered CI ids. Clearing the session and recreating BranchAccess before the probe still reproduced the failure. Neither the clock nor a stale production cache caused it; unordered fixture setup made the outcome depend on physical database state and query plans. No existing runtime fix is needed.

### Narrow test-only fix and regression

- `tests/Support/VerifiedReceivingFixture.php:20–76`: keep the existing OTC dispensing bootstrap, but supply one explicit branch to every actor assignment, Patient organisation, Visit, location and payload. The default resolves CHERAS by organisation and code, never row position; callers can pass another branch. Branch selection uses the normal `BranchAccessService::select` API instead of resetting its instance and writing a raw session selection. No existing generic helper, factory or application code changes.
- `tests/Feature/Inventory/VerifiedReceivingTest.php`: its private fixture passes the test's explicit branch. All **16 pre-existing public test bodies and assertions are byte-identical** to `14b557e`. New `test_fixture_branch_is_independent_of_heap_order_prior_selection_and_hour` (`:551`) checks both CHERAS and SUNGAI_BESI, a prior other-branch selection, a legitimate heap-moving UPDATE, and two frozen instants (`2026-10-28T00:05Z`, `23:55Z`, crossing a branch-local calendar date). Its **44 assertions** check Visit, location, expected branch, and each actor's primary assignment and active branch. The clock is restored in finally; no OS clock change.
- `tests/Feature/Inventory/PostgresVerifiedReceivingTest.php`: the second synthetic receiver now comes from the same explicit-branch STK-3 helper. No assertion, contention protocol step, timeout or worker changes.
- This report is the only other changed file. No migration, permission, dependency, production cache change or existing assertion edit. The original 29 PostgreSQL regression bodies remain unchanged.

### Reproduction, ordering and time evidence

All logs are local under `/private/tmp/kpone-stk3-evidence/`. All databases are task-owned UTF8/template0 PostgreSQL 18.6 at **127.0.0.1:58664**, owned cluster `/private/tmp/kpone-stk3-pg18`; application connection identity verified before use. No `.env` or `.env.preview`, non-task database or served-tree change.

| Probe | Before fix | After fix |
| --- | --- | --- |
| VerifiedReceivingTest, default order | 16 passed / 234 assertions / 10.192 s (`branch-before-class.log`) | 17 passed / 273 assertions / 11.058 s (`branch-after-class.log`) |
| Whole Inventory directory, default order, stop at first failure | 30 passed / one error / 342 assertions / 20.863 s; new overage test fixture hit the same inconsistent branch authority (`branch-before-directory.log`) | 45 passed / 575 assertions / 28.399 s (`branch-after-directory.log`) |
| Exact correction test body, private inherited probe with only heap-moving setup | One error / zero assertions / 1.363 s, exact active-branch refusal (`branch-exact-case-before.log`) | One passed / 12 assertions / 1.663 s (`branch-exact-case-after.log`) |
| New branch-independence guard | Same active-branch refusal before its first assertion (`branch-regression-before.log`) | Included and green in all class runs; 44 new assertions |
| Class reverse order | Not needed after deterministic reproduction | 17 passed / 273 assertions / 11.094 s (`branch-after-reverse.log`) |
| Class seeded random order, seed 406434588 | Not needed after deterministic reproduction | 17 passed / 273 assertions / 11.015 s (`branch-after-random.log`) |
| Four independent setup probes: normal plan, forced index plan, heap update, heap update plus index plan | First two built; both heap-update cases reproduced the exact failure, even with clean session/service (`branch-layout-before.jsonl`) | All four built the requested branch (`branch-layout-after.jsonl`) |

The private exact-test probe loaded the old helper from `14b557e` to demonstrate failure, then the current helper to demonstrate repair; the inherited correction method was not edited. Private probes are evidence only, not committed tests. The regression exercises two application clock values because effective assignment uses the branch-local date. All original assertions remain intact; assertion totals can vary with the unchanged query-log test's per-query checks because normal branch selection changes its warmed query set.

### Final validation for this follow-up

**Passed, exit 0.** Replayed the workflow sequence: Wayfinder generation; CI frontend glob; migrate --force; production build; exact `composer ci:check` (lint, format, types, config clear, Pint, PHPStan level 7, full backend); then the full frontend glob. Source stayed frozen throughout.

| Check | Actual result |
| --- | --- |
| Full backend | **1,521 passed / 30,835 assertions / two existing public-registration skips**, 1,523 reached, **749.172 s** (`branch-full-check.log`) |
| CI frontend behavior tests | **513 passed**, zero skips, 2.415 s (`branch-full-frontend.log`) |
| Full frontend glob | **513 passed**, zero skips, 2.344 s (`branch-gate-frontend.log`) |
| Syntax / Pint / PHPStan level 7 | All three modified PHP files pass syntax; Pint passes; whole-project PHPStan zero errors (`branch-final-phpstan.log` and exact CI log) |
| lint / format / types | Passed in exact CI sequence |
| Production build | Passed, **1.65 s** (`branch-full-build.log`) |
| `git diff --check` | Passed |

Fresh full-replay database `kpone_stk3_branch_ci_testing`; class/directory database `kpone_stk3_branch_testing`; separate deterministic-probe database `kpone_stk3_branch_repro_testing`. All owned and verified as described above. Task-only approved **1 GiB**, PHP **8.4.26**, Node **22.23.3**. No test weakened, removed or newly skipped. The new guard adds 44 assertions; the unchanged query-log test performs five fewer per-query assertions with correctly warmed branch selection, yielding 30,796 + 44 - 5 = **30,835**. All original assertion statements remain unchanged.

Prior Chrome feature evidence and separate fresh seed remain applicable: this follow-up changes only synthetic test setup, one new regression and documentation, with no feature or schema change. No new screenshots are claimed. Private diagnostic probes stay outside the repository. Served tree stays clean on main, untouched. This is one normal new commit on the same branch, no force-push and no merge.

## PR #183 CI follow-up — container-safe child database identity

Owner-authorised 2026-10-28, following CI failure on implementation commit `9bd63cd391f2104212c15737755c62e8f95f5bc1` (30 failed / 1,490 passed, as reported by the owner). The fault was in the new isolation checks, not receiving logic: they compared the client's configured endpoint `127.0.0.1` to PostgreSQL's listening interface address. A published Docker service can correctly report its container address (`172.18.0.2` in that CI run). Endpoint and server-interface addresses are not database identity.

Only new isolation code is corrected, plus this report. No application, migration, permission, worker protocol, timeout or existing 29-test body changes.

| File / location | Old new-code check | Corrected check |
| --- | --- | --- |
| `tests/Feature/PostgresDispensaryInventoryRegressionTest.php:118–125`, setup | Configured host/port equality and `inet_server_addr()` / `inet_server_port()` equality with the configured endpoint. | Require default connection alias AND connection object's name to be `pgsql_dispensary_inventory_child`; configured database must equal this test's freshly generated unique child; query actual `current_database()` and require that same child plus `server_encoding = UTF8`. No server-address or server-port comparison. |
| `tests/Feature/Inventory/PostgresReceivingRollbackTest.php:34–39`, new test | Same endpoint assumption, including two new assertions comparing actual server host and port. | Require default connection alias AND connection object's name to be `receiving_rollback`; configured database must equal its newly generated child; keep assertions on actual `current_database()` and UTF8 encoding. Remove only the two invalid host/port assertions from this new STK-3 test. |

The connection configuration remains explicit, inherited from the intended task/CI connection, with its unique child database and `url = null`; workers retain explicit host/port/user/database settings. No root/shared test database is accepted as a child. Dropping server-port comparison is deliberate: a published or forwarded client port may differ from PostgreSQL's listener, just like its address. The existing unique-name generation, scope guard, UTF8/template0 creation and guarded teardown/drop remain intact. All 29 original regression bodies are byte-identical to `9bd63cd` and the pre-STK-3 base.

### Search of new/changed tests and workers

- Fixed the two files above; they were the only server-address/port equality checks.
- `tests/Feature/Inventory/PostgresVerifiedReceivingTest.php`: no server-address/port comparison; explicit child config and child database check already work. Host/port are correctly used only as worker connection inputs. Unchanged.
- `tests/Support/PostgresVerifiedReceivingWorker.php`: unique `kpone_stk3_race_*_testing` name guard, no address comparison. Unchanged.
- `tests/Support/PostgresDispensaryInventoryWorker.php`: no address/port equality; explicit child settings supplied by the test. No new worker change.
- `tests/Feature/Inventory/PostgresBatchHoldTest.php` and `tests/Support/PostgresBatchHoldWorker.php` (merged STK-2 reference): child-name checks and explicit worker settings, no server-address/port equality. Unchanged.
- `tests/Feature/Clinical/InventoryOperationsTest.php`, `tests/Feature/Inventory/VerifiedReceivingTest.php`, `tests/Support/VerifiedReceivingFixture.php` and `tests/Frontend/verified-receiving.test.mjs`: no server-address/port comparisons. Unchanged.
- A repository-wide search of `tests/` finds no remaining `inet_server_addr()` or `inet_server_port()` use. No other test needed a change.

### Actual different-address replay

No SQL mocking or response rewriting. A throwaway Python standard-library TCP relay listened on **127.0.0.1:58667** and forwarded raw PostgreSQL traffic to the same task-owned PG18 cluster at **[::1]:58664**. The local verification query returned actual **server_host `::1`**, **server_port `58664`**, UTF8, and owned data directory `/private/tmp/kpone-stk3-pg18`, while Laravel was configured for **host `127.0.0.1`**, **port `58667`**. Thus both the configured address and port genuinely differ from the server interface/port, reproducing the Docker/published-port assumption without installing Docker or changing OS network settings.

Both affected classes ran through that relay, including all worker connections: **30 passed / 481 assertions / no skips / 57.864 seconds**, `ci-address-two-classes.log`. Actual endpoint proof: `/private/tmp/kpone-stk3-evidence/address-proof.json`. Root harness database `kpone_stk3_address_testing`; each test still used/dropped its own generated UTF8 child. The relay is task-owned and was stopped after the replay. No external or non-task-owned database was used.

### Full replay result

**Passed, exit 0.** Exact workflow validation sequence: Wayfinder generation; `node --test tests/Frontend/*.test.mjs`; `php artisan migrate --force`; `npm run build`; `composer ci:check` (npm lint/format/types, config clear, Pint, PHPStan level 7, full backend). Additional full frontend glob: `node --test tests/Frontend/*.mjs`. No feature source changed during validation.

| Check | Actual result / local log under `/private/tmp/kpone-stk3-evidence/` |
| --- | --- |
| Two affected classes through different-address/port TCP relay | **30 passed / 481 assertions / zero skips / 57.864 s**, `ci-address-two-classes.log` |
| Complete exact CI replay | **Passed, exit 0**, `ci-address-full-check.log` |
| Full backend | **1,520 passed / 30,796 assertions / two existing public-registration skips** (1,522 reached), **750.158 s** |
| CI frontend behavior tests | **513 passed**, no skips, `ci-address-full-frontend.log` |
| Full frontend glob | **513 passed**, no skips, 2.418 s, `ci-address-gate-frontend.log` |
| Syntax | Both modified PHP files pass `php -l` |
| Pint / PHPStan level 7 | Passed; PHPStan zero errors (`ci-address-full-check.log`, early `ci-address-phpstan.log`) |
| npm lint / format / types | Passed in exact CI replay |
| Production build | Passed, **1.64 s**, `ci-address-full-build.log` |
| `git diff --check` | Passed |

Fresh owned UTF8/template0 full-replay database **kpone_stk3_ci_address_testing**, PostgreSQL **18.6** at **127.0.0.1:58664**, owned cluster `/private/tmp/kpone-stk3-pg18`. Same approved task-only 1 GiB, PHP 8.4.26, Node 22.23.3. The different-address proof used the relay separately; the full replay used the normal direct task endpoint. No `.env` or `.env.preview` read/created, no non-task-owned database, no new dependency. No new skip; no PostgreSQL skip. No application, migration or permission changes; prior fresh-seed and Chrome feature evidence remain unchanged.

The assertion total changes **30,798 → 30,796 solely because the owner-authorised host and port assertions were removed from the new STK-3 rollback test**. Its actual database/UTF8 assertions remain, and both new-code guards now also enforce the configured child alias. No assertion, expected value, test name, timeout, contention step or skip in the existing 29 regression bodies changed. No other test needed modification. The same branch receives one normal new commit; no force-push or merge.

## Original implementation validation (9bd63cd, before the CI follow-up)

STK-3 implementation local validation. The original exact CI command replay exits 0 on the task-owned PostgreSQL 18 database: **1,520 backend passes / 30,798 assertions / two existing public-registration skips** (1,522 tests reached), **763.003 seconds**. Full frontend gate: **513 passes**, no skips. Syntax, Pint, PHPStan level 7, types, lint, formatting, production build, separate `migrate:fresh --seed` and whitespace checks pass. No existing assertion was weakened, removed or newly skipped.

Canonical `origin/main` was fetched again and remains the branch base `dded476d813ccb0d9a3909ef4191f19a1d231838`; no rebase was needed. All work remains in the isolated task worktree. Served tree is clean on main and unchanged. Owner subsequently authorised PR #183 and its test-only CI follow-up; no merge authorised.

Exactly two historical migration tests use the owner-authorised historical schema; every other method in the previously authorised 29-test PostgreSQL class uses the current schema. All 29 test bodies remain byte-identical to HEAD. The class passes **29 / 468 assertions / 35.325 s** in its scoped run and also passes in the final full suite. Final Chrome evidence comprises **30 synthetic screenshots**, including exact 390 CSS px captures, 1440 px, both themes, split lots, quarantine, independent approval, posting and role denial.

## Resolved stops: two historical migration setups

Historical validation failure (line positions in this subsection are from the recorded failing revision): this unchanged existing test initially failed:

```text
Tests\Feature\PostgresDispensaryInventoryRegressionTest::test_inventory_operations_migration_empty_rollback_and_reapply_use_the_laravel_migrator
SQLSTATE[2BP01]: Dependent objects still exist
cannot drop table inventory_goods_receipts because other objects depend on it
```

Test declaration: `tests/Feature/PostgresDispensaryInventoryRegressionTest.php:707`; rollback call at line 735. It unwinds the existing medicine/supplier/cost migrations, then rolls back the 2026-09-11 Inventory Operations migration, expecting its tables to disappear. STK-3 now adds restrictive foreign keys to receipt headers from `inventory_receipt_delivery_evidence`, `inventory_receipt_line_evidence`, `inventory_receiving_consumptions` and `inventory_receipt_corrections`. Those foreign keys correctly refuse the old table drop even when their tables are empty. This is **not** the approved P0001 teardown cleanup case. No migration, constraint, test body or assertion was changed to bypass it.

Command: `php artisan test --compact tests/Feature/PostgresDispensaryInventoryRegressionTest.php --stop-on-failure --stop-on-error`. Result: **21 passed / 1 error / 221 assertions**, 22 tests reached, **26.840 seconds**. Log: `/private/tmp/kpone-stk3-evidence/existing-pg-class-isolated.log`. That earlier run stopped there. The subsequent authorised setup resolved it; the final full gate is recorded above.

**Owner authorised this historical setup on 2026-10-28 (subsequently extended to the second named test):** migrate the fresh UTF8/template0 child using the repository's real migration files in normal filename order through `2026_11_01_000200_grant_branch_batch_hold_permissions.php` (the last pre-STK-3 schema, including the later supplier/cost dependencies the original test unwinds). No hand-written DDL, edited migration, reordered migration or changed down method. Its entire existing body remains unchanged. Result: **1 passed / 60 assertions / 1.018 seconds**, log `historical-rollback.log`.

Added NEW `tests/Feature/Inventory/PostgresReceivingRollbackTest.php::test_current_schema_refuses_old_rollback_and_receipt_drop_without_losing_any_rows`. It uses a separate fully migrated, connection-verified UTF8/template0 child with posted synthetic receipt/delivery/lot evidence. The real migrator rollback refuses via the existing retained-evidence guard before reaching its DROP; the exact receipt-table DROP is separately refused by PostgreSQL with **2BP01**, naming the delivery-evidence FK. Full ID-ordered table snapshots, including migrations and evidence, remain identical after each refusal. The child is always dropped in finally. Result: **1 passed / 15 assertions / 1.729 seconds**, log `current-rollback.log`.

**Required future PR statement:** rolling back the old Inventory Operations migration on a database holding STK-3 evidence is now deliberately refused. The existing populated-evidence guard remains, and the new restrictive foreign keys additionally prevent destructive table removal. No CASCADE, trigger disablement or FK removal is used.

## Resolved stop: immutable cleanup and owner-authorised test isolation

The authorised delivery-reference fixtures are now applied. A different existing regression failed in teardown, beyond that fixture authorisation:

```text
Tests\Feature\PostgresDispensaryInventoryRegressionTest::test_concurrent_purchase_order_approval_and_cancellation_have_one_versioned_winner
SQLSTATE[P0001]: Verified receiving evidence is append-only
SQL: delete from "inventory_goods_receipt_lines" where "organisation_id" = 56
```

Test declaration: `tests/Feature/PostgresDispensaryInventoryRegressionTest.php:808`. Teardown calls `deleteOrganisation()` at line 122; its delete loop is line 1377. The new statement-level immutability trigger rejects DELETE, including an empty DELETE. A read-only count on this task-owned database confirmed organisation 56 had zero receipt lines, zero receipts and zero delivery-evidence rows. Receiving tests in the same class subsequently populate those protected tables, so merely permitting an empty delete would not solve the fixture design.

Command: `php artisan test --compact tests/Feature/PostgresDispensaryInventoryRegressionTest.php --filter='test_concurrent_purchase_receipts_cannot_over_receive_the_order|test_concurrent_cross_purchase_order_receipt_key_has_one_controlled_winner|test_concurrent_purchase_order_approval_and_cancellation_have_one_versioned_winner' --stop-on-failure --stop-on-error`.

Result: **0 passed, 1 error, 6 assertions, 1 risky**, one test reached, 747 ms. Log: `/private/tmp/kpone-stk3-evidence/existing-pg-receiving.log`. This class and its assertions, names, expectations and cleanup remain untouched. No further existing failure is known from that stopped run.

**Owner authorised test-infrastructure isolation on 2026-10-28. Applied:** every test in this class creates its own uniquely named `kpone_dispensary_inventory_<16 random lowercase alphanumeric>_testing` database with UTF8/template0. Setup verifies configured host/port/name before its first child query, then checks actual host/port/database/encoding before migrations. The worker factory supplies explicit child connection settings; no worker argument, READY/GO protocol, contention step or timeout changed. Setup failure and teardown use guarded database dropping; teardown stops workers first, purges child/observer connections and drops with FORCE, never deletes protected evidence. All 29 existing test bodies are byte-for-byte identical to HEAD. The old unused deletion helper remains unchanged but is no longer called.

CI `.github/workflows/tests.yml` uses the PostgreSQL 18 service with `POSTGRES_USER: postgres`, the same user/database-creation pattern already relied upon by `PostgresBatchHoldTest`. No extra privilege beyond database creation plus ownership to drop the created database is required. Local task user verification returned `postgres|rolsuper=true|rolcreatedb=true`. GitHub CI itself has not been run for this uncommitted candidate.

Timing: the same approval/cancellation test previously errored in cleanup after **747 ms**; after isolation it passes **1 test / 6 assertions in 1,281 ms** (534 ms longer, but the old result was a failure, not a valid passing baseline). The three originally targeted tests pass **3 / 23 assertions in 3,740 ms**. Logs: `existing-pg-single-isolated.log` and `existing-pg-isolated.log`. The full class then exposed the separate migration rollback error below. A post-run check found **zero** child databases with this prefix: cleanup also succeeded after that error.

## Owner decisions and deliberate changes

- Exception approval permission `inventory.receiving.exceptions.approve.branch`: exactly `ca_supervisor`, `finance_officer`, `director`. Fresh effective-branch authority, independent approver, no implied receiving or hold-release authority.
- **Delivery reference is REQUIRED on every new receipt, including the parked demo receiving flows.** This is a deliberate behaviour change. It is bounded plain text, part of the retry fingerprint, never copied into audit. A normalised duplicate reference for the same organisation, supplier and branch refuses a second new receipt; an identical UUID retry returns the original.
- **Every new receipt lot needs a real human-entered future expiry.** Disabled SKU expiry tracking refuses with: “This item has no expiry tracking. A real expiry date is required before it can be received. Review the item setup.” No invented date or historical rewrite. Known sentinel input is refused. STK-2 eligibility and dispensing code are unchanged.

## Implementation

- Exact thousandth arithmetic uses `StockUnitQuantity`; no procurement quantity floats or silent rounding. Identity factor 1.000 only, submitted non-base units refused. Integer-sen receipt costs use integer validation, with no valuation or historical cost update.
- `ReceivingPayload` supports partial receipts and split lots with separately entered physical quantity and exact accepted/quarantined/rejected conservation. Maximum 100 lines, 20 lots per line, 100 lots total. Wrong-item lots can only be rejected, never substituted silently.
- `ReceivingReviewService`: append-only request, independently approved frozen payload/hash and source version, fresh scoped authority, UUID replay/conflict handling and mandatory values-free audit. Overage, explicitly declared short delivery and nonaccepted outcomes require approval before posting.
- Approved overage is retained separately from applied order quantity. The existing PO constraint `received_quantity <= ordered_quantity` remains intact; actual stocked quantity is represented in movement and new lot evidence.
- Accepted and quarantined lots enter stock; rejected lots create no batch or stock movement. Quarantine places an existing STK-2 branch hold atomically with posting. Release remains the existing Finance/Director different-person path, with no second mechanism. The entire batch at that branch is held, including earlier stock or an accepted lot of the same batch.
- `ReceiptCorrectionService`: approved whole-receipt reversal, original receiver only, new immutable correction/consumption evidence and linked `adjustment_out` movements. Original receipt and movements are retained. Closed orders and receipts with subsequent outgoing batch movements refuse correction. No automatic hold release, supplier return or payment workflow.
- New receiving form, review/approval/history and correction controls use existing Inventory authority and explicit redirects. Evidence lists are lazy, bounded at 25 rows per page with batched reads. Delivery metadata includes receiver id and branch-local presentation time. Branch switches clear loaded receiving state. Real Chrome verification is recorded in the evidence section below.
- New private receiving routes have existing session receipt nonce and throttles; no public route, job, dependency or outbound application call.
- Clinical/dispensary code and `BranchAccessService` have no diff.

### Files changed

27 changed/new tracked-candidate paths in this worktree (including this report):

| Area | Files |
| --- | --- |
| Domain | `app/Domain/Access/PermissionCatalogue.php`; Inventory services `ProcurementService.php`, `InventoryMovementService.php`, `InventoryOperationsDirectoryService.php`, `ReceivingPayload.php`, `ReceivingReviewService.php`, `ReceiptCorrectionService.php`. |
| HTTP | `app/Http/Controllers/InventoryController.php`, `InventoryOperationsController.php`; `routes/web.php`. |
| UI | `resources/js/components/inventory/InventoryOperationsPanel.vue`, `VerifiedReceivingForm.vue`, `ReceivingReviews.vue`; `resources/js/types/inventory-operations.ts`. |
| Schema | The three additive migrations listed below. |
| Existing fixtures only | `tests/Feature/Clinical/InventoryOperationsTest.php`; `tests/Feature/PostgresDispensaryInventoryRegressionTest.php`; `tests/Support/PostgresDispensaryInventoryWorker.php`. |
| New tests/support | `tests/Feature/Inventory/VerifiedReceivingTest.php`, `PostgresVerifiedReceivingTest.php`, `PostgresReceivingRollbackTest.php`; `tests/Support/VerifiedReceivingFixture.php`, `PostgresVerifiedReceivingWorker.php`; `tests/Frontend/verified-receiving.test.mjs`. |
| Documentation | `docs/reports/STK-3.md`. No screenshot, private evidence or environment file is in the candidate diff. |

### Migrations and tables

| Additive migration | Changes |
| --- | --- |
| `2026_11_02_000100_create_receipt_delivery_evidence.php` | `inventory_receipt_delivery_evidence`: required reference, scoped unique reference key, receiver/time/version, restrictive FKs, scope and immutable guards. No backfill. |
| `2026_11_02_000200_grant_receiving_exception_approval.php` | Approved permission/holders only; `firstOrCreate`, missing grants only, missing roles logged/skipped; down never revokes. Catalogue agrees. |
| `2026_11_02_000300_create_verified_receiving_evidence.php` | Six tables: `inventory_receiving_reviews`, `inventory_receiving_approvals`, `inventory_receipt_line_evidence`, `inventory_receipt_lots`, `inventory_receiving_consumptions`, `inventory_receipt_corrections`; conservation/scope/independent approval and immutable guards. Also protects existing receipt headers/lines against update, delete and truncate, exposing the legacy teardown conflict above. |

Seven new tables total. Evidence migrations retain evidence on down. Existing databases would need `php artisan migrate`; this candidate is not production-approved.

### Compatibility limits requiring review

A legacy flat receipt line is emitted only for a single stocked lot. Split-lot receipts use the new canonical line/lot tables rather than fabricating a single batch for an aggregate. Consumers that assume every receipt has legacy flat lines require review before handoff. Corrections are whole-receipt only and conservative. Backend source-version checks refuse stale posting; a stale review can remain visible until refreshed. Normal receipts without exception reviews have summary-only history; their canonical line/lot rows are retained. See validation and open questions below.

### Read-only pilot review: expiry tracking switched off

Tracking is stored on `inventory_skus`, not as a separate `inventory_items` expiry flag. An authorised operator can run the following on their approved environment, using the selected organisation id; it is not an application repair and was **not run against any real database**:

```sql
SELECT count(*) AS skus_without_expiry_tracking
FROM inventory_skus
WHERE organisation_id = :organisation_id
  AND expiry_tracking_required IS FALSE;

SELECT s.id, s.sku_code, s.is_active, i.code AS item_code
FROM inventory_skus AS s
JOIN inventory_items AS i
  ON i.id = s.inventory_item_id AND i.organisation_id = s.organisation_id
WHERE s.organisation_id = :organisation_id
  AND s.expiry_tracking_required IS FALSE
ORDER BY s.id
LIMIT 25 OFFSET :offset;
```

Count includes inactive SKUs so the review does not silently omit old setup. Page through the bounded list; no automatic flag change, batch rewrite or historical repair is proposed.

## Verified first-action survey

All references below are in the isolated worktree at base `dded476d813ccb0d9a3909ef4191f19a1d231838`. Paths abbreviated as inventory services/models are under `app/Domain/Organisation/Inventory/`.

| Subject | Verified current behaviour and source |
| --- | --- |
| Order creation | `Services/ProcurementService.php:38–59,324–337`: creates a draft with supplier, destination, generated PO number, creator and version; inserts one line per SKU. No stock movement at order creation. Draft replacement is allowed before submission (`:63–81`). |
| Order states / approval | `ProcurementService.php:85–140`: submit, approve by a different actor from creator, cancel, close. Database state allowlist: draft, submitted, approved, partially_received, fully_received, closed, cancelled (`database/migrations/2026_09_11_000100_create_inventory_operations.php:268`). Order locks/version checks at `ProcurementService.php:268–283`. |
| Receipt HTTP boundary | `app/Http/Controllers/InventoryOperationsController.php:121–141`: verifies the session receipt nonce, then passes expected branch, order version, UUID idempotency key and lines to `receive`. Validation is explicitly redirected to Inventory; no new redirect design is needed. No delivery reference/outcome/exception payload exists here. |
| Receiving UI | `resources/js/components/inventory/InventoryOperationsPanel.vue:414–458`: builds existing per-order-line receipt payload, obtains/reuses a UUID intent tied to remembered context, submits session nonce and order version. Existing input surface includes batch number/expiry, not split-lot outcomes or exception approval. Preserve secure session/context and retry semantics when extending it. |
| Receipt transaction | `ProcurementService.php:144–200`: active branch, fresh receive authority, locked order/destination, UUID and normalised-line hash; matching retry returns the same receipt, conflicting order/hash refuses. New receipts require approved or partially_received status and current version. All lines are planned before posting and SKU locks are ordered. |
| Posted receipt | `ProcurementService.php:202–235`: resolves batch, creates receipt line, records purchase receipt movement, adds to received quantity, advances PO status and version, then records audit in the same transaction. There is no receipt draft, inspection outcome, independent delivery-exception approval or correction action in this flow. |
| Partial / over receipt | `ProcurementService.php:172–175,229–232`: smaller quantities are accepted, remainder leaves the order partially_received; more than remaining plus a float epsilon is refused with `A received quantity exceeds the remaining Purchase Order quantity.` No approved-overage path exists. PostgreSQL also constrains `received_quantity <= ordered_quantity` (`2026_09_11_000100_create_inventory_operations.php:269`). An approved exception will need explicit new evidence/accounting of the authorised excess, not removal of the old no-silent-overage protection. |
| Substitution | The submitted line is resolved from this order; the SKU comes from that order line (`ProcurementService.php:165–179,205`). No submitted substitute SKU or approved substitution path exists. Do not quietly replace the order line's SKU. |
| Lots | Receipt validation makes `lines.*.line_public_id` distinct (`ProcurementService.php:361–380`); the database uniquely pairs receipt/order line (`2026_09_11_000100_create_inventory_operations.php:102`). Today one line has one batch within one receipt. Multiple receipts can address a partially received line. Split lots within one receipt need additive child evidence, retaining old receipt lines. |
| Batch creation | `ProcurementService.php:386–410`: new/reused identity is SKU + batch number + expiry; new batches start available with received date in branch timezone. Today/past expiry and non-available existing batches refuse. Independently, `InventoryReferenceAdministrationService.php:225` exposes reference batch creation. That reference operation does not itself record opening stock. |
| Batch statuses | `Models/InventoryBatch.php:13–17`: **available, quarantined, damaged**. STK-2 holds are separate branch evidence, not another global status. |
| Opening / stock quantity | `InventoryMovementService.php:24–56` records a separate, one-time opening balance with opening authority; receiving does not call that opening API. `recordPurchaseReceipt(:122–149)` increases balance and writes a `purchase_receipt` movement referencing the goods receipt. Balance update helpers `:211–251` use the STK-1 exact contract and versioned update. |
| Quantity representation | PO and receipt quantities are PostgreSQL decimal(15,3), not integer counts (`2026_09_11_000100_create_inventory_operations.php:64–65,96`). `StockUnitQuantity.php:7–45` provides exact thousandths, bounded range, no precision loss and base-unit-only conversion; movement posting already calls it. |
| Legacy floats | `ProcurementService.php:172–174` subtracts and compares float quantities with epsilon; `:229` sums received quantity with float and formats; order validator `:350–353` and receipt validator `:372–375` convert/format via floats. These are verified hardening targets explicitly anticipated by STK-3, not reasons to keep floats. `InventoryMovementService.php:326` also validates integer-sen input using float/floor, despite storing integer sen; new verified receiving must avoid that float validation path without changing historical costs or adding valuation. |
| Costs | `2026_10_01_000200_add_unified_catalogue_setup_and_costing.php:57–69` adds nullable integer-sen estimated PO unit cost, receipt unit cost, and movement unit cost/supplier id. `ProcurementService.php:209,217,226` uses actual submitted cost if supplied, otherwise the order estimate. Existing costs are evidence, not an AP/payment or selling-price update. UI `InventoryOperationsPanel.vue:48–62` converts bounded RM text through BigInt before Number; this is not the procurement quantity-float path. |
| Existing evidence fields | PO: UUID, organisation, supplier/destination, order number, status/version, create/submit/approve/close/cancel actors and times, cancellation reason, timestamps (`2026_09_11_000100_create_inventory_operations.php:28–55`). PO line: UUID, organisation/order/SKU, ordered/received quantities, timestamps (`:58–70`) plus estimated sen cost. Receipt: UUID, organisation/order, UUID key/hash, receiving actor/time, timestamps (`:73–86`). Receipt line: organisation/receipt/order-line/SKU/batch, quantity, timestamps (`:89–102`) plus sen cost. No delivery reference, accepted/quarantined/rejected quantities, exception approval or correction chain is present. |
| Bounded projection | `InventoryOperationsDirectoryService.php:28–31` currently returns the latest **50** orders with batched line reads (`:74–78`), not 25-row paginated receiving. STK-3 should page only its receiving/order evidence surface; do not silently redesign parked transfers/stocktakes. |

### Existing tests and PostgreSQL worker

- `tests/Feature/Clinical/InventoryOperationsTest.php:120–177`, `test_purchase_order_lifecycle_partial_and_final_receiving_are_atomic_and_idempotent`: partial 4.000 then final 6.000, same-key replay, exactly-once movements, actual cost versus fallback estimate and received totals.
- Same file `:179–242`, `test_goods_receipt_service_rejects_noncanonical_quantity_representations_without_mutation`: strict refusal of whitespace, signs, exponents, excessive precision/range and zero; baseline receipt/batch/balance/movement/audit invariance. These expectations must not be weakened by reusing a more permissive parsing helper.
- Same file `:244,307`: missing/foreign/session-regenerated receipt nonces refuse before mutation.
- Same file `:358`: idempotency key cannot cross orders; `:392–425`: unapproved order, overage and PO self-approval refusal with no receipt/batch/movement side effect.
- `tests/Feature/PostgresDispensaryInventoryRegressionTest.php:808–845`: concurrent PO approval/cancellation and concurrent receipt contenders; exactly one receipt, one stale-version loser and one purchase movement.
- Same file `:846–866`: cross-order UUID collision has one controlled winner.
- `tests/Support/PostgresDispensaryInventoryWorker.php:246–255` calls the same `ProcurementService::receive` with expected branch, version, UUID and one lot, then reports `RECEIVED`. It is not an independent posting implementation.

This is the original base-code survey. Subsequent authorised fixture changes and executed validation are listed below; its line references describe the base, not the modified candidate.

## Every changed existing assertion and fixture

**Existing assertions changed: none.** No expected value, test name, timeout, contention protocol or skip changed. No hub, permission-list or staff-authority assertion changed. All 29 bodies in `PostgresDispensaryInventoryRegressionTest` are byte-identical to HEAD. Only the setup/teardown changes below were authorised. Exactly **two** historical migration tests need the historical schema; no third test or other regression class has needed it.

| File / test or worker | Current line | Old setup | New setup and reason | Runtime |
| --- | --- | --- | --- | --- |
| `tests/Feature/Clinical/InventoryOperationsTest.php`, `receiptAttributes` (all callers, including `test_purchase_order_lifecycle_partial_and_final_receiving_are_atomic_and_idempotent`) | 988 | No delivery reference. | Add only `'delivery_reference' => 'SYN-DELIVERY-'.$key`. Owner-authorised synthetic setup for the deliberately required field; retry retains its reference. Quantities, real expiry and assertions unchanged. | Existing class: 24 passes / 687 assertions, 10.901 s in the scoped run. |
| `tests/Support/PostgresDispensaryInventoryWorker.php`, `purchase-order-receive` | 249 | No delivery reference. | Add only `'delivery_reference' => 'SYN-PG-DELIVERY-'.(string) $argv[6]`. Owner-authorised setup; outcomes/protocol unchanged. | Measured with the class below, not as a standalone test. |
| `tests/Feature/PostgresDispensaryInventoryRegressionTest.php`, class setup/teardown, `dropChildDatabase`, worker connection setup (all 29 methods) | 97, 154, 176, 1258 | Shared test database; organisation-row DELETE cleanup; worker inherited DB environment. | Owner-authorised test-infrastructure isolation: unique UTF8/template0 child per test, configured child connection/name and actual database/UTF8 verification before fixture queries (CI correction above), explicit host/port/child settings for workers, stop workers and always drop the guarded child database, including failures/timeouts. No assertion change. | Same single approval/cancellation test: failed cleanup at 0.747 s before, passes at 1.281 s after; not a passing before-baseline. Targeted 3 tests: 3.740 s. Full unchanged class: 29 passes / 468 assertions / 35.325 s. |
| Same file, setup only for `test_inventory_operations_migration_empty_rollback_and_reapply_use_the_laravel_migrator` (body line 718) | 128–138 | Current schema prevented historical table-drop assertions through STK-3 FKs. | Owner-authorised test-infrastructure isolation: run only real repository migrations, normal filename order, through `2026_11_01_000200_grant_branch_batch_hold_permissions.php` (last pre-STK-3 schema, retaining the supplier/cost dependencies its body unwinds). Original body unchanged; no hand-written DDL or migration/down edits. | 1 pass / 60 assertions / **1.018 s**, `historical-rollback.log`. Earlier failed-class run was 26.840 s; no standalone pre-isolation duration was recorded. |
| Same file, setup only for `test_inventory_operations_migration_refuses_every_retained_evidence_table_and_new_movement_without_changing_migrator_evidence` (body line 782) | 128–138 | Current receipt immutability refused its internal per-table fixture cleanup DELETE. | Same owner-authorised real-migration historical setup. Preserve every body statement, including internal cleanup, so it still proves the historical guard contract. Current-schema protection is proved separately below. No assertion change. | 1 pass / 168 assertions / **1.349 s**, `historical-retained.log`. Earlier failed-class run was 27.938 s; no standalone pre-isolation duration was recorded. |

New tests are not changes to pre-STK-3 assertions: `PostgresReceivingRollbackTest::test_current_schema_refuses_old_rollback_and_receipt_drop_without_losing_any_rows` (line 18) proves retained-evidence rollback refusal, exact PostgreSQL **2BP01** receipt-table-drop refusal and full ID-ordered row preservation on the current schema. It passes 1 test / 15 assertions / 1.729 s in its scoped run. `VerifiedReceivingTest::test_receipt_evidence_is_utc_and_local_display_is_independent_of_database_session_timezone` (line 533) proves UTC storage and New Year branch-local presentation under both UTC and Asia/Kuala_Lumpur database sessions (1 test / 8 assertions / 1.238 s).

CI uses the PostgreSQL 18 service with `POSTGRES_USER: postgres`, the same database-creation pattern already used by merged `PostgresBatchHoldTest`. CREATE DATABASE plus ownership to drop its own database is sufficient. Local task user is postgres with CREATEDB; no CI permission or workflow change. Effective settings and actual server/database identity are checked before fixture SQL. Child cleanup was verified after failed and successful runs; zero prefixed children remained. All pre-existing production migrations and immutability protections are untouched.

**Future PR statement:** rolling back the old Inventory Operations migration on a database holding STK-3 evidence is now deliberately refused. The existing populated-evidence guard refuses first; new restrictive foreign keys independently refuse destructive removal of referenced receipt tables. No FK removal, CASCADE drop, trigger bypass or evidence deletion is used.

## Original full validation (9bd63cd)

Task-owned **PostgreSQL 18.6**, **127.0.0.1:58664**, cluster `/private/tmp/kpone-stk3-pg18`, UTF8/template0 databases. Effective host, port, database name, encoding, server version and data directory were verified before application use. Final CI database **kpone_stk3_final_testing**; separate final seed database **kpone_stk3_seed_final_testing**; final Chrome database **kpone_stk3_preview_utc_testing**. Earlier task databases are retained synthetic diagnostics, not production data. Child regression databases are uniquely prefixed and always dropped.

PHP **8.4.26**, task-only `memory_limit=1G` and Composer 1 GiB; Node **22.23.3**, npm **10.9.9**. Explicit placeholder environment only; no `.env` or `.env.preview` opened or created. No new dependency. Dependency directories are independent copies, not writable symlinks into another worktree.

| Final check | Result | Log under `/private/tmp/kpone-stk3-evidence/` |
| --- | --- | --- |
| Exact `composer ci:check` | **Passed, exit 0** | `final-ci-check.log` |
| Full backend | **1,520 passed / 30,798 assertions / 2 existing skips**, 763.003 s; 1,522 tests reached | `final-ci-check.log` |
| CI frontend behavior test command | **513 passed**, no skips | `final-ci-frontend.log` |
| Full `node --test tests/Frontend/*.mjs` | **513 passed**, no skips, 2.448 s | `final-gate-frontend.log` |
| PHP syntax, every new/changed PHP file | **21 files passed** | `final-php-lint.log` |
| Pint `--parallel --test` | **Passed** | `final-ci-check.log` |
| PHPStan configured level 7, memory-limit 1G | **Passed, zero errors** | `final-ci-check.log` |
| Vue/TypeScript | **Passed** | `final-ui-types.log` |
| ESLint | **Passed** | `final-ui-lint.log` |
| Prettier | **Passed** | `final-ui-format.log` |
| Production build | **Passed**, 1.80 s | `final-ui-build.log` |
| Separate `migrate:fresh --seed --force` | **Passed**, separate owned database, both reference/development seeders complete | `final-fresh-seed.log` |
| Tracked and new-file whitespace; complete review patch | **Passed** | `/private/tmp/kpone-stk-3.diff` |

The two unchanged skips are `tests/Feature/Auth/RegistrationTest.php::test_registration_screen_can_be_rendered` and `::test_new_users_can_register`: public registration remains disabled. No PostgreSQL test was skipped. No test was weakened, removed or newly skipped.

CI validation commands ran in repository workflow order after already-installed dependencies: `php artisan wayfinder:generate --with-form --no-interaction`; `node --test tests/Frontend/*.test.mjs`; `php artisan migrate --force`; `npm run build`; `composer ci:check`. That Composer script runs npm lint, format and types, then `php artisan config:clear --ansi`, Pint, PHPStan and `php artisan test`. The full frontend glob and separate fresh seed are additional gate commands. Frontend checks/build were repeated after the final local wrapping/count wording fixes; PHP/domain source was unchanged during that final backend run. CI checkout/dependency installation was not rerun, no real environment file was used, and remote GitHub CI is not claimed.

The earlier replay (`ci-check.log`) was deliberately interrupted after the Chrome UTC defect was found. It is not claimed as a backend pass; the clean final replay above is the gate evidence. Earlier stopped logs remain for history: missing-reference fixture `operations-delivery.log` (2 passes / 1 error / 56 assertions), immutable cleanup `existing-pg-receiving.log` (0 passes / 1 error / 6 assertions / 1 risky), historical empty rollback `existing-pg-class-isolated.log` (21 passes / 1 error / 221 assertions), and historical internal cleanup `pg-class-historical.log` (22 passes / 1 error / 353 assertions). All were resolved only by owner-authorised fixture setup. No production protection was changed to make them pass.

### Safety and query evidence

- `VerifiedReceivingTest::test_full_unrelated_row_comparison_review_approval_and_post_audit_failure_rolls_back`, `::test_post_changes_only_declared_receiving_rows_and_expected_stock_and_audit_contains_no_reference`, and `::test_correction_audit_failure_rolls_back_and_correction_evidence_is_immutable`: full ID-ordered unrelated-table comparisons, pre-existing movement/audit prefixes unchanged, only declared receiving evidence/expected balances and movements may differ. Clinical, billing, payment, document and unrelated stock tables remain identical. Mandatory audit failure rolls back request, approval, receipt and correction.
- `::test_foreign_organisation_and_branch_are_isolated_and_page_is_bounded_without_patient_queries`: 32 reviews, only 25 returned, at most 25 SQL queries, no patient/clinical/billing/credential reads. Separate synthetic measurement: **12 SQL queries with 1 returned review**, and **12 with 25 returned reviews from 33 total** (`query-budget.json`). No per-row queries.
- `PostgresVerifiedReceivingTest::test_concurrent_receipts_and_duplicate_delivery_references_have_one_post_and_no_lost_audit`: independent connections and different receivers; observed PostgreSQL lock wait; one controlled winner, one receipt, one movement and one audit; prior movements unchanged.
- `PostgresReceivingRollbackTest`: real migrator refuses retained evidence before DROP; the exact DROP step separately raises **2BP01**; full rows including evidence and migrations unchanged. Update/delete/truncate protection is tested separately on all declared immutable tables.
- The new UTC regression pins **31 Dec 2026 16:30 UTC → 01 Jan 2027 00:30 Asia/Kuala_Lumpur** under both UTC and Asia/Kuala_Lumpur PostgreSQL sessions. New, unreleased timestamp columns follow the existing receipt convention of UTC `timestamp` values. No existing database row, receipt, movement or clinical timestamp was rewritten.
- No diff in Clinical/Dispensary services, `BranchAccessService`, billing or document source code. Existing suites pass in the full gate.

## Chrome evidence and local artifacts

Real Chrome, production build, synthetic `.test` accounts only. Final preview used database `kpone_stk3_preview_utc_testing` and storage `/private/tmp/kpone-stk3-evidence/preview-utc-storage`. Tests use separate temporary test storage. PHP's built-in HTTP server was launched from `public`, with explicit environment and `variables_order=EGPCS` so the configured storage root is effective. No test fake-storage helper operated on the live preview storage. The earlier pre-fix synthetic preview database/files were retained, not rewritten.

Private manifest: `/private/tmp/kpone-stk3-evidence/evidence-manifest.json`; viewport measurements: `viewport-measurements.json`; screenshots: `/private/tmp/kpone-stk3-evidence/screenshots/`. **30 final JPEGs**, prefix `final-`, local only, not committed or attached. Every normal app capture verifies `innerWidth === document.documentElement.scrollWidth` at exactly **390** or **1440** CSS px.

| Evidence | Local screenshot filenames (prefix `final-`) |
| --- | --- |
| Filled split lots, accepted and quarantined outcomes | `{390,1440}-{light,dark}-split-quarantine.jpg` |
| Retained exception request and no posting before approval | `{390,1440}-{light,dark}-exception-request.jpg` |
| A different Director's live approval control | `{390,1440}-{light,dark}-independent-approval.jpg` |
| Approved state / original receiver's post control | `{390,1440}-light-approved.jpg`, `{390,1440}-light-approved-receiver.jpg` |
| Posted receipt, correct branch-local time, retained lot outcomes | `{390,1440}-{light,dark}-posted-quarantine.jpg`; readable details `390-dark-posted-detail.jpg`, `1440-light-posted-detail.jpg` |
| Existing STK-2 screen: quarantine batch held, different checker needed to release | `{390,1440}-{light,dark}-quarantine-hold.jpg` |
| Marketing's Inventory 403 under both preferences | `{390,1440}-{light,dark}-preference-denied-marketing.jpg` |

The built-in 403 page is fixed light even when the application's selected preference is dark; this existing behavior is recorded honestly and unchanged. The final normal receiving/approval screens are verified in both actual themes. A long supplier summary next to the approver's receiving screen originally overflowed mobile; only its local wrapping classes in `InventoryOperationsPanel.vue` changed, with no supplier behavior or permission change. New history counts use singular for 1. Final Chrome console error/warning check was empty.

The exercised receipt contains **3.000 accepted + 2.000 quarantined = 5.000 physical**, requested by the CA Supervisor and approved by a different Director. Original receiver then posted; the existing hold page shows `Held: damaged` on the quarantined batch and no active hold on the accepted batch. No release was performed or second release path added.

## Known limits and open questions

1. Quarantine is an entire-batch hold at one branch, not an isolated sub-lot/global recall. Earlier stock or accepted quantities sharing that batch at that branch are also held. Finance/Director existing independent release rules remain the only release path.
2. Wrong product is recorded as rejected, not accepted as an approved substitution. A broader substitution workflow is deferred. Every new line still requires a real future expiry; no no-expiry item type or professional waiver is introduced.
3. Approved overage is new explicit lot/line evidence and expected movement quantity; old PO received totals remain capped at ordered quantity. Normal legacy flat receipt lines are retained only for a single stocked lot; split-lot consumers must use the new canonical evidence. No fabricated single-batch aggregate.
4. Corrections reverse the entire new verified receipt, by its original receiver after independent approval. Closed orders, historical unverified receipts, or stock already moved out require owner review. No partial correction, supplier return, cost revaluation or automatic hold release.
5. Review/history page size is 25 each; 100 receipt lines, 20 lots per line and 100 lots total. The pre-existing purchase-order summary remains capped at 50; this slice does not redesign all parked operations. Normal posted history is summary-only if no exception review exists; lot evidence is retained in PostgreSQL. Stale approval reviews remain visible until refreshed, with backend stale-source refusal.
6. Professional questions remain unanswered: responsible clinician, regulated-item handling, inspection/cold-chain acceptance criteria, FEFO policy, registers and retention are **to confirm with a pharmacist or the regulator**. No production pilot approval is implied by this local gate.

STK-4 through STK-10, AP/supplier payments, supplier returns, valuation, printable purchase orders, transfers, stocktake, imports, notifications and dispensing changes remain deferred and unauthorised. No real supplier, medicine, price or personal data was used.

Full uncommitted review diff: `/private/tmp/kpone-stk-3.diff`. Original survey diff: `/private/tmp/kpone-stk-3-survey.diff`. Final state: task-owned HTTP server and PostgreSQL cluster stopped, no child regression databases remaining; served tree clean on main and untouched. PR #183 remains open. This owner-authorised CI fix is a new commit on the same branch; no merge or force-push.
