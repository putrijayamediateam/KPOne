# PILOT-SCALE-LIMITS — local candidate, not production-approved

## Scope and base

`fix/pilot-scale-limits`, rebased on owner-specified main **`8732b5599aefe0becb07080c79716b64055d186c`** (PR #175 merged). Original work/measurements started at `349aae4`. The owner authorised the rebase, final mobile check, commit, push and one PR on 2026-10-27; no merge is authorised. Restoring the uncommitted candidate after rebasing conflicted only in `docs/BASELINE.md`: main's Job 1 changes were retained and the Job 2 row/follow-ups reconciled. No application/test conflict. No new permission, migration, dependency, financial definition, report filter, audit or CSV contract; no production database or real data used.

## Owner-requested picker confirmations (2026-10-27)

1. **Confirmed beyond-cap search:** typing `004999` in the Item search returns `SCALE-004999 · Synthetic item SCALE-004999`, selectable from the 5,000-item synthetic fixture despite lying outside the initial 25. Verified in real Chrome after the rebase in light/dark at 390 and 1440 CSS pixels, and retained in the backend scale test.
2. **Confirmed visible limit notice:** every searched picker says **“Up to 25 matches. Search to find more.”** Default queries return 25, server maximum is 100. The operations alert summaries separately explain their 100-entry limit and the full searchable tabs.
3. **Confirmed Search remains visible with the Item list open at 390 px:** the prior layout put the dropdown over the Search row. Search and its limit notice now precede the selector; this new picker's list opens downward, is constrained to the trigger width and wraps text. Shared `OperationalSelect` keeps collision handling enabled by default; only the searched pickers opt out. At 390×844, Search occupies y=344–376 and the list y=444–538, x=33–357; there is no overlap or horizontal overflow. Both themes and desktop passed. Geometry: `/private/tmp/kpone-pilot-scale-evidence/rebase-picker-geometry.json`; four current screenshots under `/private/tmp/kpone-pilot-scale-evidence/rebase-screenshots/`. A new frontend regression pins this layout opt-in and preserves the default for other selectors.

## Changes and root causes

- **A — inventory references:** initial item/SKU/location/medicine/branch/supplier/batch projections used full lists. A shared internal, authenticated picker uses existing `inventory.view.branch`, effective branch and organisation scope, default 25 and maximum 100, explicit PostgreSQL `COLLATE "C"` ordering with an id tie-break, bounded literal search, and private/no-store responses. Pickers retain the selected option while searching. Stale branch and late asynchronous responses are refused/discarded. The existing SKU-batch route is bounded too. Warehouse eligibility and active/available/expiry filters are preserved by context. Initial page props contain only 25 choices per list; the selected bookmarked inventory location is retained within that bound. Operations low-stock and near-expiry summaries show at most 100 entries with a visible note directing users to searchable Stock/Batches tabs. The existing 50-document operations limits remain.
- **B — reports:** daily Panel/Due called five ledger queries per invoice; the ledger now has a 500-invoice batch path using the same calculator and adjustment reversal definition. Panel claims, earliest submission and remittances are batched per 500 allocations. Stock signed movements aggregate as exact PostgreSQL numeric milliunits rather than materialising the retained 100,000 rows in PHP; the existing reconciliation and unknown-type refusal remain, and out-of-range integer totals refuse rather than round. Transaction identity resolves three bounded lookup sets after the filtered cap, retaining original identity validation/masking and the existing private identity resolver. No source or money is changed.
- **C — boards:** Registration performed per-visit checkout/case and repeated branch-authority queries. These are bounded batches and request-local policy hints sharing the existing policy predicates. The completed doctor queue had per-row dispensary-attention and checkout-reopen checks; it now batches structural read hints. Mutation authorities remain fresh and unchanged. Waiting queue, TV feed and QR status were already bounded; their behaviour remains unchanged.

## Measurement method

Task-owned PostgreSQL 18.6 cluster `/private/tmp/kpone-pilot-scale-pg18`, host `127.0.0.1`, port `58562`; connection configuration and server data directory verified before use. Databases created with `ENCODING UTF8 TEMPLATE template0`: `kpone_pilot_scale_testing`, `kpone_pilot_scale_ci_testing`, `kpone_pilot_scale_ci2_testing`, `kpone_pilot_scale_seed_testing`, `kpone_pilot_scale_benchmark_testing`. No other database used. Explicit `.env.example` placeholder equivalents supplied through process environment; no `.env` or `.env.preview` opened or created. Task-only PHP 8.4 validation configuration uses the owner-approved 1 GiB memory limit.

Large synthetic fixture: 20,001 invoices, 20,001 receipts, 2,001 approved Panel allocations and 100,000 immutable movements spread across 12 months, plus 5,000 items, 5,000 SKUs and 5,000 locations. Report period 2026-10-01–2026-10-26, one branch, fixed generation time. The stock export has exactly 5,000 rows. Current-day waiting fixture has 400 queued visits; historical financial fixture visits also exist. A supplementary fixture has 25 completed queue rows and 40 TV calls (8 displayed). Values are synthetic; no real medicines, suppliers or prices. Some synthetic movement-only SKUs have negative retained quantities; old and new reports agree, and no balances are invented.

Original services and full outputs were captured before edits. Every financial page result and complete CSV (including totals/headers), Registration, waiting Queue, QR status, completed Queue and nonempty TV feed match their original JSON bytes/SHA-256. Inventory choice payloads intentionally change to bounded lists. Times are local single-process observations, not an SLA; audit and authority queries are included.

| Read | Before queries | Before seconds | After queries | After seconds |
|---|---:|---:|---:|---:|
| daily-page | 7179 | 2.432605 | 41 | 0.150106 |
| daily-csv | 7174 | 2.386556 | 36 | 0.082692 |
| panel-page | 342 | 0.075606 | 18 | 0.031654 |
| panel-csv | 342 | 0.057591 | 18 | 0.014408 |
| stock-page | 15 | 1.339359 | 15 | 0.105474 |
| stock-csv | 15 | 1.322885 | 15 | 0.100653 |
| transactions-page | 78 | 0.054106 | 21 | 0.043810 |
| transactions-csv | 4311 | 0.651218 | 21 | 0.104795 |
| registration | 92 | 0.068969 | 21 | 0.056993 |
| queue | 12 | 0.029682 | 12 | 0.025166 |
| qr-status | 8 | 0.003467 | 8 | 0.007164 |
| inventory-references | 7 | 0.120114 | 7 | 0.005006 |
| inventory-operations | 15 | 0.031170 | 14 | 0.014623 |
| doctor-completed-queue | 242 | 0.162141 | 22 | 0.055720 |
| tv-with-calls | 2 | 0.003552 | 2 | 0.001631 |

Inventory references: 1,665,160 → 8,506 bytes. Operations: 2,080,450 → 10,850 bytes. Original empty TV feed was 2 queries / 0.001413 s; nonempty feed is compared separately above.

## Files

- `app/Domain/Clinical/Dispensary/Services/DoctorDispensaryAttentionService.php`
- `app/Domain/Clinical/Services/CheckoutReopenEligibility.php`
- `app/Domain/Organisation/Inventory/Services/InventoryDirectoryService.php`
- `app/Domain/Organisation/Inventory/Services/InventoryOperationsDirectoryService.php`
- `app/Domain/Organisation/Inventory/Services/InventoryPickerService.php`
- `app/Domain/Queue/Policies/QueueEntryPolicy.php`
- `app/Domain/Queue/Services/QueueDirectoryService.php`
- `app/Domain/Shared/Services/DailySummaryReportService.php`
- `app/Domain/Shared/Services/PanelSummaryReportService.php`
- `app/Domain/Shared/Services/StockMovementReportService.php`
- `app/Domain/Shared/Services/TransactionReportService.php`
- `app/Domain/Visit/Billing/Services/FinancialLedger.php`
- `app/Domain/Visit/Billing/Services/InvoiceAdjustmentService.php`
- `app/Domain/Visit/Services/VisitDirectoryService.php`
- `app/Http/Controllers/InventoryController.php`
- `app/Http/Controllers/InventoryReferenceController.php`
- `docs/BASELINE.md`
- `docs/reports/PILOT-SCALE-LIMITS.md`
- `resources/js/components/inventory/InventoryOperationsPanel.vue`
- `resources/js/components/inventory/InventoryReferenceDataPanel.vue`
- `resources/js/components/inventory/InventorySearchSelect.vue`
- `resources/js/components/ui/select/OperationalSelect.vue`
- `resources/js/lib/inventory-picker.ts`
- `resources/js/pages/Inventory/Index.vue`
- `routes/web.php`
- `tests/Feature/Clinical/PilotCompletedQueueScaleTest.php`
- `tests/Feature/Clinical/PilotInventoryScaleTest.php`
- `tests/Feature/Queue/PilotBoardScaleTest.php`
- `tests/Feature/Reports/PilotScaleReportTest.php`
- `tests/Fixtures/PilotScale/original-small-reports.json`
- `tests/Frontend/pilot-scale-limits.test.mjs`

## Tests and changed existing assertions

**Changed existing assertions: none. No existing test file edited.** No test weakened, removed or newly skipped.

- `PilotInventoryScaleTest`: 5,000 item/SKU/location sets; default/max limits, small payload/query budgets, search beyond initial choices, all catalogue role denials, fresh revocation, scope isolation, Unicode byte order and literal search metacharacters; full ID-ordered source invariance.
- `PilotScaleReportTest`: checked-in synthetic golden outputs captured from unmodified original services, exact four-report page/CSV equality; discount, surcharge, independently reversed adjustment, receipt reversal, archived method, Panel and pay-later, local New Year and empty date, signed transfer/stock fractions; batch ledger equals singular ledger with five queries; invalid financial totals and integer quantity overflow refuse; source evidence unchanged.
- `PilotBoardScaleTest`: 400 waiting visits, bounded Registration/Queue/TV reads and payloads, exact policy-hint equivalence for every catalogue role, foreign denial; all source evidence unchanged.
- `PilotCompletedQueueScaleTest`: 25 positive reopened-eligible current checkouts, batch equals original single-visit hint, bounded queue queries and all source rows unchanged. Existing Treatment Plan partial-dispensary positive/denial/revocation tests remain unchanged and pass.
- `pilot-scale-limits.test.mjs`: internal encoded URLs and limits, result labels, inactive supplier handling, selected-option pinning, stale-response and branch/SKU reset wiring, plus a new mobile Search-before-list regression and unchanged default collision behaviour for other selectors. Existing frontend identity-after-cap, safe SQL, CSV and picker contracts stay intact.

## Previous-base validation (retained)

**Previous-base full gate and CI-command replay green**, before the authorised 2026-10-27 rebase, on task-owned PostgreSQL 18.6 after the new test-client correction. Backend: **1,418 passed / 29,493 assertions / two existing skips** (1,420 total; 663.867 s). Frontend: **490 passed / zero skips**. No existing assertion changed, test weakened, removed or newly skipped.

| Check | Result |
|---|---|
| Full backend, approved task-only 1 GiB | 1,418 passed; 29,493 assertions; two existing skips |
| Pint `--parallel --test` | Passed |
| PHPStan level 7, `--memory-limit=1G` | Passed, zero errors |
| Frontend types / ESLint / Prettier | All passed |
| Production build | Passed |
| `node --test tests/Frontend/*.test.mjs` (CI) | 490 passed |
| `node --test tests/Frontend/*.mjs` (full frontend gate) | 490 passed |
| `migrate:fresh --seed --force`, separate seed database | Passed, exit 0 |
| `git diff --check origin/main` | Passed |
| Required full-suite attempt without override | Failed at local 128 MiB in existing Panel claims test; exact failure below; retained, not hidden |

CI replay order: `composer install --no-interaction --prefer-dist --no-progress`; `npm ci`; `php artisan wayfinder:generate --with-form --no-interaction`; `node --test tests/Frontend/*.test.mjs`; `php artisan migrate --force`; `npm run build`; `composer ci:check` (ESLint, Prettier, Vue types, config clear, Pint, PHPStan, full artisan tests). Final replay used fresh `kpone_pilot_scale_ci2_testing` and separate `/private/tmp/kpone-pilot-scale-evidence/ci2-storage`.

**Environment differences from GitHub:** macOS PHP 8.4.26 / Node 22.23.3; the workflow's `.env.example` copy step is replaced by explicit placeholder process environment (no `.env` created/read). The owner-approved task-only 1 GiB configuration is inherited by CLI children. These are local CI-command results, not a claim that a GitHub/Linux run has executed. The first replay logs remain in `ci-first/`. In that earlier round, all tests finished before preview. For the authorised rebase round, the separate preview server was stopped and the task tab closed before the exact CI replay; tests use Job 1's per-process temporary storage isolation. No test suite ran while preview was live or against its retained storage.

## Validation corrections retained in private logs

- Required no-memory-override attempt: Herd PHP 8.4.27, 128 MiB; exit 2, `Allowed memory size of 134217728 bytes exhausted (tried to allocate 7356416 bytes)` in existing `PanelClaimService.php:442`, while `PanelClaimsTest::test_notes_remarks_and_photo_documents_are_branch_scoped_audited_and_validated` ran. The owner-approved task-only 1 GiB configuration is used for the full gate/CI replay. Production limits are unchanged.
- First full CI replay: 1,417 passed / 29,490 assertions / one failure in the NEW inventory-scale test / two existing skips (673.825 s). After build, a simulated Inertia request without its loaded asset version correctly returned HTTP 409 with `X-Inertia-Location`. The new test now sends the version from the HTML page and additionally asserts the JSON version is identical. All original 200-status, size and source-invariance checks remain. Red reproduction and green focused run (3 tests / 81 assertions) retained. No application middleware or existing test changed; the complete CI sequence is repeated against fresh `kpone_pilot_scale_ci2_testing`.
- Initial frontend run found the existing Transaction Detail source guard no longer saw `$this->identity($receipt)` after the cap, because the first batching implementation replaced that resolver. The candidate now keeps a real resolver over the prepared, bounded identity batch; both batch preparation and row resolution remain after scope/cap checks. The assertion is unchanged. The other initial failure was missing generated Wayfinder `resources/js/routes/index.ts`; generating routes fixed setup without editing any assertion.
- PHPStan caught missing/incorrect collection types in new batching code, and ESLint caught new imports/style errors. Corrected in candidate code; no suppression/baseline or existing test edit. A new picker cancellation-state review fix was followed by another complete frontend type/lint/format/test/build run.
- Private large-fixture setup initially hit database constraints on incomplete synthetic graphs (missing governed method/current guards/submission details and QR payload shape). Only the private fixture scripts were corrected; constraints stayed enabled, and no application rule or existing assertion changed.

## Evidence

Private local root `/private/tmp/kpone-pilot-scale-evidence`; manifest `/private/tmp/kpone-pilot-scale-evidence/manifest.md`. Original/final metrics, complete synthetic outputs, hashes, original service copies and fixture scripts remain private and uncommitted. **13 historical pre-mobile-fix Chrome screenshots** at exactly 1440×1000 and 390×844 CSS pixels, light/dark, use the same task-owned reference fixture through normal synthetic `.test` sign-in on `kpone-pilot-scale.localhost:18672`. Separate session cookie and preview storage; no served-app session or tree used.

Chrome verified the 5,000th item/SKU/location can be searched and selected; the location outside the initial 25 retains its label after apply and full reload. Page scroll width equals viewport width in every recorded light/dark desktop/mobile case (`layouts.json`); no warning/error console entries (`chrome-console.json`). Operations summary limit note is visible in both mobile themes. The movement-only benchmark has no live stock balances/usable batches; the empty stock/batch state is expected. No stock posting or clinical action was performed for screenshots.

- `/private/tmp/kpone-pilot-scale-evidence/screenshots/1440-dark-filter-reload.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/1440-dark-item-search.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/1440-dark-selected-location-sku.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/1440-light-filter.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/1440-light-item-search.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-dark-item-search.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-dark-operations-note.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-dark-selected-location-sku.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-dark-sku-search.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-light-filter.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-light-item-search.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-light-operations-note.jpg`
- `/private/tmp/kpone-pilot-scale-evidence/screenshots/390-light-selected-item.jpg`

After evidence, the task Chrome tab closed and viewport override reset; the task-owned preview server (18672) and PostgreSQL cluster (58562) were stopped. Data, screenshots and logs are retained privately for review. Owner review subsequently authorised commit/push/PR on 2026-10-27. The final rebase validation and four updated picker screenshots supersede these earlier mobile captures.

## Open questions and limits

- No owner UAT or production-volume SLA is claimed. SQL aggregates still scan the scoped retained movements in PostgreSQL; they avoid shipping full history into PHP. No index or schema change was needed for the measured fixture.
- Operations summary panels show the first 100 matching alert entries; searchable stock and batch tabs are the full listing. Existing production procurement/receiving/stocktake authorisation boundaries remain unchanged.
- Initial supplier/medicine/branch choice lists are bounded; this slice adds no supplier master lifecycle or other inventory workflow.
- No blocking owner decision. Job 1 is included through the rebase onto `8732b55`; no minors-contact change or Job 3 work is included. Production owner UAT remains outstanding.

## Rebased validation (2026-10-27)

**Final exact CI-command replay and full gate GREEN on base `8732b5599aefe0becb07080c79716b64055d186c`.** Backend: **1,432 passed / 29,593 assertions / two existing skips** (1,434 total, 653.297 s). Frontend: **491 passed / zero skipped**, both the workflow `*.test.mjs` run and the full `*.mjs` gate. No existing assertion changed, removed, weakened or newly skipped.

| Final rebased check | Result |
|---|---|
| Full `composer ci:check`, task-only 1 GiB | Passed: 1,432 backend / 29,593 assertions / two existing skips |
| Pint `--parallel --test` | Passed |
| PHPStan level 7 | Passed, zero errors |
| Vue types / ESLint / Prettier | All passed |
| Production build | Passed |
| CI and full frontend runs | Each 491 passed |
| Separate `migrate:fresh --seed --force` | Passed |
| `git diff --check` against rebased HEAD/main | Passed |

Fresh integration database: `kpone_pilot_scale_rebase_testing`; separate seed database: `kpone_pilot_scale_rebase_seed_testing`, both `ENCODING UTF8 TEMPLATE template0` on task-owned PostgreSQL 18.6 at `127.0.0.1:58562`. Endpoint and memory configuration verified before use (`rebase-ci-endpoint.log`, `rebase-seed-endpoint.log`). The approved task-only 1 GiB setting and explicit placeholder process environment were retained; no `.env`/`.env.preview` was created/read, no production memory limit changed. The complete workflow command order above was repeated, including install, Wayfinder, frontend, migration, build and `composer ci:check`, then the full frontend and separate seed gates.

After rebase, every financial page/CSV and Registration/Queue/QR/nonempty-TV output again matched the original bytes (`rebased-metrics.json`, `rebased-supplement-metrics.json`). Query counts below match this integrated run; no numerical difference. Four fresh screenshots, 1440/390 light/dark, are under `rebase-screenshots/`; `rebase-picker-geometry.json` proves the open list fits and Search is uncovered. Chrome warning/error log is empty. The preview server/tab closed before CI; test storage was per-process temporary storage from main's Job 1 isolation guard.

The authorised candidate is ready for commit and one PR; no merge is authorised. No blocking open question. Production owner UAT remains outstanding.
