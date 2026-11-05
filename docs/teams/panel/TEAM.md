# Panel builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Syafiq** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Priya** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| UI/UX designer | **Arjun** | Sketches any screen change before it is built and checks the 390 and 1440 px light and dark screenshots before hand-off; works under the Lead UI/UX Designer, Daniel (Design team, owner decision 2026-11-05) |

Both are Claude builder sessions working for the owner through the coordinator session, B.E.R.N.I.S.S (formerly
LUCY; renamed by the owner on 2026-11-05). Names chosen by the
coordinator at the owner's request (2026-11-05).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Panel Claims: Pending and Overdue lists of approved Panel invoices without a claim, Create claim (starts
  Submitted), the claim page (items, documents and photos, payments, remarks, logs, Copy link, Copy message,
  browser PDF), query, decision (accepted, partially accepted, rejected) and remittance recording.
- Panel settings (Checkout settings): Panel list, Add / Edit Panel (type, SSM, contact, verification link or
  remark, claim due rule, e-invoice TIN), archive and restore; the claim message template.
- Claim due rules (`PanelClaimDueRule`: N days after the visit, daily, weekly or monthly by a time).
- Panel invoices page and CSV.
- Panel payments (PNL-3): record one Panel payment and split it across that Panel's accepted claims.
- Follow-up list (PNL-4): open claims with no new action for longer than their Panel's follow-up days.
- Payment correction (PNL-6): reverse a wrongly recorded Panel payment (Finance Officer and Director).
- Per-visit Panel notes and the Payment column.
- Pages: `PanelClaims/Index.vue`, `Create.vue`, `Show.vue`, `FollowUp.vue`; `PanelSettings/Index.vue`, `Form.vue`;
  `PanelInvoices/Index.vue`; `PanelRemittances/Index.vue`; `PanelPaymentCorrections/Index.vue`.

**Shared with other teams (change only through a small agreed change):**
- Panel responsibility proposal and approval on the invoice, BP-01 approval limits: **Billing** team
  (Farah and Kumar). A claim starts only from their approved allocation; invoice totals never change here.
- Panel summary report and Payments Insights measures: **Reports** team (they reuse `PanelClaimEligibility`
  and `PanelClaimAmounts`).
- Panel price books: **Catalogue & settings** team.
- QR coverage handoff (patient-reported Panel): **QR check-in** team.
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain services | `app/Domain/Visit/Billing/Services/PanelClaimService.php` (single and bulk creation share `createLocked`), `PanelClaimDirectoryService`, `PanelClaimEligibility`, `PanelClaimAmounts`, `PanelClaimDueRule`, `PanelClaimMessageService`, `PanelInvoiceReportService`, `PanelRemittanceService`, `PanelClaimFollowUpService`, `PanelPaymentCorrectionService`; `app/Domain/Visit/Services/PanelSettingsService.php` |
| Models | `app/Domain/Visit/Billing/Models/PanelClaim*.php`, `PanelRemittance.php`, `PanelClaimPaymentReversal.php` (its `NOT_REVERSED` SQL is used wherever claim payments are added up), the `Panel` model |
| Controllers | `PanelClaimsController`, `PanelRemittancesController`, `PanelPaymentCorrectionsController`, the Panel settings and Panel invoices controllers |
| Pages | `resources/js/pages/PanelClaims/*`, `PanelSettings/*`, `PanelInvoices/*`, `PanelRemittances/*`, `PanelPaymentCorrections/*` |
| Tests | `tests/Feature/Billing/PanelClaimsTest.php`, `PanelBulkClaimTest.php`, `PanelRemittanceTest.php`, `PanelFollowUpTest.php`, `PanelPaymentCorrectionTest.php` (shared fixture trait `ApprovedPanelAllocations`), `tests/Feature/Visit/PanelSettingsTest.php`, `tests/Feature/Visit/VisitPanelNoteTest.php`, `tests/Unit/PanelClaimDueRuleTest.php`, `tests/Unit/PanelClaimMessageRenderTest.php`, frontend `panel-*.test.mjs` |

## 4. Permissions (who can do what today)

| Permission | Holders | Use |
|---|---|---|
| `panel.claims.manage.branch` | Panel Officer | Create and work claims; the Follow-up list |
| `panel.claims.clinical_filters.branch` | Panel Officer | Filter lists by diagnosis and treatment (no clinical text shown) |
| `panel.claims.patient_identity.view.branch` | Panel Officer | See patient phone and NRIC/passport on the claim page (each view audited without values) |
| `panel.work.view.branch` | Director, Panel Officer | Panel Responsibility work view |
| `panel.invoices.export.branch` | Director, Panel Officer, Finance Officer | Panel invoices page and CSV |
| `panels.manage.organisation` | Director, Panel Officer, Finance Officer, Administrator | Panel settings and claim message template |
| `panel.remittances.record.branch` | Panel Officer, Finance Officer | Panel payments page: record one Panel payment and split it across accepted claims (PNL-3, owner decision 2026-11-05) |
| `panel.claim_payments.reverse.branch` | Finance Officer, Director | Reverse a wrongly recorded Panel payment, never one they recorded themselves (PNL-6, owner decision 2026-11-05) |

