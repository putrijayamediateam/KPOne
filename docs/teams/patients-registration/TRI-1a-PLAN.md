# TRI-1a build plan: triage backend

Status: **PLAN, revised after Farhana's review (19 Nov 2026).** "start TRI-1a" was relayed by the coordinator for
the owner on 19 Nov 2026; section 8 lists what changed after the review.
Source: `TRI-1-DESIGN.md` (sections 4, 5, 7, 8 and the owner decisions of 19 Nov 2026 in section 9).
Team: Triage (Syazwan, Lead; Farhana, Reviewer). Branch: `feature/tri-1a-triage-backend`. Synthetic data only.

TRI-1a is backend only: two guideline tables, the triage table, two permissions, `TriageGuidelineService`,
`TriageService`, the zone rules and tests. No route, page, Vue file, board, Call In warning, TV or QR change
(those are TRI-1b and TRI-1c). No V2 or spaces file is touched.

## 1. Migration range

**`2026_11_20_500100` to `2026_11_20_500399`** for the Triage team. No migration dated 2026-11-20 or later
exists on `main` or on any of the 1,146 remote branches (checked 19 Nov 2026). The coordinator records the range
in BASELINE section 6, item 23.

| Migration | Content |
|---|---|
| `2026_11_20_500100_create_triage_guideline_versions` | `triage_guideline_versions`, `triage_guideline_signs`, `triage_guideline_thresholds`, `triage_guideline_events`, `triage_guideline_commands`, CHECKs and triggers |
| `2026_11_20_500200_create_visit_triage_assessments` | `visit_triage_assessments`, CHECKs and triggers |
| `2026_11_20_500300_grant_triage_permissions` | the two permissions, additive grants only |

Every migration: `Schema::hasTable` guard, `CREATE OR REPLACE FUNCTION` (so a repeated `migrate:fresh` cannot hit
a duplicate function, the PC-01 lesson), PostgreSQL-only trigger block, and `down()` that drops nothing once any
row exists (evidence is retained; the grant migration's `down()` is a no-op).

## 2. Tables and constraints

### 2.1 `triage_guideline_versions` (one row per saved version)

| Column | Type | Rule |
|---|---|---|
| `id` | bigint | |
| `organisation_id` | FK organisations | restrict |
| `version_number` | int | gapless per organisation, from 1; unique (`organisation_id`, `version_number`) |
| `status` | varchar(20) | `draft`, `approved`, `replaced`, `discarded` |
| `child_age_limit_years` | smallint | 1 to 18; "child" means age in whole years **below** this limit on the branch-local triage date |
| `approving_doctor_name` | varchar(120) | the doctor named on the signed paper copy (typed by a person; never seeded) |
| `doctor_signed_on` | date | not in the future (branch-free organisation date, checked in the service) |
| `entered_by_user_id` | FK users | restrict |
| `entered_at` | timestamptz | server time |
| `approved_by_user_id`, `approved_at` | nullable | both or neither |
| `replaced_at`, `discarded_by_user_id`, `discarded_at` | nullable | |
| `idempotency_key` uuid, `payload_hash` char(64) | | unique (`organisation_id`, `entered_by_user_id`, `idempotency_key`) |
| `red_flag_count`, `yellow_sign_count`, `threshold_count` | smallint | stored at insert; 1–12, 0–12, 0–28 |
| `lock_version` | int | > 0 |
| timestamps | | |

CHECKs (each comparison parenthesised; PostgreSQL does not chain `=`): status list;
`(approved_by_user_id IS NULL) = (approved_at IS NULL)`; `approved_by_user_id <> entered_by_user_id`;
`(status IN ('approved','replaced')) = (approved_at IS NOT NULL)`; `(status = 'discarded') = (discarded_at IS NOT
NULL)`; `(status = 'replaced') = (replaced_at IS NOT NULL)`; the three count ranges.

Insert trigger `kp_triage_guideline_insert` (BEFORE INSERT): `status = 'draft'`, `lock_version = 1`, every
approval, discard and replace column null, `version_number` = 1 + the organisation's highest (the service holds
the organisation lock; the trigger takes it too), and the entrant is an active user of the organisation. So a
direct write can never insert an approved version or skip the two-person rule.
Partial unique index `triage_guideline_one_in_force ON (organisation_id) WHERE status = 'approved'`.
Partial unique index `triage_guideline_one_draft ON (organisation_id) WHERE status = 'draft'` (one draft at a
time keeps the "check against the paper" step simple; a new draft needs the old one discarded first). **Open
point A** in section 7.

