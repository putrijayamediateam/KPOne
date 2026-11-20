# TRI-1b build plan: triage screens and the two owner rules

Status: **PLAN, revised after Farhana's review (20 Nov 2026; section 8).** The owner said start TRI-1b on 20 Nov 2026 and gave two
rule answers the same day (BASELINE section 6, item 31). Team: Triage (Syazwan, Lead; Farhana, Reviewer). Branch:
`feature/tri-1b-triage-screens` from `origin/main`. Synthetic data only. **Classic pages only; no V2 file is
created or changed.**

Source: `TRI-1-DESIGN.md` (sections 3, 5, 7, 8 and 9), `TRI-1a-PLAN.md` (what is built), owner answers of 20 Nov.

## 1. Owner rule 1: lowering a zone

Owner (20 Nov): "a zone may be lowered only after the reading that crossed its limit has been retaken and is within
limits, and never while a red flag is ticked." Replaces TRI-1a's "any changed reading with a reason".

A save **lowers** the zone when either:
- the chosen zone is below the suggested zone (an override down), or
- the chosen zone is below the chosen zone of the visit's latest earlier row that has a zone (the patient's zone
  goes down, even when the new suggestion agrees).

A lowering is accepted only when **all** of these hold, checked **after** the existing checks and in this order, so
the messages TRI-1a tests pin stay the same (the same-zone-reason refusal still comes first):
1. an override down still needs a fixed lower reason (unchanged: `doctor_reviewed_at_triage`,
   `device_error_confirmed`);
2. there is an earlier row on this visit and this save has at least one reading ("Take the readings again before
   choosing a lower zone.");
3. **no red flag is ticked** in this save, **and none was ticked in any earlier save of this visit** ("A zone cannot
   be lowered while a red flag is ticked." / "A red flag was ticked at this visit, so the zone stays red."). The red
   flag is sticky below red (open point R1; Farhana: otherwise unticking the flag plus one reading is a free way down);
4. **every reading that crossed a limit (yellow or red) in any earlier row of this visit** is entered in this save
   and is **within the limits of the chosen zone**: past no limit of a zone above the one chosen ("Take the readings
   again: Temperature must be retaken and within limits before the zone is lowered."). So 40 °C then 38.5 °C (past the
   yellow limit only) may go from red to yellow, not to green (open point R5);
5. an override **below the suggestion** is accepted only when **no reading in this save** is past a limit of a zone
   above the one chosen, so in practice it can only be against yellow signs.

"Crossed" for an earlier row is worked out against that row's own guideline version and age band; for this save,
against the version in force and today's age band. A row with no guideline never crossed anything. A newer guideline
version that drops a limit means a retaken reading no longer counts as past it (intended).

**PostgreSQL** (migration `2026_11_20_500500_enforce_triage_lowering_rule`): a new SQL function
`kp_triage_assess(version_id, age_band, the seven readings)` returns the zone from the readings, the crossed readings
and the readings past a red limit, using the same rules as PHP (section 3; red flags and yellow signs are applied in
the trigger, not the function). The insert trigger `kp_visit_triage_scope` is replaced (`CREATE OR REPLACE`), with the
new checks after the existing ones, so that it (a) refuses an `age_band` that does not follow the patient's date of
birth, the branch time zone and `patient_adult_confirmations` (Farhana blocker 2: otherwise a direct write could call a
child an adult), (b) refuses a `suggested_zone` that does not follow the readings and codes, and (c) repeats rules 2
to 5. PHP works out the age group from the same instant it stores as `recorded_at`, with the same whole-years rule as
SQL's `age()`. Existing rows are not touched (append-only).

**The form** shows the zone it will save: the higher of the suggestion and the lowest zone allowed now, with the
reason ("Temperature crossed its red limit earlier; retake it to lower the zone"), so "Confirm" never sends a save
that will be refused.

## 2. Owner rule 2: a yellow and a red limit per reading

Owner (20 Nov): "a reading may have two limits: a yellow one and a red one (for example temperature 38 °C yellow and
39.5 °C red)."

- A guideline may hold, per reading, age group and direction, **at most one yellow and one red limit**.
- Both present: for "very high" the yellow limit is **below** the red one; for "very low" the yellow limit is
  **above** the red one (yellow is always nearer normal). Every "very low" limit stays below every "very high" limit
  for the same reading and age group.
- Zone: at or past the red limit → red; else at or past the yellow limit → yellow (comparison unchanged: at or
  above, at or below).
- Up to 56 limits per version (7 readings × 2 age groups × 2 directions × 2 zones; was 28).

**Migration `2026_11_20_500400_allow_yellow_and_red_triage_limits`:** drop the unique key `triage_threshold_unique`
and add one on (`guideline_version_id`, `reading`, `age_band`, `direction`, `zone`); widen the
`threshold_count` CHECK to 0..56; replace the deferred consistency function to add the yellow-nearer-normal rule.
Existing approved versions stay valid (one limit per direction is still allowed). `down()` refuses once any version
has two limits for one reading, age group and direction.

`TriageGuidelineService::validateEntry`: `max:56`, the duplicate rule now per zone, and the ordering rules above.

## 3. One set of zone rules in three places, one set of test cases

- PHP `TriageZoneRules::suggest` (extended for two limits; adds `crossed` = reading → `{direction, zone}`; keeps
  `zone`, `highlights` and `missing` as they are).
- TypeScript `resources/js/lib/triage-zone.ts` for the live suggestion on the form (hundredths arithmetic, no
  floats).
- SQL `kp_triage_assess` for the trigger.
- **Shared cases:** `tests/Fixtures/triage-zone-cases.json` (readings, codes, age band, limits → zone and crossed
  readings). Read by `tests/Unit/Clinical/TriageZoneRulesTest.php`, by `tests/Frontend/tri-1b-triage-zone.test.mjs`
  and by a PostgreSQL feature test that calls `kp_triage_assess`, so the three cannot drift.

## 4. Screens (Classic only)

### 4.1 Triage tab on the Registration board
- New board status `triage`: consultation visits, `registered`, queue entry Waiting, **no triage row yet**; oldest
  first (its own order; the other tabs stay newest first). Known limit: it follows the board's date filter (today by
  default), so a carry-over patient from yesterday shows only if the CA widens the dates. One predicate shared by the list and the count (as the other tabs).
