# STK-1 — Unit contract and pilot data readiness

Date: 2026-10-27. Branch `feature/stk-1-unit-contract`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-stk-1`. Base `72f92d135608392085415c597fecf56acca88e36` (PR #179 merged). Started from `0d9daf3`, fetched/rebased onto current main before the full gate; no conflicts. Uncommitted work was preserved/restored around the rebase. No commit, push or PR. Not production-approved.

## Scope and contract

One existing `inventory_skus.stock_unit` remains the base unit for that SKU. No SKU, unit or demo quantity is imported, rewritten, converted or automatically verified. Reference managers can explicitly verify the current base into an immutable `inventory_unit_contracts` record. One contract per SKU; verified base units cannot be changed in PostgreSQL. Corrections/replacements of a verified contract are outside this slice.

The supported direction is **input → base**, identity factor **1.000**, precision **three decimal places**, maximum **999999999999.999**. `StockUnitQuantity` parses plain decimal strings/integers into integer thousandths; equivalent trailing-zero representations remain equal. Floats, exponent notation, nonpositive quantities, precision loss, overflow and non-base input units are refused. No rounding or guessed pack/strip/tablet conversion. Existing purchase/dispensing labels and `unit_conversion` fields are preserved; they do not silently authorise conversion.

The central movement boundary and locked balance arithmetic use exact quantities. Explicit non-base opening inputs are refused, and dispensing checks saved source units against the SKU base and refuses unsupported mappings/units. Existing workflows are not activated for the pilot by this work. Parked procurement, stocktake and transfer planning still contain legacy upstream float calculations; this slice does not certify or authorise those processes for production. Their later slices must review those calculations before activation.

New movements carry a `unit_snapshot` with the applied base/input unit, direction, factor and precision. The PostgreSQL insert trigger validates/fills identity evidence. The column is additive and nullable: old movements remain NULL, with no backfill or manufactured conversion history. Original raw-input precision is checked by services before numeric-column coercion; PostgreSQL cannot recover decimal digits already coerced by its numeric typemod.

## Mapping checks and bounded review

The existing `medicine_inventory_one_active_unique` index already prevents a second active mapping per medicine. It is retained unchanged. New tests pin PostgreSQL's `23505` refusal. The dispensing guard requires one active Medicine → Item → SKU path and refuses missing/ambiguous/wrong-SKU paths rather than selecting one. Completion also rechecks the mapping set and saved unit. No automatic mapping repair.

`/inventory/readiness`, linked from Inventory, is a branch-scoped read-only projection with separate verification actions. It lists active SKUs in pages of 25, with bounded search, base unit defined/verified, mapping status, opening count and signed count. Pending openings are separately paged at 25 and show SKU, location, batch/expiry and quantity in the base unit. Visible limits and page controls prevent hidden inaccessible results. Mapping/opening data is batched with grouped/joined queries, not per-row database lookups. Existing bounded picker contracts are unchanged.

This is a review checklist, not a production-dispensing gate or a clinic go-live approval. Legacy demo SKUs are not automatically certified and existing base-unit demo workflows are not globally disabled. A pilot still requires the owner's operational/professional approvals and completed readiness checks.

## Permission decision and authority

Owner approved on **2026-10-27**: `inventory.opening.verify.branch` belongs to exactly **CA Supervisor (`ca_supervisor`), Finance Officer (`finance_officer`), Director (`director`)**. No other default holder; specifically none for Administrator, Technical Admin, CA, Resident Doctor, Panel Officer or TV. Exact catalogue-role tests pin this set.

Base-unit verification reuses `inventory.references.manage.organisation`; it adds no new reference permission. Inventory read reuses `inventory.view.branch`. Reference managers can verify a unit without gaining opening sign-off. Opening verification requires a fresh grant, active actor and effective assignment at the selected branch. The checker must differ from the original opening's `actor_user_id`; role name is not used as mutation authority. Existing CA-Supervisor-only opening entry rules remain unchanged.

Mutations use `InventoryAuthorityService`, transactions, consistent actor/SKU/movement locks, pending evidence version 0 → immutable version 1, UUID retries with HMAC payload-conflict refusal and fresh authority checks before replay. Audit failure rolls back the verification. Audit metadata contains the new evidence ID only, with ordinary actor/branch/subject IDs; never quantity, unit text, medicine name or other content. Signing does not alter the original movement or balance. No opening can acquire two sign-offs.

## Migrations and PostgreSQL protection

- `2026_10_31_000100_create_inventory_readiness_evidence.php`: adds `inventory_unit_contracts`, `inventory_opening_verifications` and nullable `stock_movements.unit_snapshot`. Restrictive foreign keys; unique SKU contract, unique opening sign-off, actor-scoped retry keys; fixed direction/factor/precision/version checks; tenant/source/independent-checker guards; update/delete/truncate refusal on both evidence tables; verified SKU base protection; identity snapshot for new postings; a partial opening-review index over organisation/SKU/location/id. `down()` retains evidence.
- `2026_10_31_000200_grant_inventory_opening_verification.php`: `firstOrCreate` permission, only missing grants to the exact three roles; absent roles logged/skipped, never created; permission cache refreshed; no revocation; `down()` is a no-op.

Catalogue and grant migration agree. No new dependency or document type. Existing databases need `php artisan migrate`.

## Every changed existing assertion

**None.** No existing test file, expected value, test name or skip was edited. Existing permission-set tests derive their sets from the catalogue; new tests explicitly pin the approved holder set. No Clinic management destination was added, so its hub map is unchanged; readiness is reached from the existing Inventory screen. Original Inventory payloads and picker shapes are unchanged.

The complete run passed with all existing assertions unchanged. No test was weakened, removed or newly skipped.

## New tests and source invariance

New `StockUnitReadinessTest`, `PostgresStockReadinessTest`, `PostgresStockReadinessWorker` and frontend readiness tests cover:

- Exact grants/all catalogue roles, existing view permission, fresh revocation, ended assignment, foreign branch/organisation and self-sign-off refusal.
- Same/conflicting retries, stale versions, audit-failure rollback for both unit verification and opening sign-off.
- Decimal equivalence, loss/overflow and non-base refusal, verified-base PostgreSQL refusal, protected append-only evidence.
- Existing mapping uniqueness plus explicit wrong/missing path refusal; bounded SKU search/projection and independently paged pending openings; no automatic repair.
- Full ID-ordered before/after comparison of all unrelated public tables around both verification actions, including stock movements, balances, clinical, dispensary and billing sources. Only the two declared evidence tables and audit may differ.
- A separate disposable database is created by the concurrency test. It first runs the pre-STK schema, inserts synthetic legacy opening/balance rows, applies STK migrations and proves all old fields/balances unchanged and old `unit_snapshot` NULL. Two subprocesses use separate connections/checker accounts, both demonstrably wait on the same held SKU lock, then exactly one verifies and one is refused. The isolated database is discarded afterwards; append-only protection is never disabled for cleanup.

Existing one-time opening, inventory, dispensing and billing assertions remain strict.

## Validation history

Task-owned PostgreSQL **18.6**, `127.0.0.1:58634`, cluster `/private/tmp/kpone-stk1-pg18`. UTF8/template0 databases; effective host/port/name, encoding, version and data directory verified before application use. Placeholder process environment only; no `.env` or `.env.preview` opened or created. PHP/Composer validation uses the owner-approved task-only 1 GiB setting. No non-task-owned database is used.

- Early `php -l`, Pint and PHPStan level 7 passed. The first frontend type attempt lacked generated Wayfinder routes in this fresh worktree; generation resolved it, with no source assertion change.
- Unchanged inventory tests: **39 passed / 795 assertions**, then broader inventory run **54 passed / 1,262 assertions**.
- Stock/dispensary/PostgreSQL regression run after new tests: **67 passed / 695 assertions**.
- Initial new-test setup defects were repaired only in new code: nonexistent Branch factory, attempted fixture violating the retained mapping unique index, raw-versus-refreshed JSON attribute comparison, cloned connection name confusing the migration harness, and buffered subprocess input/readiness handling. The migration harness's failed duplicate-table attempts were confined to the task-owned `kpone_stk1_testing` database and refused before any source-row change. Disposable child databases were removed; no foreign database was connected. No production/test limit or existing assertion was weakened.

## Full gate

**Passed**, on the final source candidate. Logs: `/private/tmp/kpone-stk1-evidence/release-gate/`; completed 2026-10-27 at 07:20:17 UTC.

- CI database **kpone_stk1_release_testing**; separate fresh-seed database **kpone_stk1_seed_testing**, both on the verified task-owned PostgreSQL 18.6 at 127.0.0.1:58634.
- PHP 8.4.26, Node 22.23.3, owner-approved task-only PHP/Composer memory limit 1 GiB.
- Exact ordered workflow replay: Composer install; npm ci; Wayfinder generation; frontend `*.test.mjs`; migration; production build; **composer ci:check**; all frontend `*.mjs`; separate database verification and **migrate:fresh --seed**; **git diff --check**. Explicit placeholder process environment replaces the workflow's environment-file copy; no real environment file is used.

| Check | Final result |
|---|---|
| Backend | **1,480 passed / 30,300 assertions / 2 existing skips** (1,482 total; 687.936 seconds) |
| Frontend | **505 passed**, both the workflow selection and full `*.mjs` run |
| PHP syntax, Pint | Passed |
| PHPStan level 7 | Passed, zero errors |
| Types, ESLint, Prettier | Passed |
| Production build | Passed |
| Separate migrate:fresh --seed | Passed |
| git diff --check | Passed, repeated after report completion |

Two earlier full-run attempts were deliberately interrupted for final source review: restoring the existing bounded dispensing mapping selection, then adding the existing reference-service unit-evidence guard. Neither is claimed as the final pass. The final targeted domain/reference run passed **21 tests / 214 assertions**, followed by the complete passing run above. No existing assertion changed.

No test suite ran while preview storage was live. Tests use isolated temporary storage; the later preview uses its own database and `/private/tmp/kpone-stk1-evidence/preview-storage`.

## Chrome evidence

**14 private synthetic screenshots**, manifest `/private/tmp/kpone-stk1-evidence/chrome-manifest.json`, images `/private/tmp/kpone-stk1-evidence/screenshots/`:

- `recorder-{1440,390}-{light,dark}.jpg`: unverified units, mapping warning and self-sign-off exclusion.
- `checker-pending-{1440,390}-{light,dark}.jpg`: verified base and independent Finance Officer sign-off action.
- `signed-{1440,390}-{light,dark}.jpg`: successful sign-off, 1 / 1 status and removal from pending openings.
- `technical-admin-denied-{1440,390}-dark.jpg`: HTTP 403. The fixed error page itself is light regardless of the previous theme; the filename records the preceding dark-theme session, not a dark-styled error page.

The twelve readiness captures measured exact **390 or 1440 CSS px**, with scroll width equal to viewport width. Sign-off target height is **44 px**. Representative 390-light and 1440-dark images were visually inspected. No console errors were recorded before the deliberate denied navigation.

Real UI actions verified the first synthetic SKU's unit as the recorder and its opening as a separate checker. Technical Admin received 403. Full ID-ordered stock movement and balance rows remained identical after both actions; exactly one unit contract and one opening verification were added. Private proof: `preview-source-comparison.log`. This Chrome comparison supplements the automated all-unrelated-table comparison; it does not claim session/login rows are invariant.

The task preview server and task PostgreSQL cluster were stopped after evidence collection. The served tree stayed clean on main; no other worktree, database or storage root was modified. Screenshots are private review artifacts, outside the repository.

## Limits and deferred scope

No import, conversion of demo data, cost, valuation, new document type, new patient/clinical access, automatic correction, messaging or production activation. One branch/limited-category pilot remains an owner decision; there is no hardcoded pilot branch. Transfers and treatment-room consumption remain out of the pilot.

STK-2 batch eligibility/FEFO/quarantine/recall; STK-3 receiving/order evidence; STK-4 owned low-stock/expiry review; STK-5 count/write-off approval beyond this opening sign-off; STK-6 in-transit exceptions; STK-7 supplier returns/AP; STK-8 treatment-room consumption; STK-9 labels/scanning; STK-10 valuation/export are not built or stubbed.

Professional questions remain unanswered: responsible clinician and competent dispenser/checker; FEFO/override policy; regulated-item scope, supervision, registers, storage/cold chain, label and retention requirements. Regulatory matters are **to confirm with a pharmacist or the regulator**; this slice encodes no answer. The checklist and software sign-off are not proof of professional qualification or a correct physical count.

No commit, push or PR until the owner says "open the PR".
