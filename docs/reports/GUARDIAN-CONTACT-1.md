# GUARDIAN-CONTACT-1 — uncommitted local candidate

Date: 2026-10-27. Branch `feature/guardian-contact`, isolated worktree `/Users/klinikputrijaya/Herd/KPOne-guardian-contact`. Base: `0d9daf375671c6df0e0cea76c848d915285d0e4b`. Owner-requested rebase from `1de90ff` to this docs-only main update completed without conflicts; uncommitted changes were preserved/restored. Main's queue and board code was retained. **No commit, push or PR. Not production-approved.**

## Changes

A separate guardian contact belongs to each Patient. Relationship is one of `mother`, `father`, `legal_guardian`, `other_family_member`; name and phone are required, email optional. Name/phone/email are encrypted. Siblings have independent rows even when values match. No shared entity, backfill or Patient-column change. Guardian values never replace the Patient's own phone, email or address and are not used for Patient search, duplicate matching or WA-01.

Known minors under 18 require a guardian on creation or relevant DOB/guardian edits. Calendar birthdays determine the threshold, including leap-day boundaries. Staff forms/guardian validation use the selected branch timezone, with UTC fallback if no branch is selected. Unknown DOB keeps its existing behavior. Existing minors without guardians may save unrelated edits. Adults cannot add a new guardian but may edit an existing retained contact. No removal operation is provided.

Patient create/edit and Registration Quick create contain separate guardian fields. A minor's own phone is optional. Public QR preserves its existing age threshold and surrounding Bahasa Melayu, labels phone ownership explicitly and accepts a minor with only a guardian phone. Staff screens use English.

QR acceptance saves the guardian on a newly created minor. Matching an existing contact requires explicit keep/use-incoming choice and the current guardian version. Existing guardian values are NOT added to duplicate candidates: after the CA explicitly selects a Patient, a private, permission-checked selected-contact endpoint reads that one contact for comparison. It cannot search by guardian information. The existing 30-day intake purge remains unchanged; the accepted Patient guardian contact survives it.

Guardian details appear in the authorised Patient detail/edit projection and selected CA comparison only. They do not enter TV, QR status, Visit rows, billing print projections, Panel/report sources, Patient search or WhatsApp. Existing saved-Patient-phone contracts are preserved.

## Permissions and mutation safety

No new permission or role grant. Existing Patient view holders: Director, Resident Doctor, CA, CA Supervisor (`patients.view.organisation`). Existing create/update holders: Director, CA, CA Supervisor (`patients.create.organisation`, `patients.update.organisation`). QR review retains `public_intakes.review.branch`; the selected-contact endpoint also requires existing Patient-view authority. Unauthorised roles receive HTTP 403 and no guardian detail or values.

Actual guardian creation/change requires fresh actor permission, organisation/effective-branch authority, a transaction, actor/Patient/contact locks, Patient/contact versions and a UUID retry key with payload-conflict refusal. An unchanged supplied contact, adult creation without a guardian and unrelated legacy-minor edits impose **no new branch write requirement**. Guardian save and explicit keep-choice audit failures abort the owning transaction. Audit contains IDs, controlled choice and field names only, never guardian names/phone/email. Retry evidence is append-only. No sending channel is introduced.

## Migration and files

`database/migrations/2026_10_30_000100_create_patient_guardian_contacts.php` adds:

- `patient_guardian_contacts`: one row per Patient, organisation-scoped restrictive foreign keys, controlled relationship check, encrypted contact and independent `lock_version`.
- `patient_guardian_commands`: actor/organisation/Patient-scoped UUID/HMAC retry evidence; PostgreSQL refuses update, delete and truncate with `P0001`.

`down()` retains contact and retry evidence. No permission migration, Patient-column change, automatic backfill, independent purge or dependency. Existing databases require `php artisan migrate` before this candidate can run.

Business rules: new `PatientGuardianService`, with Patient administration, directory and intake-review wiring. HTTP validation/controllers provide the staff and QR entry points and the private selected-contact endpoint. UI: new `GuardianFields.vue`, `guardian-contact.ts`, Patient create/edit/detail, Registration Quick create and CA review. Existing search, WhatsApp, Queue/board and branch-authority implementations are untouched. The full uncommitted patch is `/private/tmp/kpone-guardian-contact-1.diff`.

## Root cause of the branch-cache side effect

The new guardian age check called `BranchAccessService::activeBranch` during ordinary Patient creation. If no branch had been selected, that resolver cached a fallback branch and wrote it to the session. The unchanged cross-branch queue fixtures later changed only the session through `VisitTestCase::selectBranch`, leaving the newly primed cache unchanged; registration then compared the requested branch against that cached fallback and refused. The fix is entirely in the new guardian date lookup: it reads the selected branch timezone directly, scoped to the actor organisation, only in guardian forms/create/edit validation, with UTC fallback when none is selected. It never calls the active-branch resolver, reads/writes its cache or changes session state. No board/queue read path calls this helper; the unchanged #176 Registration and completed-queue query-budget tests pass. A new regression forbids any active-branch resolver call and compares the session before/after.