- The tab appears only when the server sends a `triage` count (as `qr-intake`), so the frontend tab tests that use
  counts without it stay unchanged.
- Shown and counted only for holders of `visits.triage.record.branch` (CA, CA Supervisor), like `billing`; others
  get no `triage` count and a 404 on `board_status=triage`.
- Each row offers **Triage**, which opens the form. No zone, reading or sign is shown on the board in TRI-1b
  (chips are TRI-1c).
- Files: `VisitDirectoryService` (predicate, count, order), `SearchVisitsRequest` (`triage` allowed),
  `Registration/Index.vue` (the tab and the row button). I will not change the shared row-action type that CO-3 is
  moving; the Triage button is a link on the Triage tab only. If I find I must touch it, I tell the coordinator first.

### 4.2 Classic triage form at `GET/POST visits/{visit}/triage`
- `TriageController` + `RecordTriageRequest`. GET: fresh permission, active branch, the visit in that branch (404
  otherwise), consultation, Waiting or called in with no consultation started; otherwise the page shows "read only".
- Props: patient name and KP number, queue number, the age group (adult, child or unknown, no date of birth), the
  versions to send back, the guideline in force (sign labels and limits only), the latest triage readings and zone if
  any, and the readings that must be retaken before lowering (rule 1.4).
- The six readings, red flags and yellow signs as tick boxes, a **live suggested zone** (TS rules), each reading past
  a limit in bold with "Very high" or "Very low" (and "red limit" or "yellow limit"), never colour alone; the missing
  readings named; "Confirm suggested zone" or choose another with the reason list. The screen explains when lowering
  is not possible (red flag ticked, a reading not retaken). Child with a reading past a limit: "Tell the doctor now".
- POST calls `TriageService::record` (routes in the `sensitive.no-store` group with a throttle; GET is 403 without the
  permission and 404 for another branch's visit; the seven reading keys join `dontFlash` in `bootstrap/app.php`, so a
  refused save never puts readings in the session, Farhana major 4) with an idempotency key made per form load (kept on a failed send, renewed
  after success); success redirects to `registration.index` with `tab=triage` and a toast; a refusal redirects back
  to the form explicitly (no bare `back()`).
- Also reachable from the visit details page (`Registration/Show.vue`) as "Triage" or "Retake triage" while allowed,
  for corrections, since a triaged visit leaves the Triage tab.

### 4.3 Guideline settings page `/triage-guideline`
- `TriageGuidelineController`, Classic page `resources/js/pages/TriageGuideline/Index.vue`, permission
  `triage.guideline.manage.organisation` (Director, HR Manager). Shows the version in force, the draft (Approve with
  the "matches the signed copy" tick, Discard), the history, and "Enter a new version" (child age limit, doctor's
  name and signature date, up to 12 red flags and 12 yellow signs, and a limits table with a yellow and a red box per
  reading, age group and direction). The entrant sees "Someone else must check and approve this version." and no
  Approve button. Wording and limits only, never patient data.
- A **"Triage guideline"** row in Clinic management (In-clinic group) for the same permission
  (`ClinicManagementNavigation`).

### 4.4 Rules for every write
Backend permission and branch (or organisation) checks under lock, `DB::transaction`, row locks, `lock_version`,
idempotency key, append-only evidence, audit with ids and codes only (all inside the TRI-1a services, unchanged).
English staff screens, usable at 390 px, dark mode checked; screenshots at 1440 and 390 px, light and dark, to the
coordinator for Daniel's look.