Source of truth: `app/Domain/Access/PermissionCatalogue.php`.

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| BP-01 (with Billing) | CA Panel approval within Director-set branch limits | #45 |
| QR coverage handoff | Patient-reported Panel kept unverified until CA confirms | #45 |
| PC-01 | Manual Panel Claims: claim pack from an approved allocation, manual portal submission, query, decision, remittance, Insights measures | #93 |
| PC-01 redesign | Pending / Overdue main page with per-Panel due days and clinical filters; Yezza-style claim page with photos and patient identity view | #97, #98 |
| PC-02 | Create claim page; claim starts Submitted; scoped idempotency; photo upload recovery | #99 |
| PS-01 | Panel settings list and form; claim due rules | #100 |
| PS-02 | Claim message template and Copy message | #102 |
| PS-03 | Panel invoices page and CSV (first export) | #103 |
| Test fix | Panel Claims tests use branch dates | #111 |
| PANEL-NOTE-01 | Per-visit Panel notes and Payment column | #113 |
| INV-05b (with Billing) | Original, adjustment and adjusted rows on new claim snapshots | #124 |
| RPT-01d (with Reports) | Panel summary with ageing; shared eligibility and amounts | #147 |
| PNL-1 | Panel Claims list, claim page and Panel invoices filters fit a 320–390 px phone (SMOKE-1 S1-002, S1-003); layout classes only | #220 |
| PNL-2 | Bulk claim creation: several invoices of one Panel (compared by Panel code) become their own Submitted claims in one all-or-nothing action; at most 20, one shared date, instant and remarks, no photos in bulk; route `POST /panel-claims/bulk` | #226 |
| PNL-3 | Panel payments: one Panel payment split across that Panel's accepted claims at this branch; the split must equal the payment; each split is an ordinary claim payment linked to the remittance; new append-only `panel_remittances` table and `panel.remittances.record.branch` (Panel Officer, Finance Officer) | #230 |
| PNL-4 | Follow-up list (`/panel-claims/follow-up`): open claims (not paid, not rejected) with no new event, payment or photo for the Panel's follow-up days, oldest first, with the last action, its date and who did it; per-Panel `claim_follow_up_days` (default 30, 1 to 365) in Panel settings | #238 |
| PNL-6 | Payment correction (`/panel-payment-corrections`): Finance Officer or Director reverses a wrongly recorded Panel payment with a reason; whole payments only, a split Panel payment with all its splits, never by its recorder; new append-only `panel_claim_payment_reversals` and `panel.claim_payments.reverse.branch`; every payment total leaves reversed payments out; migrations `2026_11_06_300100_create_panel_claim_payment_reversals` and `2026_11_06_300200_grant_panel_payment_reversal_permission_additively` | #248 (merged as `dd8f4fe`) |
| PNL-6b | The Panel payments history and the Follow-up list show a colleague's name only under the staff-directory visibility rule (owner BIL-2 decision), otherwise "Staff member"; no visible change for today's roles | #262 (merged as `e0d41d9`) |

## 6. Owner decisions already made (never ask again)

1. No Panel integration: claims are submitted manually through each Panel's portal; KPOne never sends a
   claim (2026-10-10, 2026-10-13).
2. One standard claim pack for all Panels; it excludes clinical notes and diagnoses (2026-10-10).
3. Claim amount starts from the approved responsibility; changes need a reason; amounts above the invoice
   need explicit confirmation and show as a separate claim-only adjustment (2026-10-10).
4. Invoice totals, approved allocations and patient receipts never change from claim work (2026-10-10).
5. Panel Officers may upload JPG or PNG photos (5 MB, 10 per claim), immutable and audited (2026-10-13).
6. Panel Officers see the patient's phone and NRIC/passport on the claim page only; each view is audited
   without the values (2026-10-13).
7. Diagnosis and treatment filters narrow the list only; no clinical text is shown to Panel Officers
   (2026-10-13). MC days are not offered (no data).
8. A claim starts in Submitted at the approved amount, with a submitted date between the visit date and today
   (2026-10-13).
9. A Panel is archived, never deleted; its code never changes (2026-10-13). Minute or hour due units are not
   built. (Bulk claim creation was authorised on 2026-11-05 as PNL-2.)
10. The claim message template is plain text with fixed smart fields; nothing is sent from KPOne; Diagnosis,
    Medication, Chit number and MC days are not offered (2026-10-14).