Update trigger `kp_triage_guideline_update`: only `draft → approved`, `draft → discarded` and
`approved → replaced`, and `replaced` only with `replaced_at` equal to the `approved_at` of another version of the
organisation approved in the same transaction (checked by a deferred constraint trigger at commit, so a version
can never be retired without a successor); `lock_version` must be `OLD + 1`; every content column (`organisation_id`,
`version_number`, `child_age_limit_years`, `approving_doctor_name`, `doctor_signed_on`, `entered_by_user_id`,
`entered_at`, `idempotency_key`, `payload_hash`, `created_at`) is unchanged; on approval the approver belongs to
the organisation, is active, is not the entrant and holds `triage.guideline.manage.organisation` through
`model_has_roles`/`role_has_permissions` or `model_has_permissions` (repeats the service rule so a direct write
cannot skip it).
Delete and truncate refused (`P0001`).

### 2.2 `triage_guideline_signs` (red flags and yellow signs of one version)

`id`, `guideline_version_id` FK, `kind` (`red_flag` or `yellow_sign`), `code` varchar(40)
(`^[a-z][a-z0-9_]{1,39}$`), `label` varchar(160), `position` smallint.
Unique (`guideline_version_id`, `code`) across both kinds; unique (`guideline_version_id`, `kind`, `position`);
`CHECK (position BETWEEN 1 AND 12)`, which with the unique key caps each kind at 12.
Insert trigger: the version is `draft`. Deferred constraint trigger (fires at commit for every inserted sign):
the version's real red-flag and yellow-sign counts equal its stored counts and the version is still `draft` at
the moment of the insert's commit. Because the counts are fixed at the version's insert, a sign added in any
later transaction breaks the count and is refused. (Not the `xmin = txid_current()` idea: `xmin` is `xid`, not
bigint, and inside a savepoint it is the subtransaction's id, so it would refuse every insert in tests.) Update,
delete and truncate refused.

### 2.3 `triage_guideline_thresholds`

`id`, `guideline_version_id` FK, `reading` (`systolic_bp`, `diastolic_bp`, `pulse_bpm`, `temperature_celsius`,
`spo2_percent`, `weight_kg`, `height_cm`), `age_band` (`adult`, `child`), `direction` (`very_low`,
`very_high`), `limit_value` numeric(7,2) > 0, `zone` (`red`, `yellow`).
Unique (`guideline_version_id`, `reading`, `age_band`, `direction`). A threshold is optional: a reading with no
row for its band and direction is never highlighted. The service refuses `very_low >= very_high` for the same
reading and band. Same insert, deferred count (against `threshold_count`), update, delete and truncate rules as
2.2.
Comparison rule (in PHP and stated in the column comment): `very_high` counts when the reading is **at or
above** the limit; `very_low` when **at or below**. **Open point B.**

### 2.4 `triage_guideline_events`

`id`, `guideline_version_id`, `event_type` (`entered`, `approved`, `replaced`, `discarded`), `actor_user_id`
(nullable for `replaced`, which is a consequence of another approval), `record_version`, `occurred_at`.
Unique (`guideline_version_id`, `record_version`). Deferred constraint trigger: every version row's
`status` and `lock_version` has its matching event (as Q-2 `kp_treatment_step_consistency`). Update, delete and
truncate refused.

### 2.4b `triage_guideline_commands` (retry records for approve and discard)

`id`, `organisation_id`, `actor_user_id`, `retry_key` uuid, `payload_hash` char(64), `result` json,
`occurred_at`; unique (`organisation_id`, `actor_user_id`, `retry_key`). Append-only (update, delete, truncate
refused), as `queue_treatment_step_commands`. Entering a version keeps its key on the version row.

### 2.5 `visit_triage_assessments` (append-only, one row per save)

| Column | Type | Rule |
|---|---|---|
| `id` | bigint | |
| `organisation_id`, `branch_id`, `visit_id`, `queue_entry_id` | FKs, restrict | composite FK to `visits(id, organisation_id, branch_id)` if that unique key exists, else checked in the trigger |
| `sequence` | smallint | 1, 2, 3 … per visit, gapless; unique (`visit_id`, `sequence`); the latest row counts |
| `systolic_bp`, `diastolic_bp`, `pulse_bpm` | smallint, nullable | same CHECKs as `encounter_vital_observations` (BP pair, > 0) |
| `temperature_celsius`, `spo2_percent` | numeric(5,2), nullable | > 0; SpO2 0 to 100 |
| `weight_kg` numeric(7,2), `height_cm` numeric(6,2) | nullable | > 0 |
| `red_flag_codes`, `yellow_sign_codes` | `text[]` NOT NULL, default `{}` | codes only, never free text; one-dimensional or empty, no duplicates, at most 12; each code exists in the referenced version with the matching kind (trigger, `NOT EXISTS (SELECT 1 FROM unnest(..) c WHERE NOT EXISTS (…))`); empty when no guideline |
| `age_band` | varchar(10), nullable | `adult`, `child` or `unknown`, from the patient's date of birth on the branch-local date and the in-force version's child age limit; **null exactly when no guideline is in force** (CHECK) |
| `guideline_version_id` | FK, nullable | the version **in force** at save; null only when none was approved |
| `suggested_zone`, `chosen_zone` | varchar(10), nullable | `green`, `yellow`, `red`; both null exactly when `guideline_version_id` is null |
| `override_reason_code` | varchar(40), nullable | see 2.6 |
| `recorded_by_user_id` | FK users | |
| `recorded_at` | timestamptz | server time |
| `idempotency_key` uuid, `payload_hash` char(64) | | unique (`organisation_id`, `recorded_by_user_id`, `idempotency_key`) |
| `created_at` | timestamptz | no `updated_at` (never updated) |

CHECKs: reading CHECKs as above (SpO2 0 to 100, as the encounter table); zone lists;
`(guideline_version_id IS NULL) = (suggested_zone IS NULL)`, the same for `chosen_zone` and `age_band`; when the
guideline is null both code arrays are empty; override reason rule (2.6); for each array
`cardinality(x) <= 12 AND (cardinality(x) = 0 OR array_ndims(x) = 1)`.

Insert trigger `kp_visit_triage_scope` (BEFORE INSERT):
1. `SELECT … FROM visits WHERE id = NEW.visit_id FOR UPDATE` (the service already holds this lock; the trigger
   takes it so a direct insert is serialised the same way);
2. the visit is `registered`, `consultation`, and in the row's organisation and branch;
3. its queue entry is NEW.`queue_entry_id`, same organisation and branch, and **Waiting**, or **Serving with no
   `clinical_encounters` row for the visit** (see open point C);
4. `sequence` = 1 + the visit's highest existing sequence;
5. `recorded_by_user_id` belongs to the organisation;
6. `guideline_version_id` equals the organisation's version in status `approved`, read `FOR SHARE` (so a
   replaced version can never be cited, and null is allowed only when none is approved), and every code is one
   of its signs of the right kind;
