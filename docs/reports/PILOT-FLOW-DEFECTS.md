# PILOT-FLOW-DEFECTS — Job 1

Local candidate — full gate and CI-equivalent replay passed; committed locally only; not merged or production-approved. Owner scope: 26 October 2026. Branch `fix/pilot-flow-defects`, dedicated worktree `../KPOne-fix-flow`, based on fetched `origin/main` `61bdf64d3f0100d0d21ce5819824dd3fa5b488fe` (through PR #171). No other job, analysis worktree, parked website worktree or served-tree file changed. No new permission, migration, dependency or data model.

## Root causes and changes

| Defect | Root cause | Candidate behaviour |
| --- | --- | --- |
| Hold/Resume versus automatic return | Manual Hold/Resume locked the doctor before the Visit; Dispensary return acquired patient/Visit/Queue/Encounter/source/case locks before `holdReturningConsultation` acquired the doctor. A concurrent manual hold could wait on that Visit while holding the doctor. | `ConsultationLocks` documents and acquires actor/assigned-doctor users in ascending numeric id order, then profiles/assignments, before patient (when needed), Visit, Queue, Encounter, sources/case/holds. Call In and Dispensary return use the same entry order; manual Hold/Resume uses the same helper. Returning visits reject an assignment change after the early doctor lookup. Existing transaction retries remain defence in depth. Numeric id ordering is explicit and locale-independent; no text ordering is relied on or new SQL function introduced. |
| Cross-branch doctor busy | Both the call and Hold service's active-consultation query restricted the queue to the current branch. | One domain boolean checks all Serving, non-held consultations for that doctor within the organisation. Call In, Resume and automatic return reuse it. No other-branch patient DTO, identifier or name is returned. The new Call In refusal is fixed Bahasa Melayu and names no patient. Existing authority, branch scope, three-held limit and Hold/Resume messages remain. |
| Hard-coded Hold role | `ConsultationHoldService::lockDoctor` required both the Resident Doctor role and the existing own permission. | Require the existing `consultations.hold.own`, fresh active staff/assignment checks, assigned Visit and attending Encounter ownership; no role-name condition in Hold/Resume. No catalogue grants change. |
| Bare redirects | Branch switch, Security password update and Dispensary acknowledgement relied on stale SPA previous navigation. | Security success/request refusal uses `security.edit`; acknowledgement success/domain/request refusal uses the owning `encounters.show`. Both branch-switcher components send `page.url`; the server accepts only a bounded validated root-relative return path, with Main Menu fallback. Schemes, authorities, control/whitespace/backslash evasions, encoded evasions and dot segments are refused as return targets. No Referer or session previous URL is trusted. |
| Test storage | Test fake disks could share the worktree's real private storage context with a preview. | Shared TestCase selects a unique system-temp storage root before application bootstrap, gives every configured disk a local private root within it, and places fake-disk/framework paths there too. Root checks run during teardown. Only the owned root is eligible for shutdown cleanup, with canonical system-temp, direct-child/name and symlink guards. Cleanup is restricted to the creating PID: inherited fork-child shutdown callbacks cannot delete the parent root. Framework directories are ensured on each bootstrap, compiled views use the owned directory, and isolated disks explicitly retain serve:false. Production filesystem configuration is unchanged. No tests are run while this worktree has a live preview/demo. |

## Tests and unchanged assertions

Fourteen added tests (including the authorised continuation tests below): `Clinical/PilotConsultationTest` (custom permission-bearing role; freshly revoked Resident Doctor; cross-branch refusal/privacy, held exclusion, other-branch call and Resume refusal), `PilotRedirectTest` (branch target/evasions and stale previous URL; refused Security navigation; token-based bare `return back()` scan with empty allowlist), `PilotStorageIsolationTest` (every configured and fake disk under process temp; unsafe cleanup/traversal refusal), two added queue contention tests, and two added dispensary contention/navigation tests.

The existing PostgreSQL worker harness and `ContentionTimeouts` are reused. Added workers count outer transaction starts, not just successful end states. Manual Hold/Resume and Hold/Call In each use one attempt. The automatic-return test deterministically queues a manual hold at the doctor mutex before the return, for the same doctor and Visit/patient.

**Red/green:** with main's original `DispensaryService` temporarily restored in this task worktree, the new automatic-return test fails: manual hold prints `PILOT_TRANSACTIONS 1`, return prints `PILOT_TRANSACTIONS 2`. With the candidate order both print 1 and the test passes. The original source was restored to the candidate in a `finally` block. Evidence: `auto-contention-old-order-red.log`, `auto-contention-green.log` and `contention.log` in the private manifest directory.

**Changed existing assertions: none; one authorised existing fixture change is recorded below.** A token comparison confirms all 7 original queue test bodies and all 27 original dispensary/inventory test bodies are unchanged. No existing assertion was weakened, removed or skipped. No existing assertion is edited to accommodate a failure.

Changed helpers: shared `tests/TestCase.php`; new `tests/Support/IsolatedTestStorage.php`; added modes in `PostgresQueueWorker.php` and `PostgresDispensaryInventoryWorker.php`; new `pilotServing`/`pilotInput` fixture helpers and additional Hold/Encounter cleanup in `PostgresQueueRegressionTest`; added Hold cleanup in `PostgresDispensaryInventoryRegressionTest`. No existing worker action protocol or assertion changes. Initial new-fixture errors (missing CA Call In grant, incomplete fixture cleanup and an unscoped synthetic visit-number lookup) were corrected in new fixture/worker code only.

## Validation environment and setup deviations

Task-owned PostgreSQL 18.6 cluster: `/private/tmp/kpone-pilot-flow-pg18`, listening only on `127.0.0.1:58561`. Databases: `kpone_pilot_flow_testing`, fresh CI database `kpone_pilot_flow_ci_testing`, separate seed/evidence database `kpone_pilot_flow_seed_testing`. Effective host/port/name were printed before application requests; cluster ownership and port were verified with its own pg_ctl. Only task-owned databases were connected.

The required full suite without a memory override exhausted Herd PHP's **128 MiB** limit in existing `PanelClaimsTest::test_notes_remarks_and_photo_documents_are_branch_scoped_audited_and_validated`, at `PanelClaimService.php:442`. Preserve `backend-no-override.log`; this is not reported as a pass. The owner explicitly approved a task-only **1 GiB** CLI setting for remaining validation. Herd ignored PHP_INI_SCAN_DIR, so the first replay also remained at 128 MiB; `ci-equivalent.log` records that failed setup. The replay uses installed PHP **8.4.26** with a verified 1 GiB task-only ini directory, not a host/global or production limit change.

Automatic approval review rejected `cp .env.example .env`, citing the repository `.env` boundary. No `.env` was created, opened or read. Explicit placeholder/task-owned environment variables replace that setup step. Application command order otherwise matches CI: composer install --no-interaction --prefer-dist --no-progress; npm ci; php artisan wayfinder:generate --with-form --no-interaction; node --test tests/Frontend/*.test.mjs; php artisan migrate --force; npm run build; composer ci:check (ESLint, Prettier, Vue types, config:clear, Pint --parallel --test, PHPStan --memory-limit=1G, php artisan test). This setup difference is disclosed rather than claiming the rejected copy ran.

## Retained failed validation (before the authorised continuation)

The final CI-command replay exited 2. Full backend: **1,424 tests, 1,406 passed, 14 failed, 2 errors, 2 existing skips, 27,719 assertions**, 644.935 seconds. It is NOT green. Frontend **486 passed**, no failures/skips, under both `*.test.mjs` and `*.mjs`. Composer install, npm ci, Wayfinder, migration, build, ESLint, Prettier, Vue types, Pint and PHPStan level 7 passed. Separate `migrate:fresh --seed` passed. Diff check passed. The 12 newly added tests passed in the focused run (81 assertions); the later helper scope refinement was included in the failing full replay. No successful full-gate claim is made.

The existing `ClinicalSafetyAuthorizationTest::test_legitimate_current_care_can_read_longitudinal_allergies_across_branches` leaves the first consultation Serving, then calls the same doctor into another branch. The new owner-approved busy rule correctly refuses that second call with `Doktor sedang menjalankan konsultasi lain. Tahan atau selesaikan konsultasi tersebut dahulu.` The test's final exact allergy assertion has not changed. At that stopped run the fixture was untouched. The owner subsequently authorised holding the first consultation before calling the second, preserving the final allergy assertion and every authority check; the exact fixture change is recorded below. No exemption from the busy rule was added.

Two additional problems must be fixed in the candidate/setup, without changing existing assertions:

- New isolated-disk configuration omitted the existing explicit `serve: false`; `WebsiteDraftTest::test_anonymous_access_and_no_public_media_storage` correctly fails `assertFalse` because the value became null. Restore the flag in the new helper; keep the assertion.
- Eight HTTP failures explicitly contain `Please provide a valid cache path.` Five other HTTP 500/Inertia failures need further isolation; they are not assumed to have that same cause. The temporary view-cache lifecycle needs correction in the new helper, not weaker HTTP assertions.
- Task database encoding is **SQL_ASCII**, created by initdb's no-locale default. The existing artifact locale test cannot create its ICU collation (`0A000: current database's encoding is not supported with this provider`). Recreate only task-owned test databases as UTF8 before retrying; do not alter the locale/byte-order assertion.

Exact failed/error cases from the full replay follow. Full messages/traces are private in `backend-result.json`.

| Existing test | Result / first failure message |
| --- | --- |
| `Tests\Feature\Website\WebsiteDraftTest::test_anonymous_access_and_no_public_media_storage` | Failure: Failed asserting that null is false. |
| `Tests\Feature\Website\WebsiteOutputPlanTest::test_detail_alias_names_come_only_from_the_bound_frozen_manifest` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\Website\WebsitePeopleTest::test_exact_role_grants_every_view_and_mutation_denial_and_anonymous` | Failure: director list doctor-profiles |
| `Tests\Feature\Website\WebsitePeopleTest::test_hr_create_edit_independent_languages_history_slug_and_archive` | Failure: Not a valid Inertia response. |
| `Tests\Feature\Website\WebsitePeopleTest::test_branch_associations_search_historical_choices_and_http_source_isolation` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\Website\WebsiteReleaseTest::test_exact_role_grants_and_every_http_action_denied_without_authority` | Failure: director |
| `Tests\Feature\Website\WebsiteReleaseTest::test_all_unrelated_rows_queries_http_jobs_scheduler_and_audit_are_values_free` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\Website\WebsiteReleaseTest::test_private_no_store_noindex_http_and_no_unsaved_content` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\Website\WebsiteServicesTest::test_every_catalogue_role_exact_existing_grants_and_all_route_denials` | Failure: director list |
| `Tests\Feature\Website\WebsiteServicesTest::test_no_operational_pricing_staff_sources_no_network_jobs_and_all_unrelated_rows_identical` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\Website\WebsiteSharedReferencesTest::test_legacy_divergence_is_visible_and_next_save_converges_without_rewriting_history` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\WorkspaceTest::test_root_and_shared_workspace_navigation_converge_on_workspace` | Failure: Not a valid Inertia response. |
| `Tests\Feature\WorkspaceTest::test_shared_navigation_capabilities_follow_direct_route_permissions` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\WorkspaceTest::test_placeholder_modules_are_lightweight_and_clinic_authority_gated` | Failure: Expected response status code [200] but received 500. |
| `Tests\Feature\Clinical\ClinicalSafetyAuthorizationTest::test_legitimate_current_care_can_read_longitudinal_allergies_across_branches` | Error: Doktor sedang menjalankan konsultasi lain. Tahan atau selesaikan konsultasi tersebut dahulu. |
| `Tests\Feature\Website\WebsiteArtifactBuildTest::test_completion_manifest_uses_byte_order_even_with_a_locale_sorted_path_column` | Error: SQLSTATE[0A000]: Feature not supported: 7 ERROR:  current database's encoding is not supported with this provider (Connection: pgsql, Host: 127.0.0.1, Port: 58561, Database: kpone_pilot_flow_ci_testing, SQL: CREATE COLLATION IF NOT EXISTS artifact_test_locale (provider = icu, locale = 'en-US')) |

## Evidence before continuation

Private evidence manifest: `/private/tmp/kpone-pilot-flow-evidence/manifest.md`. Logs and scripts are local and uncommitted. No real data. The disposable browser fixture script was prepared but NOT executed; Chrome remained at about:blank and no preview server was started. Required 1440/390 screenshots, two-doctor/CA walkthrough and branch-return-path Chrome evidence are outstanding because the full gate is blocked. No tests ran against a live preview/demo.

At that stop there was no commit, push, PR or merge. Jobs 2 and 3 had not started. The owner then authorised the narrow continuation below: fix the fixture, helper and disposable UTF8 setup, repeat validation, complete Chrome evidence after tests stop and commit once when green. No existing assertion may be edited or weakened. No production access rule or permission change is needed.

## Authorised continuation — failure classification before rerun (26 October 2026)

All 14 failures and 2 errors above are classified here before the next test run. Group (d) is empty.

| Group | Exact cases from the failure table above | Cause |
| --- | --- | --- |
| (a) Cross-branch fixture (1 error) | ClinicalSafetyAuthorizationTest::test_legitimate_current_care_can_read_longitudinal_allergies_across_branches | Same doctor left Serving at branch one before call at branch two, now correctly refused. |
| (b) New storage helper (1 failure) | WebsiteDraftTest::test_anonymous_access_and_no_public_media_storage | Helper omitted explicit serve:false (null failed assertFalse). |
| (b) New storage helper (13 failures) | WebsiteOutputPlanTest::test_detail_alias_names_come_only_from_the_bound_frozen_manifest; WebsitePeopleTest::test_exact_role_grants_every_view_and_mutation_denial_and_anonymous; WebsitePeopleTest::test_hr_create_edit_independent_languages_history_slug_and_archive; WebsitePeopleTest::test_branch_associations_search_historical_choices_and_http_source_isolation; WebsiteReleaseTest::test_exact_role_grants_and_every_http_action_denied_without_authority; WebsiteReleaseTest::test_all_unrelated_rows_queries_http_jobs_scheduler_and_audit_are_values_free; WebsiteReleaseTest::test_private_no_store_noindex_http_and_no_unsaved_content; WebsiteServicesTest::test_every_catalogue_role_exact_existing_grants_and_all_route_denials; WebsiteServicesTest::test_no_operational_pricing_staff_sources_no_network_jobs_and_all_unrelated_rows_identical; WebsiteSharedReferencesTest::test_legacy_divergence_is_visible_and_next_save_converges_without_rewriting_history; WorkspaceTest::test_root_and_shared_workspace_navigation_converge_on_workspace; WorkspaceTest::test_shared_navigation_capabilities_follow_direct_route_permissions; WorkspaceTest::test_placeholder_modules_are_lightweight_and_clinic_authority_gated | Existing WebsiteOutputPlanTest and WebsiteReleaseTest concurrency fixtures use pcntl_fork and exit. Forked children inherited the new helper's shutdown callback and deleted the parent's root. Laravel's default compiled-view config uses realpath(storage_path('framework/views')); subsequent application boots got false. Eight failures print the compiler exception; five give only the resulting HTTP500/custom role label/invalid Inertia response on the same shared Blade shell. No distinct application failure is evidenced. Fix cleanup ownership by PID, ensure framework directories exist on each bootstrap and pin view.compiled to the owned directory. Add a fork regression to prove the parent view cache survives child exit. |
| (c) SQL_ASCII database (1 error) | WebsiteArtifactBuildTest::test_completion_manifest_uses_byte_order_even_with_a_locale_sorted_path_column | ICU requires supported encoding; task initdb no-locale default produced SQL_ASCII. Recreate every task database with ENCODING UTF8 and TEMPLATE template0, leave locale and byte-order assertions intact. |
| (d) Anything else | None | No other failure cause found. |

## Changed existing assertions

No assertion, test name or expectation changes. One owner-authorised fixture change only in `ClinicalSafetyAuthorizationTest::test_legitimate_current_care_can_read_longitudinal_allergies_across_branches`:

Old fixture line:
```php
$this->startEncounter($doctor, $firstVisit, $firstQueue);
```
New fixture lines (the second is inserted after the allergy add, before selecting the second branch):
```php
$firstEncounter = $this->startEncounter($doctor, $firstVisit, $firstQueue);
$this->holdEncounter($doctor, $firstVisit, $firstQueue, $firstEncounter);
```
Reason: the owner authorised the cross-branch doctor-busy rule on 2026-10-26, so a doctor cannot be Serving at two branches at once. The exact longitudinal-allergy assertion remains unchanged. Inspection of the other existing cross-branch Clinical/Queue fixtures found no further same-doctor double-Serving pattern: history fixtures either hold first, use another doctor, or only switch the viewing branch. None of them changed.

A new test `PilotConsultationTest::test_held_consultation_allows_cross_branch_call_and_three_hold_limit_is_unchanged` explicitly proves the held first-branch consultation permits another-branch call, three holds across branches succeed, and the fourth is refused. `PilotStorageIsolationTest::test_forked_worker_cleanup_cannot_remove_parent_view_cache` pins the helper correction. These are new tests, not edits to existing assertions.

## Final validation after continuation

All three task databases were recreated with **ENCODING UTF8, TEMPLATE template0** on the same owned PostgreSQL **18.6**, `127.0.0.1:58561`. Ownership was verified with pg_ctl/PID and lsof before recreation; `SHOW data_directory`, `SHOW port` and the three database encodings are retained in `utf8-databases.txt`. An initial restart without explicit port failed to bind the default port; it made no database connection. The corrected start explicitly pinned 58561 and the owned socket. Automatic approval review refused a recreation command until endpoint ownership was proved; no deletion happened on that rejection. No foreign database, served tree or unrelated worktree was touched.

| Check | Final result |
| --- | --- |
| Exact CI application command replay, same order listed above | PASS, exit 0 (`ci-equivalent-utf8-final.log`) |
| Full backend `php artisan test`, task-only 1 GiB CLI | **1,426 tests: 1,424 passed, 29,370 assertions, 2 existing skips**, 646.285 seconds; zero failures/errors |
| CI frontend `node --test tests/Frontend/*.test.mjs` | **486 passed**, zero failures/skips |
| Required full frontend glob `node --test tests/Frontend/*.mjs` | **486 passed**, zero failures/skips (`frontend-all-final.log`) |
| Pint (CI parallel/test flags) | PASS |
| PHPStan level 7, CI memory flag | PASS, zero errors |
| Vue types / ESLint / Prettier | PASS |
| Production build / Wayfinder | PASS |
| Migrate on fresh CI DB | PASS |
| `migrate:fresh --seed --force` on separate UTF8 seed DB | PASS (`seed-utf8-final.log`) |
| Existing ICU locale / byte-order test | PASS, assertions untouched, included in focused and full runs |
| Original contention-test bodies | All 7 queue and 27 dispensary/inventory bodies unchanged (`existing-assertions-check-final.log`) |
| ClinicalSafety assertions / expectations | All unchanged; only the authorised fixture diff above |
| `git diff --check` | PASS |

The new held-limit fixture initially reused a stale scoped branch-authority cache after adding its synthetic assignment. It now forgets scoped instances and selects the synthetic CA's branch before creating the second-branch visits. This corrected only new test setup. The new held-limit test passes with 8 assertions and in the complete run. No further existing assertion needed editing. No test was weakened, removed or newly skipped.

## Final Chrome evidence

Real Chrome, only `http://127.0.0.1:8151`, synthetic `.test` logins, separate task seed database and separate `/private/tmp/kpone-pilot-flow-evidence/preview-storage`. Effective DB and storage were checked before the first HTTP request. No tests ran while the preview was live. The initial uncommitted walkthrough script used a string queue number for a bigint; the preview was stopped, only the disposable evidence DB was freshly seeded again, and the script used integer queue numbers. This did not change candidate code or validation results.

Fourteen private JPEGs under `/private/tmp/kpone-pilot-flow-evidence/screenshots/` (not committed):

| Files | Evidence |
| --- | --- |
| `01-ca-cross-branch-busy-1440.jpg`, `02-ca-cross-branch-busy-390.jpg` | CA Supervisor, dark theme: generic Bahasa Melayu refusal while Doctor One is Serving in another branch; Beta remains Waiting. No Alpha name, patient number or Visit reference disclosed. |
| `03-ca-other-doctor-serving-1440.jpg` | Same CA successfully calls Gamma assigned to Doctor Two. |
| `04-ca-busy-light-1440.jpg`, `05-ca-busy-light-390.jpg` | Same generic refusal in light theme. |
| `06-doctor-one-serving-1440.jpg` | Doctor One's saved first-branch consultation before Hold. |
| `07-doctor-one-held-1440.jpg`, `08-doctor-one-held-390.jpg` | Doctor One uses On Hold; UI changes to Resume and disables clinical saving. |
| `09-branch-switch-queue-390.jpg` | Workspace branch switch Cheras → Sungai Besi stays on `/queue`. |
| `10-doctor-one-called-after-hold-390.jpg`, `11-doctor-one-called-after-hold-1440.jpg` | Doctor One successfully calls Beta at the second branch with Alpha held at the first. |
| `12-doctor-two-own-queue-1440.jpg`, `13-doctor-two-own-queue-390.jpg` | Separate Doctor Two login sees Gamma only; no Beta identity. |
| `14-standard-branch-return-profile-1440.jpg` | Standard settings branch switch Sungai Besi → Cheras preserves `/settings/profile`. |

Measured CSS viewport/document widths were 1440/1440 and 390/390 (busy modal, held clinical workspace and clinician queue); no sideways overflow. Browser viewport override was reset and the task tab closed. Existing synthetic fixture branches have no consultation rooms configured, so the existing room-setup notice is visible; no room behaviour was changed.

## Handoff

Evidence manifest: `/private/tmp/kpone-pilot-flow-evidence/manifest.md`. Logs, screenshots, local scripts, temp ini and database/storage files remain private and uncommitted. No new permission, migration, dependency or model. No financial or clinical data migration. The only changed existing fixture and all unchanged assertion checks are listed above. No open implementation question. Task preview and PostgreSQL services stopped. Served tree stays clean on main. Jobs 2 and 3 have not started. No push, PR, merge or production approval; wait for the owner.
