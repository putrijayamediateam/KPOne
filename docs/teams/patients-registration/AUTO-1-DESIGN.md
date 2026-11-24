# AUTO-1: automatic doctor assignment at registration (design note, nothing built)

Status: DESIGN ONLY. Not authorised to build until the owner types a ship-it. Owner decisions are from B.E.R.N.I.S.S.'s
relay of 2026-11-24. Purpose (owner): shorter waiting times and helping doctors reach their sales targets.

## 1. Today

- The CA picks the doctor at Register Visit and Quick create (`assigned_doctor_user_id` on the Visit). QR intake: the CA
  picks it on the review page. The queue entry has no doctor column.
- Choices come from `VisitDoctorEligibilityService::eligibleDoctors`; `QueueEntryService::enter` re-checks.
- `RegisterAndQueueService::register` runs register + send to waiting in one transaction, locks
  `ConsultationLocks::users([actor, doctor])` first, and uses one idempotency key for the whole step.
- Existing rules that stay: `ConsultationLocks::doctorBusy` (Serving and not held, organisation-wide), held cap 3, doctor
  room per day, not-ready flag.
- BIL-30 already exists: `sales_targets` (monthly, per branch, optional per doctor), `SalesTargetService::progress()`,
  permissions `sales_targets.manage.organisation` (Director, Finance Officer) and `sales_targets.view.own` (doctor).

## 2. Owner rules (as decided)

1. On duty: from the HR roster (doctor's room, CA station). Needs HR's survey (Liyana) before build.
2. Free: not Serving (today's busy rule). A doctor with a held patient is free only if nobody is waiting for them.
3. Among free doctors: lowest target progress % first, with month-to-date patient count weighed in.
4. Patients go only to free doctors, never stacked behind a busy low-sales doctor.
5. When all doctors are busy: the doctor with the lowest sales gets the patient even though busy.
6. Sales: finalised net invoices this calendar month at the registering branch. Today's open visits count as load.
7. No target: included, ranked after doctors with targets.
8. Residents first; a locum only when no resident is free, and only once locums can consult in KPOne.
9. Follow-up: offer the previous doctor first, never force; auto-assign if not free.
10. Named doctor: CA picks from a Recommended list (free, number waiting); an override needs a reason code, audited.
11. Assigned doctor becomes busy or leaves: keep the assignment, warn the CA, the CA reassigns.
12. CA sees the name and a neutral reason ("Auto: next in turn"); never sales or targets.
13. Switch: Director, per branch, off by default.

## 3. Proposed design

**Service.** New `DoctorAssignmentService::recommend(branch, actorContext, patient)` returns an ordered list with
`free`, `waiting`, `reason_code` per doctor. It is pure ordering over data read inside the caller's transaction.

**Ordering (rules 2-8).** Candidates = on-duty doctors with an account who are eligible today and have a room (once the
branch uses rooms). Tiers: (a) free residents, (b) free locums, (c) all busy, lowest sales first. Within a free tier:
progress % ascending (no target last), then month-to-date sales per patient ascending, then fewer waiting, then user id
(deterministic). The "weigh patient count" formula is the one open owner choice (Q-A below).

**Race safety.** The pick happens inside `RegisterAndQueueService`'s one transaction, after locking all candidate doctors
in ascending id (`ConsultationLocks::users` already supports a list), so two simultaneous registrations serialise and the
second sees the first's new waiting entry. Authorisation and the switch are re-read under that lock. The idempotency key
replay returns the same Visit and the same doctor, never a re-pick.

**Audit.** `visit.created` gains `doctor_source` (`manual`, `auto`, `override`) and `doctor_reason_code`
(`next_in_turn`, `follow_up_previous`, `all_busy_lowest_sales`, `no_free_doctor`, `ca_override_*`). No sales figures, no
targets, no progress numbers, never. A separate `visit.doctor_reassigned` row already exists for edits.

**CA screen.** The doctor field defaults to "Auto". The Recommended list shows name, Free/Busy, patients waiting. After
save the toast and board row show the doctor and the neutral reason. When an assigned doctor later becomes busy or
leaves duty, the row shows a warning chip; reassigning uses the existing edit-doctor path (keeps place).

**QR.** Same service at acceptance (CA review page shows the recommendation preselected, editable).

**Permissions.** Assigning needs nothing new. The service reads sales and targets as the system on behalf of the CA, so
the CA does not need `sales_targets.*`, and nothing about sales is returned to the browser. Proposed: no new permission.
Setting the per-branch switch needs a new `registration.auto_assign.manage.branch` (Director). Needs owner approval; any
migration granting to `ca` must also grant to `medical_assistant`.

**Data.** One additive migration: per-branch setting row (off by default, lock version, audit), and the two audit fields
(no column; they live in the audit metadata). No change to visits or queue entries.

## 4. Existing assertions that would change

- Registration validation currently requires a doctor for a consultation (`PublicIntakeReviewService` line ~347 message
  "Choose the doctor ..."; `StoreVisitRequest` rules). With the switch off these stay exactly as they are; with it on,
  blank means Auto. Tests pinning the required-doctor message must keep passing with the switch off (no change).
- `RegisterAndQueueSendToWaitingTest` and the edit-race tests are unchanged; new tests add the auto path.
- Frontend tests pinning the doctor select on Register Visit and Quick create would gain an "Auto" option only when the
  branch switch is on; default rendering unchanged. Expect to change none, to be confirmed against the code at build.

## 5. Tests planned

Ordering matrix (free/held/busy/no target/locum/follow-up), all-busy lowest-sales rule, two-process race (two
simultaneous registrations pick different doctors when two are free), idempotent replay, switch off = today's behaviour,
audit has no sales values, CA payload has no sales or target keys, QR acceptance path, roster off-duty doctor never picked.

## 6. Remaining questions for the owner (recommended option marked *)

Q-A How to weigh patient count with progress %?
 [*] Rank by progress %; ties and near-ties (within 5 points) go to the lower sales-per-patient doctor
 [ ] Rank by sales per patient only
 [ ] Weighted score (needs a formula from the owner)
Q-B A doctor not on the roster today but eligible: [*] never auto-picked (CA can still choose manually) [ ] allowed.
Q-C All doctors busy, lowest sales is also the one with the longest queue: [*] cap at 3 waiting for that doctor, then next
 lowest [ ] no cap.
Q-D Follow-up window ("previous doctor"): [*] seen within the last 30 days [ ] 14 days [ ] 90 days.
Q-E Day-of switch off: patients already assigned keep their doctor: [*] yes [ ] no.
Q-F Roster not published for the day: [*] auto-assign is unavailable, CA picks manually, with a notice [ ] fall back to
 eligible doctors.
Q-G New permission `registration.auto_assign.manage.branch` for the Director: [*] approve [ ] reuse an existing one.

Dependencies: HR (roster: on duty, room, locum accounts); Queue (Amirul: busy, held, waiting counts); Billing (Farah:
net sales per doctor, branch scope, existing `SalesTargetService::progress`).

## 7. Update 2026-11-24 (owner answers relayed by B.E.R.N.I.S.S.)

- Locums hold `resident_doctor` as well as `locum`. "Residents first" therefore means doctors without the `locum` role
  come before doctors with it; locums enter the eligible list once HR provisions them (HR slice e).
- On duty comes from the shared `RosterOnDuty` lookup (HR slice b): approved shifts only, minus leave and on-call, with
  the doctor's room. AUTO-1 depends on it; the room check in section 3 reads the room from this lookup.
- Stations (Registration, MA, Dispensary, Treatment) on CA and MA shifts are background only and do not change who gets
  patients.
