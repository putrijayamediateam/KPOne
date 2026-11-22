# TRI-1c build plan: zone chips, vitals from triage and the "No triage yet" warning

Lead: Syazwan. Reviewer: Farhana. Owner start: "start TRI-1c" (team session, 22 Nov 2026). Design:
`TRI-1-DESIGN.md` sections 3.5, 3.6, 9.5 and 10. Classic only. Synthetic data only.

## 0. Owner choices (team session, 22 Nov 2026)

1. **Who sees the zone chip:** only the CA, the CA Supervisor (`visits.triage.record.branch`) and the patient's own
   doctor (the visit's assigned doctor holding `encounters.view.own`). The Director and every other board viewer still
   see the existing Urgent chip, never the zone. The TV and the public QR page show nothing (unchanged).
2. **Vitals from triage are saved at consultation start:** KPOne copies the latest triage readings into the
   encounter's vitals record inside the encounter-start transaction, and the consultation page shows
   "Taken at triage by <CA> at <time>" until the doctor changes them.
3. **Warning:** a "No triage yet" chip on waiting consultation rows of both boards, plus a note in the existing Queue
   Call In dialog and in the Call next dialog. Registration's Call In stays one click. Never a block.

## 1. Zone projection

`App\Domain\Clinical\Triage\TriageZoneProjection::forVisits(User $actor, iterable $visits): array<int, array>`, keyed
by visit id, one query (the latest row per visit by `sequence`, organisation-scoped). A visit gets an entry only when:

- it is a consultation, and
- the actor holds `visits.triage.record.branch`, or the actor is the visit's assigned doctor and holds
  `encounters.view.own`, and
- it has a triage row, or its queue entry is Waiting (so "No triage yet" shows only where it matters).

Entry: `{ zone: 'red'|'yellow'|'green'|null, triaged: bool }`. `zone` is the latest `chosen_zone` (null for a
"readings only" row before any guideline is approved). No reading, sign, reason or name ever leaves on the boards.

Used by:
- `VisitDirectoryService::row` (Registration board): new row key `triage`;
- `QueueDirectoryService::row` (Queue board): new row key `triage`;
- `CallNextService::candidate` (Call next block): new candidate key `triage` (the actor is passed in);
- `ClinicalEncounterDirectoryService::detail` (consultation page): `clinical.triage`.

Not touched: the TV feed, the public QR status, audit metadata of `queue.called` (exact-key tests pin it), tab counts.

## 2. Vitals from triage at consultation start

- **Migration `2026_11_20_500600_add_triage_source_to_encounter_vitals`:** nullable
  `encounter_vital_observations.source_triage_assessment_id`, FK to `visit_triage_assessments.id` (restrict), and a
  PostgreSQL trigger `kp_encounter_vitals_triage_source`: when the source is set, the triage row must belong to the
  encounter's visit, organisation and branch, and the seven readings must equal the triage row's readings (so the label
  is always true); on update the source may only stay the same or become null, never point to another row. `down()`
  drops the trigger and the column. Additive; `ai_read` reads only `EXISTS` on this table, so no view changes.
- **`ClinicalEncounterService::start`:** after the encounter row is created, inside the same transaction (the visit row
  is already locked, which serialises it with `TriageService::record`), read `TriageService::latest()`. If that row has
  at least one reading, insert the vitals row with its readings, `observed_at` = the triage `recorded_at`,
  `recorded_by_user_id` = the CA who recorded it, `updated_by_user_id` = the doctor, and the source id. Audit
  `encounter.vitals_from_triage` with the encounter, visit and triage ids only (no readings). No triage, or a triage
  with no readings: nothing is copied, exactly as today. The "returns the existing encounter" path copies nothing.
- **`ClinicalEncounterService::update`:** when the doctor changes the vitals of a row that has a source, the source is
  cleared and the row becomes the doctor's own observation (`observed_at` now, `recorded_by_user_id` the doctor). A save
  that leaves the vitals unchanged keeps the source.
- **Consultation page:** `clinical.vitals.fromTriage = { recordedBy, recordedAt }` while the source is set; the vitals
  section shows "Taken at triage by <CA> at <time>" (branch time zone). The form is seeded from the saved row as today,
  so nothing shows as "Not saved yet" (the Q-11 code and its pinned regexes are untouched).
- Triage stays read only once the encounter exists (TRI-1a rule, unchanged), so the copy is final.

## 3. Screens (Classic)

- **`PatientBoard.vue` Status cell** (no new column; Q-2b pins the widths): after the Urgent chip, one chip:
  "Red zone", "Yellow zone" or "Green zone" (bordered theme tokens with a dot, the word always shown, never colour
  alone), "Triaged" (readings only) or a muted outline "No triage yet". `PatientBoardRow` gains optional `triage`.
  Registration and Queue `boardRows` pass it through.
- **Consultation page patient card:** the same zone chip beside Urgent (no "No triage yet" here).
- **`QueueCallDialog.vue`:** under the patient card, "No triage yet. You can still call." when the row's `triage`
  says not triaged.
- **`CallNextControl.vue` candidate card:** the zone as a `StatusBadge`, or "No triage yet".
- Staff screens in English; 390 px and dark mode checked; the Lead UI/UX Designer reviews screenshots.

## 4. Tests

- Backend (new files under `tests/Feature/Clinical/Triage/`): projection gating (CA, CA Supervisor, own doctor see it;
  another doctor, the Director, Administrator and Finance do not); `triage` on Registration rows, Queue rows, the Call
  next candidate and the consultation page; nothing on the TV feed; "No triage yet" only on Waiting rows; pre-fill copies
  the latest row (sequence 2), keeps the CA as recorder, sets the source and writes one audit row; no triage or a
  readings-free triage copies nothing; a doctor change clears the source; PostgreSQL refuses a source from another
  visit, readings that differ from the source, and a source switched to another row.
- Contention: a new case in the serial Contention suite racing triage against encounter start with the real copy: either
  triage commits first and the encounter's vitals carry exactly that row, or the encounter commits first and triage is
  refused; never a triage row after the encounter and never two copies.
- Frontend: a new `tests/Frontend/tri-1c-triage-chips.test.mjs` (source checks of the chips, the label and the notes).

## 5. Existing assertions

None expected to change. If the full run finds one, it is listed with file and line and sent to the coordinator for the
owner's approval before it is changed.

## 6. Out of TRI-1c

TRI-2 (emergency lane, all-doctor alert), TRI-3 (standing order), any zone on the TV or QR page, any change to queue
order beyond the existing Urgent rule, a confirm step on Registration's Call In (owner chose no).
