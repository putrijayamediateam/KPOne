# TRI-1 design note: the triage station

Status: **DESIGN NOTE, NOT BUILT, NOT AUTHORISED.** Nothing here is built until the owner approves this note, the
new permission and the open questions in section 9. Synthetic data only.

Owners: Patients & Registration (the triage record and the CA screen), with Queue & TV (queue order, the boards
and Call next). The Lead UI/UX Designer reviews every new or changed screen.

Builds on: `PLANS-VITALS-AND-MC-REVIEW.md` section 1 ("Vitals before the doctor"), which this note replaces for
the vitals part. Source: the 19 Nov 2026 meeting on the patient flow and its review.

## 1. What the meeting asked for

Before the doctor, a Clinic Assistant (CA) takes blood pressure, temperature, pulse, SpO2, weight and height. The
patient gets a zone: red, yellow or green. About ten red flags, from a clinic guideline, put a patient in red.
Very high readings are highlighted. Red-zone patients go to an emergency lane (TRI-2, a separate note). A child
with a very high fever may get paracetamol from the CA under the doctor's standing order.

Owner decisions already given (19 Nov 2026): the triage guideline is signed by **Dr Fatin Adilah**; KPOne calls
no AI service; staging uses synthetic data only. The Medical Assistant (MA) role is a separate decision and note.

## 2. What KPOne has today

- **Vitals** exist only on the Clinical Encounter (`encounter_vital_observations`: systolic and diastolic BP,
  pulse, temperature, SpO2, weight, height). The encounter exists only once the doctor starts the consultation,
  and only the attending resident doctor writes it. The CA has no vitals screen.
- **Priority** is a Visit field, `normal` or `urgent`, set at registration or on visit edit by holders of
  `visits.update.branch` (Director, CA, CA Supervisor), audited as `visit.priority.marked_urgent` and
  `visit.priority.returned_normal`. The Queue board and Call next order Urgent first, then by arrival.
