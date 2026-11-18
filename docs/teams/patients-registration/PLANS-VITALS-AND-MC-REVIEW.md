# Plans (no build): Vitals before the doctor, and Director MC review

Status: PLAN ONLY. Nothing here is built or authorised until the owner decides.

## 1. Vitals before the doctor

### What exists today
- Vitals live on the Clinical Encounter (`encounter_vital_observations`, one per encounter: BP, pulse,
  temperature, SpO2, weight, height). The encounter exists only after the doctor starts the consultation
  (Serving), and only the attending resident doctor writes it (`encounters.update.own`).
- Nothing is stored on the Visit or the Patient, and the CA has no vitals screen.

### Proposed flow
1. Register visit sends the patient to Waiting (the register-and-queue PR).
2. A new **Vitals** tab on the Registration board lists consultation visits Waiting with no vitals yet
   (count chip, oldest first). A CA presses **Record vitals**, enters the same seven fields, saves.
3. The visit shows a "Vitals done" chip. The patient stays Waiting in the same place in the queue
   (vitals never change the number or order).
4. When the doctor starts the encounter, the encounter vitals form opens **pre-filled** from the visit
   vitals; the doctor may edit them; the encounter keeps its own immutable-by-version record and notes
   "first recorded by <CA> at <time>".

### Design choices to confirm
- **Storage:** a new append-only `visit_vital_observations` table (visit-owned, branch-scoped, with the
  CA, time, `lock_version`, idempotency key, audit with field names only). A correction is a new row
  (latest wins; history kept). It is copied into the encounter observation when the encounter starts; it is
  not shared mutable state. "Saved on the patient record" = the encounter copy becomes part of the existing
  patient clinical history; we do **not** add a separate patient-level vitals table.
- **Does Call next wait for vitals?** Recommended: **no, warn only**. Call In and Call next keep working;
  the Call next dialog and the doctor's queue show "Vitals not done" so the doctor can still call.
  A hard block would stall the room when the CA is busy. Option B (owner): block Call In until vitals are
  done, with the doctor override reason used for the not-ready flag. Needs Amirul's review either way.
- **Not a new queue state:** Waiting stays Waiting. "Vitals done" is a visit attribute, so the TV, QR status,
  queue order and the not-ready flag are untouched. A not-ready flag and vitals are independent.
- **Who records (permission):** a **new narrow permission** is needed, because the CA holds no clinical
  permission today: `visits.vitals.record.branch`, proposed for `ca` and `ca_supervisor` only (not Director,
  Administrator, Technical Admin, Finance, Panel, TV). AGENTS.md requires an explicit role decision for a
  clinical permission, so **this needs the owner's approval**. Viewing the recorded vitals on the visit for
  those roles needs the same permission; the doctor already sees them through the encounter.
- **Edits:** the CA may correct their own vitals until the doctor starts the encounter; after that the
  doctor edits the encounter copy only. A visit cancelled or OTC has no vitals tab entry.
- **Domain rules:** `VisitVitalsService` in `app/Domain/Clinical` or `Visit`; fresh permission and branch
  check under lock, `DB::transaction`, visit row lock, `lock_version`, idempotency key, audit; lock order
  through `ConsultationLocks` (doctor users first, then patient, visit, queue entry). Plausible-range
  checks as warnings, not blocks.
- **Race tests (serial Contention suite):** two CAs saving vitals for one visit; vitals save vs doctor
  starting the encounter (copy exactly once); vitals vs Call In.
- **Wording:** staff screen, English. Pilot data synthetic.

### Slices
V1 backend (table, permission migration, service, tests); V2 encounter pre-fill; V3 Vitals tab and record
dialog (V2 page pattern, 390 px, dark mode); V4 doctor view "Vitals taken by CA". Amirul reviews the queue
side; Daniel reviews the tab.

### Open owner questions
1. Approve `visits.vitals.record.branch` for CA and CA Supervisor?
2. Call next / Call In: warn only (recommended) or block until vitals are done?
3. May a CA record vitals for an OTC patient (for example blood pressure)? Recommended: not in v1.
4. Which fields are mandatory, if any? Recommended: none.

## 2. Director review and approval of Medical Certificates

### What exists today (DOC-02b)
The attending resident doctor issues an MC while the consultation is Serving, not held, before checkout.
The MC gets its number at issue, an encrypted immutable pre-signature snapshot, replace and void by the
original issuer only, no charge. The wording is DRAFT (`production_approved` false). This is a clinical
document rule, so **the owner chooses the option**.

### Option A: approval before issue (hold, then release)
The doctor **requests** an MC; it is saved as a pending request (no MC number, nothing printable). The
Director approves or returns it with a reason; only on approval does the number get allocated and the copy
become printable. Pros: nothing leaves the clinic without review. Cons: the patient waits for the Director
at checkout; if the Director is absent the consultation stalls (needs a deputy rule); the doctor can no
longer hand a signed MC at the bedside; the checkout gate and the "before checkout" rule must change.

### Option B: review after issue (recommended)
The doctor issues as today. The Director gets a **Review** list of issued MCs (branch, doctor, date,
days, number; no diagnosis). The Director marks each **Reviewed** or **Flagged** with a reason. A flagged
MC asks the original doctor to replace or void it (the existing replace and void flow, unchanged).
Pros: no stall, no change to issue, void and replace already exist, evidence is append-only.
Cons: a wrong MC can already be in the patient's hand before review.

### Option C: threshold hybrid
Issue as today for short MCs; MCs above N days or with an optional printed reason require Option A.
Needs the owner to set N.

### Design for A or B (either way)
- New permission(s) need owner approval: `medical_certificates.review.organisation` for Director only
  (Option B), or `medical_certificates.approve.organisation` (Option A). A doctor can never review their
  own MC; the Director who is also the attending doctor needs another Director or is refused (domain and
  PostgreSQL).
- Append-only `medical_certificate_reviews` (MC id, version, decision, reason code, reviewer, time), PostgreSQL
  refuses update, delete, truncate and a reviewer equal to the issuer. Audit holds ids and codes only.
- The Director sees only what the existing view permissions allow for MC content: Option B shows the
  rendered copy (patient name and dates, no diagnosis unless the doctor printed the optional reason with
  consent). A Director holding MC content access is a clinical-content decision under AGENTS.md, so the
  review screen may show metadata only (number, doctor, dates, days) unless the owner says otherwise.
- Copies print "Reviewed" status only in the evidence footer if the owner wants it; the wording stays DRAFT
  until a named doctor approves it.

### Open owner questions
1. Option A, B or C? (Recommended: B.)
2. May the Director see the MC content (patient name, dates, optional reason) or metadata only?
3. If A or C, who approves when the Director is away?
4. Should the printed copy show "Reviewed by Director"?
