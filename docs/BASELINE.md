# KPOne Baseline

This file is the authoritative delivery status and supersedes the overview in `PROJECT.md`. Every row marked merged is
merged only; none is production-approved. For `main`'s current tip, run `git log -1`; for every merge, `git log --merges`.

Older history, and the text moved out on 2026-11-27 to keep this auto-loaded file small, is kept unchanged in [BASELINE-HISTORY.md](BASELINE-HISTORY.md); moving it changed no rule.

Yezza remains the operational source of truth. Nothing below is production-approved; all data is synthetic.

## 1. Delivery status

| Phase / slice | Scope | Status |
|---|---|---|
| **LATEST RECORD (2026-11-18 to 2026-11-20): new-look pilot, then Classic only, triage backend, design notes and team additions** | This row records the newest merges only; the long gap between PR #376 and PR #982 is not yet written up here (see `git log`). **Merged 18 Nov:** agent "approve prepares work" chain AG-32 4a to 4c and AG-33 (PRs #975, #982, #988; the switch `agents.prepare_enabled` stays OFF until the owner turns it on); restock suggestions warn about an open order (PR #984); HR-21 work permit warnings (PR #992, migration `2026_11_10_007700`); the New look (preview) pilot: switch and `user_space_preferences.new_look` column (PR #985), colours and self-hosted Geist (PR #991), two-part sidebar (PR #993); new-look design notes, space rules, named exception and the four canvas-only mock-up designers Zara, Jun Hao, Deepa and Haikal in the Design team (PRs #983, #989, #990). **Merged 19 and 20 Nov:** starter visit reasons in demo data and stock-value parity (PR #995); Stock value report in Classic with a Director-only link (PR #996); TRI-1 triage design note with the owner's answers (PR #997); TRI-2 emergency lane and Medical Assistant role design notes (PR #998, docs only, nothing authorised to build yet); **TRI-1a triage backend** (PR #999: guideline versions with two-person approval, append-only triage records, `TriageService` and `TriageGuidelineService`, new permissions `visits.triage.record.branch` for CA and CA Supervisor and `triage.guideline.manage.organisation` for Director and HR Manager, migrations in the Triage range `2026_11_20_500100` to `500399`; no screens yet); **CO-1 Classic only** (PR #1000: everyone sees Classic, the "Try the new layout" switch and the new-look preview are no longer offered, the department spaces and Me home open in the Classic layout; `user_space_preferences` is kept and no longer read; 23 owner-approved test changes). **Team additions (owner request 2026-11-20):** Syazwan (Lead) and Farhana (Reviewer) for triage, and Aqilah (Lead) and Imran (Reviewer) for patient-flow notes, join Patients & Registration; Haris (Lead) and Nadiah (Reviewer) for the Classic-only work join Staff Experience. They had worked in short-term sessions on 19 and 20 Nov while the coordinator's usage limit was reached. | **Merged** (PR CI green on each exact head). New migrations need `php artisan migrate`; the frontend needs `npm run build`. Next: Classic-only CO-3 to CO-10 (remove the V2 pages, components and switching code area by area); TRI-1b triage screens and TRI-1c (see section 6 item 31); TRI-2 and the MA role wait for the owner's answers in their notes. Not production-approved. |
| **BUILDER TEAMS: area teams build and maintain KPOne (owner decisions 2026-11-05)** | Ten Claude builder teams, each a Lead who builds and a Reviewer who checks, each with a memory file under `docs/teams/<area>/TEAM.md` (scope, code map, permissions, history, owner decisions, open items, rules, backlog); the team list with Leads, Reviewers and designers is kept in BASELINE-HISTORY.md. A Design team (Daniel, Lead UI/UX Designer, with one designer per builder team) owns `docs/design/` and reviews new or changed screens (`docs/teams/design/TEAM.md`). A coordinator session (B.E.R.N.I.S.S, formerly LUCY) assigns work, reviews every PR, merges after green CI on the exact head (owner decision 2026-11-05, replacing "merge N" for team PRs) and records BASELINE. **Team rules (owner decisions 2026-11-05):** teams never edit `docs/BASELINE.md` (the coordinator records it in one docs PR per batch); teams never merge; a "ship it" or decision relayed by the coordinator counts as the owner's once the owner confirms that rule in each team session (unchanged by the rename); **backup push:** a team that stops to wait for the owner with unpushed work commits and pushes it to its own feature branch (no PR, no merge, no force-push, never `main`), and the PR still opens only after "ship it"; the coordinator does not merge a layout PR with the Lead UI/UX Designer's "Not OK" unless the owner accepts it as debt. **Owner delegation (2026-11-05):** while the owner is away, the coordinator approves in the owner's place, labelled "B.E.R.N.I.S.S for the owner", and lists each such approval on the owner's board for later review; the owner's own words in a team session always win (section 6, item 27); it ended when the owner returned at about 01:10 UTC on 2026-11-06, and the owner turned it on again four times that day, the last time open-ended (item 27). Migration timestamp ranges are in section 6, item 23. | **Working process only; no code.** Batch counts moved to BASELINE-HISTORY.md. Not production-approved. |