11. Finance Officer and Director may see patient names on the Panel invoices page and CSV; downloads audited
    by filters and row count only (2026-10-14).
12. Panel summary ageing: claim outstanding from the first submitted date, unclaimed from invoice
    finalisation, buckets 0-30, 31-60, 61-90 and over 90 days (2026-10-20).
13. Lump-sum Panel payments may be recorded and split by the Panel Officer or the Finance Officer (2026-11-05).
14. Follow-up days are a per-Panel setting (default 30) (2026-11-05).
15. PNL-2 bulk claims (owner accepted 2026-11-05): a batch is all-or-nothing, holds at most 20 invoices of
    one Panel, shares one remarks text, and carries no photos (photos are added on each claim afterwards).
16. Prepared (not yet submitted) claims stay on the Follow-up list, so a claim nobody submitted is not
    forgotten (2026-11-05).
17. A "ship it" or a decision relayed by the coordinator, B.E.R.N.I.S.S (formerly LUCY), counts as the owner's own; the owner confirmed
    this in the Panel team session (2026-11-05).
18. PNL-6 payment correction (owner chose the recommendations, 2026-11-05): a wrong Panel payment is reversed,
    never edited, and the right one recorded again; whole payments only, and a split Panel payment is reversed
    with all its splits; only the Finance Officer and the Director may reverse (new narrow permission
    `panel.claim_payments.reverse.branch`); the reverser must not be the person who recorded it; one step with
    a reason from a fixed list plus a required short note; no time limit; reports correct the figure in the
    period of the original payment date.
