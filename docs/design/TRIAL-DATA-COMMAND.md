# TRIAL-DATA: fictional demo data for the trial server (design note)

Status: **design for review**, not built. Owner-approved task (21 Nov 2026, relayed by B.E.R.N.I.S.S and confirmed by
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
   or changes a Director).
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
- **Branches:** CHERAS is the main demo branch. PUCHONG gets a small second stock so transfers, branch
  replenishment and cross-branch recall have something to show.

## 4. Phases

Each phase is a separate PR-sized slice with its own tests, run in order. Later phases need the earlier ones.

### Phase 1 — foundation (smallest useful set)

- **Staff:** one fictional account per role (12 roles: Director exists; Resident Doctor ×2, CA ×2, CA Supervisor ×2,
  Panel Officer, Finance Officer, Administrator, HR Manager, Business Development, Marketing, Technical Admin, and the
  TV display account), all at CHERAS, through `StaffProvisioningService`. Two of the roles that need independent
  approvals get a second person.
- **Clinic set-up:** Business details and footer, payment methods (Cash, Card, e-wallet; Cash classified Cash),
  billing approval limits, visit reasons, preset diagnoses, registration settings, consultation rooms and the TV
  settings, verified clinician credentials (obviously fictional MMC numbers) for both doctors.
- **Catalogue and prices:** about 12 generic medicines (one controlled), 6 services, the consultation tariff;
  Self-pay and default Panel prices; two fictional Panels with a claim message template.
- **Stock:** dispensary and store locations at CHERAS, a small store at PUCHONG, suppliers, openings with real future
  expiry dates (one batch close to expiry, for the expiry views), verified units and second-person sign-off.
- **Patients:** about 20 fictional patients (adults, a minor with a guardian, one with allergies, one with an
  unknown date of birth and the adult confirmation).

Menus that come alive: Staff, Branches, Access, Business details, Payment Methods, Approval Limits, Medicine and
Service catalogues, Pricing, Checkout settings, Suppliers, Medicine details, Registration and Consultation settings,
Queue Display settings, Clinician credentials, Patient Records, Inventory (stock, readiness).

### Phase 2 — a clinic day

Scenario visits through the real flow (register → queue → consultation → dispensary → billing → complete), ending in
different states: waiting, not-ready-to-call, serving, held, waiting at dispensary, returned to doctor, treatment-room
step, triage zones; paid by cash and by card, Panel approved, pay later, with an adjustment, partly refunded,
invoice voided and reissued, receipt corrected; an OTC (medicine only) visit; one QR intake waiting for review;
the day's terminal reconciliation and cash closing. MC, referral and attendance confirmation are issued on
consultations while the patient is being served (their wording stays marked DRAFT).

Menus: Registration, Consultation, QR Intake, Dispensary and Returns to check, Patient Billing, Finance / Billing,
Bills to collect, Terminal Reconciliation, Daily cash closing, Panel Responsibility, documents, TV screen, all
Reports and Insights (for today), department space homes.

### Phase 3 — Panel

Claims in every stage (prepared, submitted, queried, accepted, partly accepted, rejected, partly paid, paid), a
bulk claim, a claim adjustment, a remittance, a payment correction and a Panel price change proposal (one approved,
one waiting).

Menus: Panel Claims, Claims follow-up, Panel payments, Correct a Panel payment, Panel price changes, Panel
invoices, Panel summary report.

### Phase 4 — inventory workflows

Purchase request → PO → approval → partial receipt; a delivery exception with independent approval; supplier invoice
(matched and waiting); a supplier return; a stock request from PUCHONG with a short receipt; a governed count with a
recount and an approved write-off; a batch hold placed and released; a recall started; a cycle count; stock review
acknowledgements; treatment usage; the controlled register.

Menus: every Inventory sub-page, including reorder suggestions, replenishment, stock health, stock value and journal,
and the stock movement report.

### Phase 5 — HR

Leave types, entitlements, holidays and requests (pending, approved, rejected, cancelled); a roster (one approved
week, one draft); attendance sessions and an overtime request; a correction request; staff files (no uploads), bank
details awaiting a second check, a checklist; credentials (no file); memos (text only); letter templates and an
issued letter; an appraisal cycle; an incident; open shifts; locum pay; staff claims in a category that needs no
receipt (waiting, approved, paid); a discipline case (no files); job positions; payroll set-up and one pay run up to
**draft** (see section 5).

Menus: every HR page except where section 5 says otherwise; the HR dashboard and labour cost fill from the above.

### Phase 6 — the rest

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
   photos, the opening import file. Default: **skipped**. Option, for the owner to decide: generated plain placeholder
   images and one-page PDFs saying "FICTIONAL TRIAL DATA" (needs GD on the server).
3. **Clock-in** requires a photo. Attendance sessions can only be created with a generated placeholder photo (option
   2). Without it, attendance stays empty and only corrections and overtime settings are shown.
4. **Payroll statutory tables** (EPF, SOCSO, EIS, PCB/MTD) must be entered and checked by two people from the
   official rates. Fictional rates would produce wrong-looking payslips. The pay run stops at draft unless HR or
   Finance supplies the tables; payslips and the bank and accountant exports stay empty.
5. **Messages** (email, WhatsApp, SMS, OTP) stay off. Only in-app message settings are shown.
6. **Public forms** (QR check-in submission, the job application form) are off by default. One QR intake is created
   through the public service only if `PUBLIC_PATIENT_INTAKE_ENABLED` is already on; job applications only if
   `HR_APPLICATIONS_ENABLED` is on.
7. **Website releases** can be prepared and approved by the Director as drafts; nothing is published or deployed.
8. **Professional content:** the triage guideline, MC, referral and attendance wording, drug labels and the
   controlled register are fictional examples, not clinical guidance; they remain marked as drafts where the system
   marks them.

## 6. Tests (per phase, on PostgreSQL 18)

Refused without the flag; refused with the wrong host name; refused non-interactively; refused when a non-`.test`
account (other than the Director) exists; refused without exactly one Director; creates the phase's records once;
a second run changes no table's row count; the password appears in no output, log or audit row; the phase audit
row has no actor and ids or counts only. No existing assertion is expected to change; any that must will be listed
for the owner before it is touched.

## 7. Open questions for the owner

1. Placeholder files (section 5, items 2 and 3): generate them, or leave those menus empty?
2. Payroll: stop at a draft run, or will HR or Finance supply checked statutory tables?
3. Is CHERAS plus a small PUCHONG stock right, or should all three branches get data?
4. The command acts as the existing Director for Director-only steps (provisioning, verifying clinician
   credentials, website release approval), so those audit rows show the Director's account. Is that acceptable?