## 5. Tests

New: shared zone cases (PHP, TS, SQL); lowering rule in the service and by direct SQL insert (each of rules 2 to 5,
plus a faked suggested zone); two limits (yellow and red crossing, ordering refusals, 56 cap, direct SQL duplicate
per zone); Triage tab (membership, count, oldest first, hidden and 404 without the permission, OTC never listed,
leaves after triage); form GET and POST (roles, branch 404, read only after the consultation starts, explicit
redirects, retry key); guideline page (roles, enter, approve by the other manager, entrant cannot approve, discard);
Clinic management row; frontend tests for the form, the tab and the settings page; one contention race: two CAs
saving the form at once (already covered in the service; a route-level replay check added).

### 5.1 Existing assertions I expect to change (none changed before the owner approves through the coordinator)

| File:line | Today | After | Why |
|---|---|---|---|
| `tests/Feature/Clinical/Triage/TriageServiceTest.php:205-206` | lowering red to yellow with temperature 39.90 (still past the red limit) and a reason is **accepted** | refused ("within limits"); then a retake at 37.00 with the suggested green is accepted as row 2 | owner rule 1 |
| `tests/Feature/Clinical/Triage/TriageGuidelineServiceTest.php:97` | pulse red 120 plus yellow 130 refused with `'only once'` | the same pair refused with `'nearer normal'` (yellow beyond red); a new line shows yellow 110 plus red 120 accepted | owner rule 2 |
| `tests/Feature/Queue/TabCountsTest.php:81` | exact CA `tabCounts` without `triage` | gains `'triage' => 1` at the place its predicate sits | the new tab |
| `tests/Feature/ClinicManagementTest.php:45` (exact destination map) | no triage row | gains `'/triage-guideline' => ['triage.guideline.manage.organisation']` | the new hub row |

Not expected to change: the TRI-1a direct-insert messages (`TriageServiceTest.php:308-315`, new checks run after
them), the same-zone-reason refusal (`:214`), the yellow-sign-to-green save (`:122`, allowed under the defaults below),
and `tests/Frontend/tab-counts-1.test.mjs:32` (the tab is added only when a count is sent).
If the full run finds another, I list it with file, line and reason and wait.

## 6. Open points for the owner (built as the default unless told otherwise)

- **R1. Red flags (default: sticky).** Once any save at this visit ticked a red flag, the CA can no longer choose a
  zone below red; the doctor sees the patient. Alternative: a later save that unticks it may lower (Farhana showed this
  lets one changed reading clear a red).
- **R2. Which earlier readings must be retaken (default: any earlier row).** Alternative: only the latest row's
  (Farhana: bypassed by an earlier save with no readings).
- **R3. Override below the suggestion against yellow signs only (default: allowed with a lower reason).**
  Alternative: never.
- **R4. A zone raised by a yellow sign or by a higher override (default: may come down on a later save with any
  reading entered).** The owner's rule speaks of readings and red flags only, so a yellow from a yellow sign, or a red
  chosen for "staff concern", may drop when the CA no longer ticks the sign or no longer chooses higher (this is what
  `TriageServiceTest.php:122` already shows). Alternative: it may drop only with "Doctor reviewed at triage", which
  needs a CHECK change and changes that assertion.
- **R5. "Within limits" (default: within the limits of the chosen zone).** A retaken reading past only its yellow limit
  allows red to yellow, not to green. Alternative: past no limit at all, which makes red to yellow impossible (the CA
  could only keep red).

## 7. Clashes

No change to `PermissionCatalogue.php` (no new permission), shared layouts or V2 files. Shared files touched:
`VisitDirectoryService`, `SearchVisitsRequest`, `Registration/Index.vue`, `Registration/Show.vue`,
`ClinicManagementNavigation`, `routes/web.php` (four route groups). CO-3 (Haris, Nadiah) is told through the
coordinator before merge.

## 8. Changes after Farhana's review (20 Nov 2026)

Blocker 1 (unticking a red flag is a free way down): red flags sticky below red by default, R1 and R4 for the owner.
Blocker 2 (a direct write could fake the age group): the trigger works out the age group itself. Major: red to yellow
would have been impossible, so "within limits" is judged against the chosen zone (R5) and the form shows the zone it
will save (3); readings kept out of flashed input (4); the tab appears only with its count, so
`tab-counts-1.test.mjs:32` stays (5); new trigger and service checks run after the existing ones (6, 7). Minor: the
unique key swap runs on SQLite too and the whole shape CHECK is re-added, equal yellow and red limits refused (8); the
fixture holds sign and `unknown` cases (9); the Triage tab has its own order and a noted date-filter limit (10);
no-store, throttle, 403 and 404 on the form routes (11); no crossed readings in audit, messages name readings and
never values (12); a dropped limit in a newer version is intended (13); R2 default kept (14); `/triage-guideline` is
not mapped in the V2 shell, which is being removed (15).
