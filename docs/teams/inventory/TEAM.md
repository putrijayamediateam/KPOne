# Inventory & Stock builder team

Status: **set up with the owner on 2026-11-05.** This folder is the team's memory: what is built, what
the owner decided, the rules, and what to build next. The team reads it before every task and updates it in
the same PR as the code.

## 1. Team

| Role | Name | Job |
|---|---|---|
| Lead | **Zulkifli** | Plans each piece, builds it (backend, pages, tests), keeps this file current |
| Reviewer | **Mei Ling** | Checks every piece against the rules below, the tests and the owner's decisions before it reaches the owner |
| UI/UX designer | **Wei Jie** | Sketches every screen change before it is built and checks the 390 and 1440 px light and dark screenshots before hand-off; works under the Lead UI/UX Designer, Daniel (Design team) |

Both are Claude builder sessions working for the owner through the coordinator session. Names chosen by the
coordinator at the owner's request (2026-11-05).

## 2. What this area covers

**In scope (the team builds and maintains):**
- Stock references: items, SKUs, verified base stock units, locations, batches, medicine-to-SKU mapping.
- Opening balances and their independent sign-off (Pilot readiness).
- Verified receiving: delivery references, split lots, accepted / quarantined / rejected outcomes, delivery
  exception approval, reversing corrections.
- Batch safety holds and release; one shared batch eligibility rule; FEFO exception reasons.
- Stock review worklists (low stock, expiring, expired) and acknowledgements.
- Governed stock count, recount and write-off approval.
- Transfers and stock requests; reorder levels; suppliers.
- The stock movement ledger and balances, and the Stock movement report's data source.
- Pages: `Inventory/Index.vue`, `Readiness.vue`, `BatchHolds.vue`, `StockReview.vue`, `GovernedStock.vue`, the
  Suppliers settings screen.

**Shared with other teams (change only through a small agreed change):**
- Dispensing allocation and stock debit at Complete; drug-label batch and expiry: **Dispensary & OTC** team
  (Aisyah and Hakim). Their DSP-2 prints batch and expiry on labels; scanning stays here.
- Stock figures in reports: **Reports** team.
- Medicine catalogue and Unified Catalogue Setup: **Catalogue & settings** team.
- Agents that read stock (Hana, Rizal): **Agent platform** team, read-only through data sources.
- Permissions catalogue: changed only by an owner-approved migration.

## 3. Where the code lives

| Kind | Files |
|---|---|
| Domain services | `app/Domain/Organisation/Inventory/Services/` — `InventoryMovementService`, `InventoryBatchEligibility`, `InventoryBatchHoldService`, `GovernedStockService`, `InventoryStockReviewService`, `InventoryReadinessService`, `ProcurementService`, `ReceivingReviewService`, `ReceiptCorrectionService`, `StockRequestService`, `SupplierReturnService`, `TreatmentUsageService`, `InventoryCodeLookupService`, `InventoryReferenceAdministrationService`, `SupplierSettingsService`, `InventoryPickerService`, `InventoryMovementProjection`, `StockUnitQuantity`, `LegacyStockPath` and others |
| Models | `app/Domain/Organisation/Inventory/Models/` — items, SKUs, batches, locations, balances, movements, purchase orders, goods receipts, stock requests, stocktakes, adjustments, reorder levels, suppliers |
| Pages | `resources/js/pages/Inventory/*.vue` |
| Analysis | `docs/reports/ANALYSIS-STOCK.md` (proposals STK-1 to STK-10, owner and pharmacist questions) |
| Delivery reports | `docs/reports/STK-1.md` to `STK-9.md`, `STK-10B.md`, `STK-11.md` |
| Tests | `tests/Feature/Inventory/*` (including the PostgreSQL contention tests), `PostgresDispensaryInventoryRegressionTest`, `InventoryOperationsTest`, `NAV01ExplicitRedirectsTest` |

## 4. Permissions (who can do what today)