7. a lower `chosen_zone` than `suggested_zone` needs `sequence >= 2`, at least one reading entered, and readings
   that differ from the previous row's (decision 3: only after the readings are taken again; open point D).

Update, delete and truncate refused (`P0001`), as Q-2 and DSP-7.

No `ai_read` view reads this table or the guideline tables (privacy: readings are clinical).

### 2.6 Override reason codes (fixed list, in `TriageZone::OVERRIDE_REASONS`)

- Lower than suggested (required): `doctor_reviewed_at_triage` ("Doctor reviewed at triage"),
  `device_error_confirmed` ("Device error confirmed by a second device"). ("Reading repeated and normal" from the
  note is not needed as an override: a repeated normal reading already makes the new suggestion lower; open
  point D.)
- Higher than suggested (optional): `staff_concern` ("Staff concern"), `patient_appears_unwell` ("Patient appears
  unwell").
- Same zone: no reason (CHECK refuses one).

CHECK: `chosen = suggested` → reason null; `chosen < suggested` → reason in the lower list; `chosen > suggested`
→ reason null or in the higher list. Order green < yellow < red, as a CASE expression. The wording is a draft for
the owner (**open point E**).

## 3. Domain code

New folder `app/Domain/Clinical/Triage/` (the note puts `TriageService` in `app/Domain/Clinical`):

- `TriagePermissions` — constants `RECORD_BRANCH = 'visits.triage.record.branch'` (`ca`, `ca_supervisor`) and
  `GUIDELINE_MANAGE_ORGANISATION = 'triage.guideline.manage.organisation'` (`director`, `hr_manager`), with
  `grants()` like `TreatmentStepPermissions`.
- Models `TriageGuidelineVersion`, `TriageGuidelineSign`, `TriageGuidelineThreshold`, `VisitTriageAssessment`
  (each refuses `delete()` in `booted()`, and the assessment refuses `update`).
- `TriageZoneRules` — pure PHP, no database: `suggest(array $readings, list<string> $redFlags, list<string>
  $yellowSigns, string $ageBand, GuidelineSnapshot $g): array{zone, highlights}`. Section 5.1 exactly: any red
  flag or a red threshold crossed → red; else a yellow sign or a yellow threshold → yellow; else green. A blank
  reading never counts as normal and never crosses a threshold; `highlights` lists each crossed reading with
  `very_high` or `very_low` (for 5.2, used by TRI-1b), and `missing` lists the blank readings. For age band
  `unknown` it applies both the adult and the child thresholds, takes the higher zone and highlights a reading
  if either band's threshold is crossed (**open point F**).
  Numbers are compared as decimal strings scaled to hundredths (integers), never floats.
- `TriageGuidelineService`
  - `enter(User $actor, array $input): TriageGuidelineVersion` — new Draft with its signs and thresholds.
  - `approve(User $actor, TriageGuidelineVersion $v, array $input): TriageGuidelineVersion` — input
    `lock_version`, `idempotency_key`, and a required `matches_signed_copy` = true tick.
  - `discard(User $actor, TriageGuidelineVersion $v, array $input)` — a Draft only; either manager may discard.
  - `inForce(int $organisationId): ?GuidelineSnapshot` — read helper.
- `TriageService`
  - `record(User $actor, Visit $visit, array $input): VisitTriageAssessment`
  - `latest(Visit $visit): ?VisitTriageAssessment` — read helper for TRI-1b and TRI-1c.

### 3.1 `TriageGuidelineService` locks and checks

`DB::transaction(…, 3)`, actor before organisation (the order `VisitAdministrationService::update` uses through
`VisitPanelNoteService::lockCommands`):
1. actor: user, staff profile, role and permission grant rows, as `TransactionalActorAuthority::lock` but without
   a branch (an organisation permission; the actor must be active and hold
   `triage.guideline.manage.organisation` fresh under lock). A small private helper; `TransactionalActorAuthority`
   is not changed;
2. organisation row `FOR UPDATE` (serialises every guideline write of the organisation);
3. **idempotency replay first**: `enter` looks up its key on the version rows, `approve` and `discard` in
   `triage_guideline_commands`; the same keyed hash (`hash_hmac('sha256', …, app.key)`, as `InvoiceNoteService`,
   including the version id and expected `lock_version`) returns the stored result; a different hash is refused;
4. `approve`/`discard`: the draft row `FOR UPDATE`, then the in-force row `FOR UPDATE` (one fixed order: draft,
   then in-force; the organisation lock already serialises writers); stale `lock_version` refused; approver ≠
   entrant refused **before** any write ("You entered this version, so someone else must check and approve
   it."); approving sets the old in-force version to `replaced` first, then the draft to `approved`, both with
   the same timestamp and each with its event.

Validation: 1 to 12 red flags and 0 to 12 yellow signs (**open point G**: may a version have no red flag?),
labels 3 to 160 characters, codes generated by the service from the position (`rf_01` … `rf_12`, `ys_01` …
`ys_12`) so staff never type codes, at most one threshold per reading, band and direction, the doctor's name 3 to
120 characters, the signature date not in the future.

Audit (ids, version numbers and counts only, never wording or thresholds): `triage.guideline.entered`
(`version_number`, `red_flag_count`, `yellow_sign_count`, `threshold_count`), `triage.guideline.approved`
(`version_number`, `entered_by_user_id`, `replaced_version_id`), `triage.guideline.discarded`. An audit failure
rolls the write back (503), as `CallHoldService`.

### 3.2 `TriageService::record` input

`expected_branch_id`, `visit_lock_version`, `queue_lock_version`, `expected_latest_triage_id` (nullable; null
means "I saw no earlier triage"), `expected_guideline_version_id` (nullable; the version the form was built on),
the six readings (all optional; decision 4), `red_flag_codes[]`, `yellow_sign_codes[]`, `chosen_zone` (required
when a guideline is in force, prohibited otherwise), `override_reason_code`, `idempotency_key` (uuid).
Validation mirrors `ClinicalEncounterService::validateAggregate` for the readings (same messages).

### 3.3 `TriageService::record` lock order and checks

Before the transaction: `TransactionalActorAuthority::branch($actor, expected_branch_id)`; the visit must belong to
that branch (404 otherwise, never 403, so another branch's visit is not confirmed to exist); input validation.

`DB::transaction(…, 3)`, in the `ConsultationLocks` order used by Call In and by `VisitAdministrationService::update`:
1. `ConsultationLocks::users($org, [$actor->id, $visit->assigned_doctor_user_id])` — users ascending, then their
   profiles and assignments. This serialises a triage that raises priority with Call next for that doctor.
2. `TransactionalActorAuthority::lock($actor, $branch, 'visits.triage.record.branch')` — fresh permission and
   branch assignment under lock.
3. the patient's date of birth, read **without a lock** (the age band is derived data; locking the patient would
   invert the visit → patient order of `MedicalCertificateSnapshot`).
4. the visit `FOR UPDATE` (organisation and branch scoped); if its assigned doctor changed since step 1, refuse as
   stale (as `CallHoldService`).
5. the queue entry `FOR UPDATE`.
6. the visit's latest triage row (`ORDER BY sequence DESC LIMIT 1`; no lock needed, the visit lock serialises
   every writer, including the trigger).
7. `clinical_encounters` for the visit — existence only (encounter start holds the visit lock, so this read is
   stable).
8. the in-force guideline version `FOR SHARE` (approval takes `FOR UPDATE` on it, so an approval waits for, or
   is waited for by, the triage save; approval locks no visit, so no cycle) and its signs and thresholds.

Then, in this order:
- idempotency replay: same actor and key with the same keyed hash returns the stored row (no new audit) only if
  the stored row's `visit_id` is this visit; the hash covers the visit id, every expected version and the whole
  input; a different hash or another visit is refused;
- `visit.lock_version`, `queue.lock_version` and `expected_latest_triage_id` must match, else "This patient's
  triage or queue changed. Reload and review the latest readings.";
- visit `registered` and `consultation`; queue Waiting, or Serving with no encounter (open point C); otherwise
  "Triage is read only once the doctor has started the consultation.";
- `expected_guideline_version_id` equals the in-force id (both may be null), else "The triage guideline changed.
  Reload the form.";
- codes all belong to the in-force version (no guideline → no codes accepted);
- zone: `suggested` from `TriageZoneRules`; `chosen` lower than `suggested` needs a reason from the lower list
  **and** an earlier row on this visit (decision 3; the first save can never lower);
- insert the row (`sequence` = latest + 1);
- priority: `chosen_zone` yellow or red, `visit.priority = 'normal'` **and the queue entry Waiting** →
  `VisitAdministrationService::raiseToUrgentForTriage` (3.4). While Serving the zone is recorded and the visit is
  not touched: Urgent order means nothing once the patient is called, the visit edit already refuses changes
  after Call In, and bumping the visit version would make the doctor's open encounter start stale. Green never
  lowers priority (note 4.3);
- audit `visit.triage.recorded`, **subject the Visit** (so the Audit log hides the subject id from viewers without
  patient access, as other visit events): `triage_assessment_id`, `sequence`, `guideline_version_id`,
  `suggested_zone`, `chosen_zone`, `override_reason_code`, `red_flag_codes`, `yellow_sign_codes`,
  `priority_raised` (bool). **No reading values and no age band** (note 8: ids, the zone and codes only). Audit
  failure → 503 and rollback. Rule for later: the Audit log must never show this metadata to the HR Manager,
  because codes plus the guideline page reveal the wording.

Returns the row; the caller (TRI-1b) redirects explicitly (no bare `back()`).

### 3.4 Shared file: `VisitAdministrationService` (Patients & Registration's own file)

New public method `raiseToUrgentForTriage(Visit $lockedVisit, User $lockedActor, Branch $branch, int
$triageAssessmentId): void`, callable only inside a transaction (`abort_unless(DB::transactionLevel() > 0)`), on
a visit the caller already locked. It re-asserts that the locked actor holds `visits.triage.record.branch`, the
visit is a registered consultation and its queue entry is Waiting. It sets `priority = 'urgent'`,
`updated_by_user_id`, `lock_version + 1`, and records the same audit rows `update()` would for a priority-only
change: `visit.details.updated` with `changed_fields: ['priority']` (REG-4 change history reads it) and
`visit.priority.marked_urgent` with `record_version`, plus `source: 'triage'` and `triage_assessment_id` on the
latter. It does not require `visits.update.branch` (the triage permission
is the authority, checked in 3.3 step 2) and does not touch reasons, coverage, doctor or Panel note. Why not call
`update()`: it needs the whole visit payload, re-validates coverage and reasons, re-locks users and the patient,
and checks `visits.update.branch`. **Open point H.**

Triage **without** a priority change does not bump `visit.lock_version`, so an open Call In or visit edit is not
made stale by a green triage. A yellow or red triage that raises priority does bump it (through the method
above), so a Call next dialog that named someone else re-checks, which is what Q-4 expects.

### 3.5 Other shared files

- `app/Domain/Access/PermissionCatalogue.php`: add `...array_keys(TriagePermissions::grants())` to `all()` and a
  foreach over `TriagePermissions::grants()` in `roles()`, with a comment naming the owner decision. Shared file,
  two short additions; no other line changes.
- `bootstrap/app.php` `dontFlash`: not needed (no secret field).
- No change to `ConsultationLocks`, `ClinicalEncounterService`, `QueueEntryService`, `CallNextService`, the call
  holds, the TV, the QR page or any V2 or spaces file.

## 4. Permission migration `2026_11_20_500300_grant_triage_permissions`

As `2026_11_08_000200_grant_queue_treatment_progress_permissions`: literal names, `firstOrCreate` each
permission, give only what is missing, a missing role logged and skipped, never revoked, `down()` a no-op.

- `visits.triage.record.branch` → `ca`, `ca_supervisor`
- `triage.guideline.manage.organisation` → `director`, `hr_manager`

Neither is in `AUTHORITY_OVER_PEOPLE_AND_ACCESS`, so staff authority is unchanged. `canAssignRoles`: the Director
still assigns every role (protected-authority branch); a non-Director could not assign CA or CA Supervisor before
and still cannot. The HR Manager gains no patient or triage reading access (tested).

## 5. Tests

### 5.1 New files

- `tests/Unit/Clinical/TriageZoneRulesTest.php` — each rule of 5.1; red beats yellow; at-limit counts; blank
  never normal; `unknown` age band takes the higher zone; hundredths arithmetic (`37.995` refused by validation,
  `38.00` vs `38.0` equal).
- `tests/Feature/Clinical/TriageGuidelineServiceTest.php` — enter and approve by two different managers
  (Director → HR Manager and HR Manager → Director); entrant cannot approve (service and a direct SQL update,
  23514); a direct SQL insert of an approved version refused; a direct SQL `approved → replaced` with no successor
  refused at commit; a sign or threshold inserted after the version's transaction refused at commit; replace
  keeps one in force; discard; one draft at a time; idempotent retry of enter, approve and discard (including a
  retried approval after it succeeded) and changed-payload refusal; stale `lock_version`; `very_low >= very_high`
  refused; other roles 403 (all 11 other catalogue roles, including `administrator`, `locum` and the TV
  account); inactive actor;
  other organisation 404; content immutable after insert (direct SQL update, delete and truncate refused); signs
  and thresholds cannot be added to an approved version; audit carries no wording.
- `tests/Feature/Clinical/TriageServiceTest.php` — CA and CA Supervisor record; every other role 403, including
  Director, Resident Doctor, HR Manager, Administrator, Technical Admin, Finance, Panel, Marketing, BD, Locum, TV;
  wrong branch / no assignment today 403; another branch's visit 404; OTC visit, cancelled or completed visit and
  Removed queue entry refused; Waiting allowed; Serving with an encounter refused; no guideline → readings only,
  zone null, age band null, codes refused; yellow and red set Urgent while Waiting with
  `visit.priority.marked_urgent` (`source: triage`) and `visit.details.updated`; yellow while Serving records the
  zone and leaves the visit and its version unchanged (the doctor's encounter start still succeeds);
  green on Urgent leaves Urgent; lower zone on the first save refused; lower zone on a second save with
  readings identical to the first, or all blank, refused; lower zone on a second save with new readings and a
  reason accepted; out-of-range readings (32768, 1000.00, 37.995) refused by validation, never a database error;
  a retry key reused on another visit refused; higher zone with and without a reason; same-zone reason refused; codes from a replaced version
  refused; stale versions and `expected_latest_triage_id` refused; guideline approved meanwhile refused;
  idempotent retry returns the same row and writes one audit; audit metadata pinned exactly and has no reading
  value; direct SQL update, delete, truncate and an out-of-scope insert refused; age band from date of birth on
  the branch-local date (Kuala Lumpur midnight case, frozen clock built from the branch time zone, CLOCK-1 lesson),
  unknown date of birth → `unknown`.
- `tests/Feature/Clinical/TriagePermissionMigrationTest.php` — fresh grant, already seeded, up → down → up,
  missing role skipped, nothing revoked, exact holders.
- `tests/Feature/PostgresTriageRegressionTest.php` with worker `tests/Support/PostgresTriageWorker.php`, in the
  serial **Contention** suite (`phpunit.xml`; `ContentionSuiteTest` requires it), using `ContentionTimeouts` and
  proving through `pg_blocking_pids` that the second worker waits before releasing the first:
  1. **two CAs triaging one visit** — both send `expected_latest_triage_id = null`: exactly one row, the other
     refused as stale; one audit row;
  2. **triage against the doctor starting the consultation** — Serving, no encounter, a yellow zone: either
     triage commits first (no visit bump, so encounter start still succeeds, and a lookup the worker runs inside
     its encounter-start transaction, standing in for TRI-1c's pre-fill, sees exactly that one row) or encounter
     start commits first and triage is refused; never a triage row after the encounter;
  3. **triage against Call In** (Waiting, yellow) — they serialise on the doctor and visit rows: if Call In wins,
     triage is refused as stale (queue version) and nothing is written (a reloaded triage is then recorded on
     the Serving entry without a priority change); if triage wins and raises priority, Call In with the old
     visit version is refused as stale, as any visit change does today; never a deadlock, never both on the old
     versions;
  4. **two Directors approving one guideline version** (entered by the HR Manager) — one approval, one
     `approved` event, one in force; the other refused as stale.
  Also: **triage against guideline approval** (the triage either cites the old version or waits and is refused
  as "guideline changed", never a raw database error); **triage against visit cancel** (one wins; no triage row
  on a cancelled visit); **triage raising priority against Call next for the same doctor** (no deadlock; Call
  next names the urgent patient or refuses as changed).

### 5.2 Existing assertions that may need the owner's approval

Adding two permissions changes role permission lists. Farhana searched the tests: the pins in
`AdministratorRoleTest`, `SupervisorPricingTest`, `LocumSignInTest`, `QueueDisplayTest`,
`StaffAuthorityBoundaryTest`, `DepartmentSpaceMatrixTest` and `ClinicManagementTest` do not touch the four roles
that gain a permission, and no exact CA, CA Supervisor, Director or HR Manager list is pinned. If the full run
still finds one, it is listed with file and line and sent to the coordinator for the owner's approval **before**
it is changed. `tests/Frontend/fixtures/access-control-roles.json` is a static catalogue snapshot that does not
fail; it is refreshed in this slice only if a test reads the new permissions, otherwise noted. Tests and seeders
use a fictional doctor name (`Dr Synthetic Triage`), never the real signing doctor's name.

### 5.3 Quality gate

Full `CLAUDE.md` gate on a task-owned PostgreSQL 18 container (`max_locks_per_transaction=1024`) with explicit
`DB_*` settings, the effective host, port and database name printed and checked before the first test; `kphp`
for PHP 8.4; a `php` shim to `kphp` for `npm run build`; `migrate:fresh --seed` on a second disposable database.
No `.env` is read or created.

## 6. Out of TRI-1a

Routes, controllers, requests, the Triage tab and form (TRI-1b); zone chips, the encounter pre-fill and the Call In
and Call next "no triage yet" warning (TRI-1c); TRI-2 and TRI-3; any medicine instruction; the settings page for
the guideline (TRI-1b or a TRI-1a-ui slice; **open point I**).

## 7. Places where the note is unclear or conflicts with the code

- **A. One draft at a time?** The note says each save is a new version starting as Draft. I propose one draft per
  organisation; a second draft first discards the first. Alternative: many drafts.
- **B. "Past a threshold":** at or above / at or below (proposed), or strictly past?
- **C. Waiting only, or until the encounter starts? (conflict; built as proposed.)** Note 4.1 says the insert trigger requires the
  queue entry to be **Waiting**; note 8 says a CA may add rows **until the doctor starts the consultation**, and
  race 2 (triage vs encounter start) only exists if triage is allowed while Serving. In the code, Call In moves the
  entry to Serving and the encounter starts later (`ClinicalEncounterService::start` requires Serving). Proposed:
  Waiting, or Serving with no encounter yet (honours section 8 and its race); while Serving the zone is recorded
  but priority is not raised (3.3). Alternative: Waiting only (then race 2 becomes "triage vs Call In" and Call
  In ends triage).
- **D. "A second set of readings" (decision 3) — needs the owner's word.** If the repeated reading is normal, the
  new save already suggests a lower zone and no override is needed, so an override only matters when the
  guideline still says "higher". Built default: a lower zone than suggested is accepted only on a save that is not
  the visit's first, with at least one reading entered, readings different from the previous save's, and a reason
  (`doctor_reviewed_at_triage` or `device_error_confirmed`). Alternative: one save carries two reading sets.
  Changing this later is one rule in the service and the trigger.
- **E. Override reason wording** in 2.6 is a draft; the owner or Dr Fatin Adilah may want other reasons.
- **F. Unknown date of birth:** proposed: apply both adult and child thresholds and take the higher zone; record
  `age_band = unknown`. A patient with an adult confirmation (PR #181) counts as adult. Alternative: adult only.
- **G. Can a version have no red flag?** Proposed: at least 1 red flag; yellow signs may be 0.
- **H. Priority writer:** the note says priority is set "through `VisitAdministrationService`". `update()` cannot be
  reused as is (whole payload, `visits.update.branch`, patient re-lock), so I add the narrow method in 3.4, which
  keeps the existing audit event and Urgent order. Confirm the CA's triage permission alone may raise priority
  (today CA and CA Supervisor also hold `visits.update.branch`, so nothing new in practice).
- **I. Guideline settings page:** the note has no slice for it; TRI-1a gives only the service. Proposed: TRI-1b
  adds the page beside the Triage tab, with the Lead UI/UX Designer's look.
- **J. "lock_version on the visit":** proposed: every triage checks `visit.lock_version` but bumps it only when
  priority changes (yellow or red while Waiting), so a green triage, or any triage while Serving, never makes an
  open Call In or encounter start stale.
- **K. HR Manager and branch:** `triage.guideline.manage.organisation` is organisation-scoped; the HR Manager needs
  no branch assignment for it. The HR Manager still gets no triage reading (no `visits.triage.record.branch`).

## 8. Changes after Farhana's review (19 Nov 2026)

Blocker 1 (the `xmin` idea cannot work): stored counts on the version plus deferred count triggers and a
position CHECK. Major: an insert trigger on versions and a successor rule for `replaced` (2); no priority raise
while Serving (3); decision 3 rule reworded and sent to the owner (4); `triage_guideline_commands` and replay
before the stale checks (5); the in-force version read `FOR SHARE` and a new race test (6). Minor: parenthesised
CHECKs (7); date of birth read without a lock (8); race descriptions corrected and three races added (9); `locum`
and `administrator` in the denial lists (10); validation bounds and `decimal:0,2`, SpO2 0 to 100 (11); `age_band`
null with no guideline, both bands' highlights for `unknown` (12); `NOT NULL` one-dimensional, duplicate-free code
arrays (13); `raiseToUrgentForTriage` re-checks authority and Waiting and writes `visit.details.updated` (14); the
Visit as audit subject, no age band in audit, a rule on metadata display (15); one lock order, actor before
organisation (16); the triage retry hash covers the visit and the replay checks it (17); `very_low < very_high`
(18); a fictional doctor name in tests and the fixture note (19).

## 9. Build (19 Nov 2026)

Built on `feature/tri-1a-triage-backend` as planned. The coordinator answered open points A, B, C, D, F, G and I for
the owner ("be me, i approve all", 19 Nov 2026) with the defaults above; the guideline settings page goes in
TRI-1b, and later slices build Classic pages only (no V2). Farhana's diff review found no blocker; her fixes are
in the build: a CHECK hole for a reason code on a readings-only row, a successor rule that names the newest
approved version, `very_low < very_high` in PostgreSQL, the recorder must hold the triage permission in the
insert trigger, `model_type` and guard filters in the permission check, checked audit writes in
`raiseToUrgentForTriage`, and more tests (unknown date of birth, adult confirmation, an ended assignment, direct
insert messages, Call next state). Her race finding on the very first guideline is closed with a triage-only
PostgreSQL advisory lock (`kp_triage_guideline_gate`: shared for a triage save, exclusive for guideline entry and
approval), not the organisation row, because the visit edit locks the organisation before the visit and
triage locks the visit first.

Open for the owner: "readings differ" counts a change in any reading (for example the height), so a red from a
reading that is still past its threshold, or from a ticked red flag, can be lowered on a retake with a reason;
and one limit per reading, age group and direction means no tiered rule such as "38 is yellow, 39.5 is red".