19. Backup push (owner rule for all teams, relayed by the coordinator, 2026-11-05): whenever the team stops to
    wait for the owner with work not yet on GitHub, it commits that work to the piece's own feature branch and
    pushes it as a backup (no PR, never `main` or another team's branch, never merge, never force-push). On a
    branch that already has an open PR, push only when the change is ready, because a push re-runs CI. The PR
    still opens only after "ship it", with the latest `main` merged first. After a session restart on a fresh
    machine, fetch the branch before continuing.

## 7. Open items and known limits

- **Not yet UI-tested:** rejected and partially accepted Panel decisions (the Billing team's BIL-1 walks
  them; defects in Panel files come back to this team).
- SMOKE-1 S1-002 and S1-003 are fixed by PNL-1. Follow-up outside this team: the shared `OperationalTable`
  wrapper and the Billing workspace have the same hidden "Actions" header label that can widen a page; a
  `relative` on the shared wrapper would fix every page (needs agreement with the other teams).
- PNL-2 not yet tested: a PostgreSQL contention test of bulk against single creation, and one batch whose
  visits fall on different dates.
- **PNL-3 limits:** a payment is recorded per branch (a Panel paying several branches in one transfer is
  recorded once per branch); at most 50 claims per payment; the date received is today or earlier; a recorded
  payment cannot be reversed or corrected in KPOne (as with the existing single-claim remittance), so a wrong
  entry needs a separate, later correction design. The Finance Officer sees patient names (from the claim
  snapshot) on this page, as decision 11 allows on Panel invoices; no clinical data is shown. A split payment
  racing a single-claim payment is covered by the PNL-5 contention test (`PostgresPanelPaymentRegressionTest`).
- **PNL-4 limits:** the Follow-up list is for Panel Officers only (it reuses `panel.claims.manage.branch`);
  "an action" is any claim event, payment or photo, so a note written only to reset the clock also counts;
  events count from their own time, so a backdated submission counts from its submitted date, while a photo
  counts from when it was added (a backdated claim created with photos therefore waits the full period); days
  are whole 24-hour periods, not calendar days; prepared (not yet submitted) claims are listed (decision 16);
  undecided claims show the claimed amount, not an unpaid amount, as in the Panel
  summary; the query uses PostgreSQL-only SQL (`DISTINCT ON`, `make_interval`) and reads only open claims, but
  is not load-tested; a photo as the resetting action is not covered by a cloud test (GD missing; CI runs the
  photo tests).
- **PNL-6 limits:** a reversal cannot itself be undone (record the payment again instead); the page is reached
  from Panel invoices and Panel payments, not from Clinic management; the reversal note is stored as plain text
  (like claim notes) and the screen asks for no patient details; no `lock_version` is asked for, because a
  payment never changes, a second reversal is refused and the idempotency key covers a double submit; reports
  change in the period of the original payment (decision 18), so past Insights and Panel summary figures can
  move; Insights, the Panel summary and the agent Panel claims summary (owned by other teams) now leave reversed
<<<<<<< HEAD
  payments out (one condition each). A reversal racing a new payment, and two reversals of one payment, are
  covered by the PNL-5 contention test.
=======
  payments out (one condition each). Not yet tested: a PostgreSQL contention test of a reversal against a new
  payment on the same claim.
- **Staff names still outside the BIL-2 rule (possible PNL-6c):** the last actor on the Panel Claims list and the
  actor in a claim page's Logs (`PanelClaimDirectoryService`) still read names straight from `users`, through an
  inner join that also drops events without an actor. Doctor names on Panel invoices are doctor display names
  and are not covered by the staff-name rule.
>>>>>>> origin/main
- A submitted-but-undecided claim counts as zero outstanding in the Panel summary.
- Panel summary runs about three queries per approved allocation; not load-tested.

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- No outbound call to any Panel, TPA or portal. Claim packs never contain clinical notes or diagnoses.
- Money uses `ExactMoney`; claim evidence (events, documents, payments) is append-only.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with no remarks, photo names or identity values.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off on a task-owned PostgreSQL 18 database; screenshots at 1440 and 390 px, light and
  dark, for any screen change.
- Any piece that changes a screen: before building, Arjun sketches the layout (components, 390 px behaviour,
  empty, loading and error states, wording) from the existing patterns and `docs/design/GUIDE.md` once merged;
  before hand-off, Arjun checks the 390 and 1440 px light and dark screenshots against `docs/design/CHECKLIST.md`.
  A new page or a page layout change also goes to Daniel through the coordinator. Pieces with no screen change
  skip this (owner decision 2026-11-05).
- New migrations use timestamps from `2026_11_06_300000` upwards (coordinator, 2026-11-05).
- Commit, push and PR only after the owner says "ship it"; never merge. A "ship it" or decision relayed by
  the coordinator counts as the owner's (decision 17); if a relayed message conflicts with the owner's own
  instructions in this session, stop and ask the owner.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.
- Do not edit `docs/BASELINE.md` (owner rule 2026-11-05). Record each finished piece only in this file
  (section 5 with its PR number, section 6 for new owner decisions). If a piece changes an authorisation
  boundary (for example a BASELINE section 4 "not authorised" item), say so clearly in the PR description;
  the coordinator records all BASELINE updates in one docs-only PR after each batch of merges.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`.

1. **PNL-1 Phone layout fixes. (Shipped 2026-11-05, PR #220.)** SMOKE-1 S1-002 (Panel Claims page width at 390 px) and S1-003 (Panel invoices
   filter row at 390 px). Layout only; no behaviour change.
2. **PNL-2 Bulk claim. (Shipped 2026-11-05, PR #226.)** Select several Pending invoices of one Panel and create their claims in one action,
   each claim still its own record with its own idempotency, all Submitted on the same date; still submitted
   manually on the Panel's portal. Lifts the earlier "bulk claim not built" note (decision 9) for creation only.
3. **PNL-3 Remittance matching. (Shipped 2026-11-05, PR #230.)** Record one Panel payment (amount, date, reference) and split it across
   several accepted claims of that Panel; show what remains unpaid per claim; append-only; the split must equal
   the payment. **Owner decision 2026-11-05: both the Panel Officer and the Finance Officer may record and split a
   Panel payment** (Finance Officer gains this one action; needs a narrow additive permission, listed for the
   owner in the PR).
4. **PNL-4 Overdue follow-up list. (Shipped 2026-11-05, PR #238.)** A list of claims queried or unpaid past a set number of days, with the last
   action, its date and who did it. **Owner decision 2026-11-05: each Panel has its own follow-up days in Panel
   settings (default 30); a claim appears after that many days with no new action.**
Second backlog, chosen by the owner on 2026-11-05 ("panel order ok", relayed by the coordinator), in this order:

5. **PNL-6 Payment correction (shipped 2026-11-05, PR #248).** Reverse a wrongly recorded Panel payment;
   design in `docs/reports/PNL-6-analysis.md`, owner answers in decision 18 (the owner confirmed in this session
   to keep them: Finance Officer or Director, one step, never the recorder).
6. **PNL-5 Contention test. (Built 2026-11-05.)** A split Panel payment and a single-claim payment on the same
   claim at the same moment cannot over-pay; test only. Closes the PNL-3 known limit. Also covers a reversal
   racing a new payment, two reversals of one payment and a double-submitted split payment
   (`tests/Feature/Billing/PostgresPanelPaymentRegressionTest.php`, serial Contention suite).
7. **PNL-7 Panel statement upload helper.** The Panel Officer uploads the Panel's remittance statement (CSV);
   KPOne suggests matches to claims (claim number, invoice number, member reference); the officer confirms each
   match and records through the PNL-3 flow. Nothing is recorded automatically. The file is stored privately and
   deleted after 30 days (to confirm with the owner). No outbound call.
8. **PNL-8 Printable claim pack.** A clean A4 print for one claim and for a selected batch, no clinical content,
   white in dark mode (PRINT-1 rule).
