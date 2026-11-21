# TRIAL-DATA: fictional demo data for the trial server (design note)

Status: **Phases 1 to 6 built** (branch `feature/trial-data-command`); All phases are built. Owner-approved task (21 Nov 2026, relayed by B.E.R.N.I.S.S and confirmed by
the owner in the DEMO-1 session): demo data that uses every function, every user role and every menu module, built
in phases. Branch `feature/trial-data-command`. No PR until the owner's ship-it.

## 1. Why a command, not a seeder

`KPOneDemoSetupSeeder` (DEMO-1) refuses to run outside `local` and `testing`, on purpose. The trial server runs with
`APP_ENV=production` and holds fictional data only. A separate, deliberately awkward command keeps the refusal in
the seeders intact and makes every run a conscious, confirmed act.

## 2. The command and its locks

`php artisan kpone:trial-data --phase=<n>` (interactive; `--phase=all` runs every built phase in order).

It stops, before any write, unless **all** of these hold:

1. The environment variable `KPONE_TRIAL_DATA_ALLOWED=true` is set (off everywhere by default; never in
   `.env.example` as `true`).
2. The operator types the site's host name, and it equals the host of `config('app.url')`.
3. The terminal is interactive (no `--no-interaction`, no scheduler, no deploy hook). It is never called from a
   seeder, `DatabaseSeeder`, a migration or a deploy script.
4. Exactly one active Director exists. The command acts as that Director for Director-only steps (it never creates
   or changes a Director). The Director must have a current assignment at every branch; if not, the command
   stops, names the branches, and tells the operator to run `php artisan kpone:director-branches` first.
5. Every existing user other than that Director has an email ending in `.test`. Any other address means the database
   may hold real staff, so the command stops and names how many such accounts exist, never which.
6. Every account it creates uses `@kpjaya.test`.

It asks once, hidden, for **one shared password** for all test accounts, twice for confirmation. The value goes
straight to `StaffProvisioningService` (which hashes it through the `hashed` cast) and is held in memory only for the
run. It is never printed, logged, audited, written to a file or passed on a command line. A test proves it appears in
no output, log line or audit row.

## 3. How data is created

- **Only through the existing domain services**, acting as the named fictional account each role would use (the
  DEMO-1 `actAs` pattern: reset the branch context, then call the service). So permissions, branch scope, row locks,
  `lock_version`, idempotency keys and audit records all apply, and every record is in a state the real screens can
  produce. No direct inserts.