- **Not ready to call** (PR #177) is a separate controlled flag on a Waiting entry. It is unchanged here.
- **The public QR status page** counts "patients ahead" by arrival time only and ignores priority.
- **The TV** shows called numbers and rooms only, never priority.

## 3. The flow

1. The CA registers the visit and sends the patient to Waiting, as today. Registration does not wait for triage.
2. A **Triage** tab on the Registration board lists consultation visits that are Waiting with no triage yet,
   oldest first, with a count. OTC visits never appear.
3. The CA presses **Triage**, enters the readings and ticks any red flag or yellow sign seen, then saves.
4. KPOne **suggests** a zone from the approved guideline (section 5). The CA confirms the suggested zone, or
   chooses another with a reason (section 5.3).
5. The visit shows a zone chip on the Registration board, the doctor's queue and the consultation page.
   - **Green:** normal order.
   - **Yellow:** Urgent order (sets `priority = urgent`).
   - **Red:** Urgent order now, and the emergency lane and all-doctor alert once TRI-2 is built.
6. When the doctor starts the consultation, the encounter vitals form opens **pre-filled** from the latest
   triage, labelled "Taken at triage by <CA> at <time>". The doctor may change them; the encounter keeps its own
   record, and the triage record stays as it was.
7. A child with a very high temperature shows **"Tell the doctor now"** (section 6). KPOne gives no medicine
   instruction in TRI-1.

The patient's number and place in the queue never change because of triage, except that yellow and red follow
the existing Urgent rule.

## 4. Data

### 4.1 `visit_triage_assessments` (new, append-only)

One row per save; a correction is a new row and the latest row counts. Columns:

- Organisation, branch and visit.
- The six readings, with the same types and checks as the encounter table.
- The red flag and yellow sign codes ticked (a small array of codes, never free text).
- The guideline version used, the suggested zone, the chosen zone, and an override reason code when they differ.
- Who recorded it and when (server time), an idempotency key and a payload hash.

PostgreSQL refuses update, delete and truncate. An insert trigger checks that the visit is a consultation, in
the same branch and organisation, and that its queue entry is Waiting.

### 4.2 `triage_guideline_versions` (new, versioned settings)

KPOne ships **no clinical list**, as with drug groups and leave types. A Director enters:

- the red flags (code, staff wording, up to 12) and yellow signs (up to 12);
- the "very high" and "very low" thresholds for each reading, for adults and for children, with the child age
  limit;
- the approving doctor's name (Dr Fatin Adilah) and the date of her signature.

Each save is a new version, starting as Draft. A version counts only once a second Director, or a Director other
than the person who entered it, marks it **Approved**, confirming that the signed paper guideline matches. The
earlier approved version stays in force until then. Until any version is approved, triage records readings only
and suggests no zone.

### 4.3 The Visit

Choosing yellow or red sets `priority = urgent` through `VisitAdministrationService`, so the existing audit, queue
order and Call next rule apply. Green on a visit already marked Urgent by hand does not lower it; only a person
does that, as today.

## 5. How the zone is suggested

### 5.1 Rules (no AI, no scoring)

- Any red flag ticked, or any reading past a "very high" or "very low" threshold marked red in the guideline: **red**.
- Otherwise any yellow sign ticked, or a reading past a threshold marked yellow: **yellow**.
- Otherwise: **green**.
- A reading left blank never counts as normal; the screen shows which readings are missing.

### 5.2 Highlighting

A reading past a threshold is shown in bold with the word "Very high" or "Very low", never by colour alone, on the
triage form and wherever the triage readings appear.

### 5.3 Choosing another zone

- **Higher than suggested:** always allowed, with an optional reason code.
- **Lower than suggested:** needs a fixed reason code (for example "Reading repeated and normal", or "Doctor
  reviewed at triage"). The record keeps both zones. Recommended: a CA may lower only after a second reading.

## 6. Child fever and the standing order

TRI-1 shows **"Tell the doctor now"** and nothing more. A CA giving paracetamol before the doctor needs a written
standing order from a named doctor, the clinic SOP, training records and a check that it is within a CA's scope.
That is a separate slice (TRI-3), not designed here, and only after the owner supplies those documents.

## 7. Access

- **New permission `visits.triage.record.branch`** for `ca` and `ca_supervisor` only: record triage and see the
  triage readings on the board. This is a clinical write for the CA, so `AGENTS.md` requires the owner's explicit
  role decision. The MA joins only if the MA role is approved.
- **Doctor:** sees triage readings for their own patients through the consultation (`encounters.view.own`, existing).
- **Guideline settings:** a new permission, `triage.guideline.manage.organisation`, for the Director only. A
  Director approves a version entered by someone else.
- **None for** Administrator, Technical Admin, Finance Officer, Panel Officer, HR, Marketing, Business Development
  or the TV account.
- **The TV and the public QR page show no zone and no readings.**

## 8. Rules for the build

- **Domain:** `TriageService` in `app/Domain/Clinical`, with a fresh permission and branch check under lock,
  `DB::transaction`, the visit and queue entry row locks in the `ConsultationLocks` order, `lock_version` on the
  visit, an idempotency key, and audit `visit.triage.recorded` with ids, the zone and codes only, never readings.
- **Corrections:** a CA may add a new triage row until the doctor starts the consultation; after that, triage is
  read only and the doctor changes the encounter vitals.
- **Races (serial Contention suite):**
  - two CAs triaging one visit;
  - triage against the doctor starting the consultation (the pre-fill copies exactly one row);
  - triage against Call In;
  - two Directors approving one guideline version.
- **Screens:** staff screens in English, Classic and the new layout from the same props, usable at 390 px, dark
  mode checked.

## 9. Open owner questions

1. Approve `visits.triage.record.branch` for CA and CA Supervisor, and `triage.guideline.manage.organisation`
   for the Director?
2. Does yellow join the Urgent order (recommended), or only show a chip?
3. May a CA choose a lower zone than suggested? Recommended: only with a reason code after a repeated reading.
4. Which readings are required before saving? Recommended: none required, but the missing ones are named.
5. Should Call In warn when a patient has no triage yet? Recommended: warn only, never block.
6. The QR page's "patients ahead" ignores Urgent today. Keep it (simpler, slightly optimistic for some patients),
   or count Urgent first (accurate, but it hints that someone is urgent)? Recommended: keep it.
7. Who enters the guideline into KPOne, and who confirms that it matches Dr Fatin Adilah's signed copy?

## 10. Slices

- **TRI-1a:** guideline versions, triage table, permissions, `TriageService` and tests.
- **TRI-1b:** the Triage tab and form on the Registration board.
- **TRI-1c:** zone chips on the boards and the consultation page, and the encounter pre-fill.
- **TRI-2 (separate note):** the emergency lane and the all-doctor alert.
- **TRI-3 (separate note, needs the SOP):** the standing order.
