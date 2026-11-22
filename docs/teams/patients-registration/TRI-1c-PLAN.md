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
4. **(After Farhana's review) Which readings are copied:** for each reading, its most recent value across the visit's
   triage saves (a retake often re-enters only the reading that crossed a limit). The label reads "From triage, last
   taken by <CA> at <time>", naming the latest save that gave a reading.
5. **(After Farhana's review) Who sees "No triage yet":** the zone viewers above and anyone who may call that patient
   (for example the Director from the branch board), so nobody calls an untriaged patient unwarned. The zone colour
   itself stays with the CA, the CA Supervisor and the patient's own doctor.

## 1. Zone projection

`App\Domain\Clinical\Triage\TriageZoneProjection::forVisits(User $actor, iterable $visits): array<int, array>`, keyed
by visit id, one query (the latest row per visit by `sequence`, organisation-scoped). A visit gets an entry only when:

- it is a consultation whose queue entry is Waiting or Serving (a completed or removed row gets no chip and costs no
  query; the bounded-query pilot test of the Completed tab stays within its limit), and
- the actor holds `visits.triage.record.branch`, or the actor is the patient's own doctor with the `resident_doctor`
  role and `encounters.view.own` (the same pair as `ClinicalEncounterPolicy`); the consultation page passes the
  attending clinician as the own doctor: entry `{ zone, triaged: true }` once triaged, `{ null, false }` while Waiting
  with no triage;
- otherwise, for anyone who may call the patient (`queue.call.branch`, or `queue.call.own` as the assigned doctor), only
  `{ zone: null, triaged: false }` while Waiting with no triage, and nothing once triaged (owner choice 5).

`zone` is the latest `chosen_zone` (null for a "readings only" row before any guideline is approved, shown as
"Triaged"). No reading, sign, reason or name ever leaves on the boards.

Used by:
- `VisitDirectoryService::row` (Registration board): new row key `triage`;
- `QueueDirectoryService::row` (Queue board): new row key `triage`;
- `CallNextService::candidate` (Call next block): new candidate key `triage` (the actor is passed in);
- `ClinicalEncounterDirectoryService::detail` (consultation page): `clinical.triage`.

Not touched: the TV feed, the public QR status, audit metadata of `queue.called` (exact-key tests pin it), tab counts.

## 2. Vitals from triage at consultation start

- **Migration `2026_11_20_500600_add_triage_source_to_encounter_vitals`:** nullable
  `encounter_vital_observations.source_triage_assessment_id` (no foreign key: one referencing the append-only triage
  table changes how PostgreSQL refuses a TRUNCATE of it, which an existing TRI-1a test pins; triage rows are never
  deleted and the trigger checks the source row exists), and a
  PostgreSQL trigger `kp_encounter_vitals_triage_source`: when the source is set, the triage row must belong to the
  encounter's visit, organisation and branch, and the seven readings must equal the triage row's readings (so the label
  is always true): the source must be the visit's latest row (max `sequence`), and the seven readings, compared as one
  row with `IS DISTINCT FROM` (so a null cannot slip through), must equal the latest non-empty value of each reading
  across the visit's triage rows; `observed_at` is not compared in SQL (the two columns differ in time zone type). On
  update the source may only stay the same or become null, never be set from null or point to another row. `down()`
  drops the trigger and the column. Additive; `ai_read` reads only `EXISTS` on this table, so no view changes.
- **`ClinicalEncounterService::start`:** after the encounter row is created, inside the same transaction (the visit row
  is already locked, which serialises it with `TriageService::record`), read the visit's triage rows. Each reading takes
  its latest non-empty value (owner choice 4). If any reading has a value, insert the vitals row with them,
  `observed_at` and `recorded_by_user_id` from the latest save that gave a reading, `updated_by_user_id` = the doctor,
  and the source = the visit's latest triage row. Audit
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

- Backend (`tests/Feature/Clinical/Triage/TriageChipsAndVitalsTest.php`): projection gating (the zone for CA, CA
  Supervisor and the own doctor; the warning only for the Director; nothing for another doctor, Finance,
  Administrator, HR Manager or Panel Officer); `triage` on Registration rows (service and the poll JSON), Queue rows,
  the Call next candidate (`GET /queue/call-next`) and the consultation page; nothing on the TV feed; no entry for OTC or
  a called-in untriaged row; the copy takes the latest of each reading, names the latest contributing CA, sets the
  source and writes one ids-only audit row; no triage or a readings-free triage copies nothing; an unchanged save keeps
  the source, a change clears it, clearing all deletes the row; PostgreSQL refuses changed readings under the source, a
  switched or newly set source, and a source from another visit.
- Contention: a new case in the serial Contention suite racing triage against encounter start with the real copy: either
  triage commits first and the encounter's vitals carry exactly that row, or the encounter commits first and triage is
  refused; never a triage row after the encounter and never two copies.
- Frontend: a new `tests/Frontend/tri-1c-triage-chips.test.mjs` (source checks of the chips, the label and the notes).

## 5. Existing assertions

None expected to change. If the full run finds one, it is listed with file and line and sent to the coordinator for the
owner's approval before it is changed.

## 6. Farhana's review (22 Nov 2026) and what changed

- Partial retakes: owner choice 4 (latest of each reading); a test with a temperature-only second save.
- Director and other callers unwarned: owner choice 5; tests for the Director (warned, no zone) and another doctor.
- Consultation page: gated on the attending clinician, the page's own viewer.
- Doctor check: the role and permission pair of `ClinicalEncounterPolicy`.
- Trigger: row comparison with `IS DISTINCT FROM`, latest-row source, no SQL time comparison, no set-from-null.
- Contention: a new case (`startcopy` worker mode) races triage against the real copy; the TRI-1a case is untouched.
- Update rules: the source clears in the same save that changes a reading; an unchanged save keeps it; clearing every
  reading deletes the row (tested). A one-reading edit makes all readings the doctor's own observation (intended).
- Privacy: once copied, the readings are encounter vitals and follow encounter vitals everywhere they already go (visit
  history and the review pages for those with access), as design 3.6 intends. `fromTriage` is only on the consultation
  page (`ClinicalEncounterDirectoryService`), not in history or review payloads.
- One chip component: not extracted. A new import in `PatientBoard.vue` breaks the existing mount tests
  (`q-1-waiting-time`, `q-2-treatment-progress`) that allow a fixed list of imports, so the chip stays inline and
  `tri-1c-triage-chips.test.mjs` pins the same words and colours in all three places.
- "Tell the doctor now" (design step 7) already shows on the TRI-1b triage form; TRI-1c adds nothing to it.
- The Registration Triage tab uses its own table, so the "No triage yet" chip never repeats there.

**Known limits:** an open Call next confirm card is not refreshed when a green triage lands meanwhile (the card's
same-patient check compares lock versions, which a green triage does not change), so it can still say "No triage yet";
it is a warning only. The zone on a board follows the board's poll.

## 7. Out of TRI-1c

TRI-2 (emergency lane, all-doctor alert), TRI-3 (standing order), any zone on the TV or QR page, any change to queue
order beyond the existing Urgent rule, a confirm step on Registration's Call In (owner chose no).
