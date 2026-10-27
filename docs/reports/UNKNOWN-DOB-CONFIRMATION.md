# UNKNOWN-DOB-CONFIRMATION — Job 6

Date: 2026-10-27. Branch `feature/unknown-dob-confirmation`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-unknown-dob`. Base `97e2bdba7f189dffb0ec4e78fcfe265c4097d743` (STK-1 #180), verified to include `72f92d1` (guardian #179). Fresh fetch before validation confirmed the base is still current main, so no rebase was necessary. No commit, push or PR. Not production-approved.

## Changes and boundaries

Staff Patient create and Registration Quick create with a blank date of birth require the explicit English acknowledgement: **“Date of birth unknown. I confirm the patient is an adult (18 or older).”** The domain refuses the operation without an accepted acknowledgement and UUID confirmation key. Existing phone, identifier and SET-04 requirements keep their validation precedence; confirmation does not replace them.

Clearing an existing known DOB requires the same confirmation. Unrelated edits of legacy patients whose DOB is already absent remain allowed without confirmation or any new branch requirement. Entering a DOB afterwards takes precedence; a known minor still requires the existing guardian validation. No DOB or age is invented, no existing patient is backfilled, no patient column is added. The checkbox is a staff attestation, not evidence that KPOne verified the person's age.

SET-04 is unchanged. Required DOB cannot be replaced by a confirmation, and the staff checkbox is hidden when DOB is configured as required. Patient Edit receives the existing settings projection to apply the same presentation rule. Public QR's existing mandatory DOB rule and its intake/acceptance flow are unchanged; staff confirmation is explicitly excluded from the intake creation path.

`PatientAdultConfirmationService` owns validation, fresh scoped authority, retry matching and evidence recording. Existing Patient create/update transactions invoke it only for the relevant staff operation. Quick create passes the already-validated Registration branch to the Patient operation. The ordinary Visit idempotency remains intact.

## Evidence, migration, permissions and audit

New additive migration `2026_10_31_000300_create_patient_adult_confirmations.php` adds only `patient_adult_confirmations`:

- Organisation, Patient, branch, confirming actor, UTC confirmed time, source (`staff_create` including Quick create, or `staff_edit`), Patient lock version, UUID retry key and HMAC payload fingerprint. No identity/contact/body values are stored in this evidence.
- Restrictive foreign keys, Patient/organisation composite FK, unique actor-scoped retry key and Patient/version evidence. Source/version checks distinguish initial creation from later clears.
- PostgreSQL insert validation requires matching Patient/actor/branch organisation, blank Patient DOB and the current Patient version. Update, delete and truncate raise `P0001`. Functions are replay-safe for disposable fresh migrations. `down()` retains evidence.

Existing databases need **`php artisan migrate`**. No new permission, role grant or dependency. `patients.create.organisation` and `patients.update.organisation` remain the authority, with existing default holders **Director, CA and CA Supervisor**. Confirmation uses fresh transaction-locked actor/grant/effective branch checks before the Patient lock and retry, then the existing Patient lock version. No authority rule is widened. Known-DOB creation and unrelated legacy blank-DOB edits keep their existing authority path.

Identical retries return the same Patient/evidence; changed payloads under the same key are refused. Audit `patient.adult_confirmed` records only `confirmation_id` in metadata plus normal actor/organisation/subject IDs. An absent audit result or thrown audit failure rolls the whole creation/edit back. No confirmation projection is added to Patient details, prints, TV, QR status or reports. The checkbox appears only on staff forms already guarded by Patient create/update permissions.

## Every changed existing assertion and fixture

**Assertions changed: none.** No expected value, assertion, test name or skip changed. Only the expected blank-DOB creation setup changed in the following nine test/worker fixtures; DOB remains blank in the confirmation fixtures; the concurrency worker gets an explicit synthetic adult DOB as detailed below. No under-18 fixture is converted into an adult. The reason for every addition is the owner-authorised 2026-10-27 explicit adult-confirmation rule.

| File and test | Current line(s) | Exact setup change |
| --- | --- | --- |
| `tests/Feature/Patient/GuardianContactTest.php` — `test_unknown_dob_has_no_guardian_gate_and_changed_branch_or_revoked_actor_cannot_write_guardian` | 169–170 | Existing `GuardianFixture::assign($ca)` moved from after the unchanged zero-guardian assertion to before this create. Old overrides: `['date_of_birth' => null]`. New: same plus `adult_confirmed => true`, UUID `adult_confirmation_key`, `expected_branch_id => $branch->id`. Guardian refusal assertions remain intact. |
| `tests/Feature/Patient/RegistrationSettingsTest.php` — `test_default_and_required_patient_creation_and_legacy_edit` | 125 | Initial optional-policy blank-DOB create adds the acknowledgement, UUID and existing branch ID. The later required-policy failure fixture and all required-field assertions are untouched. |
| `tests/Feature/Visit/QuickPatientValidationTest.php` — `test_quick_create_http_and_domain_require_phone_and_identification` | 28 | Only the successful final Quick input adds acknowledgement/UUID. All invalid phone/identifier fixtures and their assertions remain unchanged. |
| `tests/Feature/Visit/VisitCreationTest.php` — `test_quick_patient_and_visit_commit_as_one_logical_operation` | 97 | Adds acknowledgement/UUID to the existing blank-DOB Quick input. |
| Same file — `test_late_visit_failure_rolls_back_quick_patient_both_counters_and_audits` | 198 | Adds acknowledgement/UUID so the original late-Visit failure and rollback assertions remain exercised. |
| Same file — `test_idempotent_quick_patient_retry_does_not_create_another_patient` | 237 | Adds acknowledgement/UUID to the reused input; the original one-Patient/one-Visit retry assertions remain. |
| `tests/Feature/Visit/VisitReasonCatalogueTest.php` — `test_new_consultation_and_quick_create_cannot_bypass_structured_reason` | 363 | Adds acknowledgement/UUID only to the successful blank-DOB Quick fixture. Existing structured-reason failures and assertions remain. |
| `tests/Feature/PostgresVisitRegistrationRegressionTest.php` — `test_late_quick_patient_visit_failure_rolls_back_both_counters_and_audits` | 322–324,350 | Adds acknowledgement/UUID to the Quick input and a synthetic StaffProfile/effective assignment for its acting account (this fixture originally had direct permissions only). The injected late failure rolls back the confirmation with the Patient, counters and audits; all assertions stay strict. |
| `tests/Support/PostgresVisitRegistrationWorker.php` — register Quick fixture, used by `test_concurrent_same_idempotency_key_creates_one_logical_visit` | 53 | Adds explicit synthetic adult DOB `1990-01-01` to the previously DOB-absent worker input. This is not a minor fixture and no existing DOB assertion changes. It keeps the existing Patient-counter contention test and its manual fixture cleanup unchanged: that cleanup deletes Patients, which correctly cannot delete retained confirmation evidence. Blank-DOB confirmation/retries are tested by the new dedicated tests instead; no immutability bypass or cleanup change. |

No shared helper logic, permission catalogue, guardian implementation, matching, WhatsApp, board/queue code or public QR test was edited. The existing concurrency worker has only the explicit synthetic DOB fixture addition listed above. The two PostgreSQL setup-only additions above were identified by the subsequent full run; no other existing assertion changes are made.

## Added tests

`UnknownDobConfirmationTest` (7 tests) and `UnknownDobQuickCreateTest` (2 tests): missing/false confirmation refusal; accepted evidence and IDs-only audit; known DOB produces no evidence; clear-DOB confirmation; unrelated legacy edit without new branch requirement; entered minor still requires guardian; settings-required DOB cannot be bypassed; same/conflicting retries and stale Patient version; all-role create authority and fresh revocation; ended assignment and foreign branch/organisation refusal; staff HTTP and Quick create error prefixes; Visit retry retains one evidence; PostgreSQL update/delete/truncate refusal with unchanged counts; audit failure rolls creation back.

Two new frontend tests pin English/accessibility/44px wrapper classes, staff-only scope, settings visibility and absence from public/print pages. Existing guardian, QR and other frontend assertions are unchanged.

## Validation history

Task-owned PostgreSQL **18.6**, **127.0.0.1:58644**, cluster `/private/tmp/kpone-unknown-dob-pg18`; databases created UTF8 with template0. Host, port, database, encoding, version and cluster verified before application use. Placeholder process environment only; no `.env` or `.env.preview` created or opened. PHP 8.4.26 with the owner-approved task-only 1 GiB setting. Dependencies copied independently into this worktree; no writable dependency symlink.

Early syntax checks, Pint, PHPStan level 7 and TypeScript passed. Initial new-code/setup errors were fixed without changing existing assertions: task SSL mode, replay-safe SQL function creation, missing branch in a new clear fixture, guarded-model fields in a new foreign-branch fixture, expected existing 404 response in that new test, whitespace-sensitive new frontend wording matcher and six new lint issues. The first gate attempt stopped at lint, before the long backend run; it is not a full pass.

The first Patient/Visit regression reached **157 passed / 7 fixture errors / 1,473 assertions**. All seven errors were the expected blank-DOB setups listed above, with no failed existing assertion. After setup-only changes, the suite passed **164 tests / 1,518 assertions**. Final focused new tests passed **9 tests / 64 assertions**. The unchanged PostgreSQL Visit regression suite passed **9 tests / 51 assertions** after its fixture-only corrections. New frontend tests passed **2**.

## Full gate

**Passed**, completed 2026-10-27 at **08:58:22 UTC**. Final ordered replay used **kpone_unknown_dob_candidate_testing**, separate **kpone_unknown_dob_seed_testing**, task-only 1 GiB, no live preview/storage while tests run. Logs `/private/tmp/kpone-unknown-dob-evidence/candidate-gate/`.

Exact workflow order: Composer install, npm ci, Wayfinder generation, frontend `*.test.mjs`, migration, build, `composer ci:check`; then all frontend `*.mjs`, separate `migrate:fresh --seed`, and `git diff --check`. Final results:

| Check | Result |
| --- | --- |
| Backend | **1,489 passed / 30,366 assertions / 2 existing skips**, 1,491 total; 694.605 seconds |
| Frontend workflow selection and full `*.mjs` | **507 passed** each |
| PHP syntax, Pint | Passed |
| PHPStan level 7 | Passed, zero errors |
| Types, ESLint, Prettier | Passed |
| Production build | Passed |
| Separate `migrate:fresh --seed` | Passed |
| `git diff --check` | Passed, repeated after report completion |

 The previous backend replay was deliberately interrupted when code review identified a malformed domain retry UUID reaching an evidence lookup. Both lookup entry points now reject non-UUIDs before SQL; the added new regression passes. No existing assertion changed. Only the final replay is release evidence. No test is weakened, removed or newly skipped.

The first completed full run reached **1,487 passes / 30,351 assertions / two existing skips / two errors**, in 697.140 seconds. Errors: the Visit concurrency worker returned `INVALID` before blocking because its Quick fixture had no DOB/confirmation; the late Quick rollback fixture was refused for the same new validation rule. Both are setup-only changes listed above. No failed existing assertion, weakened constraint or teardown change. The final complete rerun passed after those fixture corrections; no production change was needed for these two errors.

## Chrome evidence

**16 real Chrome screenshots**, with synthetic `.test` users only. Private manifest: `/private/tmp/kpone-unknown-dob-evidence/chrome-manifest.json`; images: `/private/tmp/kpone-unknown-dob-evidence/screenshots/`.

- `patient-create-{1440,390}-{light,dark}.jpg` (4): default unticked acknowledgement beside blank DOB.
- `quick-create-{1440,390}-{light,dark}.jpg` (4): the same staff confirmation in Registration Quick create.
- `patient-edit-cleared-{1440,390}-{light,dark}.jpg` (4): confirmation appears when the known DOB is cleared.
- `patient-edit-refused-{1440,390}-dark.jpg` (2): original save refused with the English confirmation message; no tick supplied.
- `patient-edit-confirmed-390-dark.jpg`: explicit checked acknowledgement before save.
- `patient-detail-saved-390-dark.jpg`: successful redirect, DOB “Not recorded”, no confirmation field exposed on the detail page.

Exact viewport measurements: 390×844 and 1440×1000 CSS px; scroll widths equal viewport widths in every capture. The clickable checkbox label measured **44 px high on desktop and 64 px on mobile**, with ample width. English wording and wrapping remained readable, with no hidden save/register buttons. Representative create-390-light, Quick-create-1440-dark and refused-edit-390-dark images were visually inspected. No Chrome console errors recorded. Screenshots remain outside the repository.

The preview used its own **kpone_unknown_dob_preview_testing** database and `/private/tmp/kpone-unknown-dob-evidence/preview-storage`, only after the full gate ended. Effective configuration was verified before its first HTTP request. Its first background server process ended with the launching session; the retained tool-session server was restarted on the same task-owned port, with no feature change. Chrome's successful save was verified directly on this database: exactly one `staff_edit` confirmation, Patient DOB NULL, metadata exactly `confirmation_id` (private `preview-confirmation-check.log`).

No tests ran with the preview live. Both task preview server and PostgreSQL cluster are now stopped. The served tree stayed clean on main; no unrelated worktree or non-task-owned database was used.

## Limits and deferred scope

No age-threshold change, WA-01 guardian use, guardian model/change, QR change, shared guardian, backfill, import, retention/purge policy, report or print field. Confirmation only records what the authorised staff actor explicitly attested; it does not establish legal capacity, consent or verified age. Generic source `staff_create` covers both staff entry points, while the Visit's existing source evidence identifies the registration path. Existing nullable-DOB records are not retrospectively certified.

No commit, push or PR until the owner says **“open the PR”**. The served tree stays on main and untouched. STK-2 remains separately stopped at its survey decision; no STK-2 implementation is part of this work.

## Handoff

Uncommitted local candidate only. Full diff: `/private/tmp/kpone-unknown-dob-confirmation.diff`. No commit, push or PR. Await owner review.