Source of truth: `app/Domain/Access/PermissionCatalogue.php`. Key ones:

| Permission | Holders | Use |
|---|---|---|
| `inventory.view.branch` | Director, CA, CA Supervisor, Finance Officer, Administrator | See stock and worklists |
| `inventory.references.manage.organisation` | Director, CA Supervisor, Administrator | Items, SKUs, units, locations, batches |
| `inventory.opening.verify.branch` | CA Supervisor, Finance Officer, Director | Independent opening sign-off |
| `inventory.receiving.exceptions.approve.branch` | CA Supervisor, Finance Officer, Director | Delivery exception approval |
| `inventory.batch_holds.place.branch` / `.release.branch` | place: CA Supervisor, Finance Officer, Director; release: Finance Officer, Director | Batch holds |
| `inventory.stock_reviews.manage.branch` | CA Supervisor, Finance Officer, Director | Stock review acknowledgements |
| `inventory.variances.approve.branch` | CA Supervisor, Finance Officer, Director | Count and write-off approval |
| `inventory.supplier_returns.request.branch` | CA Supervisor | Request, cancel (own) and dispatch supplier returns (STK-7) |
| `inventory.supplier_returns.approve.branch` | Finance Officer, Director | Approve or reject supplier returns (never their own request) and record the credit note (STK-7) |
| `inventory.usage.record.branch` | CA, CA Supervisor | Record treatment-room usage and waste (STK-8); reversal uses `inventory.variances.approve.branch` |

## 5. What is built (history)

| Slice | What it did | PR |
|---|---|---|
| I1A / I1B | Inventory reference governance, demo workspace | #20, #21 |
| I2 | Inventory operations demo v1 (suppliers, purchase orders, receiving, requests, transfers, stocktake, adjustment, reorder levels) | #25 to #29 |
| UI-1 / NAV-01 | Stock setup screens; explicit Inventory redirects | #36, #40 |
| Unified Catalogue Setup | Medicine setup creates or reuses its item and SKU, optional batch and opening stock | #43 |
| SET-03 | Suppliers settings | #106 |
| Pilot scale limits | Bounded inventory picker, smaller Inventory payload | #176 |
| STK-1 | One verified base stock unit per SKU; Pilot readiness; independent opening sign-off | #180 |
| STK-2 | Branch batch holds, shared eligibility, FEFO exception reasons | #182 |
| STK-3 | Verified receiving with delivery reference, split lots, outcomes, exception approval, corrections; real expiry required | #183 |
| STK-4 | Stock review worklists and acknowledgements | #185 |
| STK-5 | Governed count, recount and write-off approval | #186 |
| STK-6A | Legacy stocktake and manual adjustment closed; ledger writer allowlist | #188 |
| RPT-01e | Stock movement summary (quantities only) | #148 |
| CLOCK-1 | Stock review test uses branch dates | #208 |
| STK-6 | Transfer shortages: receipt lines (accepted, short, damaged, extra), only accepted enters stock, append-only differences resolved by a different write-off approver (domain and PostgreSQL) | #217 |
| STK-7 | Supplier returns: request from a verified receipt lot, approval by a different person (Finance Officer or Director), dispatch through a new `supplier_return` ledger movement, credit-note reference (no payment); append-only evidence guarded by PostgreSQL | #233 |
| STK-8 | Treatment-room usage and waste: CA or CA Supervisor records stock used or wasted outside dispensing (new `treatment_usage` and `treatment_waste` ledger movements), optional treatment room, no patient link or bill; a different CA Supervisor, Finance Officer or Director may reverse an entry once (credit back as `adjustment_in`); append-only evidence guarded by PostgreSQL | #244 |
| STK-9 | Barcode scanning on Inventory screens (receiving, governed count and write-off, treatment-room usage, supplier returns): handheld scanner, typing, or the browser's built-in camera reader; one barcode, one SKU per organisation (domain and PostgreSQL, existing duplicates listed on Pilot readiness); a scan only finds | merged in PR #253 (`4445021`) |
| STK-11 | Patient returns location for DSP-7: a `dispensary_returns` location type that is never picked or dispensed from, the `dispensary_return` movement written only by `InventoryMovementService::recordDispensaryReturn` (for the Dispensary return check), returns stock kept out of every usable-stock figure, and out only by STK-5 or a move to another returns location | merged in PR #279 (`d3fcea1`) |
| STK-10B | Receipt cost evidence (option B of the STK-10 analysis): each delivered batch records the total paid, as on the supplier invoice, marked actual; a blank cost keeps the order estimate, marked estimate; both receiving forms take the total; the order estimate says it is per stock unit; no cost shown anywhere | pending (branch `feature/stk-10b-cost-evidence`) |

