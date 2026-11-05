# Panel builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Syafiq** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Priya** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
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
- Per-visit Panel notes and the Payment column.
- Pages: `PanelClaims/Index.vue`, `Create.vue`, `Show.vue`; `PanelSettings/Index.vue`, `Form.vue`;
  `PanelInvoices/Index.vue`.

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
| Domain services | `app/Domain/Visit/Billing/Services/PanelClaimService.php`, `PanelClaimDirectoryService`, `PanelClaimEligibility`, `PanelClaimAmounts`, `PanelClaimDueRule`, `PanelClaimMessageService`, `PanelInvoiceReportService`; `app/Domain/Visit/Services/PanelSettingsService.php` |
| Models | `app/Domain/Visit/Billing/Models/PanelClaim*.php`, the `Panel` model |
| Controllers | `PanelClaimsController`, the Panel settings and Panel invoices controllers |
| Pages | `resources/js/pages/PanelClaims/*`, `PanelSettings/*`, `PanelInvoices/*` |
| Tests | `tests/Feature/Billing/PanelClaimsTest.php`, `tests/Feature/Visit/PanelSettingsTest.php`, `tests/Feature/Visit/VisitPanelNoteTest.php`, `tests/Unit/PanelClaimDueRuleTest.php`, `tests/Unit/PanelClaimMessageRenderTest.php`, frontend `panel-*.test.mjs` |

## 4. Permissions (who can do what today)

| Permission | Holders | Use |
|---|---|---|
| `panel.claims.manage.branch` | Panel Officer | Create and work claims |
| `panel.claims.clinical_filters.branch` | Panel Officer | Filter lists by diagnosis and treatment (no clinical text shown) |
| `panel.claims.patient_identity.view.branch` | Panel Officer | See patient phone and NRIC/passport on the claim page (each view audited without values) |
| `panel.work.view.branch` | Director, Panel Officer | Panel Responsibility work view |
| `panel.invoices.export.branch` | Director, Panel Officer, Finance Officer | Panel invoices page and CSV |
| `panels.manage.organisation` | Director, Panel Officer, Finance Officer, Administrator | Panel settings and claim message template |

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
| PNL-1 | Panel Claims list, claim page and Panel invoices filters fit a 320–390 px phone (SMOKE-1 S1-002, S1-003); layout classes only | pending |

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

## 7. Open items and known limits

- **Not yet UI-tested:** rejected and partially accepted Panel decisions (the Billing team's BIL-1 walks
  them; defects in Panel files come back to this team).
- SMOKE-1 S1-002 and S1-003 are fixed by PNL-1. Follow-up outside this team: the shared `OperationalTable`
  wrapper and the Billing workspace have the same hidden "Actions" header label that can widen a page; a
  `relative` on the shared wrapper would fix every page (needs agreement with the other teams).
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
- Commit, push and PR only after the owner says "ship it"; never merge.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; record that in `docs/BASELINE.md` with the first PR of each.

1. **PNL-1 Phone layout fixes. (Built 2026-11-05, waiting for the owner's "ship it".)** SMOKE-1 S1-002 (Panel Claims page width at 390 px) and S1-003 (Panel invoices
   filter row at 390 px). Layout only; no behaviour change.
2. **PNL-2 Bulk claim.** Select several Pending invoices of one Panel and create their claims in one action,
   each claim still its own record with its own idempotency, all Submitted on the same date; still submitted
   manually on the Panel's portal. Lifts the earlier "bulk claim not built" note (decision 9) for creation only.
3. **PNL-3 Remittance matching.** Record one Panel payment (amount, date, reference) and split it across
   several accepted claims of that Panel; show what remains unpaid per claim; append-only; the split must equal
   the payment. **Owner decision 2026-11-05: both the Panel Officer and the Finance Officer may record and split a
   Panel payment** (Finance Officer gains this one action; needs a narrow additive permission, listed for the
   owner in the PR).
4. **PNL-4 Overdue follow-up list.** A list of claims queried or unpaid past a set number of days, with the last
   action, its date and who did it. **Owner decision 2026-11-05: each Panel has its own follow-up days in Panel
   settings (default 30); a claim appears after that many days with no new action.**