## Tests

New files: `GuardianContactTest`, `GuardianIntakeTest`, `GuardianWhatsAppIsolationTest`, `GuardianFixture`, and frontend guardian behavior tests. Coverage includes existing permission holders and denials, fresh revocation, organisation/effective-branch refusal, no new branch requirement for adult/unrelated minor edits including unchanged submitted contact, frozen birthday/leap-day boundaries, encrypted independent contacts, retry/conflict/stale versions, audit rollback, QR explicit comparison and incoming/keep choices, persistence after the 30-day purge, sentinel absence from audit/status/TV/printing/search/WA-01, full ID-ordered unrelated-table comparison, and PostgreSQL append-only command protection.

The new WhatsApp isolation tests prove the redirect uses only the saved Patient phone; a guardian-only Patient has false button/list eligibility and its redirect is refused without an audit; guardian values are absent from Patient/Registration/Queue list payloads and redirect audits. The exact catalogue-role test checks that only existing Patient-contact permission holders receive the detail key. Frontend tests pin separate guardian controls and Quick create's optional own-phone behavior.

## Every changed existing assertion and fixture

**Exactly one existing assertion changed**, owner-authorised on 2026-10-27:

| File / test | Old expectation | New expectation | Reason |
|---|---|---|---|
| `tests/Feature/WhatsAppLinkBoundaryTest.php:22`, `test_patient_projection_adds_only_contact_eligibility_and_reads_do_not_audit_clicks` | Exact keys: `canWhatsapp, patientNumber, identity, contact, address, identifiers, administrative, activity, can`. | Same exact keys with only `guardian` inserted between `identity` and `contact`. | The owner authorised the Patient-detail guardian disclosure and this one-key change. The exact-set check and every other line/assertion in this test remain unchanged. |

One other existing test has an approved **setup-only** change:

| File / test | Lines | Old setup | New setup / reason |
|---|---|---|---|
| `tests/Feature/Patient/PatientDuplicatePreventionTest.php`, `test_same_name_and_shared_phone_are_allowed_only_after_soft_warning_confirmation` | import 7; assignment 67; contact 69 | Final synthetic child-create retained DOB `2015-03-03` and duplicate override but had no guardian/effective branch fixture. | Import `GuardianFixture`, assign the actor an effective synthetic branch immediately before the final child create and add a synthetic guardian to that payload. Owner-authorised 2026-10-27 for the mandatory-guardian rule. Child DOB, shared-phone setup, test name, expected values and every assertion are unchanged. |

Automated text comparison confirms each existing test equals base after removing only these authorised additions. No common existing fixture helper, Queue test, Registration test, query-budget assertion, skip, staff-authority rule or permission catalogue was changed. No test was weakened, removed or newly skipped.

## Validation history

The original Patient run stopped at the missing synthetic guardian fixture (56 passed / one error / 514 assertions). After the approved fixture correction it passed 57 tests / 515 assertions. A later original HTTP 201 assertion received 500 because an assistant edit introduced a syntax error in the new empty-name guard; the new-code repair was followed by the unchanged intake/Visit regression passing **123 tests / 2,219 assertions**. New/changed PHP was syntax-linted, then Pint and PHPStan were run early.

One intermediate CI attempt found 12 style errors in newly added selected-contact UI lines; those new lines were formatted without editing assertions. Earlier long-run attempts interrupted for implementation/privacy review are not full passes. A completed earlier full run stopped at the Patient-detail key assertion and two branch-cache errors: **1,461 passed / one failed / two errors / two existing skips; 30,022 assertions** (`verified-second/ci-check.log`). The owner then authorised the single key addition and directed the cache repair in new code. No queue fixture change was made.

## Final gate — passed

Environment: PHP **8.4.26**, Node **22.23.3**, task-only PHP/Composer **1 GiB**. PostgreSQL **18.6**, task-owned **127.0.0.1:58624**, data directory `/private/tmp/kpone-guardian-pg18`. Databases are UTF8 from template0. Effective host, port, database, version, encoding and data directory were verified before application use. No `.env` or `.env.preview` was read or created, and no non-task-owned database was used. Explicit process placeholders replace the workflow's `.env.example` copy. Dependencies are task-local copies, not writable symlinks.