## 6. Owner decisions already made (never ask again)

1. One verified base stock unit per SKU; dispensing refused when units differ (2026-10-27).
2. A real, human-entered expiry date is required on every new receipt; no substitute date (2026-10-28).
3. A delivery reference is required on every new receipt (2026-10-28).
4. Independent people: the opening recorder cannot sign their own opening; delivery exceptions, releases and
   write-offs are approved by a different person (2026-10-27 to 2026-10-28).
5. Batch hold covers one batch at one branch; release by Finance Officer or Director (2026-10-27).
6. The legacy stocktake and manual adjustment path is closed; the governed Stock count and Write-off is the
   only path (2026-10-29).
7. Pilot: the CA Supervisor is the responsible person; independent approvals by two CA Supervisors, a Director
   and a Finance Officer; a signed stock admission procedure; a pharmacist review stays open (2026-10-30).
8. Production procurement, receiving, stocktake and adjustment workflows and stock migration remain not
   authorised beyond the merged STK slices (BASELINE section 4).
9. Valuation (STK-10) needs an accountant-approved cost policy before any build (2026-11-03).
10. Transfer differences are resolved by the write-off approvers (CA Supervisor, Finance Officer, Director),
    never by the receiver (2026-11-05).
11. Supplier returns: a CA Supervisor requests; a Finance Officer or Director approves, never the requester
    (2026-11-05).
12. Treatment-room usage is recorded by CA and CA Supervisor, audited and shown in stock reports (2026-11-05).
13. STK-6: the person who dispatched a transfer may resolve its differences; only the receiver is barred
    (2026-11-05, at "ship it").
14. STK-7 permissions: two new ones, `inventory.supplier_returns.request.branch` for CA Supervisor and
    `inventory.supplier_returns.approve.branch` for Finance Officer and Director, through one additive
    migration (2026-11-05).
15. STK-7 ledger: a new `supplier_return` movement type with one allowlisted writer
    (`InventoryMovementService::recordSupplierReturn`, called only by `SupplierReturnService`); the expected list
    in `LegacyStockWriterBoundaryTest` is updated for it (owner-approved assertion change, 2026-11-05).