- **Idempotent:** each step first looks for its own result, keyed on a fictional code, email or a scenario patient
  (for example "Trial Patient 07 — Panel, paid"). If that scenario patient already has its visit, the scenario is
  skipped. A second run of any phase adds nothing (tested by comparing every table's row count before and after).
- **Audit:** the services write their own audit rows with the fictional actors. The command adds one row per phase,
  `trial_data.phase_completed`, with no actor (system step), the phase number and counts or ids only.
- **No new permission, role or route.** Code lives under `app/Domain/Shared/TrialData/` (one class per phase) and
  `app/Console/Commands/TrialData.php`. DEMO-1 and the inventory-walk seeder are not changed; their steps are
  re-implemented in the same style so their tests stay untouched.
- **Branches:** all three branches get full data (owner, 21 Nov). CHERAS is the main branch, from which the
  organisation-wide set-up (catalogue, prices, Panels, payment methods) is done.

## 4. Phases

Each phase is a separate PR-sized slice with its own tests, run in order. Later phases need the earlier ones.

### Phase 1 — foundation (built)

All three branches get full data (owner, 21 Nov).

- **Staff (28 accounts):** at each branch its own two Resident Doctors, two Clinic Assistants, two CA Supervisors and
  the waiting-room TV account; once for the organisation, a Panel Officer, Finance Officer, Administrator, HR
  Manager, Business Development, Marketing and Technical Admin, each assigned to all three branches with CHERAS as
  primary. All through `StaffProvisioningService`, acting as the Director.
- **Organisation set-up:** payment methods (Cash, Card, e-wallet, published, Cash classified Cash), visit reasons,
  preset diagnoses.
- **Each branch:** its own letterhead (Business details), the agreed footer, approval limits for its first CA
  Supervisor, consultation, dispensary and treatment rooms, verified credentials for its two doctors (obviously
  fictional twelve-digit registration numbers). The organisation logo is the Klinik Putrijaya mark, when GD is
  available.
- **Catalogue and prices:** 12 generic medicines (one controlled), 6 services, the consultation tariff; Self-pay prices, and default
  Panel prices proposed by the Panel Officer and approved by the Finance Officer (owner decision 27: no direct Panel price publishing); two fictional Panels with a claim message template.
- **Stock:** two suppliers; at each branch a dispensary and a store, openings with real future expiry dates (one batch
  expires in two months, for the expiry views), recorded by the branch's first CA Supervisor and signed off by its
  second.
- **Patients:** 30 fictional patients, 10 registered at each branch, including a minor with a guardian and an adult
  with an unknown date of birth and the adult confirmation. Allergies are recorded in Phase 2, where a consultation
  makes that possible.
- **Not in Phase 1:** TV posters and other placeholder files (see section 5, item 2), which start with Phase 2 along
  with a shared placeholder generator.

Menus that come alive: Staff, Branches, Access, Business details, Payment Methods, Approval Limits, Medicine and
Service catalogues, Pricing, Checkout settings, Suppliers, Medicine details, Registration and Consultation settings,
Queue Display settings, Clinician credentials, Patient Records, Inventory (stock, readiness).

### Phase 2 — a clinic day (built)

**Built:** at every branch, two TV posters (generated placeholder images) and ten scenario visits, one per patient:
(1) completed, cash, a discount by the CA Supervisor, MC, referral and attendance letter, an allergy recorded;
(2) completed, card receipt reversed by Finance and refunded (recorded by a CA Supervisor, approved by Finance), then
paid in cash; (3) completed Panel visit, first invoice voided by Finance and reissued, Panel share approved;
(4) completed, part pay later (approved), part cash; (5) completed OTC visit with a B number, called to the
dispensary; (6) handed off and waiting at the Dispensary, called to the counter; (7) returned to the doctor and held;
(8) held with a nebuliser step done; (9) waiting, triaged, not ready to call; (10) urgent, triaged, being seen and
called to the treatment room. Day end: the card terminal close and the cash count (left waiting for approval,
because approval needs the day to have ended). Triage has no zone until the guideline is approved in phase 6.
**Not yet in phase 2:** a QR intake (only when public intake is switched on; left for phase 6 with the QR link),
receipt and invoice prints (issued copies), and the department-space checks; they need no extra data.

Design as reviewed:

Scenario visits through the real flow (register → queue → consultation → dispensary → billing → complete), ending in
different states: waiting, not-ready-to-call, serving, held, waiting at dispensary, returned to doctor, treatment-room
step, triage zones; paid by cash and by card, Panel approved, pay later, with an adjustment, partly refunded,
invoice voided and reissued, receipt corrected; an OTC (medicine only) visit; one QR intake waiting for review;
the day's terminal reconciliation and cash closing. MC, referral and attendance confirmation are issued on
consultations while the patient is being served (their wording stays marked DRAFT).

Menus: Registration, Consultation, QR Intake, Dispensary and Returns to check, Patient Billing, Finance / Billing,
Bills to collect, Terminal Reconciliation, Daily cash closing, Panel Responsibility, documents, TV screen, all
Reports and Insights (for today), department space homes.

### Phase 3 — Panel (built)

**Built:** at every branch, eleven fictional Panel patients (registered by the branch's first CA), each with one
completed Panel visit seen by the branch's second doctor, the whole invoice approved for the Panel. Claims, by the
Panel Officer: (1) prepared; (2) submitted, with a Panel service charge adjustment requested by the Panel Officer and
confirmed by Finance; (3) queried; (4) accepted; (5) partly accepted; (6) rejected; (7) partly paid; (8) paid by a
Panel remittance recorded by Finance; (9) paid, then the payment reversed by Finance as recorded twice; (10, 11) a bulk
claim on Panel B. Two default Panel price changes from tomorrow, proposed by the Panel Officer: one approved by
Finance, one waiting. A claim already created for an approval (PNL-28a's automatic claims, when switched on) is
reused, never duplicated.

Design as reviewed:

Claims in every stage (prepared, submitted, queried, accepted, partly accepted, rejected, partly paid, paid), a
bulk claim, a claim adjustment, a remittance, a payment correction and a Panel price change proposal (one approved,
one waiting).

Menus: Panel Claims, Claims follow-up, Panel payments, Correct a Panel payment, Panel price changes, Panel
invoices, Panel summary report.

### Phase 4 — inventory workflows (built)

**Built, at every branch:** a purchase request (CA) converted to an order (CA Supervisor), approved by Finance, received
in part with actual costs, short-closed by the Director and its supplier invoice matched; a second order received with
five damaged boxes quarantined (review requested by the receiver, approved by the other CA Supervisor, then posted; the
batch is held automatically) whose invoice differs and waits for Finance; those boxes returned to the supplier
(approved, dispatched, credit note); a hold placed and released by a different person; a governed count counted and
recounted by the two CA Supervisors and approved by Finance; a damage write-off and an expiry write-off of a fictional
batch that expired the day before; a reorder level and the low-stock, expiring and expired lists acknowledged;
treatment-room usage and waste; the controlled register checked; a medicine return from phase 2 quarantined into a new
patient-returns location; a cycle count started. **Once:** supplier price terms, a PUCHONG to CHERAS transfer with two
boxes short (resolved as arriving later) and a supplier recall of one CHERAS receipt batch started by the Director.
Receipts carry actual costs, so stock value and the journal summary have costed stock.

Design as reviewed:

Purchase request → PO → approval → partial receipt; a delivery exception with independent approval; supplier invoice
(matched and waiting); a supplier return; a stock request from PUCHONG with a short receipt; a governed count with a
recount and an approved write-off; a batch hold placed and released; a recall started; a cycle count; stock review
acknowledgements; treatment usage; the controlled register.

Menus: every Inventory sub-page, including reorder suggestions, replenishment, stock health, stock value and journal,
and the stock movement report.

### Phase 5 — HR (built)

**Built:** a staff file, employment period and position for every fictional account (the HR Manager's opened by the
Director; none for the Director or the TV screens); bank details confirmed for two people and one waiting for a second
check; a contract PDF and an onboarding checklist; KPOne's default leave types and entitlements, working days, a
holiday, the grant, and leave pending, approved, rejected and cancelled; at each branch minimum staffing, next week's
roster approved by the Director and the following week in draft; attendance and overtime settings from today, the first
CA at each branch clocked in now (placeholder PNG photo), a session added by an approved correction with overtime
approved, and a correction waiting; a credential accepted and one waiting; an SOP and a memo read and acknowledged; a
confirmation letter issued and recorded as given; an appraisal signed off; an incident with an action; a discipline
case closed with a verbal warning; an open shift; a locum file with a confirmed rate; staff claims paid, approved (then
paid by payroll) and waiting; two job positions; and payroll for last month: four FICTIONAL statutory tables (names
start "FICTIONAL", notes say "FICTIONAL — NOT OFFICIAL RATES — TRIAL ONLY", round rates such as 10% and 12%) entered by
HR and checked by the Director, 19 pay profiles confirmed by the Director, a run prepared and sent by HR and approved
and locked by the Director (19 payslips), and the bank and accountant files. The Director's and HR Manager's own pay
stays outside KPOne. Not filled: open-shift requests and locum pay months (they need a locum sign-in account), job
applications (public form off), and photos on incidents.

Design as reviewed:

Leave types, entitlements, holidays and requests (pending, approved, rejected, cancelled); a roster (one approved
week, one draft); attendance sessions and an overtime request; a correction request; staff files (no uploads), bank
details awaiting a second check, a checklist; credentials (no file); memos (text only); letter templates and an
issued letter; an appraisal cycle; an incident; open shifts; locum pay; staff claims in a category that needs no
receipt (waiting, approved, paid); a discipline case (no files); job positions; payroll set-up and one pay run up to
**draft** (see section 5).

Menus: every HR page except where section 5 says otherwise; the HR dashboard and labour cost fill from the above.

### Phase 6 — the rest (built)

**Built:** clinic compliance (an operating-licence type with a licence at each branch, one due within the 60-day
warning, each with a placeholder PDF scan, and a clinic-wide insurance policy; a vaccine fridge at each branch with an
in-range reading and an out-of-range reading reviewed by the CA Supervisor; a monthly audit at each branch with one
failed item raising a task, done and verified by a second supervisor at CHERAS); text-only English and Bahasa Melayu
website drafts (every branch profile, a doctor profile, a career posting, a service category and service, a promotion,
an activity, an FAQ and a Little Shield draft) and a private release freeze prepared by Marketing and approved by the
Director (nothing is published or deployed); the triage guideline, entered by the HR Manager and approved by the
Director, with the fictional signer name "Dr Trial Signer (fictional)" (only the Director and the HR Manager may manage
the guideline, so the two people are those two, not two doctors); a QR check-in link at every branch issued by its
first CA Supervisor (and one QR intake only when public intake is switched on); and two duplicate registrations, one
merge approved and one waiting. Once the guideline is in force, phase 2's triage records choose the green zone.
**Not filled: agents.** Agents are registered only by `KPOneAgentSeeder`, which refuses to run outside local and
testing, so on a production trial server the agent office, the approval inbox, the daily summary and Dato' Arif stay
empty. Registering them there needs an owner decision. Website images and compliance photos also need the image
extension, so phase 6 uses text and PDFs only.

Design as reviewed:

Clinic compliance (licences, fridge readings, a checklist), website drafts (text only), the triage guideline (entered
and approved by two different fictional doctors), agents (an inventory review that creates proposals; the daily
summary), a QR check-in link.

## 5. What cannot be filled safely, or needs someone else's input

1. **Dates.** The services stamp records with the real clock, and the command will not fake time (audit times would
   be false). Everything is dated the day the phase runs. Month views, ageing buckets (31–90+ days), payroll months,
   carry-forward and "last 14 days" figures stay thin unless the owner runs Phase 2 on several different days. A
   `--phase=day` option can add one more clinic day each run.
2. **File uploads** need real files: clinic logo, website media and release images, TV posters, Panel claim
   documents and statements, HR documents, SOP PDFs, CVs and interview files, leave evidence, compliance and incident
   photos, the opening import file. **Owner decision (21 Nov): generate plain placeholder images and one-page PDFs
   marked "FICTIONAL TRIAL DATA"** so those menus are filled (needs GD on the trial server; a phase stops with a clear
   message if GD is missing).
3. **Clock-in** requires a photo: attendance sessions use generated placeholder photos (item 2).
4. **Payroll statutory tables** (EPF, SOCSO, EIS, PCB/MTD, HRD). **Owner decision (21 Nov):** the command enters
   FICTIONAL tables, entered by one fictional account and checked by a different one, so the pay run goes through to
   payslips and the bank and accountant exports. Every table's name and notes say "FICTIONAL — NOT OFFICIAL RATES —
   TRIAL ONLY", the numbers are obviously round (not lookalikes of real rates), and the existing "To confirm with the
   clinic's accountant" banners stay.
5. **Messages** (email, WhatsApp, SMS, OTP) stay off. Only in-app message settings are shown.
6. **Public forms** (QR check-in submission, the job application form) are off by default. One QR intake is created
   through the public service only if `PUBLIC_PATIENT_INTAKE_ENABLED` is already on; job applications only if
   `HR_APPLICATIONS_ENABLED` is on.
7. **Website releases** can be prepared and approved by the Director as drafts; nothing is published or deployed.
8. **Professional content:** the triage guideline, MC, referral and attendance wording, drug labels and the
   controlled register are fictional examples, not clinical guidance; they remain marked as drafts where the system
   marks them.

## 6. Tests (per phase, on PostgreSQL 18)

**Committed run (added after the first trial-server run, 21 Nov 2026).** Most trial-data tests run inside one
rolled-back test transaction, so PostgreSQL's deferred checks, which fire only at COMMIT (for example
`completed_visit_evidence`), never ran there. On the trial server phase 3 then failed: it completed a Panel visit and,
in the same transaction, changed that visit's invoice through the claim stage. `TrialDataEndToEndTest` now runs
phases 1 to 6 in order on a freshly migrated database with every step really committed and PNL-28a's automatic claims
on. Phase 3 commits each completed visit before its claim stage, and resumes a claim stage left undone. Phase 6
merges duplicates of patients whose phase 2 visit is completed (a merge is refused while either record has an
unfinished visit).


Refused without the flag; refused with the wrong host name; refused non-interactively; refused when a non-`.test`
account (other than the Director) exists; refused without exactly one Director; creates the phase's records once;
a second run changes no table's row count; the password appears in no output, log or audit row; the phase audit
row has no actor and ids or counts only. No existing assertion is expected to change; any that must will be listed
for the owner before it is touched.

## 7. Owner answers (21 Nov 2026, relayed by B.E.R.N.I.S.S)

1. Placeholder files: **generate them**, marked "FICTIONAL TRIAL DATA" (section 5, items 2 and 3).
2. Payroll: **fictional statutory tables**, entered and checked by two different fictional accounts, labelled as not
   official, so the pay run reaches payslips and exports (section 5, item 4).
3. Branches: **full data at all three branches** (CHERAS, SUNGAI_BESI, PUCHONG). The Director must cover every branch
   (`kpone:director-branches`); the command stops with that instruction if not.
4. Director-only steps run as the existing Director, so the audit shows that account: **accepted**.