| Check | Result / evidence |
|---|---|
| `php -l` all 19 changed/new PHP files | Passed (`owner-php-lint.log`). |
| Previously failing tests plus new isolation tests | **22 passed / 948 assertions** (`owner-targeted.log`). |
| Whole Queue, Visit/Registration and Patient suites; Auth Registration; PostgreSQL Registration; completed-queue scale | **276 passed / 2 existing skips / 2,609 assertions**, 278 reached (`owner-regression-suites.log`). Includes unchanged #176 budgets. |
| Exact CI-equivalent command sequence, including `composer ci:check` | **Passed** (`owner-final/ci-progress.log`). |
| Full backend | **1,467 passed / 2 existing skips / 30,170 assertions**, 1,469 reached, 688.047 seconds (`owner-final/ci-check.log`). |
| Pint; PHPStan level 7 | Passed, zero PHPStan errors. |
| `npm run lint:check`, `format:check`, `types:check` | Passed in `composer ci:check`. |
| `npm run build` | Passed (`owner-final/ci-build.log`). |
| `node --test tests/Frontend/*.test.mjs` | **502 passed**, no skips. |
| `node --test tests/Frontend/*.mjs` | **502 passed**, no skips (`owner-final/ci-frontend-full.log`). |
| Separate `migrate:fresh --seed --force` | Passed on `kpone_guardian_seed_testing` (`owner-final/ci-seed.log`). |
| `git diff --check` | Passed in gate and final documentation handoff. |

Final CI database: `kpone_guardian_owner_testing`. The ordered replay: `composer install --no-interaction --prefer-dist --no-progress`; `npm ci`; `php artisan wayfinder:generate --with-form --no-interaction`; frontend behavior tests; `php artisan migrate --force`; `npm run build`; `composer ci:check`; full frontend tests; separate fresh seed; diff check. No production memory limit changed. No gate was run while the preview was live.

## Chrome evidence — complete

Real Chrome, synthetic `.test` identities only. Separate `kpone_guardian_preview_testing` database and `/private/tmp/kpone-guardian-evidence/preview-storage`, verified before the first HTTP request. Preview origin: `http://guardian-review.localhost:58625`. Test and preview storage roots were separate. No screenshot contains real patient data.

**23 local private JPEGs**: 20 required combinations plus Quick-create success and two focused mobile acceptance/contact captures. Actual pixel widths were checked with `sips`: exactly 390 or 1440 as named. Every required capture measured document width equal to viewport width; no horizontal overflow. Visual review found readable controls and accessible action buttons in both themes. Long forms have full-page captures; fixed headers remain at their current scroll position in those captures, so focused viewport evidence is also supplied. Chrome console: no errors.

Manifest: `/private/tmp/kpone-guardian-evidence/chrome-manifest.json`. Screenshots root: `/private/tmp/kpone-guardian-evidence/screenshots/`.

| Screen | 1440 light | 390 light | 1440 dark | 390 dark |
|---|---|---|---|---|
| Patient create | `create-1440-light.jpg` | `create-390-light.jpg` | `create-1440-dark.jpg` | `create-390-dark.jpg` |
| Patient edit | `edit-1440-light.jpg` | `edit-390-light.jpg` | `edit-1440-dark.jpg` | `edit-390-dark.jpg` |
| Registration Quick create | `quick-1440-light.jpg` | `quick-390-light.jpg` | `quick-1440-dark.jpg` | `quick-390-dark.jpg` |
| CA QR review, selected comparison | `review-1440-light.jpg` | `review-390-light.jpg` | `review-1440-dark.jpg` | `review-390-dark.jpg` |
| Patient detail | `detail-1440-light.jpg` | `detail-390-light.jpg` | `detail-1440-dark.jpg` | `detail-390-dark.jpg` |

Additional evidence: `quick-success-1440-light.jpg`, `review-focus-390-light.jpg`, `kept-contact-390-light.jpg`.

Chrome actions completed: created `KP-00000002` with guardian phone only; saved a guardian name edit with the Patient phone still empty; Quick-created `KP-00000003` and OTC Visit `KPV-00000001` without an own phone; selected the existing `KP-00000001`, compared incoming/current contacts, explicitly chose keep and accepted intake into `KPV-00000002`; Patient detail then confirmed the original guardian remained and the Patient own phone was still empty. Visit screens showed no guardian details. These are disposable synthetic identifiers only.

## Known limits, deferred scope and handoff

No shared guardian entity, cross-patient guardian matching, guardian search, unknown-DOB confirmation, WA-01 guardian use, messaging, Panel member-reference change, Yezza import or independent retention period. Guardian retention follows the Patient contact-data policy. Legacy QR relationship labels such as `parent` require explicit CA classification into the four approved relationships; no mother/father inference. Staff enter/confirm contacts; KPOne does not verify them externally. No guardian-removal API in this slice.

No new owner decision is needed within this slice. The candidate remains uncommitted for review; no push or PR. Task-owned preview and PostgreSQL services are stopped, the Chrome task tab is closed and viewport override reset. The served tree remains clean on `main` and untouched. No non-task-owned database, private folder or other worktree was changed. Wait for owner review and explicit "open the PR" before committing/pushing/opening anything.