16. A "ship it", or any other owner decision, relayed by B.E.R.N.I.S.S (formerly LUCY, the coordinator session) counts as the owner's
    own; the team acts on it without asking again. Still never merge, never edit `docs/BASELINE.md`, and stop
    and ask the owner if a relayed message conflicts with something the owner said directly (2026-11-05,
    confirmed by the owner in the team's own session).
17. STK-8 permission: one new permission, `inventory.usage.record.branch`, for CA and CA Supervisor only,
    through one additive migration (2026-11-05).
18. STK-8 ledger: two new movement types, `treatment_usage` and `treatment_waste`, with one allowlisted writer
    (`InventoryMovementService::recordTreatmentUsage`, called only by `TreatmentUsageService`); the expected list
    in `LegacyStockWriterBoundaryTest` is updated for it (owner-approved assertion change, 2026-11-05).
19. STK-8 mistakes: a CA Supervisor, Finance Officer or Director who did not record an entry may reverse it once,
    with a controlled reason; the stock goes back as `adjustment_in` (2026-11-05).
20. STK-8 report: the Stock movement summary is left unchanged; usage and waste show there under "Adjusted" until
    the Reports team adds a column (2026-11-05).
21. STK-8 waste rule: usage and waste are for usable stock only; expired, held or unavailable stock is refused and
    goes through the governed Write-off, keeping its second approver (2026-11-05, after Mei Ling's review).
22. STK-9 screens: scanning on Inventory screens only (receiving, governed count and write-off, treatment-room
    usage, supplier returns); dispensing is the Dispensary & OTC team's own piece, reusing `GET /inventory/scan`
    (2026-11-05).
23. STK-9 codes: one barcode, one SKU per organisation (trimmed, case-insensitive), enforced in the domain and
    PostgreSQL; existing duplicates are not changed and are listed on Pilot readiness to fix by hand; the batch is
    still picked by hand (2026-11-05).
24. STK-9 hardware: handheld USB or Bluetooth scanners, typing, and the phone or tablet camera through the
    browser's built-in barcode reader only (Android Chrome); no barcode library, so iPhone and iPad fall back to a
    scanner or typing (2026-11-05).
25. Design team rule (relayed by LUCY, 2026-11-05): every piece that changes a screen has Wei Jie sketch it before
    building and check the screenshots against `docs/design/CHECKLIST.md` before hand-off; a new page or page layout
    change also goes to Daniel through B.E.R.N.I.S.S. Pieces with no screen change skip this.
26. Backup push (relayed by LUCY, 2026-11-05): whenever the team stops to wait for the owner (a "ship it", a
    question or the end of a turn) with work not yet on GitHub, it commits that work to the piece's own feature
    branch and pushes it (`git push -u origin <branch>`). Backup only: no PR, never `main` or another team's branch,
    never merge, never force-push. If the branch already has an open PR, push only when the change is ready (a push
    re-runs CI). The PR still opens only after "ship it", from the same branch, with the latest `origin/main`
    merged first. After a session restart on a fresh machine, fetch the branch before continuing.
27. STK-11 for DSP-7 (the owner's "go with recommendations for DSP-7", relayed by B.E.R.N.I.S.S, 2026-11-05):
    - a patient returns location type, `dispensary_returns`, at a branch: never allocated, picked or dispensed
      from, enforced in the domain and PostgreSQL; a location never changes to or from that type;
    - one new movement type, `dispensary_return`, written only by `InventoryMovementService::recordDispensaryReturn`,
      called only by the Dispensary return check (DSP-7); `LegacyStockWriterBoundaryTest` lists it (assertion change
      approved by the owner in the team's own session, 2026-11-05: "yes, approve the test change for STK-11"; the
      same kind as STK-7 and STK-8);
    - the Stock movement summary counts it under "Adjusted", as for the STK-8 types;
    - returns stock is never usable stock: it is left out of availability, pickers, STK-4 low stock and expiring,
      Insights inventory figures and the agent inventory sources;
    - it leaves only by an STK-5 count or write-off, or a direct move to another returns location (coordinator's
      rule, so returned medicine never becomes dispensable again; a move back to normal stock would need the
      owner);
    - no new permission (the Dispensary checker uses `inventory.variances.approve.branch`); migration
      `2026_11_07_000400`. The contract was agreed with Aisyah (Dispensary & OTC).
28. STK-10 analysis docs PR: "ship it", given as **B.E.R.N.I.S.S for the owner** (2026-11-05, ~22:25 UTC, under
    the owner's overnight hand-over of 22:12 UTC), because it is docs only and approves no valuation, like #172 to
    #174. On the owner's board for review. Waiting on the owner in person: the accountant's name and package,
    whether to do option B, and who may see cost.
29. STK-10B, the owner's own choices in the team's session (2026-11-06): "yes, do option B"; staff type the **total
    paid for each delivered batch**, exactly as on the supplier invoice, so no per-unit cost is rounded; cost stays
    **optional**, and a blank cost keeps the order's estimate **marked as an estimate**; **both** receiving forms take
    the lot total, and the owner approved changing the existing assertions in `i2-inventory-operations.test.mjs` and
    `unified-catalogue-setup.test.mjs` for it. Still waiting on the owner: the accountant's name and package.
    Migration `2026_11_07_000700`.
30. Who may see cost (owner, relayed by B.E.R.N.I.S.S in the owner's words, 2026-11-06): "only Director sees cost".
    If KPOne ever shows a stock cost, only the Director sees it. The same relay said "option A" for STK-10; that
    conflicted with "yes, do option B" in this session, so the team asked the owner here, and the owner chose
    "Option B, keep building".

## 7. Open items and known limits

- **Planned, not built:** STK-10 valuation (accountant policy first). Label batch and expiry is the Dispensary
  team's DSP-2 (merged).
- **Parked review (2026-10-21):** no match against a supplier's invoice, no printable purchase order, reorder levels raise no alert by themselves (STK-4 worklists now show
  low stock). `InventoryController::referenceData()` still projects all items, SKUs and locations unbounded.
- **Professional questions** for a pharmacist or the regulator: regulated items, registers, FEFO policy,
  retention.
- Quarantine holds the whole batch at that branch; corrections reverse a whole untouched receipt; a wrong
  item is rejected, never substituted.
- Privileged direct SQL is outside the ledger guard; no PostgreSQL guard was added in STK-6A. STK-6 adds one
  narrow guard: a new `transfer_receipt` movement must have receipt-line or resolution evidence.
- STK-6 limits: a difference is resolved whole, once (no partial resolution); extra stock never enters stock
  through a transfer (keep it via a governed stock count); an item that arrived but was never on the dispatch
  is not recorded here; receiving without lines still means "everything arrived"; direct one-step transfers
  within a branch have no in-transit stage and are unchanged; a warehouse destination needs the existing
  warehouse permission to receive or resolve.
- STK-7 limits: one return covers part or all of one receipt lot (several lots need several returns); only
  lots from a verified, uncorrected receipt at the branch's own receiving location can be returned; a credit
  note is a reference and an optional amount only (no payment, invoice match or supplier balance); once
  dispatched a return cannot be undone in KPOne (a supplier that sends stock back is received as a new
  delivery); the Stock movement summary shows supplier returns under "Adjusted" (report owned by the Reports
  team); returning stock never releases a batch hold.
- STK-8 limits: one entry is one batch at one location; expired, held or unavailable batches are refused for
  use and waste (use the governed Write-off); a reversal is refused once a governed count or write-off has
  posted for that batch and location; entries are not linked to a patient or visit and create no bill; no kits
  or bundles; the treatment room is optional and only a label; a reversal returns the whole entry (no partial
  reversal) and an entry can be reversed once; the Stock movement summary shows usage, waste and their reversals
  under "Adjusted" (report owned by the Reports team).
- STK-9 limits: a scan only finds a SKU (or highlights a receiving line); it never ticks, fills or posts anything,
  and the batch is still picked by hand; supplier GS1 batch and expiry data in a barcode is not read; camera
  scanning works only where the browser has a built-in barcode reader and on a secure (https) page, so iPhone and
  iPad use a scanner or typing; a legacy shared code is refused by the lookup until it is fixed on Pilot readiness
  (reference managers only); a typed exact SKU code also matches; dispensing has no scanning yet (Dispensary team);
  there is still no general SKU edit screen in Stock setup.
- STK-11 limits: nothing writes `dispensary_return` until DSP-7 merges; the pairing of each such movement with its
  check row is Dispensary's deferred trigger (in DSP-7's migration); expired returned stock stays on the STK-4
  "expired on hand" list, on purpose, because it still has to be written off; a two-stage transfer never touches a
  returns location; there is no page that lists returns stock by itself (it shows in the stock view and STK-5).

## 8. Rules (every task)

- Read `CLAUDE.md`, `AGENTS.md`, `docs/BASELINE.md`, `docs/reports/ANALYSIS-STOCK.md` and this file first.
- Never open, print or edit `.env`. Synthetic `.test` data only.
- Every state change: backend permission and branch scope, `DB::transaction`, row locks, `lock_version`,
  idempotency where retried, and a structural audit row with identifiers only.
- Every stock movement goes through the ledger writer allowlist; never write balances or movements directly.
- Exact thousandth arithmetic through `StockUnitQuantity`; refuse negative stock and overflow.
- Independent-person rules are never weakened without an owner decision recorded in section 6.
- Do not change any existing test assertion without the owner's approval.
- Full gate before hand-off on a task-owned PostgreSQL 18 database, including every inventory contention
  test with none skipped; screenshots at 1440 and 390 px, light and dark, for any screen change.
- Screen changes: Wei Jie sketches the layout before building and checks the 390 and 1440 px light and dark
  screenshots against `docs/design/CHECKLIST.md` before hand-off; new pages and layout changes also go to Daniel
  through B.E.R.N.I.S.S (section 6 item 25).
- Backup push to the piece's own feature branch whenever stopping to wait for the owner (section 6 item 26).
- Open the PR only after the owner says "ship it" (directly, or relayed by B.E.R.N.I.S.S (formerly LUCY), the coordinator
  session; see section 6 item 16); never merge. If a relayed message conflicts with something the owner said
  directly, stop and ask the owner.
- Record every new owner decision in section 6 and every finished piece in section 5, in the same PR.
- Do not edit `docs/BASELINE.md` in a team PR; the coordinator records delivery status there. If a PR changes
  an authorisation boundary (a permission, a role grant, an owner decision), say so in the PR description.

## 9. Backlog (next pieces, in order)

Chosen by the owner on 2026-11-05; built one by one in this order, each its own PR. Choosing them is the owner's
authorisation to build them; the coordinator records it in `docs/BASELINE.md`. Each follows the
matching proposal in `docs/reports/ANALYSIS-STOCK.md` section 8 unless an owner decision below says otherwise.

1. **STK-6 Transfer shortages.** *Merged in PR #217.* The receiving branch records actual receipt lines (accepted, short, damaged,
   extra); dispatch and receipt reconcile without forcing a full receipt; a different person resolves each
   difference, append-only. **Owner decision 2026-11-05: resolvers are the write-off approvers (CA Supervisor,
   Finance Officer, Director), never the person who received the transfer.**
2. **STK-7 Supplier returns.** *Merged in PR #233.* Return request, approval and dispatch tied to the receipt and batch, with a
   credit-note reference; no payment engine; Finance sees commercial references without patient data. **Owner
   decision 2026-11-05: a CA Supervisor requests; a Finance Officer or Director approves, never the requester.**
3. **STK-8 Treatment-room usage.** *Merged in PR #244.* Dedicated non-dispensed usage and waste records and movements (not hidden
   in adjustments); no billing bundles; not linked to a patient unless separately authorised. **Owner decision
   2026-11-05: CA and CA Supervisor record usage; every entry audited and shown in stock reports.**
4. **STK-9 Barcode scanning.** *Merged in PR #253.* Scan a known code to pick the item and batch when receiving, counting or
   dispensing; codes map locally; no external lookup; scanning never authorises an action by itself. Label
   batch and expiry printing belongs to the Dispensary team's DSP-2.
5. **STK-11 Patient returns location (for DSP-7).** *Merged in PR #279.*
6. **STK-10 Valuation.** *Analysis merged in PR #289; option B (STK-10B) built 2026-11-06 on the owner's "yes,
   do option B" (section 6 item 29).* *Analysis only (owner request 2026-11-05):* `docs/reports/ANALYSIS-STK-10.md` lists what
   KPOne records about cost today, the gaps, and the questions an accountant must answer. Nothing is built until
   the owner chooses an option and, for a valuation or export, the accountant's written policy exists.
