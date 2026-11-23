# MA role design note: the Medical Assistant

Status: **DESIGN NOTE, NOT BUILT, NOT AUTHORISED.** The owner has decided what an MA does (section 1). Nothing is
built until the owner answers section 9, approves the new role and permissions, and says to start MA-1a.
Synthetic data only.

Owners: Patients & Registration (the role, provisioning tests and the treatment-room record), with Queue & TV
(the treatment-room call and the Q-2 steps) and Staff Experience (the menus). The Lead UI/UX Designer reviews
every new or changed screen.

Source: the 19 Nov 2026 meeting on the patient flow, On hold routes A1 (the MA completes the treatment and the
patient goes to Billing), A2 (the doctor adds to the plan), B (an in-clinic result, with a priority call-back
and the same number) and C (an outside lab, with a follow-up ticket).

## 1. What the owner decided

An MA has **the same functions as a CA, plus running the treatment room**. On a treatment-room page the MA
records the procedure or sample, and whether it was **done** or **not successful, with a reason**. The doctor
sees that record later in the visit, both when the patient settles after treatment and when the patient is called
back in to see the doctor.

Owner decisions already given (19 Nov 2026): KPOne calls no AI service; staging uses synthetic data only.

## 2. What KPOne has today

- **The CA role** (`app/Domain/Access/PermissionCatalogue.php`, plus the grants classes it merges) holds 70
  effective permissions (section 3.1). Today it cannot call a patient to a treatment room and cannot write
  anything clinical except the OTC allergy record (`allergies.record_otc.branch`, DSP-5); TRI-1, once built, adds
  triage (`visits.triage.record.branch`).