| **PC-01: manual Panel Claims (authorised 2026-10-10)** | Manual Panel Claims workflow; its full scope, boundaries and evidence are kept unchanged in [BASELINE-HISTORY.md, section 1](BASELINE-HISTORY.md#1-delivery-status). | **Merged in PR #93 as `4a23486`. Not production-approved.** |
Older rows (every slice merged before 2026-11-18, newest first): see [BASELINE-HISTORY.md, section 1](BASELINE-HISTORY.md#1-delivery-status).

## 2. Domain map (`app/Domain`)

Organisation (branches, public check-in links, Inventory) · Identity · Access (`PermissionCatalogue`, branch access) ·
Audit · Patient (Patient Master, public intake) · Visit · Queue · Clinical (encounter, allergy, treatment plan,
checkout, hold, Dispensary) · Shared.

## 3. Roles

`director`, `resident_doctor`, `ca`, `ca_supervisor`, `panel_officer`, `finance_officer`, `business_development`,
`marketing`, `hr_manager`, `technical_admin`, `queue_display` (shared waiting-room TV account; display only). Source of truth: `app/Domain/Access/PermissionCatalogue.php`
(+ `BillingPermissions`). Technical admin never gains clinical or patient-content access by implication.

## 4. Not authorised yet

Do not build or store data for these without an explicitly approved phase:

- Appointments; patient portal / patient login; SMS, OTP, email to patients. WhatsApp to patients: only a plain click-to-chat link is permitted (owner decision 2026-10-22); API, templates, stored messages, delivery/read tracking, webhooks, OTP and notifications remain unauthorised for patients. **Narrow KPOne HR exception (owner decision 2026-11-06, section 6, item 28):** staff messages by WhatsApp, SMS and email, and OTP, for staff and HR only (slice HR-14, not built yet, under the conditions in item 28); patient messaging stays unauthorised
- Production stock migration. **Inventory programme authorised 2026-11-11 (owner, verbatim: "ill agree to add those but no need to wait for accountant, just do it until done." and "so a until g will be build right? u have my approval"):** the Inventory team builds, slice by slice (STK-12 to STK-36 in `docs/teams/inventory/TEAM.md`), purchasing (purchase requests, PO approval, printable PO, receiving against a PO, supplier invoice three-way match; no payment in KPOne), reorder and replenishment suggestions, supplier price lists and lead times, cycle counts with ABC classes and blind counts, a controlled-drug register and pharmacist review pack (marked pending pharmacist review), recall holds across branches, GS1 batch and expiry reading, stock valuation (weighted average cost by default), COGS, margin and RM losses, a monthly journal summary CSV (download only, no accounting integration), inventory KPIs, a bulk opening-stock import with the STK-1 sign-off, and a draft stock admission procedure. Valuation figures are labelled "Management report — cost policy to be confirmed by the clinic's accountant" and do not wait for the accountant; the pharmacist review does not block building. Each slice still needs its new permissions and role grants approved by the owner, the Lead UI/UX Designer's look for new screens, and green CI. The I2 demo panel (PR #25-#29) remains not walked against real clinic procedures.
- Clinical documents other than the Medical Certificate, the live Drug label, the Referral letter (DOC-04, merged) and the attendance confirmation (DOC-05, merged). Analysed on 2026-10-21 and **not authorised**: a patient medicines-supplied summary (live, completed CA-final lines, not a prescription), a formal prescription (needs doctor and pharmacist review of required particulars first), a patient consultation summary, employer or insurer reports, quarantine and fitness documents, and any consultation, progress or other document; a general template editor, drug label snapshots, history (except the DSP-6 print log: who printed which line's label and when, with a REPRINT mark), batch and expiry printing (except the DSP-2 case: exactly one allocated batch), barcodes or additional sizes, signed-original or e-signature capture, late first issuance or delegation of Medical Certificates; charging a price for any document
- Stock valuation (STK-10): no value report, valuation export or cost screen in KPOne (owner decision 2026-11-06, after the STK-10 analysis, PR #289). STK-10B (PR #309) records receipt cost evidence only (the lot total from the supplier invoice, with estimates marked as estimates) and shows no cost. If cost is ever shown, only the Director sees it (owner decision 2026-11-06). Any valuation first needs the accountant's written cost policy.
- Report downloads beyond RPT-01a, RPT-01b, RPT-01c, RPT-01d, RPT-01e, RPT-02a, RPT-02b and the inventory programme's valuation reports and journal summary CSV (2026-11-11): Treatment filtering, the medicine or prescription report, XLSX or PDF, and any stored, emailed or background export
- Advanced finance
  - KPOne HR (staff roster, leave, attendance and overtime, employee records and documents, staff credentials, staff claims, payroll and payslips, HR memos and reports) is no longer in this list: the owner authorised it on 2026-11-05 as a programme built slice by slice (see the KPOne HR row in section 1). Five more KPOne HR items were authorised on 2026-11-06 under conditions (section 6, item 28): staff messages by WhatsApp, SMS and email, and OTP; a locum portal; an optional selfie or location check at clock-in; a link to the outside HR product; a public job-application form. None is built yet. Still not authorised there: push notifications, continuous location tracking and any message to patients.
- Agent platform beyond wave 1 (see the AGENT-PLATFORM row): AI service calls from KPOne, voice, agent actions that change clinic data, and agents for HR, finance, marketing, Panel or management
- Website integration; marketing modules; management analytics beyond the separately authorised Insights reports below
- Yezza integration or data migration
- Splitting Q1-B2-D3 into separate PRs — it ships as one authorised scope
- Production deployment and go-live (needs a security, privacy/PDPA and legal gate first)

### Insights report authorisation (owner decision, 2026-10-01)

The owner authorised aggregate-only reports for **Today**, **Sales**, **In-clinic**, **Payments**, **Inventory**,
and **Patients**. The dedicated `insights.view.organisation` permission is granted to every catalogue role.
Appointments, general exports, package billing, patient drill-down, and patient-level financial rankings remain outside the
approved scope, except for separately authorised PS-03 and RPT-01 slices below. The implementation status, filter behavior, metric definitions, privacy limits, and unavailable
measures are maintained in [INSIGHTS.md](./INSIGHTS.md). The reports merged in PR #43 as `a08d099`; exact
candidate PostgreSQL 18.6 validation and post-merge CI passed. The owner completed the fresh-database browser
walkthrough with non-zero synthetic data on 2026-10-02 and confirmed results. This is not production approval.


### RPT-01: report download authorisation (owner decision, 2026-10-20)

RPT-01a is **merged in PR #141 as `83877a4`**: Sales summary CSV only, aggregate data, with the narrow
`reports.sales.export.organisation` permission for Director, Finance Officer and Administrator. No patient
access is granted to Administrator. One additive permission migration; no financial schema changes.
Existing Insights calculations, gross/net distinction and cohorts are reused. Values-free export audit,
10/minute per-actor RPT throttle, UTF-8 BOM/formula safety, 5,000-row refusal and no retained generated file.
RPT-01b is **merged in PR #142 as `89d10bb`** (commit `0661e2b`):
daily adjusted net sales and distinct patient counts by local finalisation date, and receipt amounts by original receipt date/method,
with explicit reversed and net columns. New `reports.payments.export.organisation` has the same three
holders (additive permission migration only). The receipt projection is shared with terminal
reconciliation; existing Payments allocation-based cards/rankings remain unchanged and their difference
is explicit. The screen hides all-zero receipt rows while the CSV keeps the full date/method matrix.
Empty export dates are zero; exports share RPT-01a's safe CSV, audit-only write and throttle rules. PS-03 is unchanged. Patient-level exports, Panel ageing and stock valuation remain
pending owner decisions at that point. They were then decided for RPT-01c-0, RPT-01c, RPT-01d, RPT-01e and RPT-02b (owner decisions 2026-10-20), merged in PRs #145 to #149 and recorded in section 1; stock valuation, Treatment filtering, XLSX/PDF and stored or emailed exports remain deferred. See [INSIGHTS.md](./INSIGHTS.md). This is not production approval.

## 5. Key decisions

- Modular monolith, one Laravel app, one PostgreSQL database. No microservices or queues/brokers without approval.
- Patients are organisation-level; Visits, Queue entries and Encounters are branch-owned.
- Public QR submissions only ever create a **Pending Intake**; a CA must review and accept before any Patient,
  Visit or Queue record exists.
- QR bearer token travels in the URL fragment (`/check-in#token`), is exchanged for httpOnly cookies, and is
  stored hashed (plus an encrypted copy for re-display of the active QR). Links expire after 90 days by default.
- Intake payloads are encrypted, retained 30 days, then purged by `public-intakes:cleanup`.
- The public QR status page shows identifying detail (first name, queue number) only after CA acceptance, and
  otherwise only aggregate, non-identifying queue figures and public branch details. Patient GPS is optional,
  requested only after a user action, used only in the browser, and never sent to KPOne or any external
  geolocation service, or persisted.
- Every mutation: backend permission + branch scope, transaction, row lock, `lock_version`, idempotency where
  retried, and an audit event without secrets.
- PostgreSQL 18 validation of an exact frozen SHA is mandatory before merge.

## 6. Open issues and decisions

Items 3 to 32 are kept unchanged, with every rule they state, in [BASELINE-HISTORY.md](BASELINE-HISTORY.md#moved-from-baselinemd-on-2026-11-27) and still apply as written there; numbers are unchanged.

1. **Production release gate:** complete access review, recovery/restore proving, security review, privacy/PDPA
   assessment, and legal approval before any go-live. No delivered feature or synthetic UAT implies production
   approval.
2. Before enabling public intake in production, deliberately set `PUBLIC_PATIENT_INTAKE_ENABLED=true`, configure
   `PUBLIC_PATIENT_INTAKE_TRUSTED_PROXIES` in every deployed environment, run the scheduler, rotate and reprint
   every QR link, and replace the `uat-draft-2026-09-19-v1` privacy-notice version with a legally approved value.
   Existing QR links use the old URL format; no data backfill is planned.
3. Operational fixes and remaining follow-ups.
4. Feature-specific owner UAT.
5. Housekeeping.
6. Decisions parked by the owner.
7. Before production.
8. Document evidence limit.
9. Known performance limits of the new reports.
11. Parked by the owner: inventory review.
12. Parked by the owner: staff communication ideas. Not authorised.
13. Website programme.
14. Small follow-ups.
16. Pilot scope and readiness.
17. Owner decisions settled 2026-10-30.
19. Dependency advisories (DEPS-AUDIT-1).
21. Clock-dependent tests after Kuala Lumpur midnight.
23. Builder-team process.
24. Owner decisions for work not merged yet.
25. HR proposal and KPOne HR.
27. Owner delegation.
28. KPOne HR: five more items authorised.
29. Owner decisions for print work not merged yet.
30. Owner decisions recorded with the sixth batch.
31. Owner decisions 19 and 20 Nov 2026.
32. Owner decisions 20-21 Nov 2026: Finance (TYBE) and Inventory proposals.

Items 10, 15, 18, 20, 22 and 26 (history only), the R1 resolved list and the recommended delivery order moved there too.

## 7. Updating this file

Update this baseline in the same PR that merges a phase or changes an authorisation boundary.

When a section 1 row is superseded, move it to `BASELINE-HISTORY.md` rather than keeping it here, so this file stays small.