- **Q-2 treatment progress** (`docs/teams/queue-tv/Q-2-DESIGN.md`, PR #256, Q-2c PR #316): CA, CA Supervisor
  (`queue.treatment_progress.set.branch`) and the attending doctor (`queue.treatment_progress.set.own`) mark one of
  eight fixed steps (Nebuliser, Wound dressing, Injection and observation, IV drip, Observation after medicine,
  ECG, Blood test (point-of-care), Other procedure) as started and done, or cancel one entered in error. The
  doctor gets a "treatment done" pop-up. **By design it is a board note, "not a clinical record"**: it has no
  outcome and no reason other than the three error codes, it is not shown in visit history, and every open step
  closes itself when the queue entry leaves Serving (which happens when the doctor completes the consultation).
- **The treatment-room call** (TV-2, PR #60) announces a patient being served to an active treatment room on the
  TV. The route `queue.treatment-call` needs `queue.call.own` (the attending doctor) or `queue.call.branch` (CA
  Supervisor, Director), and `RoomCallService::callToTreatment` repeats that check under lock. **It refuses a
  held patient** ("Only a patient being served now can be called…"), although Q-2 says a doctor often holds a
  patient while a treatment runs. **`queue.call.branch` also allows Call In, Call next and Call
  Again for any doctor**, so it is not a treatment-room permission.
- **Treatment-room usage** (STK-8, PR #244): CA and CA Supervisor already record stock used or wasted in
  treatment (`inventory.usage.record.branch`), with no patient link and no bill.
- **There is no treatment-room page.** Billing of services still comes from the lines the CA confirms at
  Dispensary (DS-01a-services), unchanged.
- **Staff provisioning** (`AGENTS.md`, `StaffAuthorityService`): accounts are created only through
  `StaffProvisioningService`. `canAssignRoles` lets an actor grant a role only if they hold every permission in
  it, except the Director, who may assign any role. `canManage` compares only the people-and-access authority
  permissions.

## 3. The role

Role code `medical_assistant`, shown as **"Medical Assistant"**. A new role, not a CA with extra direct
permissions, so that audit, roster and reports can tell the two apart.

### 3.1 The CA's permissions (all kept, unchanged)

Built in the catalogue as "everything the CA has, then the additions" (section 3.3), so the two cannot drift.
On the current branch the CA's effective list is:

- **Own and branch basics:** `dashboard.view.own`, `profile.view.own`, `staff.view.own`, `staff.view.branch`,
  `branches.view.branch`, `branch_context.switch.branch`, `insights.view.organisation`.
- **Patients:** `patients.search.organisation`, `patients.view.organisation`, `patients.create.organisation`,
  `patients.update.organisation`, `patients.merge.propose.organisation`.
- **Visits and queue:** `visits.view.branch`, `visits.history.view.branch`, `visits.create.branch`,
  `visits.update.branch`, `visits.cancel.branch`, `visits.complete.branch`, `queue.view.branch`,
  `queue.enter.branch`, `queue.call_hold.set.branch`, `queue.call_hold.clear.branch`,
  `queue.treatment_progress.set.branch`, `public_intakes.review.branch`.
- **Dispensary:** `dispensary.view.branch`, `dispensary.start.branch`, `dispensary.update.branch`,
  `dispensary.complete.branch`, `dispensary.return_to_doctor.branch`, `dispensary.otc.create.branch`,
  `dispensary.labels.print.branch`, `allergies.record_otc.branch`.
- **Billing and payments:** `billing.view.branch`, `billing.build.branch`, `billing.finalize.branch`,
  `billing.print.branch`, `billing.adjustments.add.branch`, `billing.bill_to.view.branch`,
  `billing.bill_to.manage.branch`, `billing.notes.view.branch`, `billing.notes.add.branch`,
  `coverage.propose.branch`, `coverage.approve.branch`, `outstanding.view.branch`, `outstanding.request.branch`,
  `payments.add.branch`, `payments.reconcile.branch`.
- **Documents (view and print issued copies only):** `medical_certificates.view.branch`,
  `medical_certificates.print.branch`, `attendance_documents.view.branch`, `attendance_documents.print.branch`.
- **Inventory:** `inventory.view.branch`, `inventory.transfer.branch`, `inventory.stock_requests.create.branch`,
  `inventory.transfers.receive.branch`, `inventory.usage.record.branch`,
  `inventory.purchase_requests.create.branch`.
- **Clinic compliance:** `compliance.fridge.record.branch`.
- **KPOne HR (own only):** `hr.appraisal.view.own`, `hr.attendance.clock.own`, `hr.claims.submit.own`,
  `hr.credentials.view.own`, `hr.incidents.report.own`, `hr.leave.request.own`, `hr.memos.read.own`,
  `hr.messages.receive.own`, `hr.payslips.view.own`, `hr.records.view.own`, `hr.roster.view.own`,
  `hr.sops.acknowledge.own`.

The builder re-reads this list from the catalogue at build time; any CA permission added after this note is
inherited automatically (section 7).

### 3.2 From TRI-1, if approved

- `visits.triage.record.branch`: TRI-1 section 7 says "The MA joins only if the MA role is approved". Recommended:
  yes (owner question 2).

### 3.3 New for the treatment room

- **`treatment_room.record.branch`** (new): record treatment-room entries (section 4) at the MA's effective
  branch. A clinical write, so it needs the owner's explicit role decision (`AGENTS.md`). Holders: `medical_assistant`
  only in v1 (owner question 3 asks about the CA Supervisor).
- **`queue.treatment_call.branch`** (new): call a patient who is being served to an active treatment room on the TV
  (TV-2), and nothing else. Both the route middleware of `queue.treatment-call` and the domain check under lock
  in `RoomCallService::callToTreatment` accept it beside `queue.call.own` and `queue.call.branch`; a test proves it
  never reaches `QueueEntryService::call`, `recall` or `CallNextService`. **The MA does not get
  `queue.call.branch`**, so the MA can never Call In, Call next or Call Again a patient to a doctor. Recommended
  holders: `medical_assistant` and `ca_supervisor` (who already has the wider `queue.call.branch`, so nothing
  changes for them).
  - **Held patients (owner question 10):** today the call refuses a held patient, which is the usual case while a
    treatment runs. Recommended: under `queue.treatment_call.branch` only, allow the call for a held patient too
    (the hold and the consultation are unchanged; the call only announces), with its own race test against
    Resume. Until the owner decides, the page shows "Called to the treatment room" only for patients not held,
    and the MA fetches a held patient in person.
- **`treatment_room.view.branch`** (new): read the treatment-room records at the branch (the page, the board
  chip's outcome and reason, and the visit-history section) for `ca`, `ca_supervisor` and `medical_assistant`.
  The doctor reads them through the existing encounter permissions (section 5). The Director holds
  `queue.view.branch` and `visits.history.view.branch` but not this permission, so the Director's board chip
  shows only the Q-2 step ("Nebuliser: done") and no outcome or reason (owner question 7).

### 3.4 Places that check the role name, not a permission

Copying the CA's permissions is not enough: some code checks the role `ca` by name, so an MA would get 404 or 403
there. Each site gains `medical_assistant` (or moves to a permission check) in MA-1a, with a test that an MA can
complete the whole CA flow, Dispensary included:

- `app/Domain/Clinical/Dispensary/Services/DispensaryAuthorityService.php` (every Dispensary write) and
  `DispensaryDirectoryService.php` (the case list, search and case page): `hasAnyRole(['ca', 'ca_supervisor'])`.
- `app/Domain/DepartmentSpace/PharmacySpace.php`, `app/Domain/Visit/Services/VisitDirectoryService.php` (the "Open
  Dispensary" hint) and `app/Domain/Access/WorkspaceLandingService.php` (landing on Registration after sign-in).
- KPOne HR: `RosterPermissions::HUMAN_ROLES` (11 roles; the other HR grants classes use it), `RosterRules::ROLE_LABELS`
  (memo and SOP audiences refuse a role that is not in it), `RosterRules::STAFFING_ROLES`, `OpenShiftService::ROLES`
  and the overtime role options (`OvertimePageService`).

The builder searches again for `hasRole(`/`hasAnyRole(` naming `ca` at build time, since this list is a snapshot.

### 3.5 What the MA never gets

No `treatment_plans.*` (no prescribing or ordering), no `encounters.*` (no clinical note, vitals on the encounter
or diagnosis), no `allergies.update.own` or `problems.*`, no `consultations.*` (no hold, resume or complete), no
`services.confirm.own`, no `queue.call.own` or `queue.call.branch`, no `queue.call_hold.override.own`, no MC,
referral or attendance issue permission, no staff, access, branch or audit authority, and no pricing or
settings permission.

## 4. What the treatment-room page records

### 4.1 The page

`/treatment-room` for the active branch: "Patients for the treatment room", listing consultation patients whose
queue entry is **Serving (held or not)** and who have a Q-2 step started or a treatment-room call today, oldest
first, with a "Record treatment" button. On a phone, cards. Staff screens in English.

### 4.2 The record: `treatment_room_records` (new, append-only)

One row per procedure or sample. Columns:

- Organisation, branch, visit, queue entry, and the linked Q-2 step (`queue_treatment_steps.id`, nullable).
- **Procedure or sample code**, from a fixed list (owner question 4). Recommended: the eight Q-2 step codes,
  plus three sample codes: `blood_sample_outside_lab`, `urine_sample`, `swab_sample`.
- **Outcome**: `done` or `not_successful`.
- **Reason code**, required when not successful, from a fixed list. Recommended: `patient_declined`,
  `could_not_obtain_sample`, `stopped_patient_reaction`, `equipment_or_stock_unavailable`, `doctor_stopped`,
  `other`.
- Started at and finished at (server time, UTC), who recorded it, an idempotency key and a payload hash.
- **No free text in v1** (owner question 5), and **no result values** (no glucose reading, no ECG reading):
  a result is the doctor's to read and write in the clinical note.

A correction is a new row that **supersedes** the earlier one with a fixed code (`wrong_patient`,
`wrong_procedure`, `wrong_outcome`); nothing is edited or deleted. PostgreSQL refuses update, delete and truncate;
an insert trigger checks that the visit is a consultation whose queue entry is Serving, in the same organisation
and branch, that the reason code is present exactly when the outcome is not successful, and that a superseded row
belongs to the same visit.

### 4.3 How it fits Q-2 (the doctor's pop-up keeps working)

In the same transaction as the record, `TreatmentRoomService` drives the Q-2 step through `TreatmentStepService`:
it starts the step when the MA presses "Start" if none is open, and marks it **done** when the MA saves the
outcome, **whether done or not successful** (the treatment is over either way; "cancel" stays only for a step
entered in error). So the doctor still gets "Treatment done: A-012 Nebuliser. Ready to resume", and the pop-up
adds "Not successful: patient declined" when it applies. Q-2 itself stays a board note; the record is the part
that lasts.

### 4.4 Which route it serves

- **A1 (treatment, then Billing):** the MA records the outcome; the doctor sees it and **completes the
  consultation** as today. The MA never completes a consultation: `consultations.complete.own` stays the
  doctor's (owner question 6).
- **A2 (the doctor adds to the plan):** the doctor reads the record and changes the Treatment Plan; the MA does
  nothing in the plan.
- **B (in-clinic result, call-back with the same number):** the patient stays Serving and held, so the number is
  unchanged. The record says the sample was done; the doctor resumes and reads it. The priority call-back itself
  is a Queue & TV slice, not part of this note.
- **C (outside lab, follow-up ticket):** the MA records `blood_sample_outside_lab` as done. The follow-up ticket
  is a separate slice, not designed here.

## 5. How the doctor sees it

- **On the consultation page**, while the patient is Serving or held: a read-only **"Treatment room"** panel listing
  each current record (procedure, outcome, reason, time, "by <MA>"), newest first, superseded rows shown struck
  through with the correction code. It is there when the doctor resumes after treatment (routes A1, A2) and when
  the patient is called back in (route B). Permission: `encounters.view.own` (existing), attending doctor only.
- **In the visit history** (`VisitHistoryService::detail()`, the Invoices page and the doctor's previous-visit
  view): a **"Treatment room"** section after Services, the same rows, read-only. Permission:
  `encounters.history.view.organisation` for doctors, and `treatment_room.view.branch` for CA, CA Supervisor and
  MA (not `visits.history.view.branch`, which the Director also holds). Owner question 7.
- **CA, CA Supervisor and MA** read the same rows on the visit page and visit history through
  `treatment_room.view.branch` (section 3.3); nobody else does.
- **Not shown** on the TV, the public QR page, prints (invoice, receipt, labels, claim packs, MC, referral,
  attendance), Insights, reports or `ai_read`.

## 6. What it never does

- No prescribing, ordering or plan change; no clinical note, diagnosis or encounter vitals; no medicine given by
  standing order (that is TRI-3, which needs the SOP).
- No bill change: services are still billed from the CA-confirmed Dispensary lines. No stock movement: usage is
  still recorded through STK-8, with no patient link.
- No Call In, Call next or Call Again to a doctor; only the treatment-room announcement.
- No completing, holding or resuming a consultation.
- No record once the queue entry has left Serving (the doctor has completed, which removes the entry as
  `sent_to_billing`, or the visit was cancelled). **Known limit:** a sample taken or a result back after the
  consultation is completed (route C, a late outside-lab sample) cannot be recorded here; that belongs to the
  follow-up ticket slice.

## 7. Provisioning and governance

- **In practice only the Director can assign the MA role.** `canAssignRoles` lets a non-Director grant a role only
  if they hold all of its permissions. The CA Supervisor already holds all 70 CA permissions, so if the owner gives
  it `treatment_room.record.branch` and `queue.treatment_call.branch` (owner question 3), `canAssignRoles` would
  return true for it; it still cannot provision or change anyone's roles, because it holds no
  `staff.manage.organisation` or `access.manage.organisation`. A test pins "only the Director can provision an MA"
  both with and without the owner question 3 grants.
  HR Manager and Technical Admin may still administer an existing MA account's profile and status (`canManage`;
  the role holds no people-or-access authority), but cannot assign or restore the role. This is the AC-01
  asymmetry, and it must not be "fixed" by loosening `canAssignRoles`.
- Accounts are created only through `StaffProvisioningService`; no self-registration; no self-escalation (an MA
  cannot add a role to their own account); exactly one effective primary branch.
- **Migration:** additive, like ADM-01 (`firstOrCreate` the role and the three new permissions, grant only what is
  missing, log and skip a missing role, `down()` a no-op). It also grants the new role every permission the CA
  holds **at migration time**.
- **Drift rule for every team:** a future migration that grants a permission to `ca` must grant it to
  `medical_assistant` too. A pin test fails if the CA holds a permission the MA does not.
- **KPOne HR:** `RosterPermissions::HUMAN_ROLES` gains `medical_assistant` (11 becomes 12; the `locum` role stays
  outside it, as today), so the MA has their own roster, leave, attendance and other own HR pages, and the role
  labels, memo and SOP audiences and overtime role options gain it too (section 3.4). Roster minimum staffing (HR-1) does not count an
  MA as a CA unless the owner says so (owner question 8).
- **Clinic management and menus:** the MA sees the CA's menus plus "Treatment room"; the exact Clinic management
  destination map test gains the new page for this role (an owner-approved existing assertion change at build).
- Audit: role attach and detach as today; `treatment_room.recorded` and `treatment_room.superseded` with ids and
  codes only.

## 8. Rules for the build

- **Domain:** `TreatmentRoomService` in `app/Domain/Clinical/TreatmentRoom/`, with a fresh permission and effective
  branch check under lock, `DB::transaction`, locks in the `ConsultationLocks` order (users, staff profile and
  assignments, visit, queue entry, encounter, then the Q-2 step and the treatment-room rows last, after the
  encounter and before any Dispensary row), `lock_version` on the Q-2 step, an idempotency key, and structural
  audit. `TreatmentStepService` is called inside this transaction, so its own lock order (users, visit, queue
  entry, step) must be checked to match before the build.
- **Races (serial Contention suite):** two MAs recording the same step; an MA saving an outcome against the doctor
  completing the consultation (one wins; a record after completion is refused); an MA saving against the doctor
  cancelling the Q-2 step; a treatment-room call against Hold or Resume (whichever rule the owner picks in
  question 10).
- **Q-2 step already closed:** if the Q-2 step was already done (by a CA or the doctor) the record is saved and the
  step is left as it is; if it was cancelled as entered in error, the record starts a new step. **"Not
  successful" is never hidden:** a test proves it shows in full on the doctor's pop-up, the board chip and the
  consultation panel.
- **Tests:** the exact MA permission list, the CA-subset pin, `canAssignRoles` (Director only), denial for every
  other role, TV and QR payloads unchanged, prints unchanged, visit history and consultation panel shown only to
  the right viewers, screenshots at 1440 and 390 px in light and dark.

## 9. Open owner questions, with recommendations

1. **Approve the role `medical_assistant` and the new permissions** (`treatment_room.record.branch`,
   `queue.treatment_call.branch`, and `treatment_room.view.branch` in question 11). This is the explicit role decision for a clinical write that `AGENTS.md`
   requires.
2. **Does the MA record triage (TRI-1)?** Recommended: yes, the MA joins `visits.triage.record.branch`.
3. **Can a CA Supervisor also record treatment-room entries** when no MA is on shift? Recommended: yes, so a
   branch without an MA is not stuck; the CA does not.
4. **The procedure and sample list:** recommended the eight Q-2 codes plus the three sample codes in section 4.2,
   fixed in code. If the clinic wants its own list, a Director-entered, versioned list (as the TRI-1 guideline)
   is a later slice.
5. **Free text:** recommended none in v1. A note box tends to become a clinical note, which the MA must not write.
   If needed, a short encrypted note (at most 200 characters) only with reason `other`.
6. **Route A1 "MA completes and goes to Billing":** recommended the doctor still completes the consultation after
   reading the record (the MA cannot). If the owner wants the patient to leave without the doctor pressing
   anything, that needs a separate clinical decision and a doctor's standing rule.
7. **Who else reads the record:** recommended doctors, CA, CA Supervisor and MA only; not Finance, Panel,
   Administrator or the Director (the Director has no clinical-content permission today, so the Director's
   board chip shows the Q-2 step without outcome or reason).
8. **Roster:** does an MA count towards the CA minimum on the roster planner? Recommended: no, a separate MA line.
9. **Result values** (for example a point-of-care glucose): recommended not in v1. If wanted, the values and
   their units need the doctor's approval as a clinical design of their own.
10. **Treatment-room call for a held patient:** today the TV-2 call refuses a held patient, though treatment
    usually runs while the consultation is held. Recommended: allow it under `queue.treatment_call.branch` only
    (section 3.3).
11. **Approve `treatment_room.view.branch`** for CA, CA Supervisor and MA (section 3.3).

## 10. Slices

- **MA-1a:** the role, the three permissions, the migration, the catalogue rule, the role-name sites in section
  3.4 and the provisioning tests (no screen).
- **MA-1b:** `treatment_room_records`, `TreatmentRoomService`, the Q-2 link and the treatment-room call permission.
- **MA-1c:** the treatment-room page, the consultation panel and the visit-history section.
